<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Service\AppUrl;
use App\Service\PageContent;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * The SEO layer as a crawler actually receives it: real requests to the test
 * web server, and the two things that can only be asserted there — the
 * .htaccess rewrite that serves the generated /robots.txt, and the fact that
 * every route really does emit the head its resolver decided on.
 *
 * The indexability half is what this file exists for. A CMS page marked
 * noindex must say so in its own <head> AND disappear from the sitemap, and
 * the transactional shop routes must never be indexable at all — before SEO
 * Foundation V1, /cart.php and /checkout.php carried a canonical tag and no
 * robots tag whatsoever.
 *
 * Skips itself when the web server is unreachable, like every other test in
 * the http tier.
 */
final class SeoRoutingTest extends TestCase
{
    /**
     * Made of the characters a real slug is (`[a-z0-9-]`): .htaccess only
     * rewrites that charset, so an underscore key would 404 in Apache before
     * pagina.php ever saw it.
     */
    private const TEST_KEY = 'zz-test-seo-indexability';

    private PageRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new PageRepository();

        if ($this->request('/') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $this->removeTestPage();
    }

    protected function tearDown(): void
    {
        $this->removeTestPage();
    }

    // ------------------------------------------------------------ robots.txt

    public function testRobotsTxtIsServedByTheRewriteAsPlainText(): void
    {
        $response = $this->request('/robots.txt');

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('text/plain', (string) $this->header($response, 'Content-Type'));
        $this->assertStringContainsString('User-agent: *', $response['body']);
    }

    public function testRobotsTxtAdvertisesTheSitemapAtTheConfiguredUrl(): void
    {
        $response = $this->request('/robots.txt');

        $this->assertNotNull($response);
        $this->assertStringContainsString('Sitemap: ' . AppUrl::canonical(Sitemap::PATH), $response['body']);

        // Requested over the test server's own host, yet the URL it hands a
        // crawler is the configured public one — never the request's Host
        // header, and never a domain typed into a file.
        $this->assertStringNotContainsString(TestEnvironment::requestHost(), $response['body']);
    }

    // --------------------------------------------------- a noindex CMS page

    public function testANoindexPageSaysSoInItsHeadAndLeavesTheSitemap(): void
    {
        $id = $this->createTestPage(noindex: true);

        $response = $this->request('/' . self::TEST_KEY);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status'], 'a noindex page stays perfectly reachable');
        $this->assertStringContainsString('name="robots" content="noindex,follow"', $response['body']);

        $sitemap = $this->request('/sitemap.xml');
        $this->assertNotNull($sitemap);
        $this->assertStringNotContainsString(
            '<loc>' . AppUrl::canonical(self::TEST_KEY) . '</loc>',
            $sitemap['body'],
            'the sitemap must not submit a URL the page itself refuses to be indexed under'
        );

        // The same page without the flag is both indexable and listed, so
        // the assertion above is about the flag and not about the fixture.
        $this->repository->update($id, $this->updateData(noindex: false));
        PageContent::clearCache();

        $response = $this->request('/' . self::TEST_KEY);
        $this->assertNotNull($response);
        $this->assertStringContainsString('name="robots" content="index,follow"', $response['body']);

        $sitemap = $this->request('/sitemap.xml');
        $this->assertNotNull($sitemap);
        $this->assertStringContainsString('<loc>' . AppUrl::canonical(self::TEST_KEY) . '</loc>', $sitemap['body']);
    }

    public function testAnUnpublishedPageIsNeitherReachableNorListed(): void
    {
        $this->createTestPage(noindex: false, status: 'draft');

        $response = $this->request('/' . self::TEST_KEY);
        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);

        $sitemap = $this->request('/sitemap.xml');
        $this->assertNotNull($sitemap);
        $this->assertStringNotContainsString(AppUrl::canonical(self::TEST_KEY), $sitemap['body']);
    }

    // ------------------------------------------- private and system routes

    public function testTheTransactionalShopRoutesAreNotIndexable(): void
    {
        foreach (['/cart.php', '/checkout.php', '/bestelling-status.php'] as $path) {
            $response = $this->request($path);

            $this->assertNotNull($response, $path);
            $this->assertSame(200, $response['status'], $path);
            $this->assertStringContainsString(
                'name="robots" content="noindex,follow"',
                $response['body'],
                $path . ' must never be indexable'
            );
        }
    }

    public function testTheLegalRoutesAreNotIndexableAndCanonicalToTheConfiguredDomain(): void
    {
        foreach (['/cookiebeleid.php', '/herroeping.php'] as $path) {
            $response = $this->request($path);

            $this->assertNotNull($response, $path);
            $this->assertStringContainsString('name="robots" content="noindex,follow"', $response['body'], $path);
            $this->assertStringContainsString(
                '<link rel="canonical" href="' . AppUrl::canonical(ltrim($path, '/')) . '">',
                $response['body'],
                $path . ' must canonical to the configured base URL, not to a literal domain'
            );
        }
    }

    public function testAnUnknownUrlIsNoindexAndClaimsNoCanonical(): void
    {
        $response = $this->request('/zz-deze-pagina-bestaat-niet');

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('name="robots" content="noindex,follow"', $response['body']);
        $this->assertStringNotContainsString('rel="canonical"', $response['body']);
        $this->assertStringNotContainsString('property="og:', $response['body']);
    }

    // ------------------------------------------------- the indexable pages

    public function testThePublicPagesCarryTheirFullHead(): void
    {
        $response = $this->request('/');

        $this->assertNotNull($response);
        $this->assertStringContainsString('name="robots" content="index,follow"', $response['body']);
        $this->assertStringContainsString('<link rel="canonical" href="' . AppUrl::canonical('/') . '">', $response['body']);
        $this->assertStringContainsString('property="og:url" content="' . AppUrl::canonical('/') . '"', $response['body']);
        $this->assertStringContainsString('name="twitter:card"', $response['body']);
    }

    // ------------------------------------------------------------- helpers

    private function updateData(bool $noindex, string $status = 'published'): array
    {
        return [
            'slug' => self::TEST_KEY,
            'status' => $status,
            'noindex' => $noindex,
        ];
    }

    private function createTestPage(bool $noindex, string $status = 'published'): int
    {
        $id = \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_KEY,
            'status' => $status,
        ], 'SEO testpagina');

        $this->repository->update($id, $this->updateData($noindex, $status));
        PageContent::clearCache();

        return $id;
    }

    private function removeTestPage(): void
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::TEST_KEY]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $db->prepare('DELETE FROM page_sections WHERE page_id = :id')->execute(['id' => (int) $row['id']]);
            $db->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => (int) $row['id']]);
        }

        PageContent::clearCache();
    }

    /**
     * @return array{status: int, body: string, headers: list<string>}|null
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

        $headers = $http_response_header ?? [];
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body, 'headers' => $headers];
    }

    /** @param array{headers: list<string>} $response */
    private function header(array $response, string $name): ?string
    {
        foreach ($response['headers'] as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
