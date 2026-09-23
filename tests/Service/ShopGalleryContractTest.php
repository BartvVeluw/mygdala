<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * What the Shop UX phase promises about its scripts and styles, read from the
 * files themselves (no browser here; the browser acceptance is in the phase
 * report). Two groups:
 *
 *   - the product page's gallery (assets/js/shop/shop.js, shop.css): the
 *     position is a 0-based INDEX kept across variants, the selected
 *     thumbnail has its own lasting state, and every motion respects
 *     prefers-reduced-motion;
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
        $script = self::source('assets/js/shop/shop.js');

        $this->assertMatchesRegularExpression(
            '/function nextGalleryIndex\(index, length\) \{\s*return index >= 0 && index < length \? index : 0;\s*\}/',
            $script,
            'same position when the new list has it, else the first picture — both 0-based'
        );
        $this->assertStringContainsString('var index = nextGalleryIndex(galleryIndex, galleryImages.length);', $script);
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
        $script = self::source('assets/js/shop/shop.js');
        $css = self::source('assets/css/shop/shop.css');

        $this->assertStringContainsString('btn.setAttribute("aria-current", "true");', $script);
        $this->assertStringContainsString('.product-detail__thumb.is-active{', $css);
        $this->assertMatchesRegularExpression('/\.product-detail__thumb:hover,\s*\.product-detail__thumb:focus-visible\{/', $css);
    }

    public function testMotionIsShortAndRespectsReducedMotion(): void
    {
        $script = self::source('assets/js/shop/shop.js');
        $css = self::source('assets/css/shop/shop.css');

        $this->assertStringContainsString('(prefers-reduced-motion: reduce)', $script);
        $this->assertStringContainsString('prefersReducedMotion()', $script);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\{\s*\.product-detail__thumb,\s*\.product-detail__main-img\{ transition: none; \}/',
            $css
        );
        // The frame keeps its square: the picture fits inside it.
        $this->assertMatchesRegularExpression('/\.product-detail__main-img\{[^}]*position: absolute; inset: 0;[^}]*object-fit: contain;/', $css);
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
        $this->assertSame(2, substr_count($picker, 'new CustomEvent("media-picker:choose"'));
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
        $this->assertStringContainsString('<?php save_bar(); ?>', $form);
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
