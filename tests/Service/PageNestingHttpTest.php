<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Service\AdminPermissions;
use App\Service\AppUrl;
use App\Service\PageAdminGroup;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * Nested pages the way a visitor and an editor meet them (Pagina's 2.0,
 * docs/pages/NESTING.md), over real HTTP against PHP's built-in server with
 * the dispatcher in front, like production's .htaccess:
 *
 *     Diensten (…-diensten, en …-services)
 *     └ Metaal graveren (…-metaal, en …-metal)
 *       └ Aluminium visitekaartjes (…-aluminium, en …-aluminium-cards)
 *     Hout graveren (…-hout, en …-wood)
 *     Alleen Nederlands (…-alleen-nl, no English address)
 *     └ Kind (…-kind, en …-child)
 *
 * The public half: every level answers at its whole path in both languages,
 * a wrong ancestor is a 404, canonical, hreflang, x-default, the language
 * switch, the breadcrumb and the sitemap all name the nested path, and a
 * child under an untranslated page has no English URL. The editor half: the
 * parent list, the confirmation before a move, the move itself with a
 * redirect for every old path of the subtree in every language, a refused
 * loop, a refused delete, the admin group of a subtree, and the overview's
 * tree markup and search.
 *
 * Every page and redirect is this test's own (zz-nesthttp) and is removed in
 * tearDown(), children first. Without a server the test skips itself.
 */
final class PageNestingHttpTest extends TestCase
{
    private const P = 'zz-nesthttp';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private PageRepository $pages;

    /** @var array<string, int> */
    private array $ids = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start([], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        self::assertSame('nl', PageLocalization::defaultLanguage(), 'this test expects the Dutch-default test database');

        $this->accounts = new AdminTestSession();
        $this->pages = new PageRepository();
        $this->cleanUp();

        $this->ids['diensten'] = $this->page('diensten', 'Diensten', 'services', 'Services');
        $this->ids['metaal'] = $this->page('metaal', 'Metaal graveren', 'metal', 'Metal engraving', $this->ids['diensten']);
        $this->ids['aluminium'] = $this->page('aluminium', 'Aluminium visitekaartjes', 'aluminium-cards', 'Aluminium business cards', $this->ids['metaal']);
        $this->ids['hout'] = $this->page('hout', 'Hout graveren', 'wood', 'Wood engraving');
        $this->ids['alleen-nl'] = $this->page('alleen-nl', 'Alleen Nederlands', null, null);
        $this->ids['kind'] = $this->page('kind', 'Kind', 'child', 'Child', $this->ids['alleen-nl']);
        PageContent::clearCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->accounts)) {
            $this->accounts->forget();
        }
        $this->cleanUp();
        PageContent::clearCache();
    }

    // ----------------------------------------------------------- visitors

    public function testEveryLevelAnswersAtItsWholePathInBothLanguages(): void
    {
        foreach ([
            $this->nl('diensten'),
            $this->nl('diensten', 'metaal'),
            $this->nl('diensten', 'metaal', 'aluminium'),
            '/en' . $this->en('services'),
            '/en' . $this->en('services', 'metal'),
            '/en' . $this->en('services', 'metal', 'aluminium-cards'),
        ] as $path) {
            self::assertSame(200, $this->get($path)['status'], $path);
        }
    }

    public function testAWrongOrMissingAncestorIsA404(): void
    {
        foreach ([
            $this->nl('hout', 'aluminium'),
            $this->nl('aluminium'),
            $this->nl('metaal', 'aluminium'),
            $this->nl('diensten', 'aluminium'),
            '/en' . $this->en('wood', 'aluminium-cards'),
            // The Dutch path under the English prefix is not the English page.
            '/en' . $this->nl('diensten', 'metaal'),
        ] as $path) {
            self::assertSame(404, $this->get($path)['status'], $path);
        }
    }

    public function testCanonicalHreflangAndXDefaultNameTheNestedPaths(): void
    {
        $nl = substr($this->nl('diensten', 'metaal', 'aluminium'), 1);
        $en = 'en' . $this->en('services', 'metal', 'aluminium-cards');

        foreach (['/' . $nl => $nl, '/' . $en => $en] as $path => $canonical) {
            $body = $this->get($path)['body'];

            self::assertStringContainsString('<link rel="canonical" href="' . AppUrl::canonical($canonical) . '">', $body, $path);
            self::assertStringContainsString('hreflang="nl" href="' . AppUrl::canonical($nl) . '"', $body, $path);
            self::assertStringContainsString('hreflang="en" href="' . AppUrl::canonical($en) . '"', $body, $path);
            self::assertStringContainsString('hreflang="x-default" href="' . AppUrl::canonical($nl) . '"', $body, $path);
        }
    }

    public function testTheLanguageSwitchAndTheBreadcrumbFollowTheTree(): void
    {
        $body = $this->get('/en' . $this->en('services', 'metal', 'aluminium-cards'))['body'];

        self::assertStringContainsString('href="' . $this->nl('diensten', 'metaal', 'aluminium') . '" hreflang="nl"', $body);
        self::assertMatchesRegularExpression(
            '#<a href="/en' . preg_quote($this->en('services'), '#') . '">Services</a>.*<a href="/en'
                . preg_quote($this->en('services', 'metal'), '#') . '">Metal engraving</a>.*aria-current="page">Aluminium business cards<#s',
            $body
        );
    }

    /**
     * Kind has an English slug, but the page above it has none: /en/…/child
     * cannot exist while its parent does not (docs/multilingual/ROUTING.md §2),
     * so it is a 404, the switch shows English unavailable, and hreflang and
     * the sitemap leave it out.
     */
    public function testAChildUnderAnUntranslatedPageHasNoEnglishUrl(): void
    {
        self::assertSame(404, $this->get('/en/' . self::P . '-alleen-nl/' . self::P . '-child')['status']);

        $body = $this->get($this->nl('alleen-nl', 'kind'))['body'];
        self::assertSame(200, $this->get($this->nl('alleen-nl', 'kind'))['status']);
        self::assertStringNotContainsString('hreflang="en"', $body);
        self::assertStringContainsString('lang-switch__unavailable', $body);

        $sitemap = $this->get('/sitemap.xml')['body'];
        self::assertStringContainsString(AppUrl::canonical(substr($this->nl('alleen-nl', 'kind'), 1)) . '</loc>', $sitemap);
        self::assertStringNotContainsString(self::P . '-child</loc>', $sitemap);
    }

    public function testTheSitemapHoldsTheNestedPathsInEveryLanguageAndNoFlatOnes(): void
    {
        $sitemap = $this->get('/sitemap.xml')['body'];

        self::assertStringContainsString('<loc>' . AppUrl::canonical(substr($this->nl('diensten', 'metaal', 'aluminium'), 1)) . '</loc>', $sitemap);
        self::assertStringContainsString('<loc>' . AppUrl::canonical('en' . $this->en('services', 'metal', 'aluminium-cards')) . '</loc>', $sitemap);
        self::assertStringNotContainsString('<loc>' . AppUrl::canonical(self::P . '-aluminium') . '</loc>', $sitemap);
    }

    // ------------------------------------------------------------ editors

    public function testTheParentListLeavesOutThePageAndEverythingBelowIt(): void
    {
        $session = $this->signIn();
        $body = self::$server->request('GET', '/admin/page.php?id=' . $this->ids['diensten'], $session)['body'];

        self::assertStringContainsString('name="parent_id"', $body);
        foreach (['diensten', 'metaal', 'aluminium'] as $excluded) {
            self::assertStringNotContainsString('<option value="' . $this->ids[$excluded] . '"', $body, $excluded);
        }
        self::assertMatchesRegularExpression('#<option value="' . $this->ids['hout'] . '"\s+data-page-path="' . self::P . '-hout"#', $body);
        self::assertStringContainsString('name="admin_group"', $body, 'a root page chooses its group');

        // A child shows the path it has, in the language on screen.
        $child = self::$server->request('GET', '/admin/page.php?id=' . $this->ids['aluminium'], $session)['body'];
        self::assertStringContainsString(AppUrl::canonical(substr($this->nl('diensten', 'metaal', 'aluminium'), 1)) . '</code>', $child);
    }

    /**
     * Nesting "Diensten" under "Hout graveren": nothing moves until the
     * editor confirms the paths they are shown; then the whole subtree moves,
     * and every old path in every language redirects to its new one.
     */
    public function testAMoveIsConfirmedAndThenRedirectsTheWholeSubtree(): void
    {
        $session = $this->signIn();

        $first = $this->save($session, $this->ids['diensten'], ['parent_id' => (string) $this->ids['hout']]);
        self::assertSame('/admin/page.php?id=' . $this->ids['diensten'], $first['location']);
        self::assertNull($this->row('diensten')['parent_id'], 'nothing is written before the confirmation');

        $change = $this->accounts->read($session, 'admin_page_url_change');
        self::assertSame($this->nl('diensten'), $change['old_path'] ?? null);
        self::assertSame($this->nl('hout', 'diensten'), $change['new_path'] ?? null);
        self::assertSame(2, $change['moving_below'] ?? null);

        $confirmation = self::$server->request('GET', '/admin/page.php?id=' . $this->ids['diensten'], $session)['body'];
        self::assertStringContainsString('name="confirmed_parent" value="' . $this->ids['hout'] . '"', $confirmation);

        $saved = $this->save($session, $this->ids['diensten'], [
            'parent_id' => (string) $this->ids['hout'],
            'confirmed_parent' => (string) $this->ids['hout'],
        ]);
        self::assertStringContainsString('updated=1', $saved['location']);
        self::assertSame($this->ids['hout'], (int) $this->row('diensten')['parent_id']);

        foreach ([
            $this->nl('diensten', 'metaal', 'aluminium') => $this->nl('hout', 'diensten', 'metaal', 'aluminium'),
            $this->nl('diensten') => $this->nl('hout', 'diensten'),
            '/en' . $this->en('services', 'metal') => '/en' . $this->en('wood', 'services', 'metal'),
        ] as $old => $new) {
            $response = $this->get($old);
            self::assertSame(301, $response['status'], $old);
            self::assertSame(AppUrl::canonical(substr($new, 1)), $response['location'], $old);
            self::assertSame(200, $this->get($new)['status'], $new);
        }
    }

    public function testRenamingAnAncestorRedirectsTheOldPathsBelowIt(): void
    {
        $session = $this->signIn();
        $newSlug = self::P . '-diensten-nieuw';

        $this->save($session, $this->ids['diensten'], ['slug' => $newSlug, 'confirmed_slug' => $newSlug]);

        $response = $this->get($this->nl('diensten', 'metaal', 'aluminium'));
        self::assertSame(301, $response['status']);
        self::assertSame(
            AppUrl::canonical($newSlug . '/' . self::P . '-metaal/' . self::P . '-aluminium'),
            $response['location']
        );
        // English did not move, so it did not get a redirect.
        self::assertSame(200, $this->get('/en' . $this->en('services', 'metal', 'aluminium-cards'))['status']);
    }

    public function testALoopIsRefusedEvenFromAHandMadeRequest(): void
    {
        $session = $this->signIn();

        $response = $this->save($session, $this->ids['diensten'], [
            'parent_id' => (string) $this->ids['aluminium'],
            'confirmed_parent' => (string) $this->ids['aluminium'],
        ]);

        self::assertSame('/admin/page.php?id=' . $this->ids['diensten'], $response['location']);
        self::assertNotEmpty($this->accounts->read($session, 'admin_page_errors'));
        self::assertNull($this->row('diensten')['parent_id']);
    }

    public function testAPageWithChildrenIsNotDeleted(): void
    {
        $session = $this->signIn();

        self::$server->request('POST', '/api/admin/delete-page.php', $session, [
            'csrf_token' => $this->csrf($session),
            'id' => (string) $this->ids['metaal'],
        ]);

        self::assertNotNull($this->pages->findById($this->ids['metaal']));
        self::assertStringContainsString('onderliggende', implode(' ', (array) $this->accounts->read($session, 'admin_page_errors')));
    }

    public function testASubtreeFollowsItsRootsGroupWhereverItGoes(): void
    {
        $session = $this->signIn();

        $this->save($session, $this->ids['diensten'], ['admin_group' => PageAdminGroup::SERVICE]);
        foreach (['diensten', 'metaal', 'aluminium'] as $name) {
            self::assertSame(PageAdminGroup::SERVICE, $this->row($name)['admin_group'], $name);
        }

        // Moved under a website root, the whole subtree comes along — and a
        // child's own admin_group field is ignored: it follows its tree.
        $this->save($session, $this->ids['metaal'], [
            'parent_id' => (string) $this->ids['hout'],
            'confirmed_parent' => (string) $this->ids['hout'],
            'admin_group' => PageAdminGroup::SERVICE,
        ]);
        self::assertSame(PageAdminGroup::WEBSITE, $this->row('metaal')['admin_group']);
        self::assertSame(PageAdminGroup::WEBSITE, $this->row('aluminium')['admin_group']);
        self::assertSame(PageAdminGroup::SERVICE, $this->row('diensten')['admin_group']);
    }

    public function testTheOverviewIsATreeInTwoGroups(): void
    {
        $this->pages->updatePlacement($this->ids['alleen-nl'], null, PageAdminGroup::SERVICE, false);
        $this->pages->updateAdminGroup([$this->ids['kind']], PageAdminGroup::SERVICE);

        $body = self::$server->request('GET', '/admin/pages.php', $this->signIn())['body'];

        self::assertStringContainsString('data-page-tree', $body);
        self::assertMatchesRegularExpression('#data-page-group="website" data-page-group-default="open"#', $body);
        self::assertMatchesRegularExpression('#data-page-group="service" data-page-group-default="closed".*?Service &amp; juridisch</span>\s*<span class="admin-page-group__count">\(2\)</span>#s', $body);
        self::assertMatchesRegularExpression(
            '#<tr id="page-row-' . $this->ids['aluminium'] . '" data-page-row="' . $this->ids['aluminium'] . '" data-page-parent="' . $this->ids['metaal'] . '" data-page-depth="2"#',
            $body
        );
        self::assertMatchesRegularExpression(
            '#aria-expanded="true" aria-controls="page-row-' . $this->ids['metaal'] . '" data-page-tree-toggle="' . $this->ids['diensten'] . '"#',
            $body
        );
        // The child is in its root's list, after the website group.
        self::assertGreaterThan(strpos($body, 'data-page-group="service"'), strpos($body, 'data-page-row="' . $this->ids['kind'] . '"'));
        self::assertLessThan(strpos($body, 'data-page-group="service"'), strpos($body, 'data-page-row="' . $this->ids['aluminium'] . '"'));
        self::assertStringContainsString('/admin/assets/page-tree.js', $body);
    }

    public function testASearchShowsAMatchWithEveryPageAboveIt(): void
    {
        $body = self::$server->request('GET', '/admin/pages.php?q=' . rawurlencode('Aluminium visitekaartjes'), $this->signIn())['body'];

        self::assertStringContainsString('data-page-tree data-page-tree-search', $body);
        foreach (['diensten', 'metaal', 'aluminium'] as $name) {
            self::assertStringContainsString('data-page-row="' . $this->ids[$name] . '"', $body, $name);
        }
        self::assertStringNotContainsString('data-page-row="' . $this->ids['hout'] . '"', $body);
        self::assertSame(2, substr_count($body, 'admin-page-tree__context'), 'the two pages above the match are context');
    }

    public function testANewPageCanStartUnderAParent(): void
    {
        $session = $this->signIn();
        $form = self::$server->request('GET', '/admin/page-new.php?parent=' . $this->ids['metaal'], $session)['body'];
        self::assertMatchesRegularExpression('#<option value="' . $this->ids['metaal'] . '"[^>]*selected#', $form);

        $response = self::$server->request('POST', '/api/admin/create-page.php', $session, [
            'csrf_token' => $this->csrf($session),
            'title' => 'Nesthttp messing',
            'slug' => self::P . '-messing',
            'status' => PageContent::STATUS_PUBLISHED,
            'template' => 'blank',
            'parent_id' => (string) $this->ids['metaal'],
            'admin_group' => PageAdminGroup::SERVICE,
        ]);
        self::assertStringContainsString('created=1', $response['location']);

        $row = $this->row('messing');
        self::assertSame($this->ids['metaal'], (int) $row['parent_id']);
        self::assertSame(PageAdminGroup::WEBSITE, $row['admin_group'], 'a child follows its tree');
        self::assertSame(200, $this->get($this->nl('diensten', 'metaal', 'messing'))['status']);
    }

    // ------------------------------------------------------------ helpers

    private function page(string $name, string $title, ?string $english, ?string $englishTitle, ?int $parentId = null): int
    {
        $slug = self::P . '-' . $name;
        $id = PageFixture::create(
            ['content_key' => $slug, 'slug' => $slug, 'status' => PageContent::STATUS_PUBLISHED, 'parent_id' => $parentId],
            $title
        );
        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => $title], $slug);

        if ($english !== null) {
            PageLocalization::save($id, 'en', [PageTranslation::TITLE => (string) $englishTitle], self::P . '-' . $english);
        }

        return $id;
    }

    private function nl(string ...$names): string
    {
        return '/' . implode('/', array_map(static fn (string $name): string => self::P . '-' . $name, $names));
    }

    private function en(string ...$names): string
    {
        return $this->nl(...$names);
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path): array
    {
        return self::$server->request('GET', $path);
    }

    private function signIn(): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        return $session;
    }

    private function csrf(string $session): string
    {
        return (string) $this->accounts->read($session, 'csrf_token');
    }

    /**
     * The settings form of admin/page.php, in Dutch, as it would be sent.
     *
     * @param array<string, string> $overrides
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function save(string $session, int $pageId, array $overrides = []): array
    {
        $row = (array) $this->pages->findById($pageId);

        return self::$server->request('POST', '/api/admin/update-page.php', $session, array_merge([
            'csrf_token' => $this->csrf($session),
            'id' => (string) $pageId,
            'language_code' => 'nl',
            'title' => (string) PageLocalization::raw($pageId, PageTranslation::TITLE, 'nl') ?: 'Titel',
            'slug' => (string) $row['slug'],
            'status' => PageContent::STATUS_PUBLISHED,
            'meta_title' => '',
            'meta_description' => '',
            'noindex' => '0',
            'show_breadcrumb' => '1',
            'parent_id' => (string) (int) ($row['parent_id'] ?? 0),
            'admin_group' => (string) $row['admin_group'],
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function row(string $name): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM pages WHERE content_key = ?');
        $stmt->execute([self::P . '-' . $name]);

        return (array) $stmt->fetch();
    }

    private function cleanUp(): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM redirects WHERE source_path LIKE ? OR source_path LIKE ?')
            ->execute(['/' . self::P . '%', '/en/' . self::P . '%']);

        for ($round = 0; $round < 10; $round++) {
            $db->prepare('DELETE FROM pages WHERE content_key LIKE ? AND id NOT IN (SELECT parent_id FROM (SELECT parent_id FROM pages WHERE parent_id IS NOT NULL) AS parents)')
                ->execute([self::P . '%']);
        }

        PageLocalization::clearCache();
    }
}
