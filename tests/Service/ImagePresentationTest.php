<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Media\ImagePresentation;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use App\Service\TextImageSplitContent;
use PHPUnit\Framework\TestCase;

/**
 * Responsive Media 3.1: the one meaning of Compact, Normaal and Groot
 * (App\Service\Media\ImagePresentation) — its closed steps and views, the
 * lengths it reads from a block's tokens, and the frames it hands the CMS.
 * Needs no database (suite unit).
 */
final class ImagePresentationTest extends TestCase
{
    public function testThreeStepsAndThreeViewsAreClosedLists(): void
    {
        self::assertSame(['compact', 'normal', 'large'], ImagePresentation::STEPS);
        self::assertSame(['desktop', 'tablet', 'mobile'], ImagePresentation::VIEWS);
        self::assertSame(ImagePresentation::STEPS, ResponsiveImage::MOBILE_HEIGHTS, 'a phone\'s own height is one of the steps');

        // Both vocabularies name the same three steps, nothing else does.
        self::assertSame('compact', ImagePresentation::step('small'));
        self::assertSame('normal', ImagePresentation::step('medium'));
        self::assertSame('large', ImagePresentation::step('large'));
        self::assertSame('compact', ImagePresentation::step('compact'));
        self::assertSame('normal', ImagePresentation::step('normal'));
        foreach (['xlarge', 'huge', '999', '', 'clamp(1rem, 2vw, 3rem)', 'LARGE'] as $word) {
            self::assertNull(ImagePresentation::step($word), $word);
        }
        self::assertNull(ImagePresentation::step(null));
    }

    public function testTheReferenceScreensAndTheirContentWidths(): void
    {
        self::assertSame([1280, 900], ImagePresentation::viewport('desktop'));
        self::assertSame([768, 1024], ImagePresentation::viewport('tablet'));
        self::assertSame([375, 812], ImagePresentation::viewport('mobile'));

        // core.css: .container at most 1200px with var(--sp-4) inside, var(--sp-3) on a phone.
        self::assertSame(1136, ImagePresentation::contentWidth('desktop'));
        self::assertSame(704, ImagePresentation::contentWidth('tablet'));
        self::assertSame(327, ImagePresentation::contentWidth('mobile'));

        $this->expectException(\InvalidArgumentException::class);
        ImagePresentation::viewport('watch');
    }

    public function testALengthIsWorkedOutAsTheBrowserWould(): void
    {
        self::assertSame(320.0, ImagePresentation::length('20rem', 'desktop'));
        self::assertSame(12.0, ImagePresentation::length('12px', 'mobile'));
        self::assertEqualsWithDelta(307.2, ImagePresentation::length('clamp(14rem, 24vw, 20rem)', 'desktop'), 0.001);
        self::assertSame(224.0, ImagePresentation::length('clamp(14rem, 24vw, 20rem)', 'mobile'), 'the floor');
        self::assertSame(320.0, ImagePresentation::length('clamp(14rem, 50vw, 20rem)', 'desktop'), 'the cap is never passed');
        self::assertSame(416.0, ImagePresentation::length('min(26rem, 80vh)', 'mobile'));
        self::assertEqualsWithDelta(665.6, ImagePresentation::length('min(clamp(26rem, 52vw, 44rem), 90vh)', 'desktop'), 0.001, 'nested');
        self::assertEqualsWithDelta(495.0, ImagePresentation::length('clamp(26rem, 55vh, 38rem)', 'desktop'), 0.001, 'vh against the reference height');
        self::assertSame(4 / 3, ImagePresentation::ratio('4 / 3'));
        self::assertSame(1.0, ImagePresentation::ratio('1 / 1'));
    }

    public function testAnythingThatIsNotALengthIsRefused(): void
    {
        foreach (['huge', '999', 'calc(1rem + 2vw)', '12em', 'clamp(1rem, 2vw)', '20rem; color: red', 'url(x)'] as $css) {
            try {
                ImagePresentation::length($css, 'desktop');
                self::fail('accepted: ' . $css);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['4/0', 'four / three', '4 / 3; x', ''] as $css) {
            try {
                ImagePresentation::ratio($css);
                self::fail('accepted ratio: ' . $css);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAFrameIsAShapeAndAHalfSizeAndTheStyleLetsNothingElseThrough(): void
    {
        self::assertSame(
            ['--admin-rm-tablet-ratio' => '704 / 396', '--admin-rm-tablet-width' => '352px'],
            ImagePresentation::frame('tablet', 704.0, 396.0)
        );

        self::assertSame(
            '--admin-rm-desktop-ratio: 536 / 307; --admin-rm-desktop-width: 268px;',
            ImagePresentation::style(['--admin-rm-desktop-ratio' => '536 / 307', '--admin-rm-desktop-width' => '268px'])
        );
        // Neither a forged name nor a forged value reaches the style attribute.
        self::assertSame('', ImagePresentation::style([
            'color' => 'red',
            '--admin-rm-desktop-ratio' => '1 / 1; background: url(x)',
            '--admin-rm-desktop-width' => '20rem',
            '--admin-rm-desktop-shape' => '1 / 1',
            '--other' => '1px',
        ]));

        $this->expectException(\InvalidArgumentException::class);
        ImagePresentation::frame('desktop', 0.0, 10.0);
    }

    public function testAForgedSizeIsNeverStoredOrShown(): void
    {
        // The block's own height: a closed list, anything else is the default.
        foreach (['huge', '999', 'clamp(1rem, 2vw, 3rem)', ['large'], null] as $forged) {
            self::assertSame('medium', TextImageSplitContent::layout(['image_height' => $forged])['image_height']);
        }

        // A phone's own height: refused with a message, the stored value stays.
        $slot = TextImageSplitContent::imageSlot();
        $stored = new ResponsiveImage(mobileHeight: 'large');
        foreach (['huge', '999', '24rem; color: red'] as $forged) {
            [$value, $errors] = ResponsiveImage::fromRequest(['image_presentation' => '1', 'image_mobile_height' => $forged], $slot, $stored);
            self::assertArrayHasKey('mobile_height', $errors, $forged);
            self::assertSame('large', $value->mobileHeight, $forged);
        }

        // And a frame key that is not a combination of the closed lists has no frame.
        self::assertArrayNotHasKey('50|huge|', TextImageSplitContent::editorFrames()['shapes']);
        self::assertInstanceOf(ResponsiveImageSlot::class, $slot);

        // A forged phone height is automatic, never a step of its own.
        foreach (['huge', '', '24rem', 'small', 'medium', 'LARGE'] as $forged) {
            self::assertSame(['normal', ImagePresentation::AUTOMATIC], ImagePresentation::onPhone('medium', $forged), $forged);
            self::assertSame(TextImageSplitContent::pictureSize('mobile', '50', 'medium'), TextImageSplitContent::pictureSize('mobile', '50', 'medium', $forged), $forged);
        }
    }

    public function testAPhoneEitherFollowsTheBlockOrHasAStepOfItsOwn(): void
    {
        self::assertSame(['compact', ImagePresentation::AUTOMATIC], ImagePresentation::onPhone('small', null));
        self::assertSame(['large', ImagePresentation::AUTOMATIC], ImagePresentation::onPhone('large', null));
        self::assertSame([null, ImagePresentation::AUTOMATIC], ImagePresentation::onPhone('xlarge', null), 'not a step: the block decides');
        foreach (ResponsiveImage::MOBILE_HEIGHTS as $own) {
            foreach (['small', 'medium', 'large', 'xlarge'] as $stored) {
                self::assertSame([$own, ImagePresentation::OWN], ImagePresentation::onPhone($stored, $own), "{$own} over {$stored}");
            }
        }
    }

    /**
     * Responsive Media 3.1.1: wherever a choice is one of these three steps,
     * the editor reads Compact, Normaal and Groot (Compact, Normal, Large),
     * while the stored words stay small / medium / large.
     */
    public function testTheStepsReadCompactNormaalGrootEverywhere(): void
    {
        $words = [
            'nl' => ['Compact', 'Normaal', 'Groot'],
            'en' => ['Compact', 'Normal', 'Large'],
        ];
        foreach ($words as $language => $labels) {
            $messages = require __DIR__ . '/../../src/Service/Language/messages/' . $language . '.php';
            foreach (['block_textimage.hoogte_', 'block_media_banner.height_', 'block_pagehero.height_'] as $prefix) {
                self::assertSame($labels, [$messages[$prefix . 'small'], $messages[$prefix . 'medium'], $messages[$prefix . 'large']], $language . ' ' . $prefix);
            }
            self::assertSame($labels, [
                $messages['media.responsive.mobile_height_compact'],
                $messages['media.responsive.mobile_height_normal'],
                $messages['media.responsive.mobile_height_large'],
            ], $language . ' phone height');
        }
    }
}
