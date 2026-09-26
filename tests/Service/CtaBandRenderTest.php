<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\CtaBandContent;
use App\Service\Media\ImageFocus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-cta-band.php';

/**
 * CTA 2.0's render contract, on the partial alone (no database, no server):
 *
 *   - a band without any CTA 2.0 setting renders the markup and classes it
 *     always had (a card, centred, the 46ch lead, its buttons);
 *   - every presentation choice is a class from a closed list, an unknown
 *     word is the list's first entry, and nothing else from the content array
 *     reaches the markup as CSS;
 *   - no, one or two buttons, and never a second without a first;
 *   - the picture is decorative, the overlay and the panel are layers in the
 *     order CONTENT-BLOCKS.md gives, and full width moves the layers from the
 *     card to the <section> without leaving the container;
 *   - assets/css/blocks/cta-band.css has a rule for every word of every list,
 *     and no viewport-width trick.
 */
final class CtaBandRenderTest extends TestCase
{
    public function testABandWithoutAnyNewSettingRendersAsItAlwaysDid(): void
    {
        $html = $this->render($this->legacy());

        self::assertStringContainsString('<section class="cta-section">', $html);
        self::assertStringContainsString('class="cta-band cta-band--card cta-band--align-center cta-band--lead-narrow" data-reveal', $html);
        self::assertStringContainsString('<div class="cta-band__content">', $html, 'no panel');
        self::assertStringNotContainsString('cta-band__media', $html, 'no picture');
        self::assertStringContainsString('<a href="/contact" class="btn">Neem contact op', $html);
        self::assertStringContainsString('<a href="/werk" class="btn btn--ghost">Bekijk werk</a>', $html);
        self::assertStringContainsString('<p class="lead">Wij helpen graag.</p>', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testNoButtonRendersNoButtonRow(): void
    {
        $html = $this->render(['primary_url' => '', 'primary_label' => '', 'secondary_label' => '', 'secondary_url' => ''] + $this->legacy());

        self::assertStringContainsString('<h2>Samenwerken?</h2>', $html, 'a title alone is a band');
        self::assertStringNotContainsString('cta-band__actions', $html);
        self::assertStringNotContainsString('<a ', $html, 'no empty link');
    }

    public function testOneButton(): void
    {
        $html = $this->render(['secondary_label' => '', 'secondary_url' => ''] + $this->legacy());

        self::assertSame(1, substr_count($html, '<a '));
        self::assertStringContainsString('class="btn"', $html);
    }

    public function testASecondButtonNeverRendersWithoutTheFirst(): void
    {
        $html = $this->render(['primary_url' => '', 'primary_label' => ''] + $this->legacy());

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('Bekijk werk', $html);
    }

    public function testALabelWithoutAnAddressIsNoButton(): void
    {
        $html = $this->render(['primary_url' => ''] + $this->legacy());

        self::assertStringNotContainsString('<a ', $html, 'a stale label never becomes an empty link');
    }

    public function testNothingToSayRendersNothing(): void
    {
        self::assertSame('', trim($this->render(['title' => '', 'primary_label' => ''] + $this->legacy())));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function choices(): iterable
    {
        foreach (CtaBandContent::ALIGNMENTS as $align) {
            yield 'align ' . $align => ['align', $align, 'cta-band--align-' . $align];
        }
        foreach (CtaBandContent::LEAD_WIDTHS as $width) {
            yield 'lead ' . $width => ['lead_width', $width, 'cta-band--lead-' . $width];
        }
        foreach (CtaBandContent::PANEL_OPACITIES as $opacity) {
            yield 'panel ' . $opacity => ['panel', $opacity, 'cta-band__content--panel cta-band__content--panel-' . $opacity];
        }
    }

    #[DataProvider('choices')]
    public function testEveryChoiceIsAClass(string $key, string $value, string $class): void
    {
        self::assertStringContainsString($class, $this->render([$key => $value] + $this->legacy()));
    }

    public function testAnUnknownWordIsTheListsFirstEntryAndNeverReachesTheMarkup(): void
    {
        $hostile = '" onmouseover="alert(1)" style="width:100vw';
        $html = $this->render([
            'align' => $hostile,
            'lead_width' => 'huge',
            'panel' => 'transparent',
            'overlay' => $hostile,
            'background_focus' => '10% 20%',
            'background' => ['image_path' => '/assets/media/x.jpg', 'width' => null, 'height' => null],
        ] + $this->legacy());

        self::assertStringContainsString('cta-band--align-center', $html);
        self::assertStringContainsString('cta-band--lead-narrow', $html);
        self::assertStringContainsString('cta-band__content--panel-strong', $html);
        self::assertStringContainsString('cta-band__media--overlay-medium', $html);
        self::assertStringNotContainsString('onmouseover', $html);
        self::assertStringNotContainsString('100vw', $html);
        self::assertStringNotContainsString('10% 20%', $html);
        self::assertStringNotContainsString('style=', $html, 'the default focus point prints no style at all');
    }

    public function testTheOverlayIsAClassOnThePictureAndOnlyWithAPicture(): void
    {
        foreach (CtaBandContent::OVERLAYS as $overlay) {
            $with = $this->render(['overlay' => $overlay] + $this->withPicture());
            self::assertStringContainsString('cta-band__media cta-band__media--overlay-' . $overlay, $with, $overlay);

            $without = $this->render(['overlay' => $overlay] + $this->legacy());
            self::assertStringNotContainsString('overlay', $without, $overlay . ' without a picture draws nothing');
        }
    }

    public function testThePictureIsDecorativeAndSitsBehindTheWords(): void
    {
        $html = $this->render(['panel' => 'medium'] + $this->withPicture());

        self::assertMatchesRegularExpression('~<div class="cta-band__media[^"]*" aria-hidden="true">\s*<img src="/assets/media/cta\.jpg" alt="" width="1600" height="900" loading="lazy" decoding="async">~', $html);
        self::assertStringContainsString('cta-band--has-media', $html);
        // The layers in order: the picture (with its overlay) before the panel before the words.
        self::assertLessThan(strpos($html, 'cta-band__content--panel'), strpos($html, 'cta-band__media'));
        self::assertLessThan(strpos($html, '<h2>'), strpos($html, 'cta-band__content--panel'));
    }

    public function testAFocusPointIsTheOnlyStyleAndComesFromItsList(): void
    {
        $html = $this->render(['background_focus' => 'top'] + $this->withPicture());

        self::assertStringContainsString('style="object-position: ' . ImageFocus::objectPosition('top') . ';"', $html);
        self::assertSame(1, substr_count($html, 'style='));
    }

    public function testFullWidthPaintsTheSectionAndKeepsTheWordsInTheContainer(): void
    {
        $colour = $this->render(['full_width' => true] + $this->legacy());
        self::assertStringContainsString('<section class="cta-section cta-section--full">', $colour, 'full width works without a picture');
        self::assertStringNotContainsString('cta-band--card', $colour, 'no card inside a full-width band');
        self::assertStringContainsString('<div class="container">', $colour);

        $picture = $this->render(['full_width' => true] + $this->withPicture());
        self::assertStringContainsString('cta-section--full cta-section--has-media', $picture);
        // The picture belongs to the section: it comes before the container.
        self::assertLessThan(strpos($picture, '<div class="container">'), strpos($picture, 'cta-band__media'));
        self::assertStringNotContainsString('cta-band--has-media', $picture);
    }

    public function testTheStylesheetHasARuleForEveryWordAndNoViewportTricks(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/cta-band.css');
        $rules = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        foreach (['left', 'right'] as $align) {
            self::assertStringContainsString('.cta-band--align-' . $align, $rules);
        }
        foreach (CtaBandContent::LEAD_WIDTHS as $width) {
            self::assertStringContainsString('.cta-band--lead-' . $width . ' .lead', $rules);
        }
        foreach (['none', 'light', 'dark'] as $overlay) {
            self::assertStringContainsString('.cta-band__media--overlay-' . $overlay, $rules);
        }
        foreach (['subtle', 'medium', 'solid'] as $opacity) {
            self::assertStringContainsString('.cta-band__content--panel-' . $opacity, $rules);
        }

        self::assertStringNotContainsString('100vw', $rules);
        self::assertDoesNotMatchRegularExpression('~margin(-inline|-left|-right)?\s*:\s*calc\(~', $rules, 'no negative-margin breakout');
        self::assertStringContainsString('--color-media-scrim-rgb', $rules, 'the overlay is the theme scrim');
        self::assertStringContainsString('--color-surface-veil-rgb', $rules, 'the panel is the theme surface');
    }

    /** @return array<string, mixed> a band as CtaBandContent gave it before CTA 2.0: no presentation keys */
    private function legacy(): array
    {
        return [
            'state' => CtaBandContent::STATE_ACTIVE,
            'eyebrow' => 'Samen',
            'title' => 'Samenwerken?',
            'lead' => 'Wij helpen graag.',
            'primary_label' => 'Neem contact op',
            'primary_url' => '/contact',
            'secondary_label' => 'Bekijk werk',
            'secondary_url' => '/werk',
        ];
    }

    /** @return array<string, mixed> */
    private function withPicture(): array
    {
        return ['background' => ['image_path' => '/assets/media/cta.jpg', 'width' => 1600, 'height' => 900]] + $this->legacy();
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
}
