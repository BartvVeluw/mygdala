<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\PageRepository;
use App\Repository\ProductRepository;
use App\Service\CollectionContent;
use App\Service\PageContent;
use App\Service\ProductSeo;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;

/**
 * What App\Service\Sitemap actually puts in the document: which rows it
 * includes, which it must never include, and how it renders them.
 *
 * The visibility rules are asserted against real rows, because "an inactive
 * product is absent" is a database question and it is the assertion that
 * stops the sitemap from ever advertising a URL that 404s.
 */
final class SitemapTest extends TestCase
{
    /**
     * The base URL this test configures itself, through the first step of
     * App\Service\AppUrl's chain: which domain an installation publishes is
     * site configuration, and asserting the one the container happens to
     * carry would test the environment rather than the sitemap.
     */
    private const CANONICAL_BASE = 'https://www.voorbeeldwinkel.example';
    private const SLUG_PREFIX = 'zz-sitemap-';

    private ProductRepository $products;
    private CollectionRepository $collections;
    private PageRepository $pages;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<int> */
    private array $collectionIds = [];
    /** @var list<int> */
    private array $pageIds = [];

    private ?string $appUrlBefore = null;

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        $this->collections = new CollectionRepository();
        $this->pages = new PageRepository();
        CollectionContent::clearCache();
        PageContent::clearCache();

        $this->appUrlBefore = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = self::CANONICAL_BASE;
    }

    protected function tearDown(): void
    {
        if ($this->appUrlBefore === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->appUrlBefore;
        }

        $db = Database::connection();

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->pageIds as $id) {
            $db->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => $id]);
        }

        $this->productIds = [];
        $this->collectionIds = [];
        $this->pageIds = [];
        CollectionContent::clearCache();
        PageContent::clearCache();
    }

    /** @return list<string> */
    private function locations(): array
    {
        return array_column(Sitemap::entries(), 'loc');
    }

    private function createProduct(bool $active, ?string $updatedAt = null): int
    {
        $id = $this->products->create([
            'name' => 'ZZ sitemapproduct',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . 'product-' . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 11.00,
            'image_path' => null,
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        if ($updatedAt !== null) {
            Database::connection()
                ->prepare('UPDATE products SET updated_at = :updated_at WHERE id = :id')
                ->execute(['updated_at' => $updatedAt, 'id' => $id]);
        }

        return $id;
    }

    private function createCollection(bool $isActive): string
    {
        $slug = self::SLUG_PREFIX . 'collection-' . bin2hex(random_bytes(4));

        $this->collectionIds[] = $this->collections->create([
            'name' => 'ZZ sitemapcollectie',
            'name_en' => null,
            'slug' => $slug,
            'description' => null,
            'description_en' => null,
            'image_path' => null,
            'is_active' => $isActive,
        ]);

        return $slug;
    }

    private function createPage(string $status): string
    {
        $slug = self::SLUG_PREFIX . 'page-' . bin2hex(random_bytes(4));

        $this->pageIds[] = \Tests\Support\PageFixture::create([
            'content_key' => $slug,
            'slug' => $slug,
            'status' => $status,
        ], 'ZZ sitemappagina');

        return $slug;
    }

    // -------------------------------------------------------- what is in it

    public function testTheHomepageAndTheSystemPagesAreListed(): void
    {
        $locations = $this->locations();

        // The system pages every installation with the Shop has. Which other
        // fixed-URL pages a site carries is that site's content.
        foreach (['/', '/shop.php'] as $path) {
            $this->assertContains(self::CANONICAL_BASE . $path, $locations, $path . ' must be in the sitemap');
        }
    }

    public function testAPublishedCmsPageIsListedAndADraftIsNot(): void
    {
        $published = $this->createPage(PageContent::STATUS_PUBLISHED);
        $draft = $this->createPage(PageContent::STATUS_DRAFT);

        $locations = $this->locations();

        $this->assertContains(self::CANONICAL_BASE . '/' . $published, $locations);
        $this->assertNotContains(self::CANONICAL_BASE . '/' . $draft, $locations);
    }

    public function testAnActiveProductIsListedAndAnInactiveOneIsNot(): void
    {
        $visible = $this->createProduct(true);
        $hidden = $this->createProduct(false);

        $locations = $this->locations();

        $this->assertContains(ProductSeo::canonicalUrl($visible), $locations);
        $this->assertNotContains(ProductSeo::canonicalUrl($hidden), $locations);
    }

    public function testAPublishedCollectionIsListedAndAnUnpublishedOneIsNot(): void
    {
        $published = $this->createCollection(true);
        $unpublished = $this->createCollection(false);

        $locations = $this->locations();

        $this->assertContains(CollectionContent::canonicalUrlForSlug($published), $locations);
        $this->assertNotContains(CollectionContent::canonicalUrlForSlug($unpublished), $locations);
    }

    /**
     * A published collection with no products at all still has a real, public
     * page that answers 200 — see CollectionRepository::findActiveForSitemap().
     */
    public function testAnEmptyButPublishedCollectionIsStillListed(): void
    {
        $slug = $this->createCollection(true);

        $this->assertContains(CollectionContent::canonicalUrlForSlug($slug), $this->locations());
    }

    // ----------------------------------------------------- what cannot be in

    public function testNoInternalOrPrivateUrlCanEverAppear(): void
    {
        $document = Sitemap::xml();

        $forbidden = [
            '/admin', '/api/', 'login.php', 'logout.php',
            '/cart.php', '/checkout.php', '/bestelling-status.php',
            '/cookiebeleid.php', '/herroeping.php',
            '/phinx.php', '/vendor/', '/src/', '/db/', '/tests/',
            'invoice-download', 'order-status', 'mollie-webhook',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $document, $needle . ' must never be in the sitemap');
        }
    }

    public function testEveryLocationIsAnAbsoluteUrlOnTheConfiguredSite(): void
    {
        foreach ($this->locations() as $loc) {
            $this->assertStringStartsWith(self::CANONICAL_BASE . '/', $loc, $loc . ' must be absolute');
            $this->assertStringNotContainsString('localhost', $loc);
            $this->assertNotFalse(filter_var($loc, FILTER_VALIDATE_URL), $loc . ' must be a well-formed URL');
        }
    }

    /**
     * The only query string a canonical URL in this project carries is a
     * product's own `?id=` — that IS the product page's URL. Anything else
     * (tracking, filters, sort order) would be a duplicate of a page already
     * listed.
     */
    public function testTheOnlyQueryStringIsAProductsOwnId(): void
    {
        $this->createProduct(true);

        foreach ($this->locations() as $loc) {
            $query = parse_url($loc, PHP_URL_QUERY);
            if ($query === null) {
                continue;
            }

            parse_str($query, $params);
            $this->assertSame(['id'], array_keys($params), $loc . ' may only carry a product id');

            // A product has no slug URL, so the LANGUAGE is the only thing
            // that differs between its versions since Multilingual 2.0
            // phase 6: /product.php?id=N and /en/product.php?id=N.
            $this->assertMatchesRegularExpression(
                '#^' . preg_quote(self::CANONICAL_BASE, '#') . '(/[a-z]{2})?/product\\.php\\?#',
                $loc
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* One <url> per language version (Multilingual 2.0 phase 6)           */
    /* ------------------------------------------------------------------ */

    /**
     * REGRESSION. The first version of the multilingual sitemap listed a
     * collection in the default language ONLY, and nothing failed: the
     * sitemap query selected `slug, updated_at` and no `id`, so the lookup of
     * its addresses per language ran against owner 0 and quietly found none.
     * A site whose English collections never reach a crawler is exactly the
     * kind of loss no visitor ever reports.
     */
    public function testACollectionIsListedInEveryLanguageItHasAnAddressIn(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $slug = $this->createCollection(true);
        $id = (int) end($this->collectionIds);

        \App\Service\ShopLocalization::collections()->save($id, $other, [
            \App\Service\ShopLocalization::SLUG => $slug . '-' . $other,
            \App\Service\ShopLocalization::NAME => 'ZZ sitemap collection',
        ]);
        \App\Service\ShopLocalization::collections()->clearCache();
        CollectionContent::clearCache();

        $default = self::CANONICAL_BASE . \App\Service\CollectionContent::publicPath($slug, $this->defaultLanguage());
        $localized = self::CANONICAL_BASE . \App\Service\CollectionContent::publicPath($slug . '-' . $other, $other);

        $locations = $this->locations();
        $this->assertContains($default, $locations);
        $this->assertContains($localized, $locations, 'the ' . $other . ' version has an address, so it is a URL a crawler must learn');

        // Reciprocal: BOTH entries carry BOTH versions.
        foreach ([$default, $localized] as $loc) {
            $this->assertSame(
                [$this->defaultLanguage() => $default, $other => $localized],
                $this->entryFor($loc)['alternates'],
                $loc . ' names every version of this collection, itself included'
            );
        }
    }

    public function testACollectionWithoutAnAddressInALanguageIsNotListedThere(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $slug = $this->createCollection(true);

        $default = self::CANONICAL_BASE . \App\Service\CollectionContent::publicPath($slug, $this->defaultLanguage());

        $this->assertContains($default, $this->locations());
        $this->assertSame([], $this->entryFor($default)['alternates'], 'one version needs no alternates');

        foreach ($this->locations() as $loc) {
            if (str_contains($loc, $slug)) {
                $this->assertStringNotContainsString('/' . $other . '/', $loc, 'no address in ' . $other . ', so no URL there');
            }
        }
    }

    public function testAPageIsListedOncePerLanguageVersionWithReciprocalAlternates(): void
    {
        $other = $this->otherLanguage();
        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $slug = $this->createPage(PageContent::STATUS_PUBLISHED);
        $id = (int) end($this->pageIds);

        \App\Service\PageLocalization::save($id, $this->defaultLanguage(), [\App\Service\PageTranslation::TITLE => 'ZZ'], $slug);
        \App\Service\PageLocalization::save($id, $other, [\App\Service\PageTranslation::TITLE => 'ZZ'], $slug . '-' . $other);
        \App\Service\PageLocalization::clearCache();
        PageContent::clearCache();

        $default = self::CANONICAL_BASE . '/' . $slug;
        $localized = self::CANONICAL_BASE . '/' . $other . '/' . $slug . '-' . $other;

        foreach ([$default, $localized] as $loc) {
            $this->assertSame(
                [$this->defaultLanguage() => $default, $other => $localized],
                $this->entryFor($loc)['alternates']
            );
        }
    }

    public function testTheXmlDeclaresAlternatesAndXDefaultOnlyWhenThereAreSome(): void
    {
        $plain = Sitemap::toXml([Sitemap::entryFor(self::CANONICAL_BASE . '/een', null)]);
        $this->assertStringNotContainsString('xmlns:xhtml', $plain, 'a single-language sitemap is byte for byte what it was');
        $this->assertStringNotContainsString('hreflang', $plain);

        $versions = Sitemap::entriesForVersions(
            [$this->defaultLanguage() => '/een', 'zz' => '/zz/one'],
            null
        );
        $xml = Sitemap::toXml($versions);

        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);
        $this->assertSame(2, substr_count($xml, '<url>'), 'one <url> per version');
        $this->assertSame(2, substr_count($xml, 'hreflang="x-default"'), 'x-default on every version');
        $this->assertSame(4, substr_count($xml, 'hreflang="' . $this->defaultLanguage() . '"') + substr_count($xml, 'hreflang="zz"'));

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml), 'the document is well-formed XML');
    }

    private function defaultLanguage(): string
    {
        return \App\Service\PageLocalization::defaultLanguage();
    }

    private function otherLanguage(): ?string
    {
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            if ($code !== $this->defaultLanguage()) {
                return $code;
            }
        }

        return null;
    }

    public function testEveryListedUrlIsUniqueSoNoPageIsSubmittedTwice(): void
    {
        $locations = $this->locations();

        $this->assertSame(array_values(array_unique($locations)), $locations);
    }

    // ---------------------------------------------------------------- lastmod

    public function testLastmodComesFromTheRowAndNotFromTheCurrentTime(): void
    {
        $id = $this->createProduct(true, '2024-03-07 09:15:00');

        $entry = $this->entryFor(ProductSeo::canonicalUrl($id));

        $this->assertSame('2024-03-07', $entry['lastmod']);
        $this->assertNotSame(date('Y-m-d'), $entry['lastmod'], 'lastmod must never be the request time');
    }

    public function testEveryLastmodIsAValidW3cDate(): void
    {
        foreach (Sitemap::entries() as $entry) {
            if ($entry['lastmod'] === null) {
                continue;
            }

            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['lastmod']);
            $this->assertNotFalse(\DateTimeImmutable::createFromFormat('!Y-m-d', $entry['lastmod']));
        }
    }

    public function testAnUnknownModificationDateProducesNoLastmodRatherThanAGuess(): void
    {
        $id = $this->createProduct(true);
        Database::connection()
            ->prepare('UPDATE products SET updated_at = NULL WHERE id = :id')
            ->execute(['id' => $id]);

        $entry = $this->entryFor(ProductSeo::canonicalUrl($id));

        $this->assertNull($entry['lastmod']);
        $this->assertStringNotContainsString(
            '<lastmod></lastmod>',
            Sitemap::toXml([$entry]),
            'an unknown date must omit the element, not emit an empty one'
        );
    }

    // ------------------------------------------------------------------ XML

    public function testTheDocumentIsValidXmlWithTheSitemapNamespace(): void
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string(Sitemap::xml());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($xml, 'the sitemap must parse as XML');
        $this->assertSame('urlset', $xml->getName());
        $this->assertSame(
            'http://www.sitemaps.org/schemas/sitemap/0.9',
            $xml->getNamespaces()[''] ?? null
        );
        $this->assertGreaterThan(0, $xml->count());
    }

    public function testNoChangefreqOrPriorityIsInvented(): void
    {
        $document = Sitemap::xml();

        $this->assertStringNotContainsString('<changefreq>', $document);
        $this->assertStringNotContainsString('<priority>', $document);
    }

    /**
     * <loc> values are built from database content (slugs), so the renderer
     * must escape unconditionally rather than rely on today's slug charset.
     */
    public function testValuesAreXmlEscapedRatherThanConcatenatedRaw(): void
    {
        $xml = Sitemap::toXml([
            ['loc' => 'https://example.test/a?x=1&y=2', 'lastmod' => null],
            ['loc' => 'https://example.test/<breek>&"\'', 'lastmod' => '2026-01-02'],
        ]);

        $this->assertStringContainsString('<loc>https://example.test/a?x=1&amp;y=2</loc>', $xml);
        $this->assertStringNotContainsString('<breek>', $xml);
        $this->assertStringContainsString('&lt;breek&gt;', $xml);

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($parsed, 'hostile values must not be able to break the document');
        $this->assertSame('https://example.test/<breek>&"\'', (string) $parsed->url[1]->loc);
    }

    // ----------------------------------------------- sitemap == canonical tag

    public function testASitemapUrlIsExactlyTheCanonicalUrlOfThatContentType(): void
    {
        $productId = $this->createProduct(true);
        $collectionSlug = $this->createCollection(true);
        $pageSlug = $this->createPage(PageContent::STATUS_PUBLISHED);

        $locations = $this->locations();

        // The very same calls partials/shop-seo-head.php and
        // partials/page-head.php render <link rel="canonical"> with.
        $this->assertContains(ProductSeo::canonicalUrl($productId), $locations);
        $this->assertContains(CollectionContent::canonicalUrlForSlug($collectionSlug), $locations);

        $page = PageContent::forSlug($pageSlug);
        $this->assertNotNull($page);
        $this->assertContains(PageContent::canonicalUrl($page), $locations);
    }

    /** @return array{loc:string, lastmod:?string} */
    private function entryFor(string $loc): array
    {
        foreach (Sitemap::entries() as $entry) {
            if ($entry['loc'] === $loc) {
                return $entry;
            }
        }

        $this->fail($loc . ' is not in the sitemap');
    }
}
