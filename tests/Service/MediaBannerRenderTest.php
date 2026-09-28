<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockPreview;
use App\Service\Blocks\BlockSamples;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Service\MediaBannerContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-media-banner.php';

/**
 * Mediabanner's render contract, on the read model and the partial alone (no
 * database: the library items come from MediaService::overrideForTests()):
 *
 *   - the library item decides: a picture renders an <img>, a video a
 *     <video>, and anything else (a document, an unknown kind, an id that is
 *     gone, no id) renders nothing at all, like STATE_HIDDEN and
 *     STATE_FALLBACK;
 *   - the width and the height are classes from closed lists, an unknown word
 *     is the default, and the only inline style is a picture's
 *     object-position from ImageFocus;
 *   - a picture's alt text is the library's, empty is decorative;
 *   - the video contract: playsinline and preload="metadata" always, autoplay
 *     only with muted, never without a way to start it, a poster only for a
 *     video and only a picture, the focus point never on a video;
 *   - assets/css/blocks/media-banner.css: a rule for every height, cover,
 *     no viewport-width trick, no radius across the page; the block script
 *     acts only for reduced motion;
 *   - the picker filter over both kinds (MediaType::VISUAL).
 */
final class MediaBannerRenderTest extends TestCase
{
    private const PICTURE = 71;
    private const VIDEO = 72;
    private const DOCUMENT = 73;
    private const SVG = 74;
    private const WEBM = 75;
    private const UNKNOWN = 76;

    protected function setUp(): void
    {
        MediaService::overrideForTests([
            self::PICTURE => ['path' => 'assets/media/foto.jpg', 'mime_type' => 'image/jpeg', 'width' => 2400, 'height' => 1600, 'alt_text' => 'Werkbank met gereedschap'],
            self::VIDEO => ['path' => 'assets/media/film.mp4', 'mime_type' => 'video/mp4', 'alt_text' => ''],
            self::DOCUMENT => ['path' => 'assets/media/prijzen.pdf', 'mime_type' => 'application/pdf', 'alt_text' => ''],
            self::SVG => ['path' => 'assets/media/vorm.svg', 'mime_type' => 'image/svg+xml', 'width' => 800, 'height' => 400, 'alt_text' => ''],
            self::WEBM => ['path' => 'assets/media/film.webm', 'mime_type' => 'video/webm', 'alt_text' => ''],
            self::UNKNOWN => ['path' => 'assets/media/oud.bin', 'mime_type' => '', 'alt_text' => ''],
            999 => null,
        ]);
    }

    protected function tearDown(): void
    {
        MediaService::overrideForTests(null);
        MediaBannerContent::clearCache();
    }

    // ------------------------------------------------------------ the item

    public function testAPictureRendersAnImageWithTheLibrarysAltText(): void
    {
        $content = $this->content(['media_id' => self::PICTURE]);
        self::assertSame(MediaType::IMAGE, $content['kind']);

        $html = $this->render($content);
        self::assertStringContainsString('<img class="media-banner__media" src="/assets/media/foto.jpg" alt="Werkbank met gereedschap" width="2400" height="1600" loading="lazy" decoding="async">', $html);
        self::assertStringNotContainsString('<video', $html);
        self::assertStringNotContainsString('style=', $html, 'the centre is the default, so no inline style');
    }

    public function testAPictureWithoutAltTextIsDecorative(): void
    {
        $html = $this->render($this->content(['media_id' => self::SVG]));

        self::assertStringContainsString('src="/assets/media/vorm.svg" alt=""', $html, 'an SVG is a picture like any other');
    }

    public function testAVideoRendersANativeVideoElement(): void
    {
        foreach ([self::VIDEO => '/assets/media/film.mp4', self::WEBM => '/assets/media/film.webm'] as $id => $src) {
            $content = $this->content(['media_id' => $id]);
            self::assertSame(MediaType::VIDEO, $content['kind']);

            $html = $this->render($content);
            self::assertStringContainsString('<video class="media-banner__media" src="' . $src . '" playsinline preload="metadata" controls></video>', $html, 'the defaults: controls on, no autoplay, no loop');
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('<iframe', $html);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function nothingToShow(): iterable
    {
        yield 'no item chosen yet' => [['media_id' => null]];
        yield 'an item that is gone' => [['media_id' => 999]];
        yield 'a document' => [['media_id' => self::DOCUMENT]];
        yield 'a file no kind claims' => [['media_id' => self::UNKNOWN]];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('nothingToShow')]
    public function testAnythingButAPictureOrAVideoRendersNothing(array $row): void
    {
        $content = $this->content($row);

        self::assertSame('', $content['kind']);
        self::assertSame('', trim($this->render($content)), 'no empty frame and no room');
    }

    public function testAHiddenOrMissingBlockRendersNothing(): void
    {
        foreach ([MediaBannerContent::STATE_HIDDEN, MediaBannerContent::STATE_FALLBACK] as $state) {
            $content = ['state' => $state] + MediaBannerContent::fromRow(['media_id' => self::PICTURE]);
            self::assertSame('', trim($this->render($content)), $state);
        }
    }

    // ---------------------------------------------------------- the layout

    public function testContentWidthSitsInTheContainerAndFullWidthInTheSection(): void
    {
        $content = $this->render($this->content(['media_id' => self::PICTURE]));
        self::assertMatchesRegularExpression('#<section class="media-banner-section media-banner-section--content">\s*<div class="container">\s*<div class="media-banner media-banner--medium media-banner--image" data-reveal>#', $content);

        $full = $this->render($this->content(['media_id' => self::PICTURE, 'width' => 'full']));
        self::assertMatchesRegularExpression('#<section class="media-banner-section media-banner-section--full">\s*<div class="media-banner media-banner--medium media-banner--image" data-reveal>#', $full);
        self::assertStringNotContainsString('container', $full, 'the section itself is the box');
    }

    public function testEveryHeightIsAClassAndAnUnknownWordIsTheDefault(): void
    {
        foreach (MediaBannerContent::HEIGHTS as $height) {
            self::assertStringContainsString('media-banner--' . $height . ' ', $this->render($this->content(['media_id' => self::PICTURE, 'height' => $height])));
        }

        foreach (['huge', '500px', '80vh', '', null] as $stored) {
            $content = $this->content(['media_id' => self::PICTURE, 'height' => $stored, 'width' => $stored]);
            self::assertSame(['medium', 'content'], [$content['height'], $content['width']]);
        }

        // Even a content array that bypassed the read model cannot put a word
        // of its own into a class.
        $html = $this->render(['height' => 'x" onload="alert(1)', 'width' => 'wide'] + $this->content(['media_id' => self::PICTURE]));
        self::assertStringContainsString('media-banner--medium', $html);
        self::assertStringContainsString('media-banner-section--content', $html);
        self::assertStringNotContainsString('onload', $html);
    }

    public function testTheFocusPointMovesAPictureAndNeverAVideo(): void
    {
        foreach (ImageFocus::keys() as $focus) {
            [$x, $y] = ImageFocus::point($focus);
            $html = $this->render($this->content(['media_id' => self::PICTURE, 'image_focus_x' => $x, 'image_focus_y' => $y]));
            if ($focus === ImageFocus::DEFAULT) {
                self::assertStringNotContainsString('style=', $html);
                continue;
            }
            self::assertStringContainsString('style="object-position: ' . $x . '% ' . $y . '%;"', $html, $focus);
        }

        // A point of its own, and a stored value that is no number reads as the middle.
        self::assertStringContainsString('style="object-position: 37% 64%;"', $this->render($this->content(['media_id' => self::PICTURE, 'image_focus_x' => 37, 'image_focus_y' => 64])));
        self::assertStringNotContainsString('style=', $this->render($this->content(['media_id' => self::PICTURE, 'image_focus_x' => '10% 20%'])), 'what is no number is the centre');

        $video = $this->content(['media_id' => self::VIDEO, 'image_focus_x' => 0, 'image_focus_y' => 0]);
        self::assertSame([50, 50], [$video['presentation']->focusX, $video['presentation']->focusY]);
        self::assertStringNotContainsString('style=', $this->render($video));
    }

    public function testDirectlyUnderAPageHeaderTheRoomAboveGoes(): void
    {
        self::assertStringContainsString('media-banner-section--tight-top', $this->render($this->content(['media_id' => self::PICTURE]), true));
    }

    // ----------------------------------------------------------- the video

    public function testAutoplayIsAlwaysMuted(): void
    {
        $html = $this->render($this->content(['media_id' => self::VIDEO, 'video_autoplay' => 1, 'video_controls' => 0, 'video_loop' => 1]));

        self::assertStringContainsString(' autoplay muted data-media-banner-autoplay loop aria-hidden="true"', $html);
        self::assertStringNotContainsString(' controls', $html);
        self::assertSame(1, preg_match_all('/\sautoplay\s/', $html));
        self::assertSame(1, preg_match_all('/\sautoplay muted\s/', $html), 'no autoplay with sound');
    }

    public function testAVideoThatDoesNotPlayByItselfAlwaysHasControls(): void
    {
        $content = $this->content(['media_id' => self::VIDEO, 'video_autoplay' => 0, 'video_controls' => 0]);
        self::assertTrue($content['controls']);

        $html = $this->render($content);
        self::assertStringContainsString(' controls', $html);
        self::assertStringNotContainsString('autoplay', $html);
        self::assertStringNotContainsString('muted', $html, 'started by the visitor, so the sound is theirs');
        self::assertStringNotContainsString('aria-hidden', $html, 'with controls it is not decoration');

        // Even a content array that says otherwise.
        self::assertStringContainsString(' controls', $this->render(['autoplay' => false, 'controls' => false] + $content));
    }

    public function testAutoplayWithControlsAndLoop(): void
    {
        $html = $this->render($this->content(['media_id' => self::VIDEO, 'video_autoplay' => 1, 'video_controls' => 1, 'video_loop' => 1]));

        self::assertStringContainsString('playsinline preload="metadata" controls autoplay muted data-media-banner-autoplay loop></video>', $html);
    }

    public function testAPosterIsAPictureAndOnlyForAVideo(): void
    {
        self::assertStringContainsString('poster="/assets/media/foto.jpg"', $this->render($this->content(['media_id' => self::VIDEO, 'poster_media_id' => self::PICTURE])));
        self::assertStringNotContainsString('poster=', $this->render($this->content(['media_id' => self::VIDEO, 'poster_media_id' => self::WEBM])), 'a video is no poster');
        self::assertStringNotContainsString('poster=', $this->render($this->content(['media_id' => self::VIDEO, 'poster_media_id' => self::DOCUMENT])));

        $picture = $this->content(['media_id' => self::PICTURE, 'poster_media_id' => self::PICTURE, 'video_autoplay' => 1, 'video_loop' => 1]);
        self::assertSame(['', false, false, false], [$picture['poster'], $picture['autoplay'], $picture['loop'], $picture['controls']], 'a picture has no video settings, whatever is stored');
    }

    // ------------------------------------------------------ CSS, JS, preview

    public function testTheStylesheetHasEveryHeightCoverAndNoTrick(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/media-banner.css');
        $rules = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        foreach (MediaBannerContent::HEIGHTS as $height) {
            self::assertStringContainsString('--media-banner-height-' . $height . ':', $rules, $height);
        }
        foreach (array_diff(MediaBannerContent::HEIGHTS, [MediaBannerContent::DEFAULT_HEIGHT]) as $height) {
            self::assertStringContainsString('.media-banner--' . $height . '{', $rules, $height . ' has its class');
        }
        self::assertStringContainsString('object-fit: cover', $rules);
        self::assertStringNotContainsString('100vw', $rules);
        self::assertDoesNotMatchRegularExpression('/margin[a-z-]*:\s*-/', $rules, 'no negative margins');
        self::assertStringContainsString('.media-banner-section--content .media-banner{ border-radius: var(--radius-lg); }', $rules, 'rounded only inside the container');
        self::assertStringNotContainsString('media-banner-section--full .media-banner{ border-radius', $rules);
    }

    public function testTheScriptOnlyActsForReducedMotion(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/blocks/media-banner.js');

        self::assertStringContainsString('(prefers-reduced-motion: reduce)', $js);
        self::assertStringContainsString('video[data-media-banner-autoplay]', $js);
        self::assertStringContainsString('video.pause()', $js);
        self::assertStringContainsString('video.controls = true', $js);
        self::assertStringNotContainsString('.play(', $js, 'it never starts a video');
    }

    public function testTheBlockIsRegisteredWithItsAssetsAndPreview(): void
    {
        $definition = BlockDefinitions::get('media_banner');

        self::assertNotNull($definition);
        self::assertSame('media_banners', $definition->contentTable());
        self::assertSame([], $definition->translatableFields(), 'no words');
        self::assertSame(['assets/css/responsive-media.css', 'assets/css/media-sequence.css', 'assets/css/blocks/media-banner.css'], $definition->styles(), 'its own sizes after the shared picture rules and media sequence');
        self::assertSame(['assets/js/media-sequence.js', 'assets/js/blocks/media-banner.js'], $definition->scripts());
        self::assertSame([BlockPreview::MEDIA], $definition->preview());
        self::assertTrue($definition->meta()['allow_multiple']);

        $sample = $definition->sampleContent(new BlockSamples());
        self::assertIsArray($sample);
        ob_start();
        $definition->renderSample($sample, 'media_banner-preview');
        $html = (string) ob_get_clean();
        self::assertStringContainsString('src="' . BlockSamples::IMAGE_PATH . '"', $html, 'the library preview shows the samples\' own picture');
    }

    // --------------------------------------------------- the picker filter

    public function testThePickerFilterOverBothKinds(): void
    {
        self::assertTrue(MediaType::isPickerFilter(MediaType::VISUAL));
        self::assertNull(MediaType::kindOfFilter(MediaType::VISUAL), 'no single kind');
        self::assertSame(['image/', 'video/'], MediaType::mimePrefixesOfFilter(MediaType::VISUAL));
        self::assertSame(['image/'], MediaType::mimePrefixesOfFilter(MediaType::SOCIAL_IMAGE), 'the other filters keep their one kind');
        self::assertSame(['video/'], MediaType::mimePrefixesOfFilter(MediaType::VIDEO));
        self::assertSame([], MediaType::mimePrefixesOfFilter('nonsense'), 'an unknown filter is no filter');
        self::assertFalse(MediaType::isLibraryFilter(MediaType::VISUAL), 'not a section of the library');

        foreach (['image/jpeg', 'image/svg+xml', 'video/mp4', 'video/webm'] as $mime) {
            self::assertTrue(MediaType::filterAccepts(MediaType::VISUAL, $mime), $mime);
        }
        foreach (['application/pdf', 'audio/mpeg', 'text/html', ''] as $mime) {
            self::assertFalse(MediaType::filterAccepts(MediaType::VISUAL, $mime), $mime);
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function content(array $row): array
    {
        return ['state' => MediaBannerContent::STATE_ACTIVE] + MediaBannerContent::fromRow($row);
    }

    /** @param array<string, mixed> $content */
    private function render(array $content, bool $tightTop = false): string
    {
        ob_start();
        try {
            render_section_media_banner($content, $tightTop);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
