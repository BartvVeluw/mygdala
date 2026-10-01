<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Media\ImagePresentation;
use App\Service\Media\ResponsiveImage;
use App\Service\MediaBannerContent;
use App\Service\PageHeroContent;
use App\Service\TextImageSplitContent;
use PHPUnit\Framework\TestCase;

/**
 * Responsive Media 3.1: Compact, Normaal and Groot mean one thing on the
 * page and in the CMS preview, on a large screen, a tablet and a phone.
 *
 *   - every length of a step a block's Content class works with is, letter
 *     for letter, what its stylesheet uses (one source, two readers);
 *   - on every reference screen the steps keep their order; a tablet shows
 *     a step of Tekst met afbeelding in its shape, never a step smaller (the
 *     bug 3.1 fixed), while a phone keeps the heights it had before 3.1,
 *     automatic and its own (3.1.1), and a large screen is unchanged;
 *   - the CMS keeps no size table of its own: the editors hand the field
 *     the block's frames, and admin.css and responsive-image.js name no step;
 *   - the field offers Desktop, Tablet and Mobiel where the block knows them.
 *
 * Reads files and renders one field; needs no database (suite contract).
 */
final class ImagePresentationContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testEveryLengthIsTheStylesheetsOwn(): void
    {
        $css = self::css('assets/css/blocks/text-image-split.css');
        foreach (TextImageSplitContent::WIDE_HEIGHTS as $height => $length) {
            self::assertStringContainsString('--text-image-height-' . $height . ': ' . $length . ';', $css);
        }
        foreach (TextImageSplitContent::STACKED_RATIOS as $height => $ratio) {
            self::assertStringContainsString('--text-image-ratio-' . $height . ': ' . $ratio . ';', $css);
        }
        self::assertStringContainsString('--text-image-stacked-max: ' . TextImageSplitContent::STACKED_MAX . ';', $css);
        self::assertStringContainsString('@media (max-width: ' . TextImageSplitContent::STACK_MAX_WIDTH . 'px){', $css);
        self::assertMatchesRegularExpression('/@media \(max-width: 860px\)\{[^@]*aspect-ratio: var\(--text-image-ratio\); max-height: var\(--text-image-stacked-max\)/s', $css, 'a tablet: a shape, not a fixed height');
        preg_match('/@media \(max-width: 860px\)\{(.*?)\n\}/s', $css, $tablet);
        self::assertStringNotContainsString('--text-image-height-small', $tablet[1], 'no phone height on a tablet');
        // A phone: the fixed heights a phone had before Responsive Media 3.1.
        self::assertSame(1, preg_match('/@media \(max-width: ' . ResponsiveImage::MOBILE_MAX_WIDTH . 'px\)\{(.*?)\n\}/s', $css, $phone));
        foreach (TextImageSplitContent::PHONE_HEIGHTS as $height => $length) {
            self::assertStringContainsString('--text-image-height-' . $height . ': ' . $length . ';', $phone[1]);
        }
        self::assertStringContainsString('.text-image__media{ height: var(--text-image-height); aspect-ratio: auto; max-height: none; }', $phone[1], 'a phone: a height, not a shape');
        foreach (TextImageSplitContent::PHONE_OWN_HEIGHTS as $mobile => $length) {
            self::assertStringContainsString('.text-image__item--mobile-' . $mobile . '{ --text-image-height: ' . $length . '; }', $phone[1]);
        }

        $css = self::css('assets/css/blocks/media-banner.css');
        foreach (MediaBannerContent::WIDE_HEIGHTS as $height => $length) {
            self::assertStringContainsString('--media-banner-height-' . $height . ': ' . $length . ';', $css);
        }
        self::assertMatchesRegularExpression('/@media \(max-width: 640px\)\{\s*\.media-banner-section\{([^}]*)\}/', $css);
        preg_match('/@media \(max-width: 640px\)\{\s*\.media-banner-section\{([^}]*)\}/', $css, $phone);
        foreach (MediaBannerContent::PHONE_HEIGHTS as $height => $length) {
            self::assertStringContainsString('--media-banner-height-' . $height . ': ' . $length . ';', $phone[1]);
        }
        foreach (MediaBannerContent::PHONE_OWN_HEIGHTS as $mobile => $length) {
            self::assertStringContainsString('.media-banner.media-banner--mobile-' . $mobile . '{ --media-banner-height: ' . $length . '; }', $css);
        }

        $css = self::css('assets/css/blocks/page-hero.css');
        self::assertStringContainsString("--page-hero-height: " . PageHeroContent::WIDE_HEIGHTS['medium'] . ';', $css);
        self::assertStringContainsString('.page-hero--background.page-hero--height-small{ --page-hero-height: ' . PageHeroContent::WIDE_HEIGHTS['small'] . ';', $css);
        self::assertStringContainsString('.page-hero--background.page-hero--height-large{ --page-hero-height: ' . PageHeroContent::WIDE_HEIGHTS['large'] . '; }', $css);
        self::assertStringContainsString('@media (max-width: ' . PageHeroContent::NARROW_MAX_WIDTH . 'px){', $css);
        self::assertStringContainsString('.page-hero--background{ --page-hero-height: ' . PageHeroContent::NARROW_HEIGHTS['medium'] . ';', $css);
        self::assertStringContainsString('.page-hero--background.page-hero--height-small{ --page-hero-height: ' . PageHeroContent::NARROW_HEIGHTS['small'] . '; }', $css);
        self::assertStringContainsString('.page-hero--background.page-hero--height-large{ --page-hero-height: ' . PageHeroContent::NARROW_HEIGHTS['large'] . '; }', $css);
        foreach (PageHeroContent::PHONE_OWN_HEIGHTS as $mobile => $length) {
            self::assertStringContainsString('.page-hero--background.page-hero--mobile-' . $mobile . '{ --page-hero-height: ' . $length . '; }', $css);
        }
        self::assertStringContainsString('aspect-ratio: ' . PageHeroContent::FIGURE_RATIO . ';', $css);
        self::assertStringContainsString('max-height: ' . PageHeroContent::FIGURE_MAX . ';', $css);
        self::assertStringContainsString('aspect-ratio: ' . PageHeroContent::FIGURE_RATIO_NARROW . '; }', $css);
        self::assertStringContainsString('grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);', $css, 'the picture beside the text is 5 of 12');
    }

    public function testTheStepsKeepTheirOrderOnEveryScreen(): void
    {
        foreach (ImagePresentation::VIEWS as $view) {
            foreach (TextImageSplitContent::COLUMNS as $column) {
                $heights = array_map(static fn (string $h): float => TextImageSplitContent::pictureSize($view, $column, $h)[1], TextImageSplitContent::HEIGHTS);
                self::assertStrictlyRising($heights, "Tekst met afbeelding, {$view}, {$column}%");
            }
            foreach (MediaBannerContent::WIDTHS as $width) {
                $heights = array_map(static fn (string $h): float => MediaBannerContent::frameSize($view, $width, $h)[1], MediaBannerContent::HEIGHTS);
                self::assertStrictlyRising($heights, "Mediabanner, {$view}, {$width}");
            }
            $heights = array_map(static fn (string $h): float => PageHeroContent::frameSize($view, PageHeroContent::IMAGE_BACKGROUND, $h)[1], PageHeroContent::HEIGHTS);
            self::assertStrictlyRising($heights, "Paginakop, {$view}");
        }

        // A phone's own heights, on a phone.
        self::assertStrictlyRising(array_map(static fn (string $m): float => TextImageSplitContent::pictureSize('mobile', '50', 'medium', $m)[1], ResponsiveImage::MOBILE_HEIGHTS), 'Tekst met afbeelding, own phone height');
        self::assertStrictlyRising(array_map(static fn (string $m): float => MediaBannerContent::frameSize('mobile', 'content', 'medium', $m)[1], ResponsiveImage::MOBILE_HEIGHTS), 'Mediabanner, own phone height');
        self::assertStrictlyRising(array_map(static fn (string $m): float => PageHeroContent::frameSize('mobile', PageHeroContent::IMAGE_BACKGROUND, 'medium', $m)[1], ResponsiveImage::MOBILE_HEIGHTS), 'Paginakop, own phone height');
    }

    public function testATabletShowsAStepOfTekstMetAfbeeldingInItsShape(): void
    {
        foreach (TextImageSplitContent::HEIGHTS as $height) {
            [$tabletWidth, $tabletHeight] = TextImageSplitContent::pictureSize('tablet', '50', $height);
            self::assertSame((float) ImagePresentation::contentWidth('tablet'), $tabletWidth, 'one column: the full width');
            if ($tabletHeight < ImagePresentation::length(TextImageSplitContent::STACKED_MAX, 'tablet')) {
                self::assertEqualsWithDelta(ImagePresentation::ratio(TextImageSplitContent::STACKED_RATIOS[$height]), $tabletWidth / $tabletHeight, 0.001, $height);
            }
        }
        // Compact about 16:9, Normaal about 4:3, Groot square, at 704px wide.
        self::assertSame([704.0, 396.0], TextImageSplitContent::pictureSize('tablet', '50', 'small'));
        self::assertSame([704.0, 528.0], TextImageSplitContent::pictureSize('tablet', '50', 'medium'));
        self::assertSame([704.0, 672.0], TextImageSplitContent::pictureSize('tablet', '50', 'large'), 'square up to the 42rem cap');

        // The reported bug: on a tablet large looked like normal, and normal like compact.
        $tablet = array_combine(TextImageSplitContent::HEIGHTS, array_map(static function (string $h): float {
            [$w, $t] = TextImageSplitContent::pictureSize('tablet', '50', $h);

            return $w / $t;
        }, TextImageSplitContent::HEIGHTS));
        $desktop = array_combine(TextImageSplitContent::HEIGHTS, array_map(static function (string $h): float {
            [$w, $t] = TextImageSplitContent::pictureSize('desktop', '50', $h);

            return $w / $t;
        }, TextImageSplitContent::HEIGHTS));
        self::assertLessThan($desktop['small'], $tablet['large'], 'large on a tablet is taller in shape than small on a large screen');
        self::assertLessThan($desktop['small'], $tablet['medium'], 'medium on a tablet is no flat strip');
    }

    /**
     * Responsive Media 3.1.1: a phone shows Tekst met afbeelding exactly as
     * before 3.1 (27311e9): the column's full width (375 - 2 x 24 = 327px) at
     * 12, 16 or 20rem automatic, and 12, 16 or 24rem as a phone's own height.
     */
    public function testAPhoneShowsTekstMetAfbeeldingAsItAlwaysDid(): void
    {
        $automatic = ['small' => 192.0, 'medium' => 256.0, 'large' => 320.0];
        foreach ($automatic as $height => $px) {
            foreach (TextImageSplitContent::COLUMNS as $column) {
                self::assertSame([327.0, $px], TextImageSplitContent::pictureSize('mobile', $column, $height), "automatic {$height}, {$column}%");
            }
        }

        $own = ['compact' => 192.0, 'normal' => 256.0, 'large' => 384.0];
        foreach ($own as $mobile => $px) {
            foreach (TextImageSplitContent::HEIGHTS as $chosen) {
                self::assertSame([327.0, $px], TextImageSplitContent::pictureSize('mobile', '50', $chosen, $mobile), "own {$mobile} whatever the large screen chose ({$chosen})");
            }
            // A tablet is no phone: the phone's own height does not reach it.
            self::assertSame(TextImageSplitContent::pictureSize('tablet', '50', 'medium'), TextImageSplitContent::pictureSize('tablet', '50', 'medium', $mobile));
            self::assertSame(TextImageSplitContent::pictureSize('desktop', '50', 'medium'), TextImageSplitContent::pictureSize('desktop', '50', 'medium', $mobile));
        }

        // The explicit Groot is its own, taller step, not the automatic one.
        self::assertGreaterThan(TextImageSplitContent::pictureSize('mobile', '50', 'large')[1], TextImageSplitContent::pictureSize('mobile', '50', 'large', 'large')[1]);
    }

    public function testALargeScreenIsUnchanged(): void
    {
        // 1280 wide: (1136 - 64) x 50% beside the text, clamp() heights at 1280px.
        self::assertEquals([536, 307.2], TextImageSplitContent::pictureSize('desktop', '50', 'small'));
        self::assertEquals([536, 460.8], TextImageSplitContent::pictureSize('desktop', '50', 'medium'));
        self::assertEquals([536, 640.0], TextImageSplitContent::pictureSize('desktop', '50', 'large'));
    }

    public function testTheCmsPreviewFramesComeFromTheSameSizes(): void
    {
        $shapes = TextImageSplitContent::editorFrames()['shapes'];
        foreach (TextImageSplitContent::COLUMNS as $column) {
            foreach (TextImageSplitContent::HEIGHTS as $height) {
                foreach (array_merge([''], ResponsiveImage::MOBILE_HEIGHTS) as $mobile) {
                    $expected = [];
                    foreach (ImagePresentation::VIEWS as $view) {
                        [$w, $h] = TextImageSplitContent::pictureSize($view, $column, $height, $mobile === '' ? null : $mobile);
                        $expected += ImagePresentation::frame($view, $w, $h);
                    }
                    self::assertSame($expected, $shapes[$column . '|' . $height . '|' . $mobile]);
                }
            }
        }
        // Mobiel: the historic phone frames; Tablet: the shapes.
        self::assertSame('327 / 320', $shapes['50|large|']['--admin-rm-mobile-ratio']);
        self::assertSame('327 / 384', $shapes['50|medium|large']['--admin-rm-mobile-ratio']);
        self::assertSame('704 / 672', $shapes['50|large|large']['--admin-rm-tablet-ratio']);
        self::assertSame('704 / 396', $shapes['50|small|']['--admin-rm-tablet-ratio']);
    }

    public function testTheCmsKeepsNoSizeTableOfItsOwn(): void
    {
        $admin = self::css('admin/assets/admin.css');
        foreach (['[data-tis-height]', '[data-tis-column]', 'name="hero_height"', 'name="image_mobile_height"', '[data-rm-mobile-height]', '[data-media-banner-form]:has', '[data-page-hero-form]:has'] as $table) {
            self::assertStringNotContainsString($table, $admin, 'admin.css keeps no size table: ' . $table);
        }

        $script = (string) file_get_contents(self::ROOT . '/admin/assets/responsive-image.js');
        foreach (['"small"', '"medium"', '"large"', '"compact"', '"normal"'] as $word) {
            self::assertStringNotContainsString($word, $script, 'responsive-image.js computes no size: ' . $word);
        }
        self::assertDoesNotMatchRegularExpression('/\d(rem|vw|vh)\b/', $script, 'responsive-image.js holds no length');

        foreach ([
            'admin/text-image-split.php' => 'TextImageSplitContent::editorFrames()',
            'admin/media-banner.php' => 'MediaBannerContent::editorFrames()',
            'admin/page-hero.php' => 'PageHeroContent::editorFrames()',
        ] as $file => $call) {
            self::assertStringContainsString($call, (string) file_get_contents(self::ROOT . '/' . $file), $file);
        }
    }

    public function testEveryChoiceHasAFrameOnEveryScreen(): void
    {
        $mobiles = array_merge([''], ResponsiveImage::MOBILE_HEIGHTS);
        $expect = static function (array $lists): array {
            $keys = [''];
            foreach ($lists as $list) {
                $next = [];
                foreach ($keys as $key) {
                    foreach ($list as $value) {
                        $next[] = $key === '' ? $value : $key . '|' . $value;
                    }
                }
                $keys = $next;
            }

            return $keys;
        };

        foreach ([
            'Tekst met afbeelding' => [TextImageSplitContent::editorFrames(), [TextImageSplitContent::COLUMNS, TextImageSplitContent::HEIGHTS, $mobiles]],
            'Mediabanner' => [MediaBannerContent::editorFrames(), [MediaBannerContent::WIDTHS, MediaBannerContent::HEIGHTS, $mobiles]],
            'Paginakop' => [PageHeroContent::editorFrames(), [PageHeroContent::HEIGHTS, PageHeroContent::IMAGE_MODES, $mobiles]],
        ] as $block => [$frames, $lists]) {
            // Every combination of the closed lists, "automatic" as an empty last part.
            $keys = $expect($lists);
            sort($keys);
            $actual = array_map('strval', array_keys($frames['shapes']));
            sort($actual);
            self::assertSame($keys, $actual, $block);
            self::assertCount(3, $frames['controls'], $block);
            foreach ($frames['shapes'] as $key => $properties) {
                self::assertCount(6, $properties, "{$block} {$key}: a shape and a size per view");
                self::assertSame(count($properties), substr_count(ImagePresentation::style($properties), ';'), "{$block} {$key}: every property passes");
            }
        }
    }

    public function testTheFieldOffersDesktopTabletAndMobielWhereTheBlockKnowsThem(): void
    {
        require_once self::ROOT . '/admin/_responsive_image_field.php';

        $html = self::field([
            'slot' => TextImageSplitContent::imageSlot(),
            'value' => new ResponsiveImage(focusX: 30, focusY: 30, zoom: 130),
            'id' => 'tis',
            'preview' => '/assets/media/thumb.jpg',
            'shapes' => TextImageSplitContent::editorFrames() + ['current' => '50|large|'],
        ]);
        self::assertStringContainsString('data-rm-view="desktop"', $html);
        self::assertSame(['desktop', 'tablet', 'mobile'], self::views($html));
        self::assertStringContainsString('--admin-rm-desktop-ratio: 536 / 640; --admin-rm-desktop-width: 268px; --admin-rm-tablet-ratio: 704 / 672;', $html, 'the stored choice\'s frames, from the block');
        self::assertStringContainsString('data-rm-shapes="', $html);
        self::assertStringContainsString('data-rm-shape-controls="', $html);
        self::assertMatchesRegularExpression('/<div class="admin-rm__views" data-rm-views hidden>/', $html, 'the switch needs the script');
        // One picture per frame, both lazy; the phone part's frame is hidden
        // (and so never downloads) while the phone follows the large screen.
        self::assertSame(2, substr_count($html, 'loading="lazy" decoding="async" data-rm-preview'));
        self::assertMatchesRegularExpression('/<div data-rm-when="mobile-focus" hidden>\s*<div class="admin-rm__focus[^"]*" data-rm-focus="mobile">/', $html);
        self::assertStringContainsString('src="/assets/media/thumb.jpg"', $html, 'the library thumbnail, not the original');

        // Without a tablet shape, no Tablet button: no invented fallback.
        $html = self::field([
            'slot' => TextImageSplitContent::imageSlot(),
            'value' => new ResponsiveImage(),
            'id' => 'plain',
            'preview' => '',
            'frame' => ['desktop' => '4 / 5', 'mobile' => '4 / 5'],
        ]);
        self::assertSame(['desktop', 'mobile'], self::views($html));
    }

    /** @param list<float> $values */
    private static function assertStrictlyRising(array $values, string $what): void
    {
        $values = array_values($values);
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            self::assertGreaterThan($values[$i - 1], $values[$i], $what . ': step ' . $i . ' is larger than the one before');
        }
    }

    private static function css(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::ROOT . '/' . $relative));
    }

    /** @param array<string, mixed> $field */
    private static function field(array $field): string
    {
        ob_start();
        responsive_image_field($field);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private static function views(string $html): array
    {
        preg_match_all('/data-rm-view-button="([a-z]+)"/', $html, $matches);

        return $matches[1];
    }
}
