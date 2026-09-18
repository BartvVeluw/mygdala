<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ProductSeo;
use App\Service\ShopLocalization;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The product SEO resolution rules: which title, description and social
 * image a product page gets, and what its Product JSON-LD may and may not
 * claim.
 *
 * Everything here goes through ProductSeo::resolve() with the photo list,
 * the variant prices and the site name injected, so the rules are asserted
 * without touching the database — the database-backed halves (which photo a
 * variant product actually contributes, and that an inactive product
 * resolves to nothing at all) are covered by
 * tests/Service/ShopSeoRoutingTest.php against the real page.
 *
 * THE WORDS ARE NOT IN THE ROW. Since Multilingual 2.0 phase 5 wave C a
 * product's name, description and SEO fields live per website language in
 * `product_translations`, so they are pinned through
 * App\Service\Language\EntityTranslations' test seam instead of being handed
 * in as `name`/`name_en` columns. The $overrides argument below still speaks
 * that pair language, because these tests are about the RULES, not about
 * where the words sit.
 */
final class ProductSeoTest extends TestCase
{
    /**
     * The site name every fallback title and the JSON-LD brand ends in.
     * Pinned through the SiteSettings test seam rather than read from
     * storage: these are resolution rules, and they hold on any install —
     * including one whose database is not reachable, which is what the
     * "fast" tier promises (TESTING.md).
     */
    private const SITE = 'Testbedrijf';

    /**
     * And the domain every absolute URL below is built on. Pinned through
     * the same seam and for the same reason: which domain THIS installation
     * uses is not what these rules are about, and asserting a made-up one is
     * also the proof that no Van Veluw literal reaches a product's JSON-LD.
     * APP_URL is taken out of the way because it would win over the setting
     * (App\Service\AppUrl).
     */
    private const BASE_URL = 'https://www.testbedrijf.example';

    private ?string $originalAppUrl = null;

    protected function setUp(): void
    {
        $this->originalAppUrl = $_ENV['APP_URL'] ?? null;
        unset($_ENV['APP_URL']);

        SiteLanguageFixture::useBilingual('nl');
        ShopLocalization::clearCache();
        ProductSeo::clearCache();

        SiteSettings::overrideForTests([
            'site_name' => self::SITE,
            'canonical_base_url' => self::BASE_URL,
        ]);
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
        ShopLocalization::clearCache();
        ProductSeo::clearCache();
        SiteLanguageFixture::reset();

        if ($this->originalAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->originalAppUrl;
        }
    }

    /** The product this file resolves, and the id its canonical URL carries. */
    private const PRODUCT = 42;

    /**
     * The four localized fields, each as the `<field>` / `<field>_en` pair the
     * $overrides argument speaks.
     */
    private const WORD_DEFAULTS = [
        'name' => 'Houten onderzetter',
        'name_en' => 'Wooden coaster',
        'description' => '<p>Van berkenhout.</p>',
        'description_en' => '<p>Made of birch.</p>',
        'meta_title' => null,
        'meta_title_en' => null,
        'meta_description' => null,
        'meta_description_en' => null,
    ];

    /**
     * The language-NEUTRAL half of a `products` row: what really is a column.
     * Word overrides are filtered out here and pinned in the words store by
     * resolve() below.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return array_diff_key($overrides, self::WORD_DEFAULTS) + [
            'id' => self::PRODUCT,
            'price' => '12.50',
            'image_path' => null,
            'og_image_path' => null,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string>         $imagePaths
     * @param list<float>          $variantPrices
     * @return array<string, mixed>
     */
    private function resolve(array $overrides = [], array $imagePaths = [], array $variantPrices = []): array
    {
        $words = array_intersect_key($overrides, self::WORD_DEFAULTS) + self::WORD_DEFAULTS;
        $byLanguage = ['nl' => [], 'en' => []];

        foreach ([
            ShopLocalization::NAME,
            ShopLocalization::DESCRIPTION,
            ShopLocalization::META_TITLE,
            ShopLocalization::META_DESCRIPTION,
        ] as $field) {
            $byLanguage['nl'][$field] = (string) ($words[$field] ?? '');
            $byLanguage['en'][$field] = (string) ($words[$field . '_en'] ?? '');
        }

        ShopLocalization::products()->overrideForTests(self::PRODUCT, $byLanguage);
        ProductSeo::clearCache();

        return ProductSeo::resolve($this->product($overrides), $imagePaths, $variantPrices);
    }

    // ---------------------------------------------------------------- title

    public function testACustomSeoTitleIsUsedVerbatim(): void
    {
        $seo = $this->resolve([
            'meta_title' => 'Onderzetters kopen in Nijmegen',
            'meta_title_en' => 'Buy coasters in Nijmegen',
        ]);

        $this->assertSame('Onderzetters kopen in Nijmegen', $seo['title_nl']);
        $this->assertSame('Buy coasters in Nijmegen', $seo['title_en']);
    }

    public function testTheTitleFallsBackToTheProductNamePlusTheSiteTitleConvention(): void
    {
        $seo = $this->resolve();

        $this->assertSame('Houten onderzetter | Shop — ' . self::SITE, $seo['title_nl']);
        $this->assertSame('Wooden coaster | Shop — ' . self::SITE, $seo['title_en']);
    }

    public function testTheSiteNameInATitleIsWhicheverSiteNameIsConfigured(): void
    {
        // The same product on a different install. The site name is a
        // setting, so the title follows the setting — and this holds
        // whatever a database happens to contain, or whether there is one.
        SiteSettings::overrideForTests(['site_name' => 'Andere Site']);

        $seo = $this->resolve();

        $this->assertSame('Houten onderzetter | Shop — Andere Site', $seo['title_nl']);
        $this->assertSame('Andere Site', $seo['json_ld']['brand']['name']);
    }

    public function testAnEmptyEnglishSeoTitleFallsBackToTheDutchOne(): void
    {
        $seo = $this->resolve(['meta_title' => 'Alleen Nederlands', 'meta_title_en' => '']);

        $this->assertSame('Alleen Nederlands', $seo['title_en']);
    }

    // ---------------------------------------------------------- description

    public function testACustomMetaDescriptionIsUsedVerbatimAndNeverTruncated(): void
    {
        $long = trim(str_repeat('Handgemaakte onderzetters uit Nijmegen. ', 8));

        $seo = $this->resolve(['meta_description' => $long]);

        $this->assertSame($long, $seo['description_nl']);
        $this->assertStringNotContainsString('…', $seo['description_nl']);
    }

    public function testTheDescriptionFallsBackToThePlainTextProductDescription(): void
    {
        $seo = $this->resolve();

        $this->assertSame('Van berkenhout.', $seo['description_nl']);
        $this->assertSame('Made of birch.', $seo['description_en']);
    }

    public function testTheFallbackDescriptionStripsHtmlAndNormalizesWhitespace(): void
    {
        $seo = $this->resolve([
            'description' => "<p>Sterk   &amp;   <strong>mooi</strong>.</p>\n<p>Tweede zin.</p>",
        ]);

        $this->assertSame('Sterk & mooi. Tweede zin.', $seo['description_nl']);
        $this->assertStringNotContainsString('<', $seo['description_nl']);
        $this->assertStringNotContainsString('&amp;', $seo['description_nl']);
    }

    public function testTheFallbackDescriptionIsShortenedOnAWordBoundary(): void
    {
        $seo = $this->resolve(['description' => '<p>' . str_repeat('woord ', 80) . '</p>']);

        $this->assertLessThanOrEqual(161, mb_strlen($seo['description_nl']));
        $this->assertStringEndsWith('…', $seo['description_nl']);
        $this->assertStringNotContainsString('woor…', $seo['description_nl']);
    }

    public function testAProductWithNoDescriptionAndNoCustomTextGetsNoDescriptionAtAll(): void
    {
        $seo = $this->resolve(['description' => null, 'description_en' => null]);

        $this->assertSame('', $seo['description_nl']);
        $this->assertSame('', $seo['description_en']);
    }

    // --------------------------------------------------------- social image

    public function testACustomSocialImageWinsOverTheProductPhoto(): void
    {
        $seo = $this->resolve(
            ['og_image_path' => 'assets/images/products/social.png'],
            ['assets/images/products/photo.png']
        );

        $this->assertSame('assets/images/products/social.png', $seo['og_image_path']);
    }

    public function testTheSocialImageFallsBackToTheFirstProductPhoto(): void
    {
        $seo = $this->resolve([], ['assets/images/products/photo.png', 'assets/images/products/two.png']);

        $this->assertSame('assets/images/products/photo.png', $seo['og_image_path']);
    }

    public function testWithoutAnyPhotoTheSocialImageIsLeftToTheSiteWideDefault(): void
    {
        $seo = $this->resolve();

        $this->assertNull($seo['og_image_path']);
    }

    // ------------------------------------------------------- canonical URLs

    public function testTheCanonicalUrlIsTheProductsOwnPageOnTheConfiguredBaseUrl(): void
    {
        $this->assertSame(
            self::BASE_URL . '/product.php?id=42',
            ProductSeo::canonicalUrl(42)
        );
        $this->assertSame('/product.php?id=42', ProductSeo::publicPath(42));
    }

    public function testTheCanonicalUrlCarriesNothingButTheProductId(): void
    {
        $seo = $this->resolve();

        $query = parse_url($seo['canonical_url'], PHP_URL_QUERY);

        parse_str((string) $query, $params);
        $this->assertSame(['id' => '42'], $params);
    }

    // --------------------------------------------------------------- JSON-LD

    public function testTheJsonLdIsValidJsonAndDescribesAProduct(): void
    {
        $encoded = $this->encode($this->resolve()['json_ld']);
        $decoded = json_decode($encoded, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertIsArray($decoded);
        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertSame('Product', $decoded['@type']);
    }

    public function testTheJsonLdCarriesTheProductsRealNameUrlAndBrand(): void
    {
        $data = $this->resolve()['json_ld'];

        $this->assertSame('Houten onderzetter', $data['name']);
        $this->assertSame(self::BASE_URL . '/product.php?id=42', $data['url']);
        $this->assertSame(['@type' => 'Brand', 'name' => self::SITE], $data['brand']);
    }

    public function testTheJsonLdDescriptionIsTheFullPlainTextProductDescription(): void
    {
        $data = $this->resolve(['description' => '<p>Eerste zin.</p><p>Tweede zin.</p>'])['json_ld'];

        $this->assertSame('Eerste zin. Tweede zin.', $data['description']);
    }

    public function testTheJsonLdImagesAreAbsoluteUrls(): void
    {
        $data = $this->resolve([], ['assets/images/products/photo.png'])['json_ld'];

        $this->assertSame(
            [self::BASE_URL . '/assets/images/products/photo.png'],
            $data['image']
        );
    }

    public function testAProductWithoutPhotosOmitsTheImagePropertyEntirely(): void
    {
        $this->assertArrayNotHasKey('image', $this->resolve()['json_ld']);
    }

    // ---------------------------------------------------------------- offers

    public function testASinglePricedProductGetsOneOfferInEurosAtTheVisiblePrice(): void
    {
        $offers = $this->resolve(['price' => '34.95'])['json_ld']['offers'];

        $this->assertSame('Offer', $offers['@type']);
        $this->assertSame('34.95', $offers['price']);
        $this->assertSame('EUR', $offers['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $offers['availability']);
        $this->assertSame('https://schema.org/NewCondition', $offers['itemCondition']);
        $this->assertSame(self::BASE_URL . '/product.php?id=42', $offers['url']);
    }

    public function testVariantsThatAllShareOnePriceStayASingleOffer(): void
    {
        $offers = $this->resolve(['price' => '35.00'], [], [35.00, 35.00, 35.00])['json_ld']['offers'];

        $this->assertSame('Offer', $offers['@type']);
        $this->assertSame('35.00', $offers['price']);
        $this->assertArrayNotHasKey('lowPrice', $offers);
    }

    public function testGenuinelyDifferentVariantPricesBecomeAnAggregateOffer(): void
    {
        $offers = $this->resolve(['price' => '35.00'], [], [35.00, 42.50, 39.00])['json_ld']['offers'];

        $this->assertSame('AggregateOffer', $offers['@type']);
        $this->assertSame('35.00', $offers['lowPrice']);
        $this->assertSame('42.50', $offers['highPrice']);
        $this->assertSame(3, $offers['offerCount']);
        $this->assertSame('EUR', $offers['priceCurrency']);
        $this->assertArrayNotHasKey('price', $offers);
    }

    public function testAVariantWithoutItsOwnPriceInheritsTheProductPrice(): void
    {
        // Exactly what api/checkout.php does with a NULL variant price, and
        // what assets/js/shop/shop.js shows on the page.
        $prices = ProductSeo::variantPricesFrom([['price' => null], ['price' => '40.00']], 35.00);

        $this->assertSame([35.00, 40.00], $prices);
    }

    // ------------------------------------------------- nothing is fabricated

    public function testNothingIsInventedThatTheApplicationDoesNotKnow(): void
    {
        $data = $this->resolve(['price' => '12.50'], ['assets/images/products/photo.png'])['json_ld'];

        foreach (['sku', 'gtin', 'gtin13', 'mpn', 'review', 'aggregateRating', 'audience'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, $forbidden . ' must never be invented');
        }

        foreach (['priceValidUntil', 'inventoryLevel', 'shippingDetails', 'hasMerchantReturnPolicy'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data['offers'], $forbidden . ' must never be invented');
        }
    }

    // ------------------------------------------------------------- escaping

    public function testQuotesUnicodeAndHtmlInAProductNameCannotBreakTheScriptTag(): void
    {
        $seo = $this->resolve([
            'name' => 'Onderzetter "Groot" & <script>alert(1)</script> — Belvédère 😀',
            'description' => '<p>Met &quot;aanhalingstekens&quot; &amp; </script> erin.</p>',
        ]);

        $encoded = $this->encode($seo['json_ld']);

        // Nothing that could terminate the surrounding <script> element, or
        // open a new tag, survives the encoding.
        $this->assertStringNotContainsString('<', $encoded);
        $this->assertStringNotContainsString('>', $encoded);
        $this->assertStringNotContainsString('</script>', $encoded);

        $decoded = json_decode($encoded, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        // ...while the real characters survive intact for a consumer: the
        // name is stored text and is round-tripped verbatim, "</script>"
        // included.
        $this->assertSame($seo['json_ld']['name'], $decoded['name']);
        $this->assertStringContainsString('Belvédère 😀', $decoded['name']);
        $this->assertStringContainsString('"Groot" & <script>alert(1)</script>', $decoded['name']);

        // The description is derived from sanitized rich text, so markup is
        // stripped there rather than round-tripped — but its entities are
        // decoded back to the real characters a reader sees.
        $this->assertSame('Met "aanhalingstekens" & erin.', $decoded['description']);
    }

    /**
     * The exact flags partials/shop-seo-head.php renders with — the test is
     * worthless if it encodes more safely than the page does.
     *
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
