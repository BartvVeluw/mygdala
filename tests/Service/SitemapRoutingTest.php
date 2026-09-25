<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AppUrl;
use App\Service\CollectionContent;
use App\Service\ProductSeo;
use App\Service\SiteSettings;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * /sitemap.xml as a crawler actually receives it: the rewrite in .htaccess,
 * the status code, the content type, and — the assertion this whole feature
 * hangs on — that the URL the sitemap submits is byte-for-byte the URL the
 * corresponding page declares canonical.
 *
 * HTTP-level for the same reason tests/Service/CollectionRoutingTest.php is:
 * the `^sitemap\.xml$` rewrite lives in .htaccess + Apache and cannot be
 * asserted any other way. Skips itself when the web server is unreachable.
 */
final class SitemapRoutingTest extends TestCase
{
    /**
     * The canonical base URL the sitemap and the canonical tags are built
     * from, written by the test itself rather than read from whatever the
     * test database was copied from. Restored exactly in tearDown(),
     * including a row that did not exist before.
     */
    private const SITE_IDENTITY = [
        'canonical_base_url' => 'https://routing-test.example',
    ];

    private const SLUG_PREFIX = 'zz-sitemaproute-';

    private ProductRepository $products;
    private CollectionRepository $collections;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<int> */
    private array $collectionIds = [];

    /** @var array<string, string|null> what each identity key held before, null for no row */
    private array $originalIdentity = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        $this->collections = new CollectionRepository();
        ProductSeo::clearCache();
        CollectionContent::clearCache();

        if ($this->request('/') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $stored = (new SiteSettingRepository())->findAll();
        foreach (array_keys(self::SITE_IDENTITY) as $key) {
            $this->originalIdentity[$key] = array_key_exists($key, $stored) ? $stored[$key] : null;
        }

        (new SiteSettingRepository())->upsertMany(self::SITE_IDENTITY);
        SiteSettings::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->originalIdentity as $key => $value) {
            if ($value === null) {
                $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => $key]);
            } else {
                (new SiteSettingRepository())->upsertMany([$key => $value]);
            }
        }
        $this->originalIdentity = [];
        SiteSettings::clearCache();

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }

        $this->productIds = [];
        $this->collectionIds = [];
        ProductSeo::clearCache();
        CollectionContent::clearCache();
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

    private function header(array $response, string $name): ?string
    {
        foreach ($response['headers'] as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    /** The <link rel="canonical"> a page renders, or null when it has none. */
    private function canonicalOf(string $path): ?string
    {
        $response = $this->request($path);
        $this->assertNotNull($response, $path);
        $this->assertSame(200, $response['status'], $path . ' must be reachable');

        if (preg_match('#<link rel="canonical" href="([^"]+)">#', $response['body'], $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    private function createProduct(bool $active = true): int
    {
        $id = $this->products->create([
            'name' => 'ZZ sitemaproute-product',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . 'product-' . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 13.00,
            'image_path' => null,
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        return $id;
    }

    private function createCollection(bool $isActive = true): string
    {
        $slug = self::SLUG_PREFIX . 'collectie-' . bin2hex(random_bytes(4));

        $this->collectionIds[] = $this->collections->create([
            'name' => 'ZZ sitemaproute-collectie',
            'name_en' => null,
            'slug' => $slug,
            'description' => null,
            'description_en' => null,
            'image_path' => null,
            'is_active' => $isActive,
        ]);

        return $slug;
    }

    // ------------------------------------------------------------ the route

    public function testTheSitemapIsServedAsXmlAtTheConventionalUrl(): void
    {
        $response = $this->request('/sitemap.xml');

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);

        $contentType = (string) $this->header($response, 'Content-Type');
        $this->assertStringStartsWith('application/xml', $contentType);
        $this->assertStringContainsString('charset=UTF-8', $contentType);
    }

    public function testTheServedDocumentParsesAndUsesTheSitemapNamespace(): void
    {
        $response = $this->request('/sitemap.xml');
        $this->assertNotNull($response);

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($xml);
        $this->assertSame('urlset', $xml->getName());
        $this->assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $xml->getNamespaces()[''] ?? null);
    }

    public function testTheServedDocumentIsTheOneTheServiceBuilds(): void
    {
        $response = $this->request('/sitemap.xml');
        $this->assertNotNull($response);

        $this->assertSame(Sitemap::xml(), $response['body']);
    }

    public function testNewlyPublishedContentAppearsWithoutAnyRegenerationStep(): void
    {
        $before = $this->request('/sitemap.xml');
        $this->assertNotNull($before);

        $productId = $this->createProduct();
        $collectionSlug = $this->createCollection();

        $after = $this->request('/sitemap.xml');
        $this->assertNotNull($after);

        $this->assertStringNotContainsString(ProductSeo::canonicalUrl($productId), $before['body']);
        $this->assertStringContainsString('<loc>' . ProductSeo::canonicalUrl($productId) . '</loc>', $after['body']);
        $this->assertStringContainsString(
            '<loc>' . CollectionContent::canonicalUrlForSlug($collectionSlug) . '</loc>',
            $after['body']
        );
    }

    public function testDeactivatingAProductRemovesItAgainOnTheNextRequest(): void
    {
        $id = $this->createProduct();

        $this->assertStringContainsString(
            ProductSeo::canonicalUrl($id),
            (string) $this->request('/sitemap.xml')['body']
        );

        $this->products->setActive($id, false);

        $this->assertStringNotContainsString(
            ProductSeo::canonicalUrl($id),
            (string) $this->request('/sitemap.xml')['body']
        );
    }

    // ------------------------------------------ sitemap URL == canonical tag

    public function testEverySitemapUrlForARepresentativePageEqualsThatPagesCanonicalTag(): void
    {
        $productId = $this->createProduct();
        $collectionSlug = $this->createCollection();

        $sitemap = $this->request('/sitemap.xml');
        $this->assertNotNull($sitemap);

        $cases = [
            // A CMS page (the homepage), a product, and a collection.
            // AppUrl in this process walks the same chain as the web server
            // sharing its container: APP_URL when pinned, else the row above.
            '/' => AppUrl::base() . '/',
            '/product.php?id=' . $productId => ProductSeo::canonicalUrl($productId),
            '/collecties/' . $collectionSlug => CollectionContent::canonicalUrlForSlug($collectionSlug),
        ];

        foreach ($cases as $path => $expected) {
            $canonical = $this->canonicalOf($path);

            $this->assertSame($expected, $canonical, $path . ': canonical tag');
            $this->assertStringContainsString(
                '<loc>' . $canonical . '</loc>',
                $sitemap['body'],
                $path . ': the sitemap must submit exactly the canonical URL'
            );
        }
    }

    public function testARepresentativeSitemapUrlActuallyReturns200(): void
    {
        $productId = $this->createProduct();
        $collectionSlug = $this->createCollection();

        // The sitemap names production URLs; request the same paths locally.
        $paths = [
            '/',
            '/shop.php',
            '/product.php?id=' . $productId,
            '/collecties/' . $collectionSlug,
        ];

        foreach ($paths as $path) {
            $response = $this->request($path);
            $this->assertNotNull($response, $path);
            $this->assertSame(200, $response['status'], $path . ' is in the sitemap and must not 404');
        }
    }

    // --------------------------------------------------------- APP_URL, not Host

    public function testTheDocumentNamesTheConfiguredSiteAndNeverTheRequestHost(): void
    {
        $response = $this->request('/sitemap.xml');
        $this->assertNotNull($response);

        // Requested over the test server's own host, yet every URL is the public site.
        $this->assertStringNotContainsString(TestEnvironment::requestHost(), $response['body']);
        $this->assertStringNotContainsString('http://', str_replace('http://www.sitemaps.org', '', $response['body']));
        $this->assertStringContainsString('<loc>' . AppUrl::canonical('/') . '</loc>', $response['body']);
    }

    // -------------------------------------------------------------- robots.txt

    public function testRobotsTxtAdvertisesTheSitemapAtTheCanonicalUrl(): void
    {
        $response = $this->request('/robots.txt');

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Sitemap: ' . AppUrl::canonical(Sitemap::PATH), $response['body']);

        // The pre-existing crawler directives must still be there — robots.txt
        // is not this feature's to rewrite.
        $this->assertStringContainsString('User-agent: *', $response['body']);
        $this->assertStringContainsString('Allow: /', $response['body']);
    }

    // ---------------------------------------------------- existing routes kept

    public function testTheExistingPublicRoutesAreUnaffectedByTheRewrite(): void
    {
        $expected = [
            '/' => 200,
            '/shop.php' => 200,
            '/diensten.php' => 200,
            '/portfolio' => 200,
            '/portfolio.php' => 301,
            '/over-mij.php' => 200,
            '/contact.php' => 200,
            '/cart.php' => 200,
            '/cookiebeleid.php' => 200,
            '/herroeping.php' => 200,
            '/algemene-voorwaarden' => 200,
            '/api/products.php' => 200,
            '/portfolio/skyline-nijmegen' => 200,
        ];

        foreach ($expected as $path => $status) {
            $response = $this->request($path);
            $this->assertNotNull($response, $path);
            $this->assertSame($status, $response['status'], $path . ' must be unchanged');
        }
    }

    public function testTheStaticSitemapFileIsGoneSoOnlyTheGeneratedOneCanBeServed(): void
    {
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2) . '/sitemap.xml',
            'a leftover static sitemap.xml would be served by Apache instead of the generated one'
        );
    }
}
