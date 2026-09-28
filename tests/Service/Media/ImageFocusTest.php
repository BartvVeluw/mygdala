<?php

declare(strict_types=1);

namespace Tests\Service\Media;

use App\Service\Media\ImageFocus;
use App\Service\Media\ResponsiveImage;
use PHPUnit\Framework\TestCase;

/**
 * The nine one-click points of a focus point (App\Service\Media\ImageFocus,
 * Responsive Media 2.0): a closed list in reading order, each a pair of whole
 * percentages — exactly the object-position the nine keys always stood for,
 * which is what db/migrations/20260928220000 turned every stored key into.
 */
final class ImageFocusTest extends TestCase
{
    public function testNinePointsRowByRowWithTheMiddleAsTheDefault(): void
    {
        self::assertSame(
            ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'],
            ImageFocus::keys()
        );
        self::assertSame('center', ImageFocus::DEFAULT);
        self::assertSame([50, 50], ImageFocus::point(ImageFocus::DEFAULT), 'what the browser does by itself: nothing moves');
    }

    public function testEveryPointIsThePairOfItsOldObjectPosition(): void
    {
        $expected = [
            'top-left' => '0% 0%', 'top' => '50% 0%', 'top-right' => '100% 0%',
            'left' => '0% 50%', 'center' => '50% 50%', 'right' => '100% 50%',
            'bottom-left' => '0% 100%', 'bottom' => '50% 100%', 'bottom-right' => '100% 100%',
        ];

        foreach ($expected as $key => $position) {
            [$x, $y] = ImageFocus::point($key);
            self::assertSame($position, ResponsiveImage::objectPosition($x, $y), $key);
            self::assertSame($key, ImageFocus::keyFor($x, $y), 'a pair on a preset shows that preset as chosen');
        }
    }

    public function testAnythingElseIsTheMiddleAndAPointOfItsOwnIsNoPreset(): void
    {
        foreach (['', 'CENTER', '0% 0%', 'top;background:red', null, 3, ['top']] as $value) {
            self::assertSame([50, 50], ImageFocus::point($value));
        }

        self::assertNull(ImageFocus::keyFor(37, 64));
        self::assertNull(ImageFocus::keyFor(0, 49));
    }
}
