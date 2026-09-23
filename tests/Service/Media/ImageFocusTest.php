<?php

declare(strict_types=1);

namespace Tests\Service\Media;

use App\Service\Media\ImageFocus;
use PHPUnit\Framework\TestCase;

/**
 * The nine focus points of a cropped picture (App\Service\Media\ImageFocus):
 * a closed list, the middle as the default, and one object-position per
 * point that the website and the editor's preview both use.
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
        self::assertSame('50% 50%', ImageFocus::objectPosition(ImageFocus::DEFAULT), 'what the browser does by itself: nothing moves');
    }

    public function testEveryPointIsAnObjectPosition(): void
    {
        $expected = [
            'top-left' => '0% 0%', 'top' => '50% 0%', 'top-right' => '100% 0%',
            'left' => '0% 50%', 'center' => '50% 50%', 'right' => '100% 50%',
            'bottom-left' => '0% 100%', 'bottom' => '50% 100%', 'bottom-right' => '100% 100%',
        ];

        foreach ($expected as $key => $position) {
            self::assertSame($key, ImageFocus::normalise($key));
            self::assertSame($position, ImageFocus::objectPosition($key));
        }
    }

    public function testAnythingElseIsTheMiddleAndNeverCss(): void
    {
        foreach (['', 'CENTER', '0% 0%', 'top;background:red', null, 3, ['top']] as $value) {
            self::assertSame('center', ImageFocus::normalise($value));
            self::assertSame('50% 50%', ImageFocus::objectPosition($value));
        }
    }
}
