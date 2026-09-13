<?php

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\SiteSettingRepository;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * End-to-end coverage of the generic /<slug> route: a published page renders,
 * a draft one 404s, an unknown slug 404s, a real application route always
 * wins over a page slug, the homepage still answers at "/", and the SEO
 * title/meta description actually reach the <head>.
 *
 * These are the only HTTP-level tests in this project. Everything else here
 * is a pure unit or repository test, because the rest of the codebase can be
 * exercised directly — but routing is precisely the behaviour that lives in
 * .htaccess + Apache and cannot be asserted any other way. They talk to the
 * web server in the same container the test suite runs in (http://localhost/),
 * and skip themselves when that isn't reachable (e.g. phpunit run outside the
 * Docker stack), so they never turn into a flaky failure for a developer
 * running the suite differently.
 */
class PageRoutingTest extends TestCase
{
    /**
     * Deliberately made of the same characters a real slug is
     * (`[a-z0-9-]`): .htaccess only rewrites that charset, so a key with
     * underscores would 404 in Apache before pagina.php ever saw it and the
     * routing assertions below would be testing nothing. The `zz-` prefix
     * keeps it obviously-fake and last in any listing.
     */
    private const TEST_KEY = 'zz-test-routing-page';

    /**
     * The site name the homepage assertion looks for, written by the test
     * itself rather than read from whatever the test database was copied
     * from. Restored exactly in tearDown(), including a row that did not
     * exist before.
     */
    private const SITE_NAME = 'ZZ Routingtest';

    private PageRepository $repository;
    private ?int $pageId = null;

    /** What `site_name` held before this test, null when there was no row. */
    private ?string $originalSiteName = null;

    private bool $siteNameWritten = false;

    protected function setUp(): void
    {
        $this->repository = new PageRepository();
        $this->skipUnlessServerReachable();
        $this->cleanUp();

        $stored = (new SiteSettingRepository())->findAll();
        $this->originalSiteName = array_key_exists('site_name', $stored) ? $stored['site_name'] : null;

        (new SiteSettingRepository())->upsertMany(['site_name' => self::SITE_NAME]);
        $this->siteNameWritten = true;
        SiteSettings::clearCache();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        if ($this->siteNameWritten) {
            if ($this->originalSiteName === null) {
                Database::connection()
                    ->prepare("DELETE FROM site_settings WHERE setting_key = 'site_name'")
                    ->execute();
            } else {
                (new SiteSettingRepository())->upsertMany(['site_name' => $this->originalSiteName]);
            }

            $this->siteNameWritten = false;
            $this->originalSiteName = null;
            SiteSettings::clearCache();
        }
    }

    private function cleanUp(): void
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::TEST_KEY]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $del = $db->prepare('DELETE FROM page_sections WHERE page_id = :id');
            $del->execute(['id' => (int) $row['id']]);

            $del = $db->prepare('DELETE FROM pages WHERE id = :id');
            $del->execute(['id' => (int) $row['id']]);
        }

        $del = $db->prepare('DELETE FROM rich_text_sections WHERE page_slug = :key');
        $del->execute(['key' => self::TEST_KEY]);

        $this->pageId = null;
        PageContent::clearCache();
    }

    private function skipUnlessServerReachable(): void
    {
        if ($this->request('/') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    /**
     * @return array{status: int, body: string}|null null when the request could not be made at all
     */
    private function request(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);
        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    private function createTestPage(string $status): void
    {
        $this->pageId = $this->repository->create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_KEY,
            'title' => 'Routing testpagina',
            'status' => $status,
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
    }

    private function attachRichText(string $html): void
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('rich_text', self::TEST_KEY);

        $db = Database::connection();
        $stmt = $db->prepare('UPDATE rich_text_sections SET content_html = :html WHERE id = :id');
        $stmt->execute(['html' => $html, 'id' => $sectionId]);

        (new PageSectionRepository())->create(
            (int) $this->pageId,
            self::TEST_KEY,
            'rich_text',
            $sectionKey,
            $sectionId
        );
    }

    public function testPublishedDynamicPageRenders(): void
    {
        $this->createTestPage(PageContent::STATUS_PUBLISHED);
        $this->attachRichText('<p>Zichtbare testinhoud.</p>');

        $response = $this->request('/' . self::TEST_KEY);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Zichtbare testinhoud.', $response['body']);
    }

    public function testDraftPageDoesNotRenderPublicly(): void
    {
        $this->createTestPage(PageContent::STATUS_DRAFT);
        $this->attachRichText('<p>Geheime conceptinhoud.</p>');

        $response = $this->request('/' . self::TEST_KEY);

        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('Geheime conceptinhoud.', $response['body']);
        $this->assertStringContainsString('Pagina niet gevonden', $response['body']);
    }

    public function testUnknownSlugProducesA404(): void
    {
        $response = $this->request('/deze-pagina-bestaat-echt-niet');

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('Pagina niet gevonden', $response['body']);
    }

    public function testApplicationRoutesTakePrecedenceOverGenericPageRouting(): void
    {
        // Every real root-level route keeps answering from its own template.
        foreach (['/shop.php', '/cart.php', '/checkout.php', '/contact.php', '/cookiebeleid.php'] as $route) {
            $this->assertSame(200, $this->request($route)['status'], "{$route} must keep working");
        }

        // /admin and /api are real directories: Apache handles them (a
        // redirect to the directory / the login screen), the generic page
        // route never sees them.
        foreach (['/admin', '/api'] as $directoryRoute) {
            $this->assertContains(
                $this->request($directoryRoute)['status'],
                [200, 301, 302],
                "{$directoryRoute} must be handled as a real directory, not as a CMS page"
            );
        }

        // Blocked directories stay blocked rather than falling through to
        // the CMS-page rewrite.
        foreach (['/vendor', '/src', '/db'] as $blocked) {
            $this->assertSame(403, $this->request($blocked)['status'], "{$blocked} must stay forbidden");
        }

        // A bare word that matches a root-level <word>.php is skipped by the
        // rewrite entirely, so it can never be answered by pagina.php: /shop
        // is not a URL of this site, /shop.php is. Apache establishes that on
        // its own, and since the Redirect Manager added an ErrorDocument the
        // request ends at 404.php, which renders the site's own not-found
        // document instead of Apache's stock error page. What must be absent
        // is therefore the storefront, not the 404.
        $shop = $this->request('/shop');
        $this->assertSame(404, $shop['status'], '/shop is not one of this site\'s URLs');
        $this->assertStringNotContainsString(
            'data-products-grid',
            $shop['body'],
            'the storefront\'s own sections must never be served at /shop'
        );
    }

    public function testHomepageStillResolvesAtTheSiteRoot(): void
    {
        $response = $this->request('/');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString(self::SITE_NAME, $response['body']);
    }

    public function testSeoTitleAndMetaDescriptionAreRenderedFromThePage(): void
    {
        $this->createTestPage(PageContent::STATUS_PUBLISHED);
        $this->repository->update((int) $this->pageId, [
            'slug' => self::TEST_KEY,
            'title' => 'Routing testpagina',
            'status' => PageContent::STATUS_PUBLISHED,
            'meta_title' => 'Aangepaste SEO-titel voor de test',
            'meta_title_en' => null,
            'meta_description' => 'Een testomschrijving met "aanhalingstekens" & een ampersand.',
            'meta_description_en' => null,
        ]);

        $response = $this->request('/' . self::TEST_KEY);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('<title data-nl="Aangepaste SEO-titel voor de test"', $response['body']);
        // Escaped safely: the raw quote/ampersand must never reach the
        // attribute unencoded.
        $this->assertStringContainsString('&amp; een ampersand', $response['body']);
        $this->assertStringNotContainsString('content="Een testomschrijving met "aanhalingstekens"', $response['body']);
    }

    public function testTitleFallsBackToThePageTitlePlusSiteNameWhenNoSeoTitleIsSet(): void
    {
        $this->createTestPage(PageContent::STATUS_PUBLISHED);

        $response = $this->request('/' . self::TEST_KEY);
        $page = $this->repository->findById((int) $this->pageId);

        $this->assertStringContainsString(
            '<title data-nl="' . htmlspecialchars(PageContent::seoTitle($page, 'nl'), ENT_QUOTES, 'UTF-8') . '"',
            $response['body']
        );
        $this->assertStringContainsString('Routing testpagina — ', $response['body']);
    }

    public function testAPageWithNoMetaDescriptionRendersNoDescriptionTag(): void
    {
        $this->createTestPage(PageContent::STATUS_PUBLISHED);

        $body = $this->request('/' . self::TEST_KEY)['body'];

        $this->assertStringNotContainsString('<meta name="description"', $body);
    }
}
