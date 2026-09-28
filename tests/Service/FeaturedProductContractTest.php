<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\FeaturedProductContent;
use PHPUnit\Framework\TestCase;

/**
 * The source contracts behind Uitgelicht product (featured_product): ONE
 * implementation of a product, shared by the product page and the block.
 * Reads files only — no database, no server.
 *
 *   - assets/js/shop/shop.js runs the product code once per
 *     [data-product-detail] element and looks inside that element only; a
 *     block's product comes from its own payload, the page's from the API;
 *     "Alleen hoofdafbeelding" is one picture; a block never reaches for the
 *     product page's configurator;
 *   - the payload has one builder (App\Service\ProductDetail), which
 *     api/product.php only echoes;
 *   - the purchase decision has one place (App\Service\ProductPurchasePath),
 *     and the purchase area's markup one partial, which product.php uses too;
 *   - the block's partial adds no structured data and no markup of a
 *     product part of its own;
 *   - featured-product.css: a rule for every word of the closed lists, one
 *     column on a narrow screen, nothing that widens the page; a sold-out
 *     unit's add row is hidden on every page (shop.css);
 *   - the editor and the endpoint are the Shop's (ModuleGuard first), with
 *     the four guards after it, and the editor has no free product id.
 */
final class FeaturedProductContractTest extends TestCase
{
    public function testShopJsRunsTheProductCodePerElementAndLooksInsideItOnly(): void
    {
        $script = self::source('assets/js/shop/shop.js');
        $detail = self::between($script, '  function initProductDetail(root) {', '     Dutch (BAG/PDOK) address lookup');

        self::assertStringContainsString('document.querySelectorAll("[data-product-detail]"), initProductDetail);', $script);
        self::assertStringContainsString('initProductDetails();', $script);
        self::assertStringNotContainsString('document.querySelector(', $detail, 'one product never reaches into another');
        self::assertStringContainsString('root.querySelector("script[data-product-payload]")', $detail);
        self::assertStringContainsString('JSON.parse(payloadEl.textContent', $detail);
        self::assertStringContainsString('fetch(S.apiUrl("/api/product.php?id="', $detail, 'the product page still asks the API');
        self::assertStringContainsString('return inBlock ? null : (window.VVLPersonalization || null);', $detail, 'no configurator in a block');
        self::assertStringContainsString('mainOnly ? images.slice(0, 1) : images', $detail);
        self::assertStringContainsString("S.escapeAttr(idPrefix) + 'variant-option-'", $detail, 'the variant ids carry the element\'s prefix');
        self::assertStringContainsString('S.checkCart(', $detail, 'the server decides before anything is added');
        self::assertStringContainsString('/api/stock-notification.php', $detail);
    }

    /**
     * A block with "Prijs tonen" off carries no price, so the cart line gets
     * its price from the server when a visitor adds — the product page's own
     * endpoint, after api/cart-check.php said yes — and never adds a line
     * without one. No second cart: the same cartAdd() either way.
     */
    public function testTheCartLineTakesItsPriceFromTheServerWhenThePageHasNone(): void
    {
        $detail = self::between(self::source('assets/js/shop/shop.js'), '  function initProductDetail(root) {', '     Dutch (BAG/PDOK) address lookup');

        self::assertStringContainsString('return linePrice(cartProduct.variant_id).then(function (price) {', $detail);
        self::assertStringContainsString('if (own != null) return Promise.resolve(own);', $detail, 'a page with a price keeps it');
        self::assertStringContainsString('fetch(S.apiUrl("/api/product.php?id=" + encodeURIComponent(product.id)))', $detail);
        self::assertMatchesRegularExpression('/if \(price == null\) \{\s*showAddMessage\(S\.text\("notify_failed"\)\);\s*return;/', $detail, 'never a line without a price');
        self::assertStringContainsString('cartProduct.price = price;', $detail);
        self::assertSame(1, substr_count($detail, 'S.cartAdd('), 'one way into the cart');
        self::assertLessThan(strpos($detail, 'return linePrice('), strpos($detail, 'S.checkCart('), 'the server says yes first');

        $content = self::source('src/Service/FeaturedProductContent.php');
        self::assertStringContainsString("if (!\$settings['show_price']) {\n            \$payload = ProductDetail::withoutPrices(\$payload);", $content, 'price off: no price in the payload, selling or not');
    }

    public function testThePayloadHasOneBuilder(): void
    {
        $api = self::source('api/product.php');

        self::assertStringContainsString('ProductDetail::forPublic($id, $language)', $api);
        foreach (['findActiveById', 'Inventory', 'PurchaseMode', 'ProductImageRepository', 'ProductVariantRepository'] as $moved) {
            self::assertStringNotContainsString($moved, self::withoutComments($api), 'api/product.php only echoes the payload: ' . $moved);
        }
        self::assertStringContainsString('ProductDetail::forPublic($productId, $language)', self::source('src/Service/FeaturedProductContent.php'));
    }

    public function testThePurchaseDecisionAndItsMarkupLiveInOnePlace(): void
    {
        $page = self::source('product.php');
        $partial = self::source('partials/product-purchase.php');
        $block = self::source('partials/section-featured-product.php');

        self::assertStringContainsString('ProductPurchasePath::forProduct($productId)', $page);
        self::assertStringContainsString('ProductPurchasePath::forProduct($productId)', self::source('src/Service/FeaturedProductContent.php'));
        self::assertStringContainsString("require_once __DIR__ . '/partials/product-purchase.php';", $page);
        self::assertStringContainsString('render_product_add_row($productOrderQuestions ?? []);', $page);

        foreach (['Prijs en bestellen op aanvraag', 'Toevoegen aan winkelwagen', 'Mail mij als dit weer beschikbaar is', 'Dit product is op dit moment niet te bestellen.'] as $words) {
            self::assertStringContainsString($words, $partial, $words);
            self::assertStringNotContainsString($words, self::withoutComments($page), 'product.php draws it through the partial: ' . $words);
            self::assertStringNotContainsString($words, self::withoutComments($block), 'the block draws it through the partial: ' . $words);
        }

        foreach (['render_product_inquiry()', 'render_product_unorderable()', 'render_product_personalize_cue(', 'render_product_add_row(', 'render_product_sold_out('] as $call) {
            self::assertStringContainsString($call, $block, $call);
        }
    }

    public function testTheBlockAddsNoStructuredDataAndPrintsItsPayloadSafely(): void
    {
        $block = self::source('partials/section-featured-product.php');

        self::assertStringNotContainsString('ld+json', $block);
        self::assertStringNotContainsString('itemprop', $block);
        self::assertStringContainsString('JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT', $block);
        self::assertStringContainsString('<script type="application/json" data-product-payload>', $block);
    }

    public function testTheStylesheetHasARuleForEveryWordAndNeverWidensThePage(): void
    {
        $css = self::source('assets/css/shop/featured-product.css');

        foreach (FeaturedProductContent::IMAGE_POSITIONS as $position) {
            if ($position !== FeaturedProductContent::DEFAULT_IMAGE_POSITION) {
                self::assertStringContainsString('.featured-product--image-' . $position, $css);
            }
        }
        foreach (FeaturedProductContent::IMAGE_SIZES as $size) {
            if ($size !== FeaturedProductContent::DEFAULT_IMAGE_SIZE) {
                self::assertStringContainsString('.featured-product--image-' . $size, $css);
            }
        }
        foreach (FeaturedProductContent::ALIGNMENTS as $align) {
            if ($align !== FeaturedProductContent::DEFAULT_ALIGNMENT) {
                self::assertStringContainsString('.featured-product--align-' . $align, $css);
            }
        }

        self::assertMatchesRegularExpression('/@media \(max-width: 900px\)\{[^@]*grid-template-columns: minmax\(0, 1fr\);/s', $css, 'one column on a narrow screen, as on the product page');
        self::assertStringNotContainsString('100vw', $css);
        self::assertDoesNotMatchRegularExpression('/grid-template-columns:[^;]*\b\d+px/', $css, 'no fixed column width');
        self::assertStringNotContainsString('.product-detail__main-img', $css, 'the gallery is styled once, in shop.css');

        self::assertStringContainsString('.product-detail__add-row[hidden]{ display: none; }', self::source('assets/css/shop/shop.css'), 'a sold-out unit shows no quantity and no button, on the product page and in the block');
    }

    public function testTheEditorAndTheEndpointAreTheShopsWithTheFourGuardsAfter(): void
    {
        $editor = self::source('admin/featured-product.php');
        $endpoint = self::source('api/admin/update-featured-product.php');

        self::assertLessThan(strpos($editor, 'AdminAuth::requireLogin();'), strpos($editor, "ModuleGuard::requireAdmin('shop');"));
        self::assertLessThan(strpos($editor, "AdminAuth::requirePermission('pages.manage');"), strpos($editor, 'AdminAuth::requireLogin();'));

        $order = [
            "ModuleGuard::requireApi('shop');",
            'AdminAuth::requireLoginForApi();',
            "AdminAuth::requirePermissionForApi('pages.manage');",
            "\$_SERVER['REQUEST_METHOD'] !== 'POST'",
            'Csrf::validate(',
            '$repository->findBySlugAndKey(',
            '->update($sectionId, $settings)',
        ];
        $last = -1;
        foreach ($order as $step) {
            $at = strpos($endpoint, $step);
            self::assertNotFalse($at, $step);
            self::assertGreaterThan($last, $at, $step . ' comes after the step before it');
            $last = $at;
        }

        self::assertDoesNotMatchRegularExpression('/type="(?:text|number)"[^>]*name="product_id"/', $editor, 'no free product id');
        self::assertStringContainsString('BlockLocalization::fields(FeaturedProductContent::TABLE)', $endpoint, 'the words are the block\'s declared fields, never a name from the request');
    }

    private static function source(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/' . $path;
        self::assertFileExists($file);

        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    private static function between(string $source, string $start, string $end): string
    {
        $from = strpos($source, $start);
        self::assertNotFalse($from, $start);
        $to = strpos($source, $end, $from);
        self::assertNotFalse($to, $end);

        return substr($source, $from, $to - $from);
    }

    private static function withoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
