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
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * What a product page and a collection page actually put in their <head>,
 * asserted against the SERVER RESPONSE rather than a rendered DOM — a
 * crawler and a link-preview bot see exactly this HTML, and product.php's
 * body is still filled in by JavaScript, so only the raw response proves the
 * metadata exists at all.
 *
 * Same HTTP-level arrangement (and the same skip-when-unreachable guard) as
 * tests/Service/CollectionRoutingTest.php: the routing itself lives in
 * .htaccess + Apache and cannot be asserted any other way.
 */
final class ShopSeoRoutingTest extends TestCase
{
    /**
     * The identity these assertions name, written by the test itself rather
     * than read from whatever the test database was copied from. Restored
     * exactly in tearDown(), including a row that did not exist before.
     */
    private const SITE_IDENTITY = [
        'site_name' => 'ZZ Routingtest',
        'canonical_base_url' => 'https://routing-test.example',
    ];

    /** Slug charset .htaccess actually rewrites; `zz-` keeps it obviously fake. */
    private const SLUG_PREFIX = 'zz-seo-collection-';

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
     * The public base URL the server builds canonical links from. The same
     * chain in this process as in the web server it shares a container with:
     * APP_URL when the environment pins one, otherwise the row set above.
     */
    private function canonicalBase(): string
    {
        return AppUrl::base();
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

    /** @param array<string, mixed> $overrides */
    private function createProduct(array $overrides = []): int
    {
        $id = $this->products->create($overrides + [
            'name' => 'ZZ SEO-product',
            'name_en' => null,
            'slug' => 'zz-seo-product-' . bin2hex(random_bytes(6)),
            'description' => '<p>Een <strong>korte</strong> beschrijving.</p>',
            'description_en' => null,
            'price' => 24.50,
            'image_path' => 'assets/images/products/zz-seo.png',
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    private function createCollection(bool $isActive = true): string
    {
        $slug = self::SLUG_PREFIX . bin2hex(random_bytes(4));

        $this->collectionIds[] = $this->collections->create([
            'name' => 'ZZ SEO-collectie',
            'name_en' => null,
            'slug' => $slug,
            'description' => '<p>Beschrijving van de collectie.</p>',
            'description_en' => null,
            'image_path' => null,
            'is_active' => $isActive,
            'meta_title' => 'ZZ eigen collectietitel',
            'meta_description' => 'ZZ eigen collectiebeschrijving.',
        ]);

        return $slug;
    }

    /** Extracts the single JSON-LD block, or null when the page has none. */
    private function jsonLd(string $body): ?array
    {
        if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m) !== 1) {
            return null;
        }

        $decoded = json_decode($m[1], true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'the JSON-LD block must be valid JSON');

        return $decoded;
    }

    // ------------------------------------------------------- product <head>

    public function testAProductPageRendersItsCustomSeoTitleAndDescriptionServerSide(): void
    {
        $id = $this->createProduct([
            'meta_title' => 'ZZ eigen producttitel',
            'meta_description' => 'ZZ eigen productbeschrijving voor Google.',
        ]);

        $response = $this->request('/product.php?id=' . $id);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('<title data-nl="ZZ eigen producttitel"', $response['body']);
        $this->assertStringContainsString('content="ZZ eigen productbeschrijving voor Google."', $response['body']);
    }

    public function testAProductPageWithoutCustomSeoFallsBackToItsOwnContent(): void
    {
        $id = $this->createProduct();

        $response = $this->request('/product.php?id=' . $id);

        $this->assertNotNull($response);
        $this->assertStringContainsString('ZZ SEO-product | Shop — ' . self::SITE_IDENTITY['site_name'], $response['body']);
        // Plain text, never the stored markup.
        $this->assertStringContainsString('content="Een korte beschrijving."', $response['body']);
        $this->assertStringNotContainsString('content="&lt;p&gt;', $response['body']);
    }

    public function testAProductPageCarriesItsOwnCanonicalAndOpenGraphTags(): void
    {
        $id = $this->createProduct();

        $response = $this->request('/product.php?id=' . $id);

        $this->assertNotNull($response);
        $this->assertStringContainsString(
            '<link rel="canonical" href="' . $this->canonicalBase() . '/product.php?id=' . $id . '">',
            $response['body']
        );
        $this->assertStringContainsString('property="og:url" content="' . $this->canonicalBase() . '/product.php?id=' . $id . '"', $response['body']);
        $this->assertStringContainsString('property="og:site_name" content="' . self::SITE_IDENTITY['site_name'] . '"', $response['body']);
        $this->assertStringContainsString('property="og:image" content="' . $this->canonicalBase() . '/assets/images/products/zz-seo.png"', $response['body']);
        $this->assertStringContainsString('property="og:type" content="website"', $response['body']);
    }

    public function testTrackingParametersNeverLeakIntoTheCanonicalUrl(): void
    {
        $id = $this->createProduct();

        $response = $this->request('/product.php?id=' . $id . '&utm_source=nieuwsbrief&fbclid=abc123&ref=x');

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString(
            '<link rel="canonical" href="' . $this->canonicalBase() . '/product.php?id=' . $id . '">',
            $response['body']
        );
        $this->assertStringNotContainsString('utm_source', $response['body']);
        $this->assertStringNotContainsString('fbclid', $response['body']);
    }

    public function testTheCanonicalUrlIsBuiltFromAppUrlAndNotFromTheHostHeader(): void
    {
        $id = $this->createProduct();

        // The request arrives on the test server's own host, yet the canonical,
        // og:url and JSON-LD url must all name the configured public site.
        $response = $this->request('/product.php?id=' . $id);

        $this->assertNotNull($response);
        $this->assertStringNotContainsString(
            'href="' . TestEnvironment::baseUrl() . '/product.php',
            $response['body']
        );
        $this->assertStringContainsString('href="' . $this->canonicalBase() . '/product.php', $response['body']);
        $this->assertSame(
            $this->canonicalBase() . '/product.php?id=' . $id,
            $this->jsonLd($response['body'])['url']
        );
    }

    // -------------------------------------------------------------- JSON-LD

    public function testTheProductJsonLdMatchesTheVisibleProductData(): void
    {
        $id = $this->createProduct(['name' => 'ZZ Onderzetter', 'price' => 24.50]);

        $response = $this->request('/product.php?id=' . $id);
        $this->assertNotNull($response);

        $data = $this->jsonLd($response['body']);

        $this->assertNotNull($data);
        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('Product', $data['@type']);
        $this->assertSame('ZZ Onderzetter', $data['name']);
        $this->assertSame('Een korte beschrijving.', $data['description']);
        $this->assertSame('EUR', $data['offers']['priceCurrency']);
        $this->assertSame('24.50', $data['offers']['price']);
        $this->assertSame('https://schema.org/InStock', $data['offers']['availability']);
        $this->assertSame('https://schema.org/NewCondition', $data['offers']['itemCondition']);
    }

    public function testTheJsonLdPriceIsTheSamePriceTheApiServesTheVisiblePage(): void
    {
        $id = $this->createProduct(['price' => 17.95]);

        $page = $this->request('/product.php?id=' . $id);
        $api = $this->request('/api/product.php?id=' . $id);

        $this->assertNotNull($page);
        $this->assertNotNull($api);

        $visiblePrice = (float) json_decode($api['body'], true)['data']['price'];

        $this->assertSame($visiblePrice, (float) $this->jsonLd($page['body'])['offers']['price']);
    }

    public function testTheJsonLdNeverCarriesReviewsRatingsOrInventedIdentifiers(): void
    {
        $id = $this->createProduct();

        $response = $this->request('/product.php?id=' . $id);
        $this->assertNotNull($response);

        $data = $this->jsonLd($response['body']);

        foreach (['review', 'aggregateRating', 'sku', 'gtin', 'gtin13', 'mpn'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
        foreach (['priceValidUntil', 'inventoryLevel', 'shippingDetails', 'hasMerchantReturnPolicy'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data['offers']);
        }
    }

    public function testQuotesUnicodeAndMarkupInAProductNameSurviveTheRoundTrip(): void
    {
        $id = $this->createProduct([
            'name' => 'ZZ "Groot" & <b>vet</b> — Belvédère',
            'description' => '<p>Met &quot;aanhalingstekens&quot; &amp; méér.</p>',
        ]);

        $response = $this->request('/product.php?id=' . $id);
        $this->assertNotNull($response);

        // The script block is still a single, parseable JSON-LD element...
        $data = $this->jsonLd($response['body']);
        $this->assertSame('ZZ "Groot" & <b>vet</b> — Belvédère', $data['name']);
        $this->assertSame('Met "aanhalingstekens" & méér.', $data['description']);

        // ...and the <title>/description attributes are HTML-escaped, so the
        // same characters cannot break out of an attribute either.
        $this->assertStringContainsString('ZZ &quot;Groot&quot; &amp; &lt;b&gt;vet&lt;/b&gt;', $response['body']);
    }

    // ---------------------------------------------------- hidden / not found

    public function testAnInactiveProductExposesNoSeoMetadataAtAll(): void
    {
        $id = $this->createProduct([
            'name' => 'ZZ Geheim product',
            'active' => false,
            'meta_title' => 'ZZ geheime titel',
            'meta_description' => 'ZZ geheime beschrijving.',
        ]);

        $response = $this->request('/product.php?id=' . $id);

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
        // "noindex,follow" since SEO Foundation V1 — the same directive the
        // whole application now emits from one place
        // (App\Service\SeoDefaults::ROBOTS_NOINDEX). "follow" so a crawler
        // still walks the links back into the working site.
        $this->assertStringContainsString('name="robots" content="noindex,follow"', $response['body']);
        $this->assertNull($this->jsonLd($response['body']), 'a hidden product must publish no structured data');
        $this->assertStringNotContainsString('rel="canonical"', $response['body']);
        $this->assertStringNotContainsString('ZZ geheime titel', $response['body']);
        $this->assertStringNotContainsString('ZZ Geheim product', $response['body']);
    }

    public function testAnUnknownProductIdIs404WithNoStructuredData(): void
    {
        $response = $this->request('/product.php?id=99999999');

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('Product niet gevonden', $response['body']);
        $this->assertNull($this->jsonLd($response['body']));
    }

    // ----------------------------------------------------- collection <head>

    public function testACollectionPageRendersItsCustomSeoValuesAndCanonical(): void
    {
        $slug = $this->createCollection();

        $response = $this->request('/collecties/' . $slug);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('<title data-nl="ZZ eigen collectietitel"', $response['body']);
        $this->assertStringContainsString('content="ZZ eigen collectiebeschrijving."', $response['body']);
        $this->assertStringContainsString(
            '<link rel="canonical" href="' . $this->canonicalBase() . '/collecties/' . $slug . '">',
            $response['body']
        );
        $this->assertStringContainsString('property="og:site_name" content="' . self::SITE_IDENTITY['site_name'] . '"', $response['body']);
    }

    public function testAnInactiveCollectionStillExposesNothing(): void
    {
        $slug = $this->createCollection(false);

        $response = $this->request('/collecties/' . $slug);

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
        // "noindex,follow" since SEO Foundation V1 — the same directive the
        // whole application now emits from one place
        // (App\Service\SeoDefaults::ROBOTS_NOINDEX). "follow" so a crawler
        // still walks the links back into the working site.
        $this->assertStringContainsString('name="robots" content="noindex,follow"', $response['body']);
        $this->assertStringNotContainsString('ZZ eigen collectietitel', $response['body']);
        $this->assertStringNotContainsString('rel="canonical"', $response['body']);
    }

    // -------------------------------------------- existing CMS page SEO kept

    public function testTheHomepageAndCmsPagesKeepTheirExistingSeoBehaviour(): void
    {
        $home = $this->request('/');

        $this->assertNotNull($home);
        $this->assertSame(200, $home['status']);
        $this->assertStringContainsString('<link rel="canonical" href="' . $this->canonicalBase() . '/">', $home['body']);
        $this->assertStringContainsString('<meta name="description"', $home['body']);
        $this->assertStringContainsString('property="og:title"', $home['body']);

        // A CMS page is not a product: it must never publish Product
        // structured data, whatever else it carries. Since SEO Foundation V1
        // the site root does emit an Organization node built purely from
        // SiteSettings (App\Service\PageSeo), so this asserts the type
        // rather than the absence of structured data altogether.
        $homeJsonLd = $this->jsonLd($home['body']);
        $this->assertNotNull($homeJsonLd, 'the site root publishes its Organization node');
        $this->assertSame('Organization', $homeJsonLd['@type'] ?? null);

        $shop = $this->request('/shop.php');
        $this->assertNotNull($shop);
        $this->assertStringContainsString('<link rel="canonical" href="' . $this->canonicalBase() . '/shop.php">', $shop['body']);
    }
}
