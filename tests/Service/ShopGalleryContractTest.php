<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * What the Shop UX phase promises about its scripts and styles, read from the
 * files themselves (no browser here; the browser acceptance is in the phase
 * report). Two groups:
 *
 *   - the product page's gallery (assets/js/shop/product-gallery.js, which
 *     shop.js hands the pictures, and shop.css): the position is a 0-based
 *     INDEX kept across variants, the selected thumbnail has its own lasting
 *     state, the big picture is contained and the thumbnails cover, the
 *     transition is one of three closed words, a swipe never blocks
 *     scrolling, and every motion respects prefers-reduced-motion
 *     (Product Gallery 2.0);
 *   - the product editor's pictures (admin/assets/product-gallery.js and the
 *     picker it uses): nothing is posted until the form's own Opslaan.
 */
final class ShopGalleryContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    /* ------------------------------------------------------ the product page */

    public function testTheGalleryKeepsAZeroBasedIndexAndFallsBackToTheFirstPicture(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');

        $this->assertMatchesRegularExpression(
            '/function nextGalleryIndex\(index, length\) \{\s*return index >= 0 && index < length \? index : 0;\s*\}/',
            $script,
            'same position when the new list has it, else the first picture — both 0-based'
        );
        $this->assertStringContainsString('var target = nextGalleryIndex(previous, images.length);', $script);
        // Stepping past either end wraps, as the site's lightbox does.
        $this->assertMatchesRegularExpression('/function wrapIndex\(index, length\) \{\s*return \(\(index % length\) \+ length\) % length;\s*\}/', $script);
    }

    public function testAVariantWithoutItsOwnPicturesShowsTheProductsPool(): void
    {
        $script = self::source('assets/js/shop/shop.js');

        $this->assertMatchesRegularExpression(
            '/function variantImages\(variant\) \{\s*return variant && Array\.isArray\(variant\.images\) && variant\.images\.length > 0 \? variant\.images : productImages;/',
            $script
        );
        $this->assertStringNotContainsString(
            'selectedVariant ? selectedVariant.images : []',
            $script,
            'a variant must never empty the gallery'
        );
    }

    public function testTheSelectedThumbnailIsMarkedForEveryone(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');
        $css = self::source('assets/css/shop/shop.css');

        $this->assertStringContainsString('btn.setAttribute("aria-current", "true");', $script);
        $this->assertStringContainsString('btn.removeAttribute("aria-current");', $script);
        $this->assertStringContainsString('.product-detail__thumb.is-active{', $css);
        $this->assertMatchesRegularExpression('/\.product-detail__thumb:hover,\s*\.product-detail__thumb:focus-visible\{/', $css);
        // Real buttons with the picture's words as their name.
        $this->assertStringContainsString('btn.type = "button";', $script);
        $this->assertStringContainsString('btn.setAttribute("aria-label", altOf(image));', $script);
    }

    public function testMotionIsShortAndRespectsReducedMotion(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');
        $css = self::source('assets/css/shop/shop.css');

        $this->assertStringContainsString('(prefers-reduced-motion: reduce)', $script);
        // Every change asks again, so a setting changed while the page is open counts.
        $this->assertStringContainsString('var mode = animate && outgoing && !prefersReducedMotion() ? transition : "none";', $script);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\{\s*\.product-detail__thumb,\s*\.product-detail__main-img,\s*\.product-detail__media\.is-transitioning \.product-detail__main-img\{ transition: none; \}/',
            $css
        );
    }

    /* -------------------------------------------- Product Gallery 2.0 */

    /** The big picture is always whole; the thumbnails fill their squares. */
    public function testTheMainPictureIsContainedAndTheThumbnailsCover(): void
    {
        $css = self::source('assets/css/shop/shop.css');

        $this->assertMatchesRegularExpression('/\.product-detail__main-img\{[^}]*position: absolute; inset: 0;[^}]*object-fit: contain;/', $css);
        $this->assertMatchesRegularExpression('/\.product-detail__main-img\{[^}]*box-sizing: border-box;\s*padding: max\(var\(--sp-1\), calc\(var\(--radius-lg\) \* 0\.3\)\);/', $css, 'clear of the rounded corners');
        $this->assertDoesNotMatchRegularExpression('/\.product-detail__main-img[^{]*\{[^}]*object-fit: cover/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.product-detail__main-img\{[^}]*border-radius/', $css, 'the frame clips, the picture has no corners of its own');
        $this->assertStringContainsString('.product-detail__thumb img{ width: 100%; height: 100%; object-fit: cover; display: block; }', $css);
        // The frame is a stage: it clips a sliding picture and lets a vertical drag scroll the page.
        $this->assertMatchesRegularExpression('/\.product-detail__media\{[^}]*aspect-ratio: 1;[^}]*overflow: hidden;[^}]*touch-action: pan-y pinch-zoom;/', $css);
        // A long thumbnail row scrolls inside its column instead of widening the page.
        $this->assertStringContainsString('.product-detail__gallery{ display: flex; flex-direction: column; gap: var(--sp-3); min-width: 0; }', $css);
    }

    /**
     * Many photos WRAP onto a next line of thumbnails: no horizontal
     * scrollbar and no thumbnail carousel (v0.1.12). The row is one shared
     * rule, so the Featured Product block — the same markup and script — wraps
     * with it; its own stylesheet does not restyle the row.
     */
    public function testTheThumbnailsWrapInsteadOfScrolling(): void
    {
        $css = self::source('assets/css/shop/shop.css');
        $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertMatchesRegularExpression('/\.product-detail__thumbs\{\s*display: flex; flex-wrap: wrap; gap: var\(--sp-2\);\s*\}/', $rules);
        preg_match_all('/\.product-detail__thumbs[^{]*\{([^}]*)\}/', $rules, $bodies);
        foreach ($bodies[1] as $body) {
            $this->assertStringNotContainsString('overflow', $body, 'nothing scrolls or clips the row');
            $this->assertStringNotContainsString('nowrap', $body);
            $this->assertStringNotContainsString('scroll-snap', $body);
        }
        $this->assertMatchesRegularExpression('/\.product-detail__thumb\{\s*width: 64px; height: 64px; padding: 0; flex: 0 0 auto;/', $rules, 'every thumbnail keeps one fixed size');

        $script = self::source('assets/js/shop/product-gallery.js');
        $this->assertStringNotContainsString('scrollLeft', $script, 'nothing scrolls the row sideways any more');
        $this->assertStringContainsString('btn.setAttribute("aria-current", "true");', $script, 'the lasting selected state stays');

        $featured = (string) preg_replace('#/\*.*?\*/#s', '', self::source('assets/css/shop/featured-product.css'));
        $this->assertDoesNotMatchRegularExpression('/\.product-detail__thumbs\s*\{/', $featured, 'no separate fix for the block');
        $this->assertStringContainsString(
            '<div class="product-detail__thumbs" data-product-thumbs hidden></div>',
            self::source('partials/section-featured-product.php'),
            'the block uses the very same row'
        );
    }

    public function testTheServerHandsTheGalleryOneResolvedWord(): void
    {
        $template = self::source('product.php');

        $this->assertStringContainsString('$galleryTransition = \App\Service\ProductGalleryTransition::forProduct($seo !== null ? $productId : 0);', $template);
        $this->assertStringContainsString('<div class="product-detail__gallery" data-product-gallery data-gallery-transition="<?= htmlspecialchars($galleryTransition, ENT_QUOTES, \'UTF-8\') ?>">', $template);
        // The controller is asked for before shop.js, which hands it the pictures.
        $this->assertLessThan(
            strpos($template, "requireScript('assets/js/shop/shop.js')"),
            strpos($template, "requireScript('assets/js/shop/product-gallery.js')")
        );
        $this->assertStringContainsString('window.VVLProductGallery.create({', self::source('assets/js/shop/shop.js'));
    }

    /** The word is checked again in the browser and only ever selects one of three rules. */
    public function testNoOtherTransitionCanReachTheStyles(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');
        $css = self::source('assets/css/shop/shop.css');

        $this->assertStringContainsString('var TRANSITIONS = ["none", "fade", "slide"];', $script);
        $this->assertStringContainsString('var FALLBACK_TRANSITION = "fade";', $script);
        $this->assertMatchesRegularExpression('/function transitionOf\(value\) \{\s*return TRANSITIONS\.indexOf\(value\) >= 0 \? value : FALLBACK_TRANSITION;\s*\}/', $script);
        $this->assertStringContainsString('root.setAttribute("data-gallery-transition", transition);', $script);
        // Never a class name or a style built from the value.
        $this->assertStringNotContainsString('classList.add(transition', $script);
        $this->assertStringNotContainsString('className = transition', $script);
        $this->assertStringNotContainsString('.style.', $script);
        $this->assertStringNotContainsString('innerHTML = "<', $script, 'pictures and thumbnails are built as elements');

        preg_match_all('/\[data-gallery-transition="([^"]+)"\]/', $css, $words);
        $this->assertSame(['fade', 'slide'], array_values(array_unique($words[1])), '"none" has no rule: it simply swaps');
    }

    public function testEveryWayToChangePictureEndsInTheSameShow(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');

        $this->assertStringContainsString('function show(target, direction, animate) {', $script);
        // A thumbnail: the direction is the difference in index.
        $this->assertStringContainsString('show(target, target - index, true);', $script);
        // A swipe and an arrow key: one step, through step().
        $this->assertMatchesRegularExpression('/function step\(delta\) \{\s*if \(images\.length < 2\) return;\s*show\(index \+ delta, delta, true\);\s*\}/', $script);
        $this->assertStringContainsString('step(dx < 0 ? 1 : -1);', $script, 'a finger moving left brings the next picture');
        $this->assertStringContainsString('step(event.key === "ArrowRight" ? 1 : -1);', $script);
        // A new variant: the index kept, animated after the page has loaded.
        $this->assertStringContainsString('show(target, target < previous ? -1 : 1, animate);', $script);
        // The CSS moves the incoming picture in from the side it comes from.
        $css = self::source('assets/css/shop/shop.css');
        $this->assertMatchesRegularExpression('/\.is-entering\[data-direction="next"\],\s*\[data-gallery-transition="slide"\] \.product-detail__main-img\.is-leaving\[data-direction="prev"\]\{ transform: translateX\(100%\); \}/', $css);
        $this->assertMatchesRegularExpression('/\.is-entering\[data-direction="prev"\],\s*\[data-gallery-transition="slide"\] \.product-detail__main-img\.is-leaving\[data-direction="next"\]\{ transform: translateX\(-100%\); \}/', $css);
    }

    /** Swiping asks for a picture; it never takes the page's scrolling away. */
    public function testASwipeIsATouchGestureThatNeverBlocksScrolling(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');

        $this->assertStringContainsString('if (window.PointerEvent) {', $script);
        $this->assertStringContainsString('event.pointerType === "mouse" || !event.isPrimary', $script);
        $this->assertStringContainsString('stage.addEventListener("pointercancel", function () { start = null; });', $script);
        $this->assertStringContainsString('var SWIPE_DISTANCE = 50;', $script);
        $this->assertStringContainsString('Math.abs(dx) >= SWIPE_DISTANCE && Math.abs(dx) > Math.abs(dy) * SWIPE_RATIO', $script);
        $this->assertStringNotContainsString('touchmove', $script);
        $this->assertStringNotContainsString('pointermove', $script);
        // The one preventDefault() in code is the arrow keys on a thumbnail, not a gesture.
        $this->assertSame(1, substr_count($script, 'event.preventDefault();'));
        $this->assertMatchesRegularExpression('/if \(event\.key !== "ArrowRight" && event\.key !== "ArrowLeft"\) return;[\s\S]{0,200}event\.preventDefault\(\);/', $script);
    }

    /** A change waits for its picture, so the stage never flashes empty or changes size. */
    public function testAChangeWaitsForItsPictureAndLeavesOnePictureBehind(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');

        $this->assertStringContainsString('img.decode().then(go, go);', $script);
        $this->assertStringContainsString('var LOAD_WAIT = 400;', $script);
        $this->assertStringContainsString('if (token !== swapToken) return; // a later change won', $script);
        $this->assertStringContainsString('function settle() {', $script);
        $this->assertStringContainsString('animation.finish();', $script);
        // Width and height travel with the picture, and its words.
        $this->assertStringContainsString('img.width = width;', $script);
        $this->assertStringContainsString('img.alt = altOf(image);', $script);
    }

    public function testTheDescriptionIsRichTextAndFollowsTheVariant(): void
    {
        $template = self::source('product.php');
        $script = self::source('assets/js/shop/shop.js');

        $this->assertStringContainsString('<div class="product-detail__desc rich-content" data-product-description hidden></div>', $template);
        $this->assertStringContainsString('showDescription(selectedVariant && selectedVariant.description ? selectedVariant.description : product.description);', $script);
    }

    public function testRichTextLinksLookLikeLinksWithoutTouchingButtons(): void
    {
        $css = self::source('assets/css/core.css');

        $this->assertMatchesRegularExpression('/\.rich-content a:not\(\.btn\)\{[^}]*text-decoration: underline;/', $css);
        $this->assertStringContainsString('.rich-content a:not(.btn):focus-visible{', $css);
        $this->assertStringNotContainsString('.rich-content a{', $css, 'a button-styled link keeps its own look');
    }

    /* ------------------------------------------------------ the product editor */

    public function testTheEditorsPicturesPostNothingOfTheirOwn(): void
    {
        $script = self::source('admin/assets/product-gallery.js');

        $this->assertStringNotContainsString('fetch(', $script);
        $this->assertStringNotContainsString('XMLHttpRequest', $script);
        $this->assertStringNotContainsString('.submit(', $script);
        $this->assertStringContainsString('"media-picker:choose"', $script);
    }

    public function testArrowsAndDragChangeTheSameOrder(): void
    {
        $script = self::source('admin/assets/product-gallery.js');
        $partial = self::source('admin/_product_gallery.php');

        $this->assertStringContainsString('data-gallery-move="-1"', $partial);
        $this->assertStringContainsString('data-gallery-move="1"', $partial);
        $this->assertStringContainsString('&larr;', $partial);
        $this->assertStringContainsString('&rarr;', $partial);
        $this->assertStringNotContainsString('&uarr;', $partial);

        foreach (['"dragstart"', '"dragover"', '"dragend"'] as $event) {
            $this->assertStringContainsString($event, $script);
        }
        // Both paths end in the same setter, and both tell the form it changed.
        $this->assertSame(2, substr_count($script, 'setTokens(order)') + substr_count($script, 'setTokens(tokens)'));
        $this->assertStringContainsString('marker.dispatchEvent(new Event("change", { bubbles: true }));', $script);
        // A move is said out loud and keeps the keyboard where it was.
        $this->assertStringContainsString('role="status" aria-live="polite" data-gallery-status', $partial);
        $this->assertStringContainsString('.focus();', $script);
    }

    public function testThePickerHandsAListEveryChosenOrUploadedItem(): void
    {
        $picker = self::source('admin/assets/media-picker.js');

        $this->assertStringContainsString('field.hasAttribute("data-media-picker-collect")', $picker);
        $this->assertStringContainsString('uploadInput.multiple = collects(field);', $picker);
        // Media Library 2.0: chosen and uploaded items are handed over in ONE
        // place, "Selecteren" (confirmSelection()), each as its own event and
        // in the order they were chosen — never on a click or an upload alone.
        $this->assertSame(1, substr_count($picker, 'new CustomEvent("media-picker:choose"'));
        $this->assertMatchesRegularExpression(
            '/function confirmSelection\(\)[\s\S]*items\.forEach\(function \(item\) \{\s*field\.dispatchEvent\(new CustomEvent\("media-picker:choose"/',
            $picker
        );
    }

    public function testTheEditorHasNoShopUploadAndNoPerPictureEndpointLeft(): void
    {
        $form = self::source('admin/product-form.php');

        $this->assertStringNotContainsString('name="images[]"', $form);
        foreach ([
            'add-product-images.php', 'delete-product-image.php', 'move-product-image.php',
            'set-primary-product-image.php', 'add-variant-images.php', 'delete-variant-image.php',
            'reorder-variant-images.php', 'update-variant-image.php',
        ] as $endpoint) {
            $this->assertStringNotContainsString($endpoint, $form);
            $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/api/admin/' . $endpoint);
        }

        $this->assertStringContainsString('product_gallery_pool($galleryPictures);', $form);
        // One save for the whole editor, pictures included (admin/_admin_editor.php).
        $this->assertStringContainsString('<?php admin_editor_bar(); ?>', $form);
        $this->assertStringContainsString('<?php media_picker_modal(); ?>', $form);
    }

    public function testTheCollectionEditorUsesTheLibraryAndOffersEditOnlyToThoseWhoMay(): void
    {
        $screen = self::source('admin/collection.php');

        $this->assertStringContainsString("media_picker_field('media_id'", $screen);
        $this->assertStringNotContainsString('name="image"', $screen);
        $this->assertStringContainsString("\$canEditProducts = AdminAuth::can('products.manage');", $screen);
        $this->assertMatchesRegularExpression(
            '#<\?php if \(\$canEditProducts\): \?>\s*<a class="admin-btn-text admin-collection-product-row__edit" href="/admin/product-form\.php\?id=<\?= \$productId \?>"#',
            $screen
        );
        $this->assertStringContainsString('<?php save_bar(); ?>', $screen);
    }
}
