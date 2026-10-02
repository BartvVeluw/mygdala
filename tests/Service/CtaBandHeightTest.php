<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\CtaBandContent;
use App\Service\Media\ResponsiveImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-cta-band.php';

/**
 * The minimum height of the Oproep met knop (CONTENT-BLOCKS.md, "De hoogte
 * van het achtergrondvlak"), on the read model, the partial and the
 * stylesheet alone (no database, no server):
 *
 *   - a band without the setting, and 'auto' on both screens, render byte for
 *     byte what they rendered before: no class, no style;
 *   - every preset is a class on the box that carries the layers (the card,
 *     or the <section> of a full-width band), an own height one pixel length
 *     in one custom property, validated on the way in and on the way out;
 *   - it is a min-height and nothing else: no height, no max-height, no
 *     overflow of its own, so more words make the band taller;
 *   - the picture, its focus point and zoom, and the buttons' styles come out
 *     exactly as without a height;
 *   - the pixels of CtaBandContent and of assets/css/blocks/cta-band.css are
 *     the same numbers.
 */
final class CtaBandHeightTest extends TestCase
{
    public function testABandWithoutTheSettingRendersAsBefore(): void
    {
        $html = $this->render($this->band());

        self::assertStringContainsString('<section class="cta-section">', $html);
        self::assertStringContainsString('<div class="cta-band cta-band--card cta-band--align-center cta-band--lead-narrow" data-reveal>', $html);
        self::assertStringNotContainsString('cta-height', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testAutomaticOnBothScreensIsTheSameMarkup(): void
    {
        $auto = $this->render(['height' => 'auto', 'height_px' => null, 'mobile_height' => 'auto', 'mobile_height_px' => null] + $this->band());

        self::assertSame($this->render($this->band()), $auto);
        self::assertSame(
            ['height' => 'auto', 'height_px' => null, 'mobile_height' => 'auto', 'mobile_height_px' => null],
            CtaBandContent::minHeight([]),
            'a row from before the migration reads as automatic'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function presets(): iterable
    {
        foreach (['compact', 'normal', 'tall'] as $preset) {
            yield $preset => [$preset];
        }
    }

    #[DataProvider('presets')]
    public function testAPresetIsAClassOnTheCard(string $preset): void
    {
        $html = $this->render($this->height(['min_height' => $preset]) + $this->band());

        self::assertStringContainsString('<div class="cta-band cta-band--card cta-band--align-center cta-band--lead-narrow cta-height cta-height--' . $preset . ' cta-height-phone--auto" data-reveal>', $html);
        self::assertStringNotContainsString('style=', $html, 'a preset prints no style');
        self::assertStringContainsString('<section class="cta-section">', $html, 'the section is untouched for a card');
    }

    #[DataProvider('presets')]
    public function testAFullWidthBandCarriesItsHeightOnTheSection(string $preset): void
    {
        $html = $this->render($this->height(['min_height' => $preset]) + ['full_width' => true] + $this->band());

        self::assertStringContainsString('<section class="cta-section cta-section--full cta-height cta-height--' . $preset . ' cta-height-phone--auto surface-emphasis">', $html);
        self::assertStringContainsString('<div class="cta-band cta-band--align-center cta-band--lead-narrow" data-reveal>', $html);
    }

    public function testAnOwnDesktopHeightIsOnePixelLength(): void
    {
        $html = $this->render($this->height(['min_height' => 'custom', 'min_height_px' => 480]) + $this->band());

        self::assertStringContainsString('cta-height cta-height--custom cta-height-phone--auto" style="--cta-min-height: 480px;" data-reveal>', $html);
        self::assertSame(1, substr_count($html, 'style='));
    }

    public function testAnOwnPhoneHeightIsItsOwnLength(): void
    {
        $html = $this->render($this->height(['min_height' => 'tall', 'mobile_min_height' => 'custom', 'mobile_min_height_px' => 300]) + $this->band());
        self::assertStringContainsString('cta-height cta-height--tall cta-height-phone--custom" style="--cta-min-height-phone: 300px;"', $html);

        $both = $this->render($this->height(['min_height' => 'custom', 'min_height_px' => 700, 'mobile_min_height' => 'custom', 'mobile_min_height_px' => 250]) + $this->band());
        self::assertStringContainsString('style="--cta-min-height: 700px; --cta-min-height-phone: 250px;"', $both);

        $phoneOnly = $this->render($this->height(['mobile_min_height' => 'compact']) + $this->band());
        self::assertStringContainsString('cta-height cta-height--auto cta-height-phone--compact" data-reveal>', $phoneOnly, 'a phone may have a minimum while a large screen has none');
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function invalidHeights(): iterable
    {
        yield 'unknown word' => ['huge', null];
        yield 'css in the word' => ['100vh', null];
        yield 'custom without pixels' => ['custom', null];
        yield 'custom below both ranges' => ['custom', 150];
        yield 'custom above the range' => ['custom', 1001];
        yield 'custom with a unit' => ['custom', '480px'];
        yield 'custom negative' => ['custom', '-480'];
        yield 'custom decimal' => ['custom', '480.5'];
        yield 'custom css' => ['custom', '480;background:red'];
    }

    #[DataProvider('invalidHeights')]
    public function testAnInvalidValueReadsAsAutomaticAndNeverReachesTheMarkup(mixed $word, mixed $pixels): void
    {
        self::assertSame(['auto', null], array_values(array_slice(CtaBandContent::minHeight(['min_height' => $word, 'min_height_px' => $pixels]), 0, 2)));

        // Straight into the partial, past the read model: checked again there.
        $html = $this->render(['height' => $word, 'height_px' => $pixels, 'mobile_height' => $word, 'mobile_height_px' => $pixels] + $this->band());
        self::assertSame($this->render($this->band()), $html);
    }

    public function testTheRangesAreTheBrief(): void
    {
        self::assertSame([200, 1000], CtaBandContent::MIN_HEIGHT_RANGE);
        self::assertSame([160, 800], CtaBandContent::MOBILE_MIN_HEIGHT_RANGE);
        self::assertSame(200, CtaBandContent::pixels('200', CtaBandContent::MIN_HEIGHT_RANGE));
        self::assertSame(1000, CtaBandContent::pixels(1000, CtaBandContent::MIN_HEIGHT_RANGE));
        self::assertSame(480, CtaBandContent::pixels(' 480 ', CtaBandContent::MIN_HEIGHT_RANGE));
        self::assertNull(CtaBandContent::pixels('', CtaBandContent::MIN_HEIGHT_RANGE));
        self::assertNull(CtaBandContent::pixels(['480'], CtaBandContent::MIN_HEIGHT_RANGE));
        self::assertNull(CtaBandContent::pixels('159', CtaBandContent::MOBILE_MIN_HEIGHT_RANGE));
        self::assertNull(CtaBandContent::pixels('801', CtaBandContent::MOBILE_MIN_HEIGHT_RANGE));
    }

    public function testThePhoneFollowsTheLargeScreenCapped(): void
    {
        $phone = static fn (array $row): ?int => CtaBandContent::phonePixels(CtaBandContent::minHeight($row));

        self::assertNull($phone([]), 'automatic everywhere: no minimum');
        self::assertSame(240, $phone(['min_height' => 'compact']));
        self::assertSame(320, $phone(['min_height' => 'normal']));
        self::assertSame(420, $phone(['min_height' => 'tall']));
        self::assertSame(300, $phone(['min_height' => 'custom', 'min_height_px' => 300]));
        self::assertSame(420, $phone(['min_height' => 'custom', 'min_height_px' => 1000]), 'a tall own height is capped on a phone');
        self::assertNull($phone(['min_height' => 'tall', 'mobile_min_height' => 'text']), 'text only');
        self::assertSame(700, $phone(['min_height' => 'compact', 'mobile_min_height' => 'custom', 'mobile_min_height_px' => 700]));
    }

    public function testMoreWordsCanOutgrowTheMinimum(): void
    {
        $rules = $this->rules();

        self::assertSame('min-height: var(--cta-min-height, auto);', $this->declaration($rules['.cta-height'] ?? '', 'min-height'));
        self::assertSame('min-height: var(--cta-min-height-phone, auto);', $this->declaration($rules['@media .cta-height'] ?? '', 'min-height'), 'a phone takes its own minimum');

        foreach ($rules as $selector => $body) {
            if (str_contains($selector, 'cta-height')) {
                self::assertDoesNotMatchRegularExpression('~(^|[;\s])(height|max-height|overflow[a-z-]*|block-size)\s*:~', $body, $selector . ': a minimum only');
            }
        }

        // Long words inside a tall band still come out whole: the partial
        // prints every word, nothing is truncated or hidden.
        $long = str_repeat('Een oproep met veel woorden. ', 60);
        $html = $this->render(['lead' => $long] + $this->height(['min_height' => 'custom', 'min_height_px' => 200]) + $this->band());
        self::assertStringContainsString('<p class="lead">' . $long . '</p>', $html);
    }

    public function testThePresetsInTheStylesheetAreTheReadModelsPixels(): void
    {
        $root = $this->rules()['.cta-section'] ?? '';

        foreach (CtaBandContent::HEIGHT_PX as $preset => $px) {
            self::assertStringContainsString('--cta-height-' . $preset . ': ' . $px . 'px;', $root);
        }
        foreach (CtaBandContent::PHONE_HEIGHT_PX as $preset => $px) {
            self::assertStringContainsString('--cta-height-phone-' . $preset . ': ' . $px . 'px;', $root);
        }
        foreach (['compact', 'normal', 'tall', 'custom'] as $word) {
            self::assertArrayHasKey('.cta-height--' . $word, $this->rules());
        }
        foreach (['text', 'compact', 'normal', 'tall'] as $word) {
            self::assertArrayHasKey('.cta-height-phone--' . $word, $this->rules());
        }
        self::assertSame(CtaBandContent::HEIGHTS, ['auto', 'compact', 'normal', 'tall', 'custom']);
        self::assertSame(CtaBandContent::MOBILE_HEIGHTS, ['auto', 'text', 'compact', 'normal', 'tall', 'custom']);
    }

    public function testThePictureFocusAndZoomAreUntouchedByAHeight(): void
    {
        $picture = (new ResponsiveImage(30, 70, zoom: 150))
            ->forRender(['image_path' => '/assets/media/cta.jpg', 'alt' => '', 'width' => 1600, 'height' => 900]);
        $band = ['background' => ['image_path' => '/assets/media/cta.jpg', 'width' => 1600, 'height' => 900], 'picture' => $picture] + $this->band();

        $without = $this->render($band);
        $with = $this->render($this->height(['min_height' => 'tall', 'mobile_min_height' => 'custom', 'mobile_min_height_px' => 400]) + $band);

        preg_match('~<div class="cta-band__media.*?</div>~s', $without, $before);
        preg_match('~<div class="cta-band__media.*?</div>~s', $with, $after);
        self::assertNotEmpty($before);
        self::assertSame($before[0], $after[0] ?? null, 'the picture, its point and its zoom print the same');
        self::assertStringContainsString('object-position: 30% 70%', $after[0]);
        self::assertStringContainsString('scale: 1.5', $after[0]);

        $css = $this->rules();
        self::assertStringContainsString('inset: 0', $css['.cta-band__media'] ?? '', 'the picture covers the whole box');
        self::assertStringContainsString('object-fit: cover', $css['.cta-band__media img'] ?? '');
        self::assertStringContainsString('height: 100%', $css['.cta-band__media img'] ?? '');
    }

    public function testTheButtonsKeepTheirStyles(): void
    {
        $without = $this->render(['primary_button_style' => null, 'secondary_button_style' => null] + $this->band());
        $with = $this->render($this->height(['min_height' => 'normal']) + ['primary_button_style' => null, 'secondary_button_style' => null] + $this->band());

        preg_match('~<div class="cta-band__actions">.*?</div>~s', $without, $before);
        preg_match('~<div class="cta-band__actions">.*?</div>~s', $with, $after);
        self::assertNotEmpty($before);
        self::assertSame($before[0], $after[0] ?? null);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> a band as CtaBandContent gives it, no height keys */
    private function band(): array
    {
        return [
            'state' => CtaBandContent::STATE_ACTIVE,
            'eyebrow' => 'Samen',
            'title' => 'Kort',
            'lead' => 'Wij helpen graag.',
            'primary_label' => 'Neem contact op',
            'primary_url' => '/contact',
            'secondary_label' => 'Bekijk werk',
            'secondary_url' => '/werk',
        ];
    }

    /**
     * A stored row's height as the read model gives it to the partial.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function height(array $row): array
    {
        return CtaBandContent::minHeight($row);
    }

    /** @param array<string, mixed> $cta */
    private function render(array $cta): string
    {
        ob_start();
        try {
            render_section_cta_band($cta);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * The stylesheet's rules by selector; a rule inside @media is keyed
     * '@media <selector>'. A selector list gives one entry per selector.
     *
     * @return array<string, string>
     */
    private function rules(): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/cta-band.css'));
        $rules = [];

        // The @media blocks first, then the rest without them.
        preg_match_all('~@media[^{]*\{((?:[^{}]*\{[^{}]*\})*)\s*\}~', $css, $media, PREG_SET_ORDER);
        foreach ($media as [$block, $inner]) {
            preg_match_all('~([^{}]+)\{([^{}]*)\}~', $inner, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $selectors, $body]) {
                foreach (explode(',', $selectors) as $selector) {
                    $rules['@media ' . trim((string) preg_replace('~\s+~', ' ', $selector))] = trim($body);
                }
            }
            $css = str_replace($block, '', $css);
        }

        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $selectors, $body]) {
            foreach (explode(',', $selectors) as $selector) {
                $rules[trim((string) preg_replace('~\s+~', ' ', $selector))] = trim($body);
            }
        }

        return $rules;
    }

    private function declaration(string $body, string $property): string
    {
        return preg_match('~(?:^|;|\s)(' . preg_quote($property, '~') . '\s*:[^;]*;)~', $body, $match) === 1 ? trim($match[1]) : '';
    }
}
