<?php

declare(strict_types=1);

namespace Tests\Service\Media;

use App\Service\Media\ImageFocus;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value of one picture's presentation (Responsive Media 2.0,
 * App\Service\Media\ResponsiveImage), without a database: what a row reads
 * as, what is stored, what a form may change and what the partial gets.
 *
 * A phone picture of its own is looked up in the Media Library, so the
 * requests that name one live in Tests\Service\ResponsiveImageEditorHttpTest;
 * here only the ones that are refused before any lookup.
 */
final class ResponsiveImageTest extends TestCase
{
    private static function full(): ResponsiveImageSlot
    {
        return new ResponsiveImageSlot('image_', 'media_id', fit: true, mobileHeight: true);
    }

    private static function bare(): ResponsiveImageSlot
    {
        return new ResponsiveImageSlot('background_', 'background_media_id');
    }

    // ------------------------------------------------------------ the value

    /** @return iterable<string, array{0: callable(): ResponsiveImage}> */
    public static function impossibleValues(): iterable
    {
        yield 'a point beyond the frame' => [static fn () => new ResponsiveImage(101, 50)];
        yield 'a negative point' => [static fn () => new ResponsiveImage(50, -1)];
        yield 'half a phone point' => [static fn () => new ResponsiveImage(mobileFocusX: 20)];
        yield 'a phone point beyond the frame' => [static fn () => new ResponsiveImage(mobileFocusX: 20, mobileFocusY: 120)];
        yield 'a fit outside the list' => [static fn () => new ResponsiveImage(fit: 'stretch')];
        yield 'a phone fit outside the list' => [static fn () => new ResponsiveImage(mobileFit: 'fill')];
        yield 'a phone height outside the list' => [static fn () => new ResponsiveImage(mobileHeight: '900px')];
        yield 'no library id' => [static fn () => new ResponsiveImage(mobileMediaId: 0)];
    }

    #[DataProvider('impossibleValues')]
    public function testAnImpossibleValueCannotExist(callable $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public function testTheDefaultIsWhatEveryPictureDidBefore(): void
    {
        $value = new ResponsiveImage();

        self::assertSame([50, 50, null, null, null, 'cover', null, null], [
            $value->focusX, $value->focusY, $value->mobileMediaId, $value->mobileFocusX, $value->mobileFocusY,
            $value->fit, $value->mobileFit, $value->mobileHeight,
        ]);
        self::assertEquals($value, ResponsiveImage::fromRow([], self::full()), 'a row without the columns reads as the default');
    }

    public function testTheBreakpointIsOneNumber(): void
    {
        self::assertSame(640, ResponsiveImage::MOBILE_MAX_WIDTH);
        self::assertSame('(max-width: 640px)', ResponsiveImage::mobileMedia());
    }

    // -------------------------------------------------------------- the row

    public function testARowReadsAsItsValueAndTheColumnsAreTheSlotsOwn(): void
    {
        $row = [
            'image_focus_x' => 37, 'image_focus_y' => '64',
            'image_mobile_media_id' => '12', 'image_mobile_focus_x' => 5, 'image_mobile_focus_y' => 95,
            'image_fit' => 'contain', 'image_mobile_fit' => 'cover', 'image_mobile_height' => 'large',
        ];

        $value = ResponsiveImage::fromRow($row, self::full());
        self::assertSame([37, 64, 12, 5, 95, 'contain', 'cover', 'large'], [
            $value->focusX, $value->focusY, $value->mobileMediaId, $value->mobileFocusX, $value->mobileFocusY,
            $value->fit, $value->mobileFit, $value->mobileHeight,
        ]);

        $stored = $value->toRow(self::full());
        self::assertSame(array_keys($row), array_keys($stored));
        self::assertEquals($value, ResponsiveImage::fromRow($stored, self::full()), 'what is stored reads back as itself');

        // A slot without a frame of its own stores no fit and no height.
        self::assertSame(
            ['background_focus_x', 'background_focus_y', 'background_mobile_media_id', 'background_mobile_focus_x', 'background_mobile_focus_y'],
            array_keys((new ResponsiveImage())->toRow(self::bare()))
        );
        self::assertSame(self::bare()->columns(), array_keys((new ResponsiveImage())->toRow(self::bare())));
        self::assertSame(self::full()->columns(), array_keys((new ResponsiveImage())->toRow(self::full())));
    }

    public function testAnOddCellReadsAsItsDefaultAndNeverBreaksAPage(): void
    {
        $value = ResponsiveImage::fromRow([
            'image_focus_x' => 255, 'image_focus_y' => 'middle',
            'image_mobile_media_id' => '-4', 'image_mobile_focus_x' => 20, 'image_mobile_focus_y' => null,
            'image_fit' => 'stretch', 'image_mobile_fit' => 'fill', 'image_mobile_height' => '9999px',
        ], self::full());

        self::assertSame([100, 50, null, null, null, 'cover', null, null], [
            $value->focusX, $value->focusY, $value->mobileMediaId, $value->mobileFocusX, $value->mobileFocusY,
            $value->fit, $value->mobileFit, $value->mobileHeight,
        ]);

        // A slot that has no fit or height ignores cells it does not own.
        $bare = ResponsiveImage::fromRow(['background_fit' => 'contain', 'background_mobile_height' => 'large'], self::bare());
        self::assertSame(['cover', null], [$bare->fit, $bare->mobileHeight]);
    }

    // ---------------------------------------------------------- the request

    public function testAFormWithoutTheFieldKeepsWhatIsStored(): void
    {
        $stored = new ResponsiveImage(10, 20, null, 30, 40, 'contain', 'cover', 'compact');

        [$value, $errors] = ResponsiveImage::fromRequest(['title' => 'Iets anders'], self::full(), $stored);

        self::assertSame([], $errors);
        self::assertEquals($stored, $value);
    }

    public function testAPointIsClampedAndSomethingElseIsRefused(): void
    {
        $stored = new ResponsiveImage(10, 20);

        [$clamped, $errors] = ResponsiveImage::fromRequest(['image_focus_x' => '180', 'image_focus_y' => '-3'], self::full(), $stored);
        self::assertSame([], $errors);
        self::assertSame([100, 0], [$clamped->focusX, $clamped->focusY]);

        [$rounded] = ResponsiveImage::fromRequest(['image_focus_x' => '37.6', 'image_focus_y' => '0'], self::full(), $stored);
        self::assertSame([38, 0], [$rounded->focusX, $rounded->focusY]);

        foreach (['10% 20%', '', 'abc', '1e3'] as $posted) {
            [$refused, $errors] = ResponsiveImage::fromRequest(['image_focus_x' => $posted, 'image_focus_y' => '50'], self::full(), $stored);
            self::assertSame(['focus'], array_keys($errors), var_export($posted, true));
            self::assertSame([10, 20], [$refused->focusX, $refused->focusY], 'a refused point keeps what is stored');
        }

        [, $errors] = ResponsiveImage::fromRequest(['image_focus_x' => ['50'], 'image_focus_y' => '50'], self::full(), $stored);
        self::assertSame(['focus'], array_keys($errors), 'an array is no number');
    }

    public function testThePhonesPictureIsTheDesktopOneOrALibraryPictureNothingElse(): void
    {
        $stored = new ResponsiveImage(mobileMediaId: 12, mobileFocusX: 30, mobileFocusY: 40);

        [$desktop, $errors] = ResponsiveImage::fromRequest(['image_presentation' => '1', 'image_mobile_source' => 'desktop', 'image_mobile_media_id' => '12'], self::full(), $stored);
        self::assertSame([], $errors);
        self::assertNull($desktop->mobileMediaId, '"Gebruik desktopafbeelding" drops the phone picture, whatever the hidden picker still holds');
        self::assertSame([null, null], [$desktop->mobileFocusX, $desktop->mobileFocusY], 'and with it the point that belonged to it');

        foreach (['phone' => 'error_mobile_media', 'own' => 'error_mobile_media_missing'] as $source => $message) {
            $input = ['image_presentation' => '1', 'image_mobile_source' => $source, 'image_mobile_media_id' => ''];
            [$refused, $errors] = ResponsiveImage::fromRequest($input, self::full(), $stored);
            self::assertSame(['mobile_media'], array_keys($errors), $source);
            self::assertStringNotContainsString('media.responsive', $errors['mobile_media'], 'a sentence, not a key');
            self::assertSame(12, $refused->mobileMediaId, 'a refused picture keeps what is stored');
        }

        [, $errors] = ResponsiveImage::fromRequest(['image_mobile_source' => 'own', 'image_mobile_media_id' => '12; DROP'], self::full(), $stored);
        self::assertSame(['mobile_media'], array_keys($errors), 'no digits, no lookup');
    }

    public function testThePhonesOwnPointFollowsItsSwitch(): void
    {
        $stored = new ResponsiveImage(10, 20, null, 30, 40);

        [$off] = ResponsiveImage::fromRequest(['image_presentation' => '1', 'image_mobile_source' => 'desktop', 'image_mobile_focus_x' => '5', 'image_mobile_focus_y' => '6'], self::full(), $stored);
        self::assertSame([null, null], [$off->mobileFocusX, $off->mobileFocusY], 'switched off: the phone follows the desktop point');

        [$on] = ResponsiveImage::fromRequest(['image_presentation' => '1', 'image_mobile_source' => 'desktop', 'image_mobile_focus_own' => '1', 'image_mobile_focus_x' => '5', 'image_mobile_focus_y' => '160'], self::full(), $stored);
        self::assertSame([5, 100], [$on->mobileFocusX, $on->mobileFocusY], 'switched on: its own point, clamped');

        [$bad, $errors] = ResponsiveImage::fromRequest(['image_presentation' => '1', 'image_mobile_focus_own' => '1', 'image_mobile_focus_x' => 'x', 'image_mobile_focus_y' => '6'], self::full(), $stored);
        self::assertSame(['mobile_focus'], array_keys($errors));
        self::assertSame([30, 40], [$bad->mobileFocusX, $bad->mobileFocusY]);

        [$without] = ResponsiveImage::fromRequest(['image_focus_x' => '1', 'image_focus_y' => '2'], self::full(), $stored);
        self::assertSame([30, 40], [$without->mobileFocusX, $without->mobileFocusY], 'a form without the field keeps the phone point');
    }

    public function testFitAndPhoneHeightAreClosedListsOfTheSlotsThatHaveThem(): void
    {
        $stored = new ResponsiveImage(fit: 'contain', mobileFit: 'cover', mobileHeight: 'compact');

        [$value, $errors] = ResponsiveImage::fromRequest(['image_fit' => 'cover', 'image_mobile_fit' => '', 'image_mobile_height' => ''], self::full(), $stored);
        self::assertSame([], $errors);
        self::assertSame(['cover', null, null], [$value->fit, $value->mobileFit, $value->mobileHeight], 'empty is "zelfde als desktop" and "automatisch"');

        [$refused, $errors] = ResponsiveImage::fromRequest(['image_fit' => 'stretch', 'image_mobile_fit' => 'fill', 'image_mobile_height' => 'huge'], self::full(), $stored);
        self::assertSame(['fit', 'mobile_fit', 'mobile_height'], array_keys($errors));
        self::assertSame(['contain', 'cover', 'compact'], [$refused->fit, $refused->mobileFit, $refused->mobileHeight]);

        // A slot without them never takes them from a request.
        [$bare, $errors] = ResponsiveImage::fromRequest(['background_fit' => 'contain', 'background_mobile_height' => 'large'], self::bare(), new ResponsiveImage());
        self::assertSame([], $errors);
        self::assertSame(['cover', null], [$bare->fit, $bare->mobileHeight]);
    }

    // ------------------------------------------------------------ rendering

    public function testAPictureWithoutPhoneSettingsRendersAsItAlwaysDid(): void
    {
        $picture = (new ResponsiveImage())->forRender(['image_path' => '/assets/media/a.jpg', 'alt' => 'Werkplaats', 'width' => 800, 'height' => 600]);

        self::assertSame([
            'src' => '/assets/media/a.jpg', 'alt' => 'Werkplaats', 'width' => 800, 'height' => 600,
            'mobile' => null, 'position' => '50% 50%', 'mobile_position' => null, 'fit' => 'cover', 'mobile_fit' => null,
        ], $picture);
    }

    public function testAPhoneGetsOnlyWhatDiffersFromALargeScreen(): void
    {
        $same = (new ResponsiveImage(20, 30, null, 20, 30, 'contain', 'contain'))->forRender(['src' => '/a.jpg']);
        self::assertSame([null, null], [$same['mobile_position'], $same['mobile_fit']], 'the same point and fit twice is nothing extra');

        $own = (new ResponsiveImage(20, 30, null, 70, 80, 'cover', 'contain'))->forRender(['src' => '/a.jpg']);
        self::assertSame(['20% 30%', '70% 80%', 'cover', 'contain'], [$own['position'], $own['mobile_position'], $own['fit'], $own['mobile_fit']]);

        // A media sequence's slides never take the phone picture: no lookup at
        // all. Nor the point set on that phone picture: a slide keeps the
        // desktop point on a phone. The phone fit is the frame's, and stays.
        $slide = (new ResponsiveImage(10, 20, 999999, 70, 80, 'cover', 'contain'))->forRender(['src' => '/a.jpg'], false);
        self::assertSame([null, null, 'contain'], [$slide['mobile'], $slide['mobile_position'], $slide['mobile_fit']]);

        // A point of its own on the desktop picture itself goes to every slide.
        $own = (new ResponsiveImage(10, 20, null, 70, 80))->forRender(['src' => '/a.jpg'], false);
        self::assertSame('70% 80%', $own['mobile_position']);
    }

    public function testAPhonePictureOfItsOwnAlwaysHasItsOwnPoint(): void
    {
        self::assertNull((new ResponsiveImage(10, 20))->mobileFocus(false), 'the desktop picture follows the desktop point');
        self::assertSame([50, 50], (new ResponsiveImage(10, 20))->mobileFocus(true), 'another picture starts in its middle');
        self::assertSame([5, 6], (new ResponsiveImage(10, 20, null, 5, 6))->mobileFocus(false));
        self::assertSame('contain', (new ResponsiveImage(fit: 'contain'))->effectiveMobileFit());
        self::assertSame('cover', (new ResponsiveImage(fit: 'contain', mobileFit: 'cover'))->effectiveMobileFit());
    }

    public function testTheSmallerTools(): void
    {
        self::assertSame('0% 100%', ResponsiveImage::objectPosition(-5, 140), 'clamped, never a number from outside the frame');
        self::assertSame([100, 0], [(new ResponsiveImage())->withFocus(300, -2)->focusX, (new ResponsiveImage())->withFocus(300, -2)->focusY]);

        $cover = (new ResponsiveImage(10, 20, 7, 30, 40, 'contain', 'contain', 'large'))->coverOnly();
        self::assertSame([10, 20, 7, 30, 40, 'cover', null, 'large'], [
            $cover->focusX, $cover->focusY, $cover->mobileMediaId, $cover->mobileFocusX, $cover->mobileFocusY,
            $cover->fit, $cover->mobileFit, $cover->mobileHeight,
        ], 'behind text only the fit gives way');

        foreach (ResponsiveImage::FLAT_RATIOS as $ratio) {
            self::assertSame($ratio, ResponsiveImage::flatRatio($ratio));
        }
        foreach (['', '2-1', null, 43, '4:3'] as $odd) {
            self::assertSame('auto', ResponsiveImage::flatRatio($odd));
        }

        // The nine presets are points of the same value.
        foreach (ImageFocus::keys() as $key) {
            [$x, $y] = ImageFocus::point($key);
            self::assertSame($key, ImageFocus::keyFor((new ResponsiveImage($x, $y))->focusX, (new ResponsiveImage($x, $y))->focusY));
        }
    }

    public function testACompactPlaceShowsThePhonePictureWithItsPointAndFitEverywhere(): void
    {
        $picture = [
            'src' => '/desktop.jpg', 'alt' => 'Werkplaats', 'width' => 1600, 'height' => 900,
            'mobile' => ['src' => '/phone.jpg', 'width' => 900, 'height' => 1600],
            'position' => '30% 40%', 'mobile_position' => '50% 0%', 'fit' => 'cover', 'mobile_fit' => 'contain',
        ];

        self::assertSame([
            'src' => '/phone.jpg', 'alt' => 'Werkplaats', 'width' => 900, 'height' => 1600,
            'mobile' => null, 'position' => '50% 0%', 'mobile_position' => null, 'fit' => 'contain', 'mobile_fit' => null,
        ], ResponsiveImage::compact($picture), 'the same alt text, the phone picture\'s own size, point and fit, nothing left to switch');

        // A phone point or fit equal to the large screen's is not repeated: the large screen's applies.
        $same = ResponsiveImage::compact(['mobile_position' => null, 'mobile_fit' => null] + $picture);
        self::assertSame(['30% 40%', 'cover'], [$same['position'], $same['fit']]);

        // Without a phone picture of its own nothing changes: the desktop
        // picture everywhere, a phone point still only below the breakpoint.
        $desktop = ['mobile' => null] + $picture;
        self::assertSame($desktop, ResponsiveImage::compact($desktop));
    }

    public function testASlotNamesOnlyLowercaseColumns(): void
    {
        self::assertSame('image_focus_x', self::full()->column('focus_x'));

        $this->expectException(\InvalidArgumentException::class);
        new ResponsiveImageSlot('Image; DROP ', 'media_id');
    }
}
