<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\RedirectRepository;
use App\Service\PageContent;
use App\Service\Redirects\Redirect;
use App\Service\Redirects\RedirectTarget;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * What a real request does. Routing is the one part of this feature that
 * cannot be asserted any other way: which of the two integration points a URL
 * reaches depends on Apache and .htaccess, not on PHP.
 *
 * Both are covered here on purpose, because they are reached by different
 * URLs and only one of them is new:
 *
 *   - /zz-... (slug characters, one segment) is rewritten to pagina.php, which
 *     404s in PHP and consults the redirect table itself;
 *   - /zz_... (an underscore) matches no rewrite at all, so Apache 404s and
 *     the new ErrorDocument (404.php) is the only thing that ever sees it.
 *
 * Skips itself when the test web server is not running, like every other HTTP
 * test here.
 */
class RedirectRoutingTest extends TestCase
{
    private const SOURCE_PREFIX = '/zz-redirect-routing';
    private const UNDERSCORE_SOURCE = '/zz_redirect_routing_legacy.html';
    private const PAGE_KEY = 'zz-redirect-routing-page';

    private RedirectRepository $repository;

    protected function setUp(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $this->repository = new RedirectRepository();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $db = Database::connection();

        // Exact prefixes only, never a LIKE pattern: "_" is a LIKE wildcard,
        // and one of these sources contains three of them.
        foreach ([self::SOURCE_PREFIX, self::UNDERSCORE_SOURCE] as $prefix) {
            $stmt = $db->prepare('DELETE FROM redirects WHERE LEFT(source_path, :length) = :prefix');
            $stmt->execute(['length' => strlen($prefix), 'prefix' => $prefix]);
        }

        $stmt = $db->prepare('DELETE FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::PAGE_KEY]);

        PageContent::clearCache();
    }

    private function store(
        string $source,
        string $target,
        int $status = Redirect::STATUS_PERMANENT,
        bool $active = true,
        string $type = RedirectTarget::TYPE_INTERNAL
    ): int {
        return $this->repository->create([
            'source_path' => $source,
            'target_type' => $type,
            'target_value' => $target,
            'status_code' => $status,
            'is_active' => $active,
            'origin' => Redirect::ORIGIN_MANUAL,
        ]);
    }

    /**
     * One request, without following the redirect — the point of these tests
     * is the response itself, not where it eventually lands.
     *
     * @return array{status: int, location: ?string, body: string}
     */
    private function request(string $path): array
    {
        $handle = curl_init(TestEnvironment::baseUrl() . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $matches) === 1) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'location' => $location, 'body' => $body];
    }

    public function testAnActivePermanentRedirectMovesTheVisitor(): void
    {
        $this->store(self::SOURCE_PREFIX . '-old', '/contact.php');

        $response = $this->request(self::SOURCE_PREFIX . '-old');

        $this->assertSame(301, $response['status']);
        $this->assertSame(\App\Service\AppUrl::canonical('contact.php'), $response['location']);
    }

    public function testATemporaryRedirectAnswersWith302(): void
    {
        $this->store(self::SOURCE_PREFIX . '-temp', '/contact.php', Redirect::STATUS_TEMPORARY);

        $this->assertSame(302, $this->request(self::SOURCE_PREFIX . '-temp')['status']);
    }

    /**
     * The new ErrorDocument is the only thing that ever sees this URL: an
     * underscore and a .html extension mean .htaccess's CMS-page rule never
     * matched, so PHP was never reached before.
     */
    public function testAUrlApacheCouldNotRouteAtAllStillRedirects(): void
    {
        $this->store(self::UNDERSCORE_SOURCE, '/contact.php');

        $response = $this->request(self::UNDERSCORE_SOURCE);

        $this->assertSame(301, $response['status']);
        $this->assertSame(\App\Service\AppUrl::canonical('contact.php'), $response['location']);
    }

    public function testADisabledRedirectDoesNotFire(): void
    {
        $this->store(self::SOURCE_PREFIX . '-off', '/contact.php', Redirect::STATUS_PERMANENT, false);

        $response = $this->request(self::SOURCE_PREFIX . '-off');

        $this->assertSame(404, $response['status']);
        $this->assertNull($response['location']);
    }

    public function testAPathWithNoRedirectStillReachesTheNormal404(): void
    {
        $response = $this->request(self::SOURCE_PREFIX . '-nothing-here');

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('Pagina niet gevonden', $response['body']);
    }

    /**
     * The same document, whichever half of the routing decided the URL was
     * unknown — before this feature, an underscore got Apache's stock error
     * page instead.
     */
    public function testAUrlApacheCouldNotRouteGetsTheSitesOwn404Page(): void
    {
        $response = $this->request('/zz_redirect_routing_nothing_here');

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('Pagina niet gevonden', $response['body']);
    }

    /**
     * The guarantee the whole design rests on: the redirect table is only ever
     * consulted where a request was already about to 404, so a row that
     * conflicts with real content is inert rather than dangerous. Written
     * straight to the database, because RedirectValidator refuses to save it.
     */
    public function testALivePageWinsOverAConflictingRedirect(): void
    {
        \Tests\Support\PageFixture::create([
            'content_key' => self::PAGE_KEY,
            'slug' => self::PAGE_KEY,
            'status' => PageContent::STATUS_PUBLISHED,
        ], 'Redirect-routing testpagina');

        $this->store('/' . self::PAGE_KEY, '/contact.php');

        $response = $this->request('/' . self::PAGE_KEY);

        $this->assertSame(200, $response['status']);
        $this->assertNull($response['location'], 'a page that exists must never be redirected away from');
    }

    public function testTrailingSlashesFindTheSameRedirect(): void
    {
        $this->store(self::SOURCE_PREFIX . '-old', '/contact.php');

        foreach ([self::SOURCE_PREFIX . '-old/', self::SOURCE_PREFIX . '-old//'] as $variant) {
            $this->assertSame(301, $this->request($variant)['status'], $variant . ' is the same URL');
        }
    }

    public function testTheVisitorsQueryStringSurvivesAnInternalRedirect(): void
    {
        $this->store(self::SOURCE_PREFIX . '-old', '/contact.php');

        $response = $this->request(self::SOURCE_PREFIX . '-old?utm_source=nieuwsbrief&utm_medium=email');

        $this->assertSame(
            \App\Service\AppUrl::canonical('contact.php') . '?utm_source=nieuwsbrief&utm_medium=email',
            $response['location']
        );
    }

    /**
     * A destination the editor gave its own parameters wins outright: gluing
     * two query strings together produces a URL neither of them meant.
     */
    public function testADestinationWithItsOwnQueryStringIsNotAppendedTo(): void
    {
        $this->store(self::SOURCE_PREFIX . '-own-query', '/contact.php?onderwerp=offerte');

        $response = $this->request(self::SOURCE_PREFIX . '-own-query?utm_source=nieuwsbrief');

        $this->assertSame(
            \App\Service\AppUrl::canonical('contact.php') . '?onderwerp=offerte',
            $response['location']
        );
    }

    public function testAChainIsFollowedToItsEnd(): void
    {
        $this->store(self::SOURCE_PREFIX . '-oldest', self::SOURCE_PREFIX . '-middle');
        $this->store(self::SOURCE_PREFIX . '-middle', '/contact.php');

        $response = $this->request(self::SOURCE_PREFIX . '-oldest');

        $this->assertSame(301, $response['status']);
        $this->assertSame(
            \App\Service\AppUrl::canonical('contact.php'),
            $response['location'],
            'the visitor goes to the end of the chain in one hop'
        );
    }

    /**
     * A loop that reached the database anyway — someone editing SQL directly,
     * say — ends as a 404, never as a request that never returns.
     */
    public function testALoopWrittenStraightToTheDatabaseEndsAsA404(): void
    {
        $this->store(self::SOURCE_PREFIX . '-loop-a', self::SOURCE_PREFIX . '-loop-b');
        $this->store(self::SOURCE_PREFIX . '-loop-b', self::SOURCE_PREFIX . '-loop-a');

        $response = $this->request(self::SOURCE_PREFIX . '-loop-a');

        $this->assertSame(404, $response['status']);
        $this->assertNull($response['location']);
    }

    public function testAnExternalDestinationIsUsedExactlyAsStored(): void
    {
        $this->store(
            self::SOURCE_PREFIX . '-external',
            'https://voorbeeld.nl/pagina',
            Redirect::STATUS_PERMANENT,
            true,
            RedirectTarget::TYPE_EXTERNAL
        );

        $response = $this->request(self::SOURCE_PREFIX . '-external?utm_source=x');

        $this->assertSame(301, $response['status']);
        $this->assertSame(
            'https://voorbeeld.nl/pagina',
            $response['location'],
            'this site does not decide what parameters another site receives'
        );
    }

    /**
     * A redirect response has no page in it, so there is no canonical tag, no
     * Open Graph block and no body for a crawler to index. The redirect is the
     * SEO signal; see SEO.md.
     */
    public function testARedirectResponseRendersNoPageAtAll(): void
    {
        $this->store(self::SOURCE_PREFIX . '-old', '/contact.php');

        $response = $this->request(self::SOURCE_PREFIX . '-old');

        $this->assertSame('', trim($response['body']));
        $this->assertStringNotContainsString('rel="canonical"', $response['body']);
        $this->assertStringNotContainsString('og:title', $response['body']);
    }

    /**
     * The Location header names the site's own APP_URL, never the host the
     * request happened to arrive on — the same rule every canonical tag and
     * sitemap entry on this site follows.
     */
    public function testTheDestinationNeverComesFromTheRequestHost(): void
    {
        $this->store(self::SOURCE_PREFIX . '-old', '/contact.php');

        $location = (string) $this->request(self::SOURCE_PREFIX . '-old')['location'];

        $this->assertStringNotContainsString(TestEnvironment::requestHost(), $location);
    }
}
