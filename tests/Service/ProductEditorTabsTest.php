<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The product editor's three tabs (Shop Product & Ordering 2.0,
 * admin/product-form.php, admin/_admin_tabs.php), read from the source:
 *
 *   - Product, SEO and Verzending, and nothing else — no tab per section;
 *   - ONE form spans all three, so Opslaan stores every tab from whichever
 *     is open, and the fallback button sits outside the panels;
 *   - Product holds the product itself, Voorraad, Afbeeldingen, Varianten,
 *     Specificaties and Bestelvelden; SEO holds the title, the description
 *     and the share image together; Verzending holds every shipping field
 *     of the product, and the Product tab none of them;
 *   - a refused save brings forward the tab of its first message, and a
 *     browser check the tab of the field it stopped at
 *     (admin/assets/admin-editor.js).
 *
 * The rendered screen and the switch in a browser are checked by the HTTP
 * tests and the browser acceptance; this pins the structure.
 */
final class ProductEditorTabsTest extends TestCase
{
    public function testThreeTabsAroundOneForm(): void
    {
        $form = self::source();

        self::assertMatchesRegularExpression(
            "/admin_tabs_start\\('product-editor', \\[\\s*'product' => admin_t\\('shop.editor.tab_product'\\),\\s*'seo' => admin_t\\('shop.editor.tab_seo'\\),\\s*'verzending' => admin_t\\('shop.editor.tab_shipping'\\),\\s*\\]/",
            $form
        );

        $start = strpos($form, "admin_tabs_start('product-editor'");
        $end = strpos($form, 'admin_tabs_end();');
        self::assertSame(1, substr_count($form, '<form method="post"'), 'one form');
        $formAt = strpos($form, '<form method="post"');
        $formEnd = strpos($form, '</form>');
        self::assertTrue($start < $formAt && $formEnd < $end, 'the form inside the tab group');

        foreach (['product', 'seo', 'verzending'] as $key) {
            $panel = strpos($form, "admin_tab_panel('" . $key . "')");
            self::assertNotFalse($panel, $key);
            self::assertTrue($formAt < $panel && $panel < $formEnd, $key . ' panel inside the form');
        }

        $fallback = strpos($form, 'data-admin-editor-fallback');
        $lastPanelEndInForm = strrpos(substr($form, 0, $formEnd), 'admin_tab_panel_end();');
        self::assertTrue($lastPanelEndInForm < $fallback && $fallback < $formEnd, 'the fallback button is outside every panel');
    }

    /**
     * Pagina-inhoud (Product & Portfolio Content Pages 1.0): a fourth tab for
     * an existing product, with the block list every page has, OUTSIDE the
     * product form — every block is saved in its own editor, and the list's
     * own buttons are forms of their own, which cannot sit inside another.
     * Only for an editor who may edit blocks at all.
     */
    public function testPaginaInhoudIsAFourthTabOutsideTheProductForm(): void
    {
        $form = self::source();

        self::assertStringContainsString("] + (\$hasContentTab ? ['inhoud' => admin_t('content_blocks.tab')] : [])", $form);
        self::assertStringContainsString('$hasContentTab = $isEdit && AdminAuth::can(\App\Service\AdminPermissions::PAGES_MANAGE);', $form);

        $formEnd = strpos($form, '</form>');
        $panel = strpos($form, "admin_tab_panel('inhoud')");
        self::assertNotFalse($panel);
        self::assertGreaterThan($formEnd, $panel, 'the block list is not part of the product form');
        self::assertStringContainsString('content_blocks_owner_panel($productKind', $form);
        self::assertStringContainsString('content_blocks_owner_modals($productKind', $form);
    }

    public function testEachTabHoldsItsSections(): void
    {
        $product = self::panel('product');
        foreach (['product', 'inventory', 'images', 'variants', 'specifications', 'order_fields'] as $section) {
            self::assertStringContainsString('data-admin-editor-section="' . $section . '"', $product, $section);
        }
        foreach (['shipping_profile', 'shipping_weight_grams', 'requires_parcel', 'meta_title'] as $field) {
            self::assertStringNotContainsString('name="' . $field . '"', $product, $field . ' is not on the Product tab');
        }

        $seo = self::panel('seo');
        self::assertStringContainsString('data-admin-editor-section="seo"', $seo);
        self::assertStringContainsString('name="meta_title"', $seo);
        self::assertStringContainsString('name="meta_description"', $seo);
        self::assertStringContainsString("media_picker_field('og_media_id'", $seo, 'the share image belongs with the SEO');

        $shipping = self::panel('verzending');
        self::assertStringContainsString('data-admin-editor-section="shipping"', $shipping);
        foreach (['shipping_profile', 'shipping_weight_grams', 'requires_parcel'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $shipping, $field);
        }
    }

    public function testARefusedSaveOpensTheTabOfItsFirstMessage(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/admin-editor.js');

        self::assertStringContainsString('window.AdminTabs.reveal(node);', $script);
        self::assertMatchesRegularExpression('/if \(!firstPlaced\) firstPlaced = field;/', $script);
        self::assertMatchesRegularExpression('/\}\);\s*revealTab\(firstPlaced\);/', $script, 'once, after every message is placed');
        self::assertStringContainsString("openSection(invalid[0]);\n    revealTab(invalid[0]);", str_replace("\r\n", "\n", $script));
    }

    private static function source(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/admin/product-form.php'));
    }

    private static function panel(string $key): string
    {
        $form = self::source();
        $start = strpos($form, "admin_tab_panel('" . $key . "')");
        $end = strpos($form, 'admin_tab_panel_end();', (int) $start);

        return substr($form, (int) $start, (int) $end - (int) $start);
    }
}
