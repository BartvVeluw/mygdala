<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The three editor behaviours that only exist in the browser, held in place
 * by inspecting the source that implements them — the technique this project
 * already uses where there is no harness (see
 * PersonalizationFrontendContractTest, ProductDeletionAdminSecurityTest).
 *
 *   1. a DRAFT survives a refresh, and a cart line can be edited again;
 *   2. the editor LOCKS while an image uploads, and a late response cannot
 *      overwrite newer state;
 *   3. the CMS order screen stacks instead of scrolling sideways.
 *
 * Each is asserted on the specific mechanism, not on its general shape, so a
 * refactor that quietly drops the guarantee fails here rather than in a
 * customer's browser.
 */
final class PersonalizationEditorContractTest extends TestCase
{
    private static function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The Shop's frontend as one string. Step 4 split assets/js/main.js by
     * owner; the cart state and the product page behaviour this contract is
     * about now live in the Shop's two files.
     */
    private static function shopJs(): string
    {
        return self::sourceOf('assets/js/shop/cart.js')
            . "\n" . self::sourceOf('assets/js/shop/shop.js');
    }

    private static function editorJs(): string
    {
        return self::sourceOf('assets/js/personalization.js');
    }

    /* ------------------------------------------------------------------ */
    /* 1. Draft persistence                                                */
    /* ------------------------------------------------------------------ */

    public function testADraftIsPersistedPerProductAndRestoredBeforeTheFirstPaint(): void
    {
        $editor = self::editorJs();

        $this->assertStringContainsString('var DRAFT_PREFIX = "vvl-personalization-draft:";', $editor);
        $this->assertStringContainsString('var DRAFT_KEY = DRAFT_PREFIX + config.product_id;', $editor);

        // Restoring happens before the editor paints, or the customer sees an
        // empty editor flash back to their work.
        $restorePos = strpos($editor, "\n  restore();");
        $switchPos = strpos($editor, "\n  switchView(activeView);");
        $this->assertIsInt($restorePos);
        $this->assertIsInt($switchPos);
        $this->assertLessThan($switchPos, $restorePos);
    }

    /**
     * Everything the task named has to survive a refresh: text, font, colour,
     * the upload reference, the selected view, and position/scale/rotation.
     */
    public function testTheDraftCarriesEveryFieldTheCustomerProduced(): void
    {
        $editor = self::editorJs();

        // Saved: the whole zone state plus which view they were on.
        $this->assertMatchesRegularExpression(
            '/writeJson\(DRAFT_KEY, \{.*active_view: activeView.*zones: state\.zones/s',
            $editor
        );

        // Restored, field by field — never a blind assign, because a stored
        // draft is untrusted input like any other.
        foreach ([
            'zoneState.text = stored.text.slice(0, zone.max_text_length);',
            'if (fontByKey(stored.font)) zoneState.font = stored.font;',
            'if (colorByKey(stored.color)) zoneState.color = stored.color;',
            'zoneState.upload_token = stored.upload_token;',
            'target.scale = clamp(numberOr(stored_.scale, 1), MIN_SCALE, MAX_SCALE);',
            'target.rotation = zone.allow_rotation',
        ] as $needle) {
            $this->assertStringContainsString($needle, $editor, $needle);
        }

        // The upload's preview URL is rebuilt from the token rather than
        // trusted from storage, so a tampered draft cannot aim an <img>
        // anywhere it likes.
        $this->assertStringContainsString(
            'zoneState.upload_preview_url = "/api/personalization-image.php?token=" + encodeURIComponent(stored.upload_token);',
            $editor
        );
    }

    /** A price must never come back out of the browser as authoritative. */
    public function testTheDraftNeverPersistsAPrice(): void
    {
        $editor = self::editorJs();

        $start = strpos($editor, 'function saveDraft()');
        $end = strpos($editor, 'function applyZoneState(');
        $this->assertIsInt($start);
        $this->assertIsInt($end);

        $saveDraft = substr($editor, $start, $end - $start);

        $this->assertStringNotContainsString('price', $saveDraft);
        $this->assertStringNotContainsString('surcharge', $saveDraft);
    }

    public function testReturningFromACartLineRestoresThatLineForEditing(): void
    {
        $editor = self::editorJs();
        $main = self::shopJs();

        // The cart links back with the line's own id...
        $this->assertStringContainsString('function cartItemEditUrl(item)', $main);
        $this->assertStringContainsString('if (item.line_id) url += "&line=" + encodeURIComponent(item.line_id);', $main);
        $this->assertStringContainsString('href="\' + escapeAttr(cartItemEditUrl(item)) + \'"', $main);

        // ...and the editor reads that line out of the cart and prefers it
        // over its own draft, so "edit this line" really edits that line.
        $this->assertStringContainsString('new URLSearchParams(window.location.search).get("line")', $editor);
        $this->assertStringContainsString('function findCartLine(lineId)', $editor);
        $this->assertStringContainsString('function zonesFromCartLine(line)', $editor);

        $linePos = strpos($editor, 'if (lineId) {');
        $draftPos = strpos($editor, 'if (!restored) {');
        $this->assertIsInt($linePos);
        $this->assertIsInt($draftPos);
        $this->assertLessThan($draftPos, $linePos, 'the named cart line wins over the product draft');
    }

    /**
     * Regression: a personalized cart line saved BEFORE line ids existed must
     * be given one on the first read.
     *
     * A customer's cart lives in their own browser, so it survives the deploy
     * that introduced line ids. Such a line is otherwise permanently
     * anonymous, and both features that depend on the id fail silently: the
     * composed preview is rasterised, posted and attached to nothing (its
     * snapshot is never claimed, and is later swept as an abandoned draft),
     * and the cart's edit link drops the customer into an empty editor
     * instead of their own personalization. Order 3727's product-1 line was
     * exactly that shape: it linked back to a bare /product.php?id=1, while
     * the line added after the feature carried its own &line=.
     */
    public function testAPersonalizedCartLineWithoutALineIdIsGivenOneOnRead(): void
    {
        $main = self::shopJs();

        $this->assertStringContainsString('function backfillCartLineIds(items)', $main);

        // Only personalized lines are touched: an ordinary line keeps exactly
        // the key it has always had.
        $this->assertStringContainsString(
            'if (item && item.personalization && !item.line_id) {',
            $main,
            'the backfill must be limited to personalized lines that lack an id'
        );
        $this->assertStringContainsString('item.line_id = newCartLineId();', $main);

        // It has to run on the way OUT of storage, so every consumer -- the
        // cart UI, cartAdd's merge path and cartItemEditUrl -- sees the id.
        $this->assertMatchesRegularExpression(
            '/function readCart\(\)\s*\{.*?backfillCartLineIds\(items\).*?\n  \}/s',
            $main,
            'readCart must route through the backfill'
        );

        // Persisted once, and deliberately NOT through writeCart(): nothing
        // the customer can see changed, so nothing should be woken up.
        $start = strpos($main, 'function backfillCartLineIds(items)');
        $end = strpos($main, 'function readCart()');
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $this->assertLessThan($end, $start, 'the backfill is declared before readCart uses it');
        $backfill = substr($main, $start, $end - $start);
        $this->assertStringContainsString('localStorage.setItem(CART_KEY, JSON.stringify(items));', $backfill);
        $this->assertStringNotContainsString('writeCart(', $backfill);
        $this->assertStringNotContainsString('vvl-cart-change', $backfill);

        // And the id must be stable: written back, not re-minted on each read.
        $this->assertStringContainsString('if (changed) {', $backfill);
    }

    /**
     * The other half of the same guarantee: merging into an existing line
     * still hands back an id, so the composed preview has something to
     * attach itself to.
     */
    public function testMergingIntoAnExistingPersonalizedLineStillYieldsItsId(): void
    {
        $main = self::shopJs();

        $this->assertStringContainsString('return touched.line_id || null;', $main);

        // cartAdd reads through readCart(), which is what guarantees the
        // merged-into line already carries an id by the time it is returned.
        $addPos = strpos($main, 'function cartAdd(product, qty)');
        $this->assertIsInt($addPos);
        $this->assertStringContainsString('var items = readCart();', substr($main, $addPos, 1400));

        // And the caller only attaches when it actually got one.
        $this->assertStringContainsString(
            'if (!lineId || !tokens || !Object.keys(tokens).length) return;',
            $main
        );
    }

    public function testTheDraftIsDroppedOnceItHasBecomeACartLine(): void
    {
        $editor = self::editorJs();
        $main = self::shopJs();

        $this->assertStringContainsString('function clearDraft()', $editor);
        $this->assertStringContainsString('clearDraft: clearDraft', $editor);
        // Reset clears it too, or a refresh would resurrect what the customer
        // just wiped.
        $this->assertMatchesRegularExpression('/function resetAll\(\)[^}]*clearDraft\(\);/s', $editor);

        $this->assertStringContainsString(
            'if (typeof personalizer.clearDraft === "function") personalizer.clearDraft();',
            $main
        );
    }

    /* ------------------------------------------------------------------ */
    /* 2. The upload race                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * The bug: upload A, keep editing, upload B, and whichever RESPONSE
     * arrived last won. Two independent guards, both required.
     */
    public function testTheEditorLocksWhileAnImageUploads(): void
    {
        $editor = self::editorJs();

        // Guard 1: everything is disabled and the state is announced.
        $this->assertStringContainsString('function setBusy(busy, message)', $editor);
        $this->assertStringContainsString('root.setAttribute("aria-busy", busy ? "true" : "false");', $editor);
        $this->assertStringContainsString('root.classList.toggle("is-busy", !!busy);', $editor);
        $this->assertStringContainsString('all("input, select, button, textarea").forEach', $editor);
        $this->assertStringContainsString('setBusy(true, t("Afbeelding uploaden…", "Uploading image…"));', $editor);

        // Only what WE disabled is re-enabled, so unlocking never enables a
        // control that was disabled for its own reasons.
        $this->assertStringContainsString('el.setAttribute("data-personalizer-relock", "1");', $editor);
        $this->assertStringContainsString('el.getAttribute("data-personalizer-relock") === "1"', $editor);

        // The purchase action is covered explicitly, wherever it lives.
        $this->assertStringContainsString('document.querySelector("[data-product-add-to-cart]")', $editor);
    }

    public function testASecondUploadCannotBeStartedAndStaleResponsesAreDropped(): void
    {
        $editor = self::editorJs();

        // Guard 1 again, at the entry point.
        $this->assertStringContainsString('if (isUploading()) {', $editor);

        // Guard 2: sequence numbers, so a response that is no longer the
        // current one changes nothing — even after a reset or a clear.
        $this->assertStringContainsString('var sequence = ++uploadSequence;', $editor);
        $this->assertSame(
            2,
            substr_count($editor, 'if (sequence !== uploadSequence) return;'),
            'both the success and the failure path must drop a stale response'
        );

        // And add-to-cart refuses while one is in flight, behind the disabled
        // button rather than instead of it.
        $this->assertStringContainsString('isUploading: isUploading', $editor);
        $this->assertMatchesRegularExpression(
            '/validate: function \(\) \{.*if \(isUploading\(\)\) \{/s',
            $editor
        );
    }

    public function testAFailedUploadUnlocksTheEditorAndExplainsItself(): void
    {
        $editor = self::editorJs();

        // finish() restores the controls, and it is called on both paths.
        $this->assertStringContainsString('function finish() {', $editor);
        $this->assertStringContainsString('setBusy(false);', $editor);
        $this->assertSame(
            2,
            substr_count($editor, 'finish();'),
            'both the success and the failure path must unlock the editor'
        );

        $this->assertStringContainsString('De afbeelding kon niet worden geüpload', $editor);
    }

    /* ------------------------------------------------------------------ */
    /* 3. The CMS order layout                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The block sits inside a table cell, so a fixed-width preview beside a
     * column of text made the cell — and the whole order screen — wider than
     * the viewport.
     */
    public function testTheOrderPersonalizationBlockStacksInsteadOfScrollingSideways(): void
    {
        $css = self::sourceOf('admin/assets/admin.css');

        $this->assertMatchesRegularExpression(
            '/\.admin-personalization__body\{[^}]*flex-direction:\s*column/s',
            $css,
            'facts and preview must stack, not sit side by side'
        );

        // Nothing in the block may establish a minimum width the table has to
        // honour: a bounded block, and grid tracks that may shrink.
        $this->assertMatchesRegularExpression('/\.admin-personalization\{[^}]*max-width:/s', $css);
        $this->assertMatchesRegularExpression(
            '/\.admin-personalization__list\{[^}]*grid-template-columns:\s*minmax\(0, max-content\) minmax\(0, 1fr\)/s',
            $css
        );
        $this->assertMatchesRegularExpression('/\.admin-personalization__facts\{[^}]*min-width:\s*0/s', $css);

        // The old fixed-width preview column is gone.
        $this->assertDoesNotMatchRegularExpression(
            '/\.admin-personalization__preview\{[^}]*flex:\s*0 0 auto;\s*width:\s*260px/s',
            $css
        );
    }

    public function testTheComposedPreviewSitsUnderTheDetailsWithItsOwnDownload(): void
    {
        $renderer = self::sourceOf('admin/_order_personalization.php');

        // Rendered per VIEW, after that view's zones — it is a picture of the
        // whole side of the product, not of one zone.
        $zonesPos = strpos($renderer, 'renderOrderPersonalizationZone($row, $snapshot);');
        $snapshotPos = strpos($renderer, 'renderOrderPersonalizationSnapshot($snapshots[$viewKey] ?? null);');
        $this->assertIsInt($zonesPos);
        $this->assertIsInt($snapshotPos);
        $this->assertLessThan($snapshotPos, $zonesPos);

        $this->assertStringContainsString('Download voorbeeld', $renderer);
        $this->assertStringContainsString('/api/admin/order-preview-snapshot.php?id=', $renderer);
        $this->assertStringContainsString("'&mode=download'", $renderer);

        // The customer's ORIGINAL upload stays separately downloadable.
        $this->assertStringContainsString('Download origineel', $renderer);
        $this->assertStringContainsString('/api/admin/order-personalization-file.php?id=', $renderer);
    }

    /* ------------------------------------------------------------------ */
    /* The colour, end to end through the browser                          */
    /* ------------------------------------------------------------------ */

    public function testTheColourIsAServerSuppliedPaletteAndNeverAFreeValue(): void
    {
        $editor = self::editorJs();
        $partial = self::sourceOf('partials/product-personalization.php');

        // The palette arrives from the server, whole.
        $this->assertStringContainsString('var COLORS = Array.isArray(config.colors) ? config.colors : [];', $editor);
        $this->assertStringContainsString("'colors' => \$colors,", $partial);
        $this->assertStringContainsString('PersonalizationColors::payload()', $partial);

        // Every hex that reaches a style attribute came out of that palette.
        $this->assertStringContainsString('layer.style.color = colorHex(zoneState.color);', $editor);
        $this->assertStringContainsString('var color = colorByKey(key) || colorByKey(DEFAULT_COLOR);', $editor);

        // Radio inputs over a colour input: five choices, not a picker.
        $this->assertStringContainsString('type="radio"', $partial);
        $this->assertStringContainsString('data-personalizer-color=', $partial);
        $this->assertStringNotContainsString('type="color"', $partial);
    }

    /* ------------------------------------------------------------------ */
    /* The composed preview is built from what is on screen                */
    /* ------------------------------------------------------------------ */

    public function testTheComposedPreviewUsesTheSameArithmeticAsTheLivePreview(): void
    {
        $editor = self::editorJs();

        $start = strpos($editor, 'function composeView(view, zoneStates)');
        $this->assertIsInt($start);
        $body = substr($editor, $start, 4000);

        // Zone rectangles are percentages of the image; layers are fractions
        // of that rectangle — identical to the on-screen preview and to
        // PersonalizationRules.
        $this->assertStringContainsString('(zone.area.x / 100) * width', $body);
        $this->assertStringContainsString('IMAGE_RATIO * transform.scale * box.w', $body);
        $this->assertStringContainsString('box.h * TEXT_RATIO * transform.scale', $body);

        // Font, colour and rotation all come from the same state the screen
        // renders from.
        $this->assertStringContainsString('ctx.font = fontSize + "px " + (font ? font.stack : "sans-serif");', $body);
        $this->assertStringContainsString('ctx.fillStyle = colorHex(zoneState.color);', $body);
        $this->assertStringContainsString('ctx.rotate((transform.rotation * Math.PI) / 180);', $body);

        // Content is clipped to its zone, exactly as the zone element clips it.
        $this->assertStringContainsString('ctx.clip();', $body);

        // Webfonts must have loaded, or the raster shows a fallback face.
        $this->assertStringContainsString('document.fonts.ready', $editor);

        // And the state it draws is captured SYNCHRONOUSLY, before the editor
        // is reset for the next unit — composing from live state would
        // rasterise an empty editor.
        $this->assertStringContainsString('function snapshotZoneStates()', $editor);
        $this->assertStringContainsString('var zoneStates = snapshotZoneStates();', $editor);
        $snapshotPos = strpos($editor, 'var zoneStates = snapshotZoneStates();');
        $readyPos = strpos($editor, 'var ready = document.fonts && document.fonts.ready');
        $this->assertIsInt($snapshotPos);
        $this->assertIsInt($readyPos);
        $this->assertLessThan($readyPos, $snapshotPos, 'the snapshot must precede the first await');
    }

    /**
     * Supplementary: composing happens AFTER the line is in the cart, and a
     * failure is swallowed. The customer's order never waits on it and never
     * depends on it.
     */
    public function testComposingAPreviewNeverBlocksOrBreaksAddToCart(): void
    {
        $editor = self::editorJs();
        $main = self::shopJs();

        $addPos = strpos($main, 'var lineId = S.cartAdd(cartProduct, qty);');
        $composePos = strpos($main, 'personalizer.composeAndAttach(lineId);');
        $this->assertIsInt($addPos);
        $this->assertIsInt($composePos);
        $this->assertLessThan($composePos, $addPos, 'the line is in the cart before anything is composed');

        // Every failure path resolves rather than rejects.
        $this->assertStringContainsString('.catch(function () { return null; });', $editor);
        $this->assertStringContainsString('.catch(function () { return {}; });', $editor);

        // The tokens are attached out of band, through the cart's own small
        // public API — unchanged in shape by the step 4 asset split, which
        // only added an `internal` sibling for the Shop's own second file.
        $this->assertStringContainsString('window.VVLCart = {', $main);
        $this->assertStringContainsString('attachPreviewTokens: cartAttachPreviewTokens,', $main);
        $this->assertStringContainsString('function cartAttachPreviewTokens(lineId, tokens)', $main);
    }
}
