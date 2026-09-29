<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\ReleaseHistory;
use App\Update\ReleaseNote;
use App\Update\ReleaseNotesMarkdown;
use App\Update\UpdateConfig;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * "Eerdere updates" on the Updates screen (v0.1.12): every published release
 * with its notes, to read back — never to install. The list comes from the
 * release host's API in one request, is validated to ReleaseNote's closed
 * shape because nothing in it is signed, is cached next to last-check.json,
 * survives a failed refresh, and its notes are rendered by
 * ReleaseNotesMarkdown, which escapes before it formats.
 */
final class ReleaseHistoryTest extends TestCase
{
    private string $storage = '';

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/mygdala-release-history-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        FeedFixture::removeDirectory($this->storage);
        UpdateConfig::overrideForTests(null);
    }

    /**
     * One release the way the GitHub Releases API lists it (only the fields
     * ReleaseHistory reads, plus some it must ignore).
     *
     * @return array<string, mixed>
     */
    private static function release(string $tag, string $body = 'Notes', array $extra = []): array
    {
        return $extra + [
            'tag_name' => $tag,
            'name' => 'Mygdala ' . ltrim($tag, 'v'),
            'published_at' => '2026-09-28T15:37:46Z',
            'draft' => false,
            'prerelease' => false,
            'body' => $body,
            'assets' => [['name' => 'manifest.json', 'browser_download_url' => 'https://evil.example/x.zip']],
            'author' => ['login' => 'someone'],
        ];
    }

    private function history(?string $url, \Closure $fetch): ReleaseHistory
    {
        return new ReleaseHistory($this->storage, $url, $fetch);
    }

    private static function json(array $releases): string
    {
        return json_encode($releases, JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------ parsing

    public function testTheListIsNewestVersionFirstWithItsNotes(): void
    {
        $notes = ReleaseHistory::parseGitHub(self::json([
            self::release('v0.1.9'),
            self::release('v0.1.11', "# Nieuw\n\n- Een"),
            self::release('v0.1.10'),
        ]));

        $this->assertSame(['0.1.11', '0.1.10', '0.1.9'], array_map(static fn (ReleaseNote $n): string => $n->version, $notes));
        $this->assertSame("# Nieuw\n\n- Een", $notes[0]->notes);
        $this->assertSame('Mygdala 0.1.11', $notes[0]->title);
        $this->assertSame('2026-09-28T15:37:46Z', $notes[0]->publishedAt);
    }

    public function testDraftsPreReleasesAndOddTagsAreLeftOut(): void
    {
        $notes = ReleaseHistory::parseGitHub(self::json([
            self::release('v0.2.0', 'draft', ['draft' => true]),
            self::release('v0.2.0-rc.1', 'rc', ['prerelease' => true]),
            self::release('nightly'),
            self::release('v0.1.5; DROP'),
            self::release('v0.1.4'),
            self::release('0.1.4'),
            'not a release',
        ]));

        $this->assertSame(['0.1.4'], array_map(static fn (ReleaseNote $n): string => $n->version, $notes), 'one entry per version');
    }

    public function testNothingButTheClosedShapeIsKept(): void
    {
        $note = ReleaseHistory::parseGitHub(self::json([self::release('v1.0.0', str_repeat('x', 30000), [
            'name' => str_repeat('T', 500),
            'published_at' => 'not a date',
        ])]))[0];

        $this->assertSame(['version', 'published_at', 'title', 'notes'], array_keys($note->toArray()), 'no package URL or asset is ever kept');
        $this->assertSame(ReleaseNote::MAX_NOTES_LENGTH, mb_strlen($note->notes));
        $this->assertSame(ReleaseNote::MAX_TITLE_LENGTH, mb_strlen($note->title));
        $this->assertSame('', $note->publishedAt, 'a date that is not one is dropped, not shown');
    }

    public function testTheListIsCapped(): void
    {
        $many = [];
        for ($i = 0; $i < 70; $i++) {
            $many[] = self::release('v0.' . $i . '.0');
        }

        $notes = ReleaseHistory::parseGitHub(self::json($many));
        $this->assertCount(ReleaseHistory::MAX_RELEASES, $notes);
        $this->assertSame('0.69.0', $notes[0]->version);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAnswers(): iterable
    {
        yield 'not JSON' => ['<html>rate limited</html>'];
        yield 'an object' => ['{"message":"API rate limit exceeded"}'];
        yield 'empty' => [''];
        yield 'too deep' => [str_repeat('[', 40) . str_repeat(']', 40)];
    }

    /** @dataProvider malformedAnswers */
    public function testAMalformedAnswerIsAnError(string $answer): void
    {
        $this->expectException(UpdateException::class);
        ReleaseHistory::parseGitHub($answer);
    }

    // ------------------------------------------------------------ cache

    public function testARefreshIsCachedAndReadBackWithoutTheNetwork(): void
    {
        $calls = 0;
        $history = $this->history('https://api.example/releases', static function (string $url, int $max) use (&$calls): string {
            $calls++;

            return self::json([self::release('v0.1.11'), self::release('v0.1.10')]);
        });

        $this->assertSame([], $history->read()['releases'], 'nothing before the first check');

        $record = $history->refresh();
        $this->assertArrayNotHasKey('error', $record);
        $this->assertSame(1, $calls, 'one request for the whole list');

        $read = $history->read();
        $this->assertSame(['0.1.11', '0.1.10'], array_map(static fn (ReleaseNote $n): string => $n->version, $read['releases']));
        $this->assertArrayHasKey('fetched_at', $read);
        $this->assertSame(1, $calls, 'reading never fetches');
    }

    public function testAFailedRefreshKeepsTheLastListAndSaysWhy(): void
    {
        $this->history('https://api.example/releases', static fn (): string => self::json([self::release('v0.1.11')]))->refresh();

        $offline = $this->history('https://api.example/releases', static function (): string {
            throw new UpdateException('update.error.feed_unreachable', [], 'Could not connect');
        });
        $record = $offline->refresh();

        $this->assertSame('update.releases.error.unreadable', $record['error']['key']);
        $read = $offline->read();
        $this->assertSame(['0.1.11'], array_map(static fn (ReleaseNote $n): string => $n->version, $read['releases']), 'the history survives an offline host');
        $this->assertSame('update.releases.error.unreadable', $read['error']['key']);
        $this->assertArrayHasKey('fetched_at', $read, 'the screen can say how old the list is');

        $garbage = $this->history('https://api.example/releases', static fn (): string => '{"message":"Not Found"}');
        $this->assertSame('update.releases.error.unreadable', $garbage->refresh()['error']['key']);
        $this->assertCount(1, $garbage->read()['releases']);
    }

    public function testAnUnexpectedErrorNeverEscapes(): void
    {
        $record = $this->history('https://api.example/releases', static function (): string {
            throw new \RuntimeException('boom');
        })->refresh();

        $this->assertSame('update.releases.error.unreadable', $record['error']['key']);
    }

    public function testNoSourceIsNoHistoryAndNoRequest(): void
    {
        $history = $this->history(null, static function (): string {
            throw new \LogicException('must not fetch');
        });

        $this->assertFalse($history->isAvailable());
        $this->assertSame('update.releases.error.no_source', $history->refresh()['error']['key']);
    }

    public function testADamagedCacheIsAnEmptyHistory(): void
    {
        mkdir($this->storage, 0777, true);
        $history = $this->history('https://api.example/releases', static fn (): string => '[]');

        file_put_contents($this->storage . '/' . ReleaseHistory::FILE, '{not json');
        $this->assertSame(['releases' => []], $history->read());

        file_put_contents($this->storage . '/' . ReleaseHistory::FILE, json_encode([
            'releases' => [['version' => '<b>1</b>', 'published_at' => 'x', 'title' => 't', 'notes' => 'n'], ['version' => '0.1.0', 'published_at' => '2026-01-01', 'title' => [], 'notes' => 'n'], ['version' => '0.1.1', 'published_at' => '2026-01-01T00:00:00Z', 'title' => 'ok', 'notes' => 'n']],
            'error' => ['key' => 'admin.something.else', 'detail' => 'x'],
        ]));
        $read = $history->read();
        $this->assertSame(['0.1.1'], array_map(static fn (ReleaseNote $n): string => $n->version, $read['releases']));
        $this->assertSame('update.releases.error.unreadable', $read['error']['key'], 'a stored key can only name one of our own messages');
    }

    // ------------------------------------------------------------ source

    public function testTheSourceIsTheManifestRepositorysReleaseList(): void
    {
        UpdateConfig::overrideForTests([]);
        $this->assertSame(
            'https://api.github.com/repos/BartvVeluw/mygdala/releases?per_page=' . ReleaseHistory::MAX_RELEASES,
            UpdateConfig::releaseHistoryUrl(),
            'the project feed: derived from DEFAULT_MANIFEST_URL'
        );

        UpdateConfig::overrideForTests([UpdateConfig::MANIFEST_URL_VARIABLE => 'https://releases.example/mygdala/manifest.json']);
        $this->assertNull(UpdateConfig::releaseHistoryUrl(), 'a feed elsewhere has no history unless it names one');

        UpdateConfig::overrideForTests([
            UpdateConfig::MANIFEST_URL_VARIABLE => 'https://releases.example/mygdala/manifest.json',
            UpdateConfig::HISTORY_URL_VARIABLE => 'https://releases.example/mygdala/releases.json',
        ]);
        $this->assertSame('https://releases.example/mygdala/releases.json', UpdateConfig::releaseHistoryUrl());

        UpdateConfig::overrideForTests([UpdateConfig::HISTORY_URL_VARIABLE => 'https://user:pass@releases.example/r.json']);
        $this->assertNull(UpdateConfig::releaseHistoryUrl(), 'the same URL rules as the feed');
    }

    // ------------------------------------------------------------ rendering

    public function testNotesAreEscapedBeforeTheyAreFormatted(): void
    {
        $html = ReleaseNotesMarkdown::toHtml(implode("\n", [
            '# Nieuw <script>alert(1)</script>',
            '',
            '<img src=x onerror=alert(1)>',
            '- **vet** en `<b>code</b>`',
            '- [link](javascript:alert(1)) en [ok](https://example.com/a?b=1&c="x")',
            '',
            '1. een',
            '2. twee',
        ]));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('<h3>Nieuw &lt;script&gt;alert(1)&lt;/script&gt;</h3>', $html);
        $this->assertStringContainsString('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $html);
        $this->assertStringContainsString('<li><strong>vet</strong> en <code>&lt;b&gt;code&lt;/b&gt;</code></li>', $html);
        $this->assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=&quot;x&quot;" target="_blank" rel="noopener noreferrer">ok</a>', $html);
        $this->assertStringContainsString("<ol>\n<li>een</li>\n<li>twee</li>\n</ol>", $html);

        preg_match_all('/<([a-z0-9]+)[\s>]/', $html, $tags);
        $this->assertSame([], array_diff(array_unique($tags[1]), ['h3', 'h4', 'h5', 'ul', 'ol', 'li', 'p', 'strong', 'em', 'code', 'a', 'br']), 'only its own closed list of tags');
    }

    public function testEmphasisNeverCrossesAnotherElement(): void
    {
        $html = ReleaseNotesMarkdown::toHtml('**a *b** c* en [**x](https://example.com) y**');

        self::assertSame(substr_count($html, '<strong>'), substr_count($html, '</strong>'));
        self::assertSame(substr_count($html, '<em>'), substr_count($html, '</em>'));
        self::assertDoesNotMatchRegularExpression('#<strong>[^<]*<em>[^<]*</strong>#', $html, 'no crossed strong/em');
        self::assertDoesNotMatchRegularExpression('#<strong>[^<]*<a [^>]*>[^<]*</strong>#', $html, 'no strong half inside a link');
    }

    public function testRealReleaseNotesKeepTheirStructure(): void
    {
        $html = ReleaseNotesMarkdown::toHtml("Intro regel.\n\n# Nieuwe contentblokken\n\n## Uitgelicht product\n\n- Zet één product groot op een pagina.\n- Bestellen vanuit het blok.\n\n### Klein\nTekst\nop twee regels");

        $this->assertSame(
            "<p>Intro regel.</p>\n<h3>Nieuwe contentblokken</h3>\n<h4>Uitgelicht product</h4>\n<ul>\n<li>Zet één product groot op een pagina.</li>\n<li>Bestellen vanuit het blok.</li>\n</ul>\n<h5>Klein</h5>\n<p>Tekst<br>op twee regels</p>",
            $html
        );
    }

    /**
     * The screen reads the cache and renders every release as a <details>
     * card with the Markdown renderer — and offers nothing to install in it.
     */
    public function testTheScreenShowsHistoryReadOnly(): void
    {
        $screen = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/updates.php');
        $start = strpos($screen, '<section class="admin-card admin-updates__releases" data-release-history>');
        $this->assertNotFalse($start);
        $section = substr($screen, $start, strpos($screen, '</section>', $start) - $start);

        $this->assertStringContainsString('$releases = $releaseHistory->read();', $screen, 'read, never fetched on render');
        $this->assertStringNotContainsString('->refresh()', $screen);
        $this->assertStringContainsString('<details class="admin-collapse admin-collapse--card admin-updates__release"', $section);
        $this->assertStringContainsString('ReleaseNotesMarkdown::toHtml($release->notes)', $section);
        $this->assertStringNotContainsString('<form', $section, 'no install, downgrade or rollback action on an old release');
        $this->assertStringNotContainsString('updates-start', $section);
        $this->assertStringContainsString("admin_te('update.releases.no_downgrade')", $section);

        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/api/admin/updates-check.php');
        $this->assertMatchesRegularExpression('/\$record = Updater::fromConfig\(\)->check\(\);\s*ReleaseHistory::fromConfig\(\)->refresh\(\);/', $endpoint, 'fetched with the check, after it');
    }
}
