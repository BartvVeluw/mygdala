<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminLocale;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use PHPUnit\Framework\TestCase;

/**
 * THE CONTRACTS OF RESPONSIVE MEDIA 3.0 (focus and zoom; MEDIA.md
 * "Responsive Media", ADMIN-UI.md "Afbeeldingsweergave"), read from the
 * source, without a database or a server:
 *
 *   - ONE RENDERING: only partials/responsive-image.php prints a zoom (the CSS
 *     `scale` around the point), a phone's zoom has one rule under the one
 *     breakpoint (and the Kaarten-carrousel's own compact width), and every
 *     frame a zoomed picture can sit in clips it, so a zoom never grows a
 *     frame or runs over what is around it;
 *   - ONE EDITOR: the shared field carries the zoom slider (100-200, named
 *     after its column), its value, a reset button the script shows, and a
 *     zoom row that "Hele afbeelding" hides without dropping it;
 *   - A LIGHT EDITOR: a preview is lazy, a field wakes only near the screen
 *     (IntersectionObserver, data-rm-ready), the listeners are a fixed set on
 *     the document whatever the number of fields, a removed row lets go of
 *     its fields, a drag draws once per animation frame, and every block
 *     editor passes the library's thumbnail rather than the original.
 */
final class ResponsiveMediaZoomContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Every frame a picture with a focus point (and so a zoom) is printed in,
     * by place: the stylesheet and the rule of the element that clips it.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const FRAMES = [
        'carousel_cards' => [['assets/css/blocks/card-carousel.css', '.orbit-card__media']],
        'text_image_split_items' => [['assets/css/blocks/text-image-split.css', '.text-image__media']],
        'page_heroes' => [['assets/css/blocks/page-hero.css', '.page-hero__media'], ['assets/css/blocks/page-hero.css', '.page-hero__figure']],
        'cta_bands' => [['assets/css/blocks/cta-band.css', '.cta-band__media']],
        'media_banners' => [['assets/css/blocks/media-banner.css', '.media-banner'], ['assets/css/media-sequence.css', '.media-sequence']],
        'hover_card_grid_items' => [['assets/css/blocks/hover-card-grid.css', '.hover-card__frame']],
        'homepage_hero' => [['assets/css/core.css', '.hero__media-frame']],
        'detail_section_images' => [['assets/css/blocks/detail-section.css', '.service-detail__gallery-item']],
        'review_block_items' => [['assets/css/blocks/reviews.css', '.review__portrait']],
    ];

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::ROOT . '/' . $relative);
        self::assertIsString($contents, $relative);

        return str_replace("\r\n", "\n", $contents);
    }

    /** The declarations of the first top-level rule for exactly this selector. */
    private static function rule(string $css, string $selector): string
    {
        $found = preg_match('/^' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $match);
        self::assertSame(1, $found, $selector);

        return $match[1];
    }

    // ------------------------------------------------------------ rendering

    public function testEveryFrameAZoomedPictureCanSitInClipsIt(): void
    {
        foreach (self::FRAMES as $place => $frames) {
            foreach ($frames as [$file, $selector]) {
                self::assertStringContainsString('overflow: hidden', self::rule(self::read($file), $selector), $place . ': ' . $file . ' ' . $selector);
            }
        }

        // The Detailsectie's square is the item itself now, and the picture a
        // bare square inside it: no border, shadow or radius that a scale
        // would enlarge along with it.
        $detail = self::read('assets/css/blocks/detail-section.css');
        self::assertStringContainsString('border: 0; border-radius: 0; box-shadow: none;', self::rule($detail, '.service-detail__gallery-item img'));
        self::assertStringContainsString('border-radius: var(--radius-md)', self::rule($detail, '.service-detail__gallery-item'));
        self::assertStringContainsString('.service-detail__gallery-item:has(.service-detail__gallery-link:focus-visible){ outline:', $detail, 'a linked item\'s focus ring is the item\'s, outside the clip');
    }

    public function testOnlyTheSharedPartialPrintsAZoom(): void
    {
        $printers = [];
        $files = array_merge(glob(self::ROOT . '/partials/*.php') ?: [], glob(self::ROOT . '/*.php') ?: []);
        foreach ($files as $path) {
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen(self::ROOT))), '/');
            if (preg_match('/[\'"]scale: |transform-origin: /', self::read($relative)) === 1) {
                $printers[] = $relative;
            }
        }
        self::assertSame(['partials/responsive-image.php'], $printers);

        // No block stylesheet scales a picture by a zoom of its own; only the
        // phone rules switch the one custom property on.
        foreach (glob(self::ROOT . '/assets/css/blocks/*.css') ?: [] as $path) {
            $css = self::read('assets/css/blocks/' . basename($path));
            preg_match_all('/scale:\s*var\(([^)]*)\)/', $css, $uses);
            self::assertSame([], array_diff(array_unique($uses[1]), ['--rm-mobile-zoom']), basename($path));
        }

        // The carousel's compact width switches the phone's zoom as the general rule does.
        $carousel = self::read('assets/css/blocks/card-carousel.css');
        self::assertStringContainsString('.orbit-card__media img[data-rm-mobile-zoom]{ scale: var(--rm-mobile-zoom) !important; }', $carousel);
        self::assertStringContainsString('.orbit-card__media img[data-rm-mobile-position]{ object-position: var(--rm-mobile-position) !important; transform-origin: var(--rm-mobile-position) !important; }', $carousel);
    }

    // ------------------------------------------------------------- the editor

    public function testTheFieldCarriesTheZoomSliderItsValueAndAResetTheScriptShows(): void
    {
        $slot = new ResponsiveImageSlot('image_', 'media_id', fit: true);

        $cover = $this->field($slot, new ResponsiveImage(20, 80, zoom: 150));
        self::assertMatchesRegularExpression('#<input type="range" id="t-desktop-zoom" name="image_zoom" min="100" max="200" step="1" value="150" aria-valuetext="150%" data-rm-axis="zoom">#', $cover);
        self::assertStringContainsString('<output class="admin-rm__zoom-value" for="t-desktop-zoom" data-rm-zoom-value>150%</output>', $cover);
        self::assertStringContainsString('<span>Zoom</span>', $cover);
        self::assertStringContainsString('<p class="admin-rm__reset" data-rm-reset-row hidden><button type="button" class="admin-btn-text" data-rm-reset>Afbeelding resetten</button></p>', $cover, 'shown by the script, which is what makes it work');
        self::assertStringContainsString('data-rm-zoom-template=":zoom%"', $cover);
        // The preview is the page's crop: scaled around the point.
        self::assertStringContainsString('loading="lazy" decoding="async" data-rm-preview style="object-position: 20% 80%; scale: 1.5; transform-origin: 20% 80%;"', $cover);
        self::assertStringContainsString('<div class="admin-rm__focus" data-rm-focus="desktop">', $cover, 'cover: the zoom row shows');

        // Contain: the frame steps back, the zoom row hides, the value stays and is posted.
        $contain = $this->field($slot, new ResponsiveImage(20, 80, fit: ResponsiveImage::FIT_CONTAIN, zoom: 150));
        self::assertStringContainsString('<div class="admin-rm__focus is-contained" data-rm-focus="desktop">', $contain);
        self::assertStringContainsString('name="image_zoom" min="100" max="200" step="1" value="150"', $contain);
        self::assertStringContainsString('style="object-position: 20% 80%; object-fit: contain;"', $contain, 'no zoom on a contained preview');
        self::assertStringContainsString('.admin-rm__focus.is-contained .admin-rm__zoom{ display: none; }', self::read('admin/assets/admin.css'));

        // A slot's own names: the Oproep's background.
        $background = $this->field(new ResponsiveImageSlot('background_', 'background_media_id'), new ResponsiveImage());
        self::assertStringContainsString('name="background_zoom" min="100" max="200" step="1" value="100"', $background);
        self::assertStringContainsString('style="object-position: 50% 50%;"', $background, 'no zoom: the preview it always had');
    }

    public function testTheScriptDrawsTheZoomAsThePageDoesAndDragsTheZoomedPicture(): void
    {
        $script = self::read('admin/assets/responsive-image.js');

        self::assertStringContainsString('preview.style.scale = ', $script);
        self::assertStringContainsString('preview.style.transformOrigin = ', $script);
        self::assertStringContainsString('var ZOOM_MIN = ' . ResponsiveImage::ZOOM_MIN . ';', $script);
        self::assertStringContainsString('var ZOOM_MAX = ' . ResponsiveImage::ZOOM_MAX . ';', $script);
        // The room a drag has is the zoomed picture's overflow.
        self::assertMatchesRegularExpression('/var scale = Math\.max\(box\.width \/ image\.naturalWidth, box\.height \/ image\.naturalHeight\) \* zoom;/', $script);
        // Reset: the middle, unzoomed, as one ordinary edit.
        self::assertStringContainsString('setPoint(focus, 50, 50, true, ZOOM_MIN);', $script);
    }

    // ------------------------------------------------------------ performance

    public function testAFieldWakesOnlyNearTheScreenAndTheListenersAreAFixedSet(): void
    {
        $script = self::read('admin/assets/responsive-image.js');
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $script);

        // Lazy: observed, woken once, never drawn all at load.
        self::assertStringContainsString('new window.IntersectionObserver(', $code);
        self::assertStringContainsString('field.setAttribute("data-rm-ready", "");', $code);
        self::assertStringContainsString('if (observer) observer.unobserve(field);', $code);
        self::assertStringContainsString('document.querySelectorAll("[data-rm]").forEach(watch);', $code);
        self::assertStringNotContainsString('document.querySelectorAll("[data-rm]").forEach(drawAll)', $code);
        // A browser without it wakes every field, as the script always did.
        self::assertMatchesRegularExpression('/if \(observer\) \{\s*observer\.observe\(field\);\s*\} else \{\s*wake\(field\);/', $code);

        // Delegated: a fixed set of listeners on the document, whatever the
        // number of fields; no listener on a field, a frame or a picture.
        preg_match_all('/(\w+(?:\.\w+)*)\.addEventListener\("([\w:-]+)"/', $code, $listeners);
        self::assertSame(array_fill(0, count($listeners[1]), 'document'), $listeners[1]);
        self::assertSame(['focusin', 'pointerdown', 'pointermove', 'pointerup', 'pointercancel', 'click', 'input', 'change', 'rm:picture', 'row-list:added', 'row-list:removed'], $listeners[2]);

        // A removed row lets go of its fields, said while it is still there.
        self::assertStringContainsString('observer.unobserve(rm)', $code);
        $rowList = self::read('admin/assets/row-list.js');
        $removed = strpos($rowList, 'row.dispatchEvent(new CustomEvent("row-list:removed", { bubbles: true }));');
        self::assertNotFalse($removed);
        self::assertLessThan(strpos($rowList, 'list.removeChild(row);'), $removed);

        // A drag draws at most once per animation frame.
        self::assertStringContainsString('drag.frameRequest = window.requestAnimationFrame(', $code);
        self::assertSame(1, substr_count($code, 'setPoint(drag.focus'), 'pointermove itself never draws');
    }

    public function testEveryPreviewIsLazyAndEveryBlockEditorPassesTheThumbnail(): void
    {
        $field = self::read('admin/_responsive_image_field.php');
        self::assertSame(1, substr_count($field, '<img '), 'one preview markup for every frame');
        self::assertStringContainsString('<img src="<?= $h($editor[\'preview\']) ?>" alt="" draggable="false" loading="lazy" decoding="async" data-rm-preview', $field);

        // What each block editor gives the field: the library's own preview
        // (MediaItem::displayPath(), the 480px thumbnail where there is one),
        // a linked item's preview_path, or a pre-library path that has nothing smaller.
        $editors = [
            'admin/carousel-card.php' => "'preview' => \$cardMedia !== null && !\$cardMedia->isVideo() ? \$cardMedia->displayPath() :",
            'admin/cta-band.php' => "'preview' => \$background !== null ? \$background->displayPath() : '',",
            'admin/homepage-hero.php' => "'preview' => \$heroMedia !== null ? \$heroMedia->displayPath() :",
            'admin/hover-card-grid.php' => "'preview' => \$mainMedia !== null ? \$mainMedia->displayPath() : '',",
            'admin/media-banner.php' => "'preview' => \$isImage ? \$media->displayPath() : '',",
            'admin/page-hero.php' => "'preview' => \$heroMedia !== null ? \$heroMedia->displayPath() : '',",
            'admin/text-image-split.php' => "\$previewSrc = \$media !== null ? \$media->displayPath() : \$legacyPath;",
            'admin/detail-section.php' => "->displayPath()\n        : (string) (\\App\\Service\\Media\\LinkedImages::resolve(\$source, (int) (\$fields['source_' . \$source] ?? 0))['preview_path'] ?? '');",
        ];
        foreach ($editors as $file => $line) {
            self::assertStringContainsString($line, self::read($file), $file);
        }
        self::assertStringContainsString("'src' => \$image['preview_path'] ?? '',", self::read('api/admin/linked-image-preview.php'), 'a linked item chosen on screen: its preview too');
    }

    /** One field rendered as a block editor prints it, without its phone part. */
    private function field(ResponsiveImageSlot $slot, ResponsiveImage $value): string
    {
        require_once self::ROOT . '/admin/_responsive_image_field.php';
        AdminLocale::overrideForTests('nl');

        try {
            ob_start();
            responsive_image_field(['slot' => $slot, 'value' => $value, 'id' => 't', 'preview' => '/assets/media/thumbs/a.jpg', 'mobile' => false]);

            return str_replace("\r\n", "\n", (string) ob_get_clean());
        } finally {
            AdminLocale::overrideForTests(null);
        }
    }
}
