<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Media\ResponsiveImage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../partials/responsive-image.php';

/**
 * THE markup of a block picture (partials/responsive-image.php, Responsive
 * Media 2.0), without a database:
 *
 *   - without phone settings it is the one <img> every block always printed,
 *     character for character, so an existing page does not change;
 *   - with a phone picture it is a <picture> with exactly one source for the
 *     one breakpoint, the alt text on the <img> only — the browser downloads
 *     one of the two, never both;
 *   - a phone's own point or fit is a custom property plus the attribute that
 *     switches it on below the breakpoint, never a second stylesheet;
 *   - everything is escaped.
 */
final class ResponsiveImageRenderTest extends TestCase
{
    /** @param array<string, mixed> $options */
    private static function html(ResponsiveImage $value, array $options = [], ?array $mobile = null): string
    {
        $picture = $value->forRender(['src' => '/assets/media/a.jpg', 'alt' => 'Werkplaats', 'width' => 1600, 'height' => 900], false);
        $picture['mobile'] = $mobile;

        return responsive_image_html($picture, $options);
    }

    public function testWithoutPhoneSettingsItIsThePlainImgItAlwaysWas(): void
    {
        self::assertSame(
            '<img src="/assets/media/a.jpg" alt="Werkplaats" width="1600" height="900" loading="lazy">',
            self::html(new ResponsiveImage())
        );

        self::assertSame(
            '<img class="orbit-card__image" src="/assets/media/a.jpg" alt="Werkplaats" width="1600" height="900" loading="eager" decoding="async" fetchpriority="high">',
            self::html(new ResponsiveImage(), ['class' => 'orbit-card__image', 'loading' => 'eager', 'decoding' => true, 'fetchpriority' => true])
        );
    }

    public function testThePointAndTheFitAreInlineAndTheMiddleIsLeftToTheBrowser(): void
    {
        self::assertStringContainsString(' style="object-position: 30% 40%;"', self::html(new ResponsiveImage(30, 40)));
        self::assertStringNotContainsString('style=', self::html(new ResponsiveImage(50, 50)));
        self::assertStringContainsString(' style="object-position: 50% 50%;"', self::html(new ResponsiveImage(), ['position' => 'always']), 'a block whose CSS sets another position asks for it');
        self::assertStringContainsString(' style="object-fit: contain;"', self::html(new ResponsiveImage(fit: 'contain')));
    }

    public function testAPhonePictureIsOnePictureWithOneSourceAndTheAltTextOnTheImg(): void
    {
        $html = self::html(new ResponsiveImage(), [], ['src' => '/assets/media/phone.jpg', 'width' => 900, 'height' => 1600]);

        self::assertSame(
            '<picture class="rm-picture"><source media="(max-width: 640px)" srcset="/assets/media/phone.jpg" width="900" height="1600">'
            . '<img src="/assets/media/a.jpg" alt="Werkplaats" width="1600" height="900" loading="lazy"></picture>',
            $html
        );
        self::assertSame(1, substr_count($html, '<source'));
        self::assertSame(1, substr_count($html, 'alt='), 'one alt text: the same content on every screen');
        self::assertStringContainsString('media="' . ResponsiveImage::mobileMedia() . '"', $html);
    }

    public function testAPhonesOwnPointAndFitAreCustomPropertiesSwitchedOnByAnAttribute(): void
    {
        $html = self::html(new ResponsiveImage(20, 30, null, 70, 80, 'cover', 'contain'));

        self::assertStringContainsString('style="object-position: 20% 30%; --rm-mobile-position: 70% 80%; --rm-mobile-fit: contain;"', $html);
        self::assertStringContainsString(' data-rm-mobile-position', $html);
        self::assertStringContainsString(' data-rm-mobile-fit', $html);

        // The same point on a phone is nothing extra.
        $same = self::html(new ResponsiveImage(20, 30, null, 20, 30));
        self::assertStringNotContainsString('--rm-mobile-position', $same);
        self::assertStringNotContainsString('data-rm-', $same);
    }

    public function testADecorativePictureHasNoAltTextAndCanBeHiddenFromAScreenReader(): void
    {
        $html = self::html(new ResponsiveImage(), ['decorative' => true, 'aria_hidden' => true]);

        self::assertStringContainsString(' alt=""', $html);
        self::assertStringContainsString(' aria-hidden="true"', $html);
        self::assertStringNotContainsString('Werkplaats', $html);
    }

    public function testEverythingIsEscaped(): void
    {
        $picture = (new ResponsiveImage())->forRender(['src' => '/assets/media/a".jpg?x=<b>', 'alt' => 'Foto "met" <tag> & meer'], false);
        $picture['mobile'] = ['src' => '/assets/media/b c,d".jpg', 'width' => null, 'height' => null];

        $html = responsive_image_html($picture, ['class' => 'x" onerror="alert(1)']);

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<tag>', $html);
        self::assertStringNotContainsString('" onerror', $html);
        self::assertStringContainsString('alt="Foto &quot;met&quot; &lt;tag&gt; &amp; meer"', $html);
        // A space or a comma would split a srcset candidate in two.
        self::assertStringContainsString('srcset="/assets/media/b%20c%2Cd&quot;.jpg"', $html);
    }
}
