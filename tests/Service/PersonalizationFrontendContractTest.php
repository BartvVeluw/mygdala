<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guards for the parts of this feature that live in
 * the browser and in server-rendered templates — the same technique as
 * tests/Service/RelatedProductsProductPageTest.php's architectural half, used
 * here because the project has no JavaScript test runner and these are
 * exactly the promises that would rot silently.
 *
 * Each test names the promise it protects rather than the code that happens
 * to satisfy it today.
 */
final class PersonalizationFrontendContractTest extends TestCase
{
    private static function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function mainJs(): string
    {
        // Step 4 split main.js by owner; the cart state and the product
        // page behaviour this contract is about are the Shop's two files.
        return self::sourceOf('assets/js/shop/cart.js')
            . "\n" . self::sourceOf('assets/js/shop/shop.js');
    }

    private static function editorJs(): string
    {
        return self::sourceOf('assets/js/personalization.js');
    }

    /* ------------------------------------------------------------------ */
    /* Cart line identity                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * The regression this feature could most plausibly cause: an
     * unpersonalized cart line's key must stay byte-for-byte what it always
     * was, so a cart saved before personalization existed keeps working and
     * two ordinary products still merge into one line.
     */
    public function testAnUnpersonalizedCartLineKeepsItsOriginalKey(): void
    {
        $this->assertStringContainsString(
            'return String(item.id) + "::" + (item.variant_id != null ? String(item.variant_id) : "");',
            self::mainJs(),
            'the legacy product+variant key must remain the key for a line without personalization'
        );
    }

    public function testAPersonalizedCartLineIsIdentifiedByItsOwnLineId(): void
    {
        $source = self::mainJs();

        $this->assertStringContainsString('if (item.line_id) return "L:" + String(item.line_id);', $source);
        $this->assertStringContainsString('line.line_id = newCartLineId();', $source);
    }

    /**
     * "Bart" and "Inge" must never collapse into one line of two, and neither
     * must two lines that differ in any single zone, font, upload or
     * position. Matching compares the WHOLE personalization rather than a
     * hash, so this is a property of the data and not of a lucky hash.
     */
    public function testTwoLinesOnlyMergeWhenTheirWholePersonalizationIsIdentical(): void
    {
        $source = self::mainJs();

        $this->assertStringContainsString('function samePersonalization(a, b)', $source);
        $this->assertStringContainsString('JSON.stringify(left) === JSON.stringify(right)', $source);
        $this->assertStringContainsString(
            'samePersonalization(candidate, { personalization: personalization })',
            $source,
            'cartAdd() must compare personalization before adding to an existing line'
        );
    }

    /**
     * The editor emits its zones in a fixed order, so two identical
     * personalizations serialise identically and the comparison above can
     * recognise them.
     */
    public function testTheEditorEmitsZonesInAStableOrder(): void
    {
        $source = self::editorJs();

        $this->assertStringContainsString('var zoneOrder = {};', $source);
        $this->assertStringContainsString(
            'used.sort(function (a, b) { return zoneOrder[a] - zoneOrder[b]; });',
            $source,
            'the editor must emit zones in the configured order, exactly like the server does'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Checkout payload                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The checkout request may say WHAT was ordered, never what it costs.
     * Adding zones, fonts and surcharges must not have widened that surface.
     */
    public function testTheCheckoutPayloadSendsIdentifiersAndPersonalizationOnly(): void
    {
        $source = self::mainJs();

        $start = strpos($source, 'var line = { id: item.id, qty: item.qty, variant_id: item.variant_id || null };');
        $end = strpos($source, 'return line;', $start === false ? 0 : $start);

        $this->assertNotFalse($start, 'the checkout items mapping must be present');
        $this->assertNotFalse($end);

        $mapping = substr($source, $start, $end - $start);

        $this->assertStringContainsString('zone_key', $mapping);
        $this->assertStringContainsString('upload_token', $mapping);
        $this->assertStringContainsString('font', $mapping);

        // Everything the server derives for itself must NOT travel.
        foreach (['price', 'surcharge', 'label', 'view_key', 'image_path', 'upload_preview_url'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $mapping,
                "the checkout payload must not carry '{$forbidden}' — the server resolves it"
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Pricing in the browser is display only                              */
    /* ------------------------------------------------------------------ */

    public function testTheCartTotalsMoneyInWholeCents(): void
    {
        $source = self::mainJs();

        $this->assertStringContainsString('function toCents(value)', $source);
        $this->assertStringContainsString('function cartUnitCents(item)', $source);
        $this->assertStringContainsString('function cartSubtotalCents(items)', $source);
        // The old float multiplication must be gone from every price display.
        $this->assertStringNotContainsString('item.price * item.qty', $source);
    }

    public function testTheEditorNeverResolvesAPriceItself(): void
    {
        $source = self::editorJs();

        // It is TOLD the base price by main.js, which got it from the API.
        $this->assertStringContainsString('setBasePrice: function (price)', $source);
        $this->assertStringContainsString('basePriceCents = centsFrom(price);', $source);
        // And surcharges only ever come out of the server-rendered config.
        $this->assertStringContainsString('zonesByKey[key].surcharge_cents', $source);
    }

    public function testTheProductPageHandsTheCurrentVariantPriceToTheEditor(): void
    {
        $source = self::mainJs();

        $this->assertStringContainsString(
            'window.VVLPersonalization.setBasePrice(effectivePrice)',
            $source,
            'the panel must follow the selected variant price'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Cart display                                                        */
    /* ------------------------------------------------------------------ */

    public function testEveryCartSurfaceUsesTheOnePersonalizationRenderer(): void
    {
        $source = self::mainJs();

        $this->assertStringContainsString('function cartPersonalizationHtml(item, modifier)', $source);
        $this->assertSame(
            3,
            substr_count($source, 'cartPersonalizationHtml(item, "'),
            'the header dropdown, the cart page and the checkout summary must all show it, through one renderer'
        );
        $this->assertStringContainsString('<img class="cart-personalization__thumb"', $source);
    }

    /**
     * A cart line saved before zones existed stored one flat zone. It must
     * still render — and still check out — rather than disappearing.
     */
    public function testTheCartStillReadsAPhase1CartLine(): void
    {
        $source = self::mainJs();

        $this->assertStringContainsString('function cartPersonalizationZones(item)', $source);
        $this->assertStringContainsString('if (Array.isArray(personalization.zones)) return personalization.zones;', $source);
        $this->assertStringContainsString('if (personalization.text || personalization.upload_token) {', $source);
    }

    public function testTheCartEscapesTheCustomersOwnText(): void
    {
        $source = self::mainJs();

        $rendererPos = strpos($source, 'function cartPersonalizationHtml(item, modifier)');
        $this->assertNotFalse($rendererPos);

        $renderer = substr($source, $rendererPos, 2200);

        $this->assertStringContainsString('escapeHtml(zone.text)', $renderer);
        $this->assertStringContainsString('escapeHtml(zone.upload_name)', $renderer);
        $this->assertStringContainsString('escapeHtml(zone.font_label)', $renderer);
        $this->assertStringNotContainsString('+ zone.text +', $renderer);
    }

    /**
     * The cart shows the administrator's customer-facing labels, never an
     * internal zone or view key.
     */
    public function testTheCartShowsLabelsNotInternalKeys(): void
    {
        $source = self::mainJs();

        $this->assertStringContainsString('function zoneDisplayLabel(zone)', $source);

        $rendererPos = strpos($source, 'function cartPersonalizationHtml(item, modifier)');
        $renderer = substr($source, $rendererPos, 2200);
        $this->assertStringNotContainsString('zone.zone_key', $renderer);
        $this->assertStringNotContainsString('zone.view_key', $renderer);
    }

    public function testTheCartNeverRendersAStoragePath(): void
    {
        $source = self::mainJs();

        $this->assertStringNotContainsString('stored_filename', $source);
        $this->assertStringNotContainsString('preview_filename', $source);
        $this->assertStringNotContainsString('/storage/', $source);
    }

    /* ------------------------------------------------------------------ */
    /* Module boundaries                                                   */
    /* ------------------------------------------------------------------ */

    public function testMainJsTreatsThePersonalizationModuleAsOptional(): void
    {
        $source = self::mainJs();

        $this->assertStringContainsString('var personalizer = window.VVLPersonalization || null;', $source);
        $this->assertStringContainsString('var personalization = personalizer ? personalizer.getState() : null;', $source);
        $this->assertStringContainsString('if (window.VVLPersonalization && window.VVLPersonalization.setBasePrice)', $source);
    }

    public function testTheProductPageOnlyLoadsTheEditorWhenTheProductOffersPersonalization(): void
    {
        $source = self::sourceOf('product.php');

        // Since step 4 the page asks App\Service\PageAssets for the editor
        // instead of hand-writing its <script> tag, but it is the same
        // condition and still the only way the file reaches a browser.
        $matched = preg_match(
            '/if \(\$personalization !== null\) \{(.*?)\r?\n\}/s',
            $source,
            $matches
        );

        $this->assertSame(1, $matched, 'the asset request must sit behind the personalization check');
        $this->assertStringContainsString('assets/js/personalization.js', $matches[1]);
        $this->assertStringContainsString('assets/css/shop/personalization.css', $matches[1]);
        $this->assertSame(
            1,
            substr_count($source, 'assets/js/personalization.js'),
            'the editor must be asked for in exactly one place'
        );
    }

    public function testTheProductPageResolvesPersonalizationServerSide(): void
    {
        $source = self::sourceOf('product.php');

        $this->assertStringContainsString('ProductPersonalizationContent::forProduct($productId)', $source);
        $this->assertStringContainsString('render_product_personalization($personalization', $source);
        $this->assertStringContainsString('$seo !== null', $source);
    }

    /**
     * Personalisatie is its own FULL-WIDTH section below the normal product
     * area, not a panel inside the product-information column — the layout
     * change this refactor exists for. Proved structurally: the call sits
     * outside `.product-detail`, and the section it renders opens its own
     * `.container`.
     */
    public function testPersonalizationIsRenderedAsItsOwnFullWidthSection(): void
    {
        $source = self::sourceOf('product.php');
        $partial = self::sourceOf('partials/product-personalization.php');

        $detailStart = strpos($source, 'class="product-detail"');
        $detailEnd = strpos($source, 'Terug naar producten');
        $renderCall = strpos($source, 'render_product_personalization(');

        $this->assertIsInt($detailStart);
        $this->assertIsInt($detailEnd);
        $this->assertIsInt($renderCall);
        $this->assertGreaterThan(
            $detailEnd,
            $renderCall,
            'the personalization section must be rendered AFTER the product-detail columns, not inside them'
        );

        $this->assertStringContainsString('class="personalizer-section"', $partial);
        $this->assertStringContainsString('<div class="container">', $partial);
    }

    /**
     * The normal product gallery is never a personalization canvas. There is
     * no fallback from one to the other anywhere: a view without its own
     * dedicated image is dropped by the resolver, and the partial only ever
     * prints the view's own `preview_image_path`.
     */
    public function testTheProductGalleryIsNeverUsedAsAPersonalizationCanvas(): void
    {
        $partial = self::sourceOf('partials/product-personalization.php');
        $resolver = self::sourceOf('src/Service/Personalization/ProductPersonalizationContent.php');

        $this->assertStringContainsString("ltrim((string) \$view['preview_image_path'], '/')", $partial);
        $this->assertStringNotContainsString('image_path', str_replace('preview_image_path', '', $partial));

        // The resolver DROPS a view that has no dedicated image rather than
        // substituting anything.
        $this->assertStringContainsString("if (\$previewImagePath === '') {", $resolver);
        $this->assertStringContainsString('continue;', $resolver);
    }

    /* ------------------------------------------------------------------ */
    /* The customer editor                                                 */
    /* ------------------------------------------------------------------ */

    public function testTheEditorKeepsCustomerContentInsideItsOwnZone(): void
    {
        $source = self::editorJs();

        // The centre is clamped to the zone...
        $this->assertStringContainsString('clamp(drag.start.x + dx, 0, 1)', $source);
        $this->assertStringContainsString('clamp(state.zones[zoneKey].transform[kind].x + dx, 0, 1)', $source);

        // ...and the zone clips whatever still overflows.
        $this->assertMatchesRegularExpression(
            '/\.personalizer__zone\{[^}]*overflow:\s*hidden/s',
            self::sourceOf('assets/css/shop/personalization.css'),
            'the engraving zone must clip its own overflow'
        );
    }

    public function testEachZoneKeepsItsOwnStateIndependently(): void
    {
        $source = self::editorJs();

        $this->assertStringContainsString('var state = { zones: {} };', $source);
        $this->assertStringContainsString('state.zones[zoneKey]', $source);
        $this->assertStringContainsString('function neutralZoneState()', $source);
    }

    /**
     * Switching views must only change what is VISIBLE. If it ever starts
     * touching values, a customer loses the front when they open the back.
     */
    public function testSwitchingViewsOnlyChangesVisibility(): void
    {
        $source = self::editorJs();

        $start = strpos($source, 'function switchView(viewKey)');
        $end = strpos($source, 'all("[data-personalizer-tab]")', $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString('stage.hidden =', $body);
        $this->assertStringContainsString('panel.hidden =', $body);
        // No assignment to any zone's stored values.
        $this->assertStringNotContainsString('state.zones[', $body);
        $this->assertStringNotContainsString('neutralZoneState', $body);
    }

    public function testTheEditorWorksWithTouchAsWellAsAMouse(): void
    {
        $source = self::editorJs();

        $this->assertStringContainsString('pointerdown', $source);
        $this->assertStringContainsString('pointermove', $source);
        $this->assertStringContainsString('pointercancel', $source);
        $this->assertStringNotContainsString('mousedown', $source, 'mouse-only dragging would exclude every phone');

        $this->assertMatchesRegularExpression(
            '/\.personalizer__layer\{[^}]*touch-action:\s*none/s',
            self::sourceOf('assets/css/shop/personalization.css')
        );
    }

    public function testTheEditorIsOperableFromTheKeyboard(): void
    {
        $source = self::editorJs();
        $partial = self::sourceOf('partials/product-personalization.php');

        foreach (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'] as $key) {
            $this->assertStringContainsString($key, $source);
        }

        $this->assertStringContainsString('tabindex="0"', $partial);
        $this->assertStringContainsString('aria-label=', $partial);
        // Scaling and rotating are real range inputs, keyboard-operable by nature.
        $this->assertStringContainsString('type="range"', $partial);
        $this->assertStringContainsString('data-personalizer-rotation', $partial);
    }

    /**
     * Every zone must be labelled and grouped for a screen reader, not just
     * laid out visually.
     */
    public function testEveryZoneIsAProperlyLabelledFormGroup(): void
    {
        $partial = self::sourceOf('partials/product-personalization.php');

        $this->assertStringContainsString('<fieldset class="personalizer__zone-fields"', $partial);
        $this->assertStringContainsString('<legend>', $partial);
        $this->assertStringContainsString('aria-required="true"', $partial);
        $this->assertStringContainsString('aria-describedby=', $partial);
        $this->assertStringContainsString('role="tablist"', $partial);
        $this->assertStringContainsString('aria-selected=', $partial);
    }

    public function testTheCustomerCanResetOneZoneOrEverything(): void
    {
        $partial = self::sourceOf('partials/product-personalization.php');
        $source = self::editorJs();

        $this->assertStringContainsString('data-personalizer-reset-zone', $partial);
        $this->assertStringContainsString('data-personalizer-reset', $partial);
        $this->assertStringContainsString('data-personalizer-remove-image', $partial);
        $this->assertStringContainsString('function resetZone(zoneKey)', $source);
        $this->assertStringContainsString('function resetAll()', $source);
        $this->assertStringContainsString('function clearImage(zoneKey)', $source);
    }

    public function testTheEditorRendersTheCustomersTextAsTextNeverAsMarkup(): void
    {
        $source = self::editorJs();

        $this->assertStringContainsString('layer.textContent = zoneState.text;', $source);
        $this->assertStringNotContainsString('innerHTML', $source);
    }

    /**
     * A font may only ever come from the zone's own server-rendered list —
     * the browser never invents one, and the server re-checks anyway.
     */
    /**
     * Fonts come from the shop's GLOBAL library and reach the editor in one
     * server-rendered block — the customer can never be offered a font the
     * server did not send, and no font is configured per zone any more.
     */
    public function testTheEditorOnlyOffersFontsTheServerSent(): void
    {
        $source = self::editorJs();
        $partial = self::sourceOf('partials/product-personalization.php');

        $this->assertStringContainsString('var FONTS = Array.isArray(config.fonts) ? config.fonts : [];', $source);
        $this->assertStringContainsString('function fontByKey(key)', $source);
        $this->assertStringNotContainsString('zonesByKey[zoneKey].fonts', $source, 'fonts are no longer a zone property');

        $this->assertStringContainsString("PersonalizationFonts::payload(\$fontKeys)", $partial);
        // A library with one font shows no selector: there is nothing to choose.
        $this->assertStringContainsString('count($fonts) > 1', $partial);
    }

    /**
     * An uploaded face is hosted by this site and made usable with a plain
     * `@font-face` — never fetched from Google Fonts or any other remote
     * service, on the storefront or in the CMS.
     */
    public function testUploadedFontsAreSelfHostedThroughFontFace(): void
    {
        $partial = self::sourceOf('partials/product-personalization.php');
        $registry = self::sourceOf('src/Service/Personalization/PersonalizationFonts.php');

        $this->assertStringContainsString('PersonalizationFonts::faceCss($fontKeys)', $partial);
        $this->assertStringContainsString('@font-face{font-family:', $registry);
        $this->assertStringContainsString('assets/fonts/personalization/', self::sourceOf('src/Service/Personalization/PersonalizationFontUploader.php'));

        foreach ([$partial, $registry] as $source) {
            $this->assertStringNotContainsString('fonts.googleapis.com', $source);
            $this->assertStringNotContainsString('fonts.gstatic.com', $source);
        }
    }

    /* ------------------------------------------------------------------ */
    /* The CMS builder                                                     */
    /* ------------------------------------------------------------------ */

    public function testPersonalizationIsOffForAProductThatWasNeverConfigured(): void
    {
        $resolver = self::sourceOf('src/Service/Personalization/ProductPersonalizationContent.php');

        $this->assertStringContainsString(
            "if (\$stored === null || (int) (\$stored['settings']['is_enabled'] ?? 0) !== 1) {",
            $resolver,
            'no settings row must read as "personalization disabled"'
        );

        // And a product that was never enrolled has no configuration row at
        // all — the overview is the only place one is created.
        $this->assertStringContainsString(
            'createForProduct(',
            self::sourceOf('api/admin/create-product-personalization.php')
        );
    }

    /**
     * The graphical rectangle is a convenience on top of four ordinary number
     * fields per zone — which is what keeps the engraving areas configurable
     * with a keyboard, a screen reader, or no JavaScript at all.
     */
    public function testEveryZoneHasRealFormFieldsBehindTheDragEditor(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');

        foreach (['x', 'y', 'width', 'height'] as $key) {
            $this->assertStringContainsString('name="area_' . $key . '"', $builder);
            $this->assertStringContainsString('data-zone-input="' . $key . '" data-zone-id="<?= $zoneId ?>"', $builder);
        }

        $this->assertStringContainsString('data-zone-editor', $builder);
        $this->assertStringContainsString('required', $builder);
    }

    /**
     * One image can now carry several rectangles, so each one has to be
     * addressable on its own — and only the selected one may show handles.
     */
    public function testTheZoneEditorHandlesSeveralZonesOnOneImage(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');
        $js = self::sourceOf('admin/assets/personalization-admin.js');
        $css = self::sourceOf('admin/assets/admin.css');

        $this->assertStringContainsString('data-zone-box="<?= $zoneId ?>"', $builder);
        $this->assertStringContainsString('admin-zone-editor__tag', $builder);

        $this->assertStringContainsString('function createZone(box, stage)', $js);
        $this->assertStringContainsString('stage.querySelectorAll("[data-zone-box]")', $js);
        $this->assertStringContainsString('other.box.classList.toggle("is-selected", other === zone)', $js);

        $this->assertStringContainsString('.admin-zone-editor__box.is-selected .admin-zone-editor__handle{ display: block; }', $css);
    }

    /**
     * The re-entrancy guard that made dragging work on both axes. Without it,
     * writing `x` fires an input event whose listener re-derives the whole
     * rectangle from a half-written set of fields.
     */
    public function testTheZoneEditorGuardsAgainstItsOwnInputEvents(): void
    {
        $js = self::sourceOf('admin/assets/personalization-admin.js');

        $this->assertStringContainsString('var syncingInputs = false;', $js);
        $this->assertStringContainsString('if (syncingInputs) return;', $js);
    }

    public function testTheZoneEditorSupportsPointerAndKeyboardInput(): void
    {
        $js = self::sourceOf('admin/assets/personalization-admin.js');

        $this->assertStringContainsString('pointerdown', $js);
        $this->assertStringContainsString('pointercancel', $js);
        $this->assertStringContainsString('ArrowLeft', $js);
        $this->assertStringNotContainsString('mousedown', $js);
    }

    /* ------------------------------------------------------------------ */
    /* The CMS order page                                                  */
    /* ------------------------------------------------------------------ */

    public function testTheOrderPageShowsEveryZoneOfEveryOrderLine(): void
    {
        $source = self::sourceOf('admin/order.php');

        $this->assertStringContainsString('OrderItemPersonalizationRepository', $source);
        $this->assertStringContainsString('renderOrderItemPersonalizations($itemPersonalizations', $source);
        $this->assertStringContainsString("\$personalizations[(int) (\$item['id'] ?? 0)] ?? []", $source);
    }

    public function testTheOrderPageGroupsZonesByTheirView(): void
    {
        $source = self::sourceOf('admin/_order_personalization.php');

        $this->assertStringContainsString('$groups[$viewKey]', $source);
        $this->assertStringContainsString('$showViewHeadings = count($groups) > 1;', $source);
    }

    public function testTheOrderPageExplainsWhatWasCharged(): void
    {
        $personalization = self::sourceOf('admin/_order_personalization.php');
        $order = self::sourceOf('admin/order.php');

        $this->assertStringContainsString('Meerprijs', $personalization);
        $this->assertStringContainsString("Money::toCents(\$row['surcharge'] ?? 0)", $personalization);

        // And the line itself explains its own unit price.
        $this->assertStringContainsString("\$item['personalization_surcharge']", $order);
        $this->assertStringContainsString("\$item['base_unit_price']", $order);
    }

    public function testTheOrderPageOffersTheOriginalFileThroughTheAuthenticatedEndpointOnly(): void
    {
        $source = self::sourceOf('admin/_order_personalization.php');

        $this->assertStringContainsString('/api/admin/order-personalization-file.php?id=', $source);
        $this->assertStringContainsString('Download origineel', $source);

        $this->assertStringNotContainsString('/storage/', $source);
        $this->assertStringNotContainsString('stored_filename', $source);
        $this->assertStringNotContainsString('/api/personalization-image.php', $source);
    }

    public function testTheOrderPageRebuildsThePreviewFromTheStoredSnapshot(): void
    {
        $source = self::sourceOf('admin/_order_personalization.php');

        $this->assertStringContainsString("\$snapshot['zone']", $source);
        $this->assertStringContainsString("\$snapshot['preview_image_path']", $source);
        $this->assertStringContainsString("\$snapshot['render']['text_base_height_ratio']", $source);
        // Including the font the customer chose, so the shapes match.
        $this->assertStringContainsString('PersonalizationFonts::stack($fontKey)', $source);

        // Nothing here may read the product's CURRENT configuration.
        $this->assertStringNotContainsString('ProductPersonalizationContent::forProduct', $source);
        $this->assertStringNotContainsString('ProductPersonalizationRepository', $source);
    }

    public function testTheOrderPageEscapesEverythingTheCustomerSupplied(): void
    {
        $source = self::sourceOf('admin/_order_personalization.php');

        // One escaping helper, applied to every value that reaches the markup.
        $this->assertStringContainsString(
            "\$esc = static fn (?string \$value): string => htmlspecialchars((string) \$value, ENT_QUOTES, 'UTF-8');",
            $source
        );
        $this->assertStringContainsString('$esc($text)', $source);
        $this->assertStringContainsString("\$esc((string) (\$row['original_filename'] ?? ''))", $source);
        $this->assertStringNotContainsString('<?= $text ?>', $source);
    }

    public function testAMissingPreviewImageDegradesToAMessageInsteadOfABrokenPage(): void
    {
        $source = self::sourceOf('admin/_order_personalization.php');

        $this->assertStringContainsString('$previewExists', $source);
        $this->assertStringContainsString('niet meer beschikbaar', $source);
        $this->assertStringContainsString('volledig bewaard gebleven', $source);
    }

    /**
     * A Phase 1 order has no view, no font and no surcharge. The renderer
     * must treat all three as optional rather than assuming Phase 2 data.
     */
    public function testThePhase1OrderShapeStillRenders(): void
    {
        $source = self::sourceOf('admin/_order_personalization.php');

        $this->assertStringContainsString("\$row['view_key'] ?? (\$snapshot['view']['view_key'] ?? '')", $source);
        $this->assertStringContainsString("if (\$fontKey !== '')", $source);
        $this->assertStringContainsString('if ($surchargeCents > 0)', $source);
    }
}
