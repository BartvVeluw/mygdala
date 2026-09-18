<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ProductSeo;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The product page's breadcrumb, which used to be the one on this site that a
 * visitor only got if their browser ran JavaScript.
 *
 * product.php shipped the word "Product" as the last level and
 * assets/js/shop/shop.js replaced it once /api/product.php had answered. So a
 * crawler, a link preview and anybody with scripting off read "Product", and
 * the trail was the only part of the page architecture that lived in the
 * browser.
 *
 * It does not have to. App\Service\ProductSeo already resolves the same
 * `products` row server-side for the <head> — the same row the API returns,
 * matched on the same `active = 1` — so the name is there before the first
 * byte of HTML. These tests are what let the workaround be deleted rather
 * than left in "just in case": they pin that the name is available where the
 * template reads it, and that nothing writes the trail from JavaScript any
 * more.
 */
final class ProductBreadcrumbTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        ShopLocalization::clearCache();
        ProductSeo::clearCache();
    }

    protected function tearDown(): void
    {
        ShopLocalization::clearCache();
        ProductSeo::clearCache();
        SiteLanguageFixture::reset();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Pins one product's name per website language and resolves it. The words
     * live in `product_translations` since Multilingual 2.0 phase 5 wave C,
     * so they are given to the store rather than to the row.
     *
     * @return array<string, mixed>
     */
    private function resolve(int $productId, string $dutch, string $english): array
    {
        ShopLocalization::products()->overrideForTests($productId, [
            'nl' => [ShopLocalization::NAME => $dutch],
            'en' => [ShopLocalization::NAME => $english],
        ]);
        ProductSeo::clearCache();

        return ProductSeo::resolve(['id' => $productId, 'price' => 10.0], [], [10.0]);
    }

    public function testTheResolvedProductCarriesItsNameInBothLanguages(): void
    {
        $resolved = $this->resolve(1, 'Gegraveerde plank', 'Engraved board');

        $this->assertSame('Gegraveerde plank', $resolved['name_nl']);
        $this->assertSame('Engraved board', $resolved['name_en']);
    }

    public function testAProductWithoutAnEnglishNameFallsBackToItsOwn(): void
    {
        // The same rule the trail's own LocalizedValue applies, so the two
        // halves of the breadcrumb can never disagree about which words a
        // visitor sees.
        $resolved = $this->resolve(2, 'Gegraveerde plank', '');

        $this->assertSame('Gegraveerde plank', $resolved['name_en']);
    }

    public function testTheProductPageBuildsItsTrailFromTheResolvedProduct(): void
    {
        $template = (string) file_get_contents(self::root() . '/product.php');

        $this->assertStringContainsString('render_breadcrumb(', $template);
        $this->assertStringContainsString("->toPage('shop', 'shop')", $template);
        $this->assertStringContainsString("\$seo['name_nl']", $template);
        $this->assertStringContainsString("\$seo['name_en']", $template);
        $this->assertStringNotContainsString(
            'data-product-breadcrumb',
            $template,
            'the hook the browser used to fill in is gone; the server fills the trail'
        );
    }

    public function testTheShopScriptNoLongerWritesTheTrail(): void
    {
        $script = (string) file_get_contents(self::root() . '/assets/js/shop/shop.js');

        $this->assertStringNotContainsString('data-product-breadcrumb', $script);
        $this->assertStringNotContainsString('breadcrumbEl', $script);
    }
}
