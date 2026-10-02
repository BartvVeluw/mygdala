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

    /**
     * Product Gallery 2.1: a thumbnail button loads the picture's small
     * version (App\Service\ProductDetail::picture()), falling back to the
     * original for a payload without one; the big picture and the "already
     * on show" check keep the original.
     */
    public function testThumbnailsUseTheSmallVersionAndTheStageTheOriginal(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');

        $this->assertMatchesRegularExpression(
            '/function thumbSrcOf\(image\) \{\s*return rootPath\(image\.thumbnail_path \|\| image\.image_path\);\s*\}/',
            $script
        );
        $this->assertMatchesRegularExpression(
            '/function srcOf\(image\) \{\s*return rootPath\(image\.image_path\);\s*\}/',
            $script,
            'the big picture is the original'
        );
        $this->assertMatchesRegularExpression('/function renderThumbs\(\) \{[\s\S]*?img\.src = thumbSrcOf\(image\);/', $script);
        $this->assertMatchesRegularExpression('/function pictureElement\(image\) \{[\s\S]*?img\.src = srcOf\(image\);/', $script);
        $this->assertSame(1, substr_count($script, 'thumbSrcOf(image);'), 'only the thumbnail row uses the small version');
    }

    /**
     * Product Gallery 2.1: the lightbox is opt-in per page. Without
     * data-gallery-lightbox (the server's default) the gallery adds no role,
     * no tab stop and no click; with it, the stage is a button that opens the
     * site's one lightbox on the pictures on show NOW, at the one on the
     * stage — so a variant switch decides the sequence, and a picture of
     * another variant can never be in it.
     */
    public function testTheLightboxIsOptInAndOpensThePicturesOnShow(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');

        $this->assertStringContainsString('var lightboxOn = !!(root && root.hasAttribute("data-gallery-lightbox"));', $script);
        $this->assertMatchesRegularExpression('/function updateOpener\(image\) \{\s*if \(!lightboxOn\) return;/', $script, 'off: the stage gets nothing');
        $this->assertMatchesRegularExpression('/if \(lightboxOn\) \{\s*stage\.addEventListener\("click"/', $script, 'off: no click handler at all');
        $this->assertSame(1, substr_count($script, 'stage.setAttribute("role", "button");'));
        $this->assertStringContainsString('stage.setAttribute("aria-haspopup", "dialog");', $script);

        // The sequence is `images` — the list setImages() was last handed by
        // shop.js (the variant's, or the product's general pictures) — built
        // at the moment of opening, and it opens on the current index.
        $this->assertMatchesRegularExpression(
            '/function openLightbox\(\) \{[\s\S]*?api\.open\(images\.map\(function \(image\) \{\s*return \{ src: srcOf\(image\), alt: altOf\(image\), caption: "" \};\s*\}\), index, stage\);/',
            $script
        );
        $this->assertStringContainsString('var api = window.VVLLightbox;', $script, 'the site\'s one lightbox, not a second one');
        $this->assertStringNotContainsString('data-lightbox-trigger', $script, 'no triggers of its own in the page');

        // Enter and Space open it from the keyboard; a swipe is never also a click.
        $this->assertStringContainsString('event.key !== "Enter" && event.key !== " "', $script);
        $this->assertMatchesRegularExpression('/swiped = true;\s*step\(dx < 0 \? 1 : -1\);/', $script);
        $this->assertMatchesRegularExpression('/if \(swiped\) \{\s*swiped = false;\s*return;\s*\}\s*openLightbox\(\);/', $script);

        // Each gallery opens with its own stage as the opener, so several on
        // one page never share state, and the one place that decides which
        // pictures a variant shows stays shop.js.
        $this->assertStringContainsString('text: S.text', self::source('assets/js/shop/shop.js'));
        $this->assertStringContainsString('.product-detail__media[data-lightbox-opener]{ cursor: zoom-in; }', self::source('assets/css/shop/shop.css'));
    }

    /**
     * Product Gallery 2.1: a change the visitor makes is announced in a
     * visually hidden status region of its own gallery ("Afbeelding 2 van
     * 5"), in the page's language through the Shop's catalogue; the page
     * loading is not announced, and a single picture says nothing.
     */
    public function testAPictureChangeIsAnnouncedButThePageLoadingIsNot(): void
    {
        $script = self::source('assets/js/shop/product-gallery.js');

        $this->assertMatchesRegularExpression(
            '/status = document\.createElement\("p"\);\s*status\.className = "visually-hidden";\s*status\.setAttribute\("role", "status"\);\s*status\.setAttribute\("aria-atomic", "true"\);/',
            $script
        );
        $this->assertStringContainsString('root.appendChild(status);', $script, 'one region per gallery');
        $this->assertStringContainsString('text("gallery_position", { index: index + 1, count: images.length })', $script, '1-based for people, 0-based inside');
        $this->assertMatchesRegularExpression('/function announce\(changed\) \{\s*if \(!status\) return;\s*if \(images\.length < 2\) \{\s*status\.textContent = "";\s*return;\s*\}\s*if \(!changed\) return;/', $script);
        $this->assertStringContainsString('announce(animate);', $script, 'only a change (animate) is announced: setImages(..., false) on load is not');
        $this->assertStringNotContainsString('innerHTML = sentence', $script);
    }

    /**
     * The shared lightbox keeps its trigger contract and gains only an
     * optional open() for a script with its own sequence; both end in the
     * same show().
     */
    public function testTheSharedLightboxKeepsItsTriggersAndOnlyAddsOpen(): void
    {
        $script = self::source('assets/js/lightbox.js');

        $this->assertStringContainsString('var trigger = event.target.closest ? event.target.closest("[data-lightbox-trigger]") : null;', $script);
        $this->assertStringContainsString('open(trigger);', $script);
        $this->assertMatchesRegularExpression('/function open\(trigger\) \{[\s\S]*?show\(triggers\.map\(function \(t\) \{[\s\S]*?\}\), triggers\.indexOf\(trigger\), trigger\);\s*\}/', $script);
        $this->assertMatchesRegularExpression('/function show\(list, at, from\) \{\s*slides = list;\s*current = at;\s*opener = from;\s*render\(\);/', $script);
        $this->assertMatchesRegularExpression('/window\.VVLLightbox = \{\s*open: function \(pictures, index, from\) \{/', $script);
        $this->assertStringContainsString('if (list.length === 0) return false;', $script, 'nothing to show opens nothing');
        $this->assertStringContainsString('show(list, at >= 0 && at < list.length ? at : 0, from || null);', $script, 'an index out of range opens the first');
        // Defined inside init(), after the overlay was found: a page without
        // the overlay has no API to call.
        $this->assertLessThan(
            strpos($script, 'window.VVLLightbox = {'),
            strpos($script, 'if (!overlay) return;')
        );
        $this->assertStringNotContainsString('innerHTML', $script);
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
        // Plus data-gallery-lightbox only when the Shop's switch is on (Product Gallery 2.1).
        $this->assertStringContainsString('<div class="product-detail__gallery" data-product-gallery data-gallery-transition="<?= htmlspecialchars($galleryTransition, ENT_QUOTES, \'UTF-8\') ?>"<?= $galleryLightbox ? \' data-gallery-lightbox\' : \'\' ?>>', $template);
        $this->assertStringContainsString('$galleryLightbox = $seo !== null && \App\Service\ProductGalleryLightbox::enabled();', $template);
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
        // The two preventDefault() calls in code are keys, never a gesture:
        // the arrow keys on a thumbnail, and Enter/Space on the stage that
        // opens the lightbox (Product Gallery 2.1, only with it switched on).
        $this->assertSame(2, substr_count($script, 'event.preventDefault();'));
        $this->assertMatchesRegularExpression('/if \(event\.key !== "ArrowRight" && event\.key !== "ArrowLeft"\) return;[\s\S]{0,200}event\.preventDefault\(\);/', $script);
        $this->assertMatchesRegularExpression('/event\.key !== "Enter" && event\.key !== " "\)\) return;[\s\S]{0,120}event\.preventDefault\(\);\s*openLightbox\(\);/', $script);
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

    /**
     * Pictures meant for variants only (v0.1.15 phase 12.1): their own list
     * and field, a way between the two lists that keeps the ticks, and a
     * variant's own "add" that makes a new picture variant-only.
     */
    public function testVariantOnlyPicturesHaveTheirOwnListAndWayBack(): void
    {
        $script = self::source('admin/assets/product-gallery.js');
        $partial = self::source('admin/_product_gallery.php');

        $this->assertStringContainsString('name="gallery_variant_only_submitted" value="1"', $partial);
        $this->assertStringContainsString('name="gallery_variant_only[]"', $partial);
        $this->assertStringContainsString('input.name = "gallery_variant_only[]";', $script);
        $this->assertStringContainsString('data-variant-gallery-add', $partial);

        // Moving between the lists never touches a variant's ticks; removing does.
        $this->assertMatchesRegularExpression('/function setVariantOnly\(token, variantOnly\) \{(?:(?!variant\.tokens)[\s\S])*?\n    \}/', $script);
        $this->assertMatchesRegularExpression('/function removePicture\(token\) \{[\s\S]*?variant\.tokens = variant\.tokens\.filter/', $script);

        // "Alleen voor varianten" only while the product has a variant to show it.
        $this->assertStringContainsString('if (!variantOnlyList || liveVariants().length === 0) return;', $script);
        // Hoofdfoto and the section's count follow the general pictures only.
        $this->assertStringContainsString('renderStrip(list, general, inputName, firstBadge, variantOnlyButton);', $script);
        $this->assertStringContainsString('count.textContent = String(general.length);', $script);
    }

    /**
     * After a save the editor draws its sections again one by one, and each
     * redraw starts the gallery again from what is on the page — possibly
     * cards this script drew itself. Those carry what the server's carry, or
     * the second start would read pictures without a source.
     */
    public function testACardTheScriptDrawsDescribesItselfLikeTheServersCard(): void
    {
        $script = self::source('admin/assets/product-gallery.js');

        $this->assertMatchesRegularExpression('/function describe\(item, picture\) \{\s*item\.setAttribute\("data-token", picture\.token\);\s*item\.setAttribute\("data-src", picture\.src \|\| ""\);\s*item\.setAttribute\("data-name", picture\.name \|\| ""\);/', $script);
        $this->assertSame(2, substr_count($script, 'describe(item, picture);'), 'both the strips and the variants-only list');
        $this->assertStringContainsString('src: item.getAttribute("data-src") || ""', $script);
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
