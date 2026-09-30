<?php

declare(strict_types=1);

namespace Tests\Service\Media;

use App\Service\Language\AdminLocale;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/partials/responsive-image.php';

/**
 * The zoom of Responsive Media 3.0 (App\Service\Media\ResponsiveImage,
 * partials/responsive-image.php, MEDIA.md "Responsive Media"), without a
 * database or a server:
 *
 *   - the value: 100 by default, 100-200, clamped from a row and from a
 *     request, refused when it is no number; a phone's own zoom lives and dies
 *     with the phone's own point;
 *   - the row: the two columns every slot has, read back as themselves; a row
 *     from before zoom existed reads as exactly what it showed;
 *   - rendering: at 100 the <img> is byte for byte the one it always was;
 *     a zoom is the CSS `scale` around the focus point; a contained picture is
 *     never zoomed; a phone gets its own zoom only with its own point, else
 *     the desktop one, and a compact place takes the phone's everywhere.
 */
final class ResponsiveImageZoomTest extends TestCase
{
    protected function setUp(): void
    {
        AdminLocale::overrideForTests('nl');
        MediaService::overrideForTests([
            7 => ['id' => 7, 'path' => 'assets/media/phone.jpg', 'thumbnail_path' => null, 'mime_type' => 'image/jpeg', 'width' => 900, 'height' => 1600],
        ]);
    }

    protected function tearDown(): void
    {
        MediaService::overrideForTests(null);
        AdminLocale::overrideForTests(null);
    }

    private static function slot(): ResponsiveImageSlot
    {
        return new ResponsiveImageSlot('image_', 'media_id', fit: true, mobileHeight: true);
    }

    private static function image(): array
    {
        return ['image_path' => '/assets/media/a.jpg', 'alt' => 'Werkplaats', 'width' => 1600, 'height' => 900];
    }

    // ------------------------------------------------------------ the value

    public function testTheDefaultIsNoZoomAndTheRangeIsOneHundredToTwoHundred(): void
    {
        self::assertSame(100, (new ResponsiveImage())->zoom, 'the default is 100: the frame filled as cover fills it');
        self::assertNull((new ResponsiveImage())->mobileZoom);
        self::assertSame([100, 200], [ResponsiveImage::ZOOM_MIN, ResponsiveImage::ZOOM_MAX]);
        self::assertSame(100, (new ResponsiveImage(zoom: 100))->zoom, 'the minimum');
        self::assertSame(200, (new ResponsiveImage(zoom: 200))->zoom, 'the maximum');

        foreach ([99, 201, 0, -5] as $outside) {
            try {
                new ResponsiveImage(zoom: $outside);
                self::fail($outside . ' is outside the range');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAPhonesZoomLivesAndDiesWithItsOwnPoint(): void
    {
        self::assertSame(100, (new ResponsiveImage(50, 50, null, 20, 30))->mobileZoom, 'a phone point of its own has a zoom: 100 unless given');
        self::assertSame(160, (new ResponsiveImage(50, 50, null, 20, 30, zoom: 120, mobileZoom: 160))->mobileZoom);

        $this->expectException(\InvalidArgumentException::class);
        new ResponsiveImage(zoom: 120, mobileZoom: 160);
    }

    public function testAPostedZoomIsClampedAndAnythingButANumberRefused(): void
    {
        $stored = new ResponsiveImage(40, 60, zoom: 130);
        $request = static fn (mixed $zoom): array => ResponsiveImage::fromRequest(['image_zoom' => $zoom], self::slot(), $stored);

        foreach (['100' => 100, '200' => 200, '150' => 150, '99' => 100, '0' => 100, '-40' => 100, '201' => 200, '99999' => 200, '137.6' => 138, ' 125 ' => 125] as $posted => $expected) {
            [$value, $errors] = $request((string) $posted);
            self::assertSame([], $errors, (string) $posted);
            self::assertSame($expected, $value->zoom, 'posted ' . $posted);
        }

        foreach (['', 'groot', 'NaN', 'INF', '1e3', '0x80', '150%', '<script>', ['150'], null, true] as $posted) {
            [$value, $errors] = $request($posted);
            self::assertSame(['zoom'], array_keys($errors), var_export($posted, true));
            self::assertSame(130, $value->zoom, 'refused: the stored zoom stays');
            self::assertStringContainsString('100% tot 200%', $errors['zoom']);
        }

        // A form without the field keeps what is stored.
        [$kept, $errors] = ResponsiveImage::fromRequest(['image_focus_x' => '10', 'image_focus_y' => '20'], self::slot(), $stored);
        self::assertSame([], $errors);
        self::assertSame([10, 20, 130], [$kept->focusX, $kept->focusY, $kept->zoom]);
    }

    public function testFocusAndZoomAreStoredTogetherAndReadBackAsThemselves(): void
    {
        [$value, $errors] = ResponsiveImage::fromRequest([
            'image_presentation' => '1',
            'image_focus_x' => '0', 'image_focus_y' => '100', 'image_zoom' => '175',
            'image_mobile_source' => 'desktop', 'image_mobile_focus_own' => '1',
            'image_mobile_focus_x' => '80', 'image_mobile_focus_y' => '10', 'image_mobile_zoom' => '140',
        ], self::slot(), new ResponsiveImage());
        self::assertSame([], $errors);

        $row = $value->toRow(self::slot());
        self::assertSame([0, 100, 175, 80, 10, 140], [$row['image_focus_x'], $row['image_focus_y'], $row['image_zoom'], $row['image_mobile_focus_x'], $row['image_mobile_focus_y'], $row['image_mobile_zoom']]);
        self::assertEquals($value, ResponsiveImage::fromRow($row, self::slot()));
        self::assertSame(self::slot()->columns(), array_keys($row), 'exactly the slot\'s columns, zoom included');

        // withFocus() and coverOnly() keep the zoom.
        self::assertSame([175, 140], [$value->withFocus(3, 4)->zoom, $value->withFocus(3, 4)->mobileZoom]);
        self::assertSame([175, 140], [$value->coverOnly()->zoom, $value->coverOnly()->mobileZoom]);
    }

    public function testAPhoneWithoutItsOwnPointStoresNoZoomAndAPhonePointStartsFromOneHundred(): void
    {
        $stored = new ResponsiveImage(50, 50, null, 20, 30, zoom: 150, mobileZoom: 180);

        // The switch goes off: the phone follows the desktop point and zoom.
        [$off] = ResponsiveImage::fromRequest(['image_presentation' => '1', 'image_mobile_source' => 'desktop', 'image_mobile_zoom' => '190'], self::slot(), $stored);
        self::assertNull($off->mobileFocusX);
        self::assertNull($off->mobileZoom);
        self::assertNull($off->toRow(self::slot())['image_mobile_zoom']);

        // A phone picture of its own without a posted zoom: its stored one, else 100.
        [$own] = ResponsiveImage::fromRequest(['image_presentation' => '1', 'image_mobile_source' => 'own', 'image_mobile_media_id' => '7'], self::slot(), new ResponsiveImage());
        self::assertSame([7, 50, 50, 100], [$own->mobileMediaId, $own->mobileFocusX, $own->mobileFocusY, $own->mobileZoom]);

        // A phone zoom that is no number is refused on its own part.
        [$refused, $errors] = ResponsiveImage::fromRequest(['image_presentation' => '1', 'image_mobile_source' => 'desktop', 'image_mobile_focus_own' => '1', 'image_mobile_focus_x' => '10', 'image_mobile_focus_y' => '10', 'image_mobile_zoom' => 'veel'], self::slot(), $stored);
        self::assertSame(['mobile_zoom'], array_keys($errors));
        self::assertSame(180, $refused->mobileZoom, 'the stored phone zoom stays');
    }

    public function testARowFromBeforeZoomReadsAsWhatItAlwaysShowed(): void
    {
        // No zoom columns at all (a row read before the migration), and the
        // migration's own defaults (100 and NULL).
        foreach ([[], ['image_zoom' => 100, 'image_mobile_zoom' => null], ['image_zoom' => '100', 'image_mobile_zoom' => '']] as $zoomColumns) {
            $legacy = ResponsiveImage::fromRow(['image_focus_x' => 20, 'image_focus_y' => 80, 'image_mobile_focus_x' => 5, 'image_mobile_focus_y' => 95, 'image_fit' => 'contain'] + $zoomColumns, self::slot());
            self::assertSame([20, 80, 5, 95, 'contain', 100, 100], [$legacy->focusX, $legacy->focusY, $legacy->mobileFocusX, $legacy->mobileFocusY, $legacy->fit, $legacy->zoom, $legacy->mobileZoom]);
        }

        // A hand-written cell never breaks a page: clamped, or the default.
        $odd = ResponsiveImage::fromRow(['image_zoom' => 255, 'image_mobile_focus_x' => 1, 'image_mobile_focus_y' => 1, 'image_mobile_zoom' => 'x'], self::slot());
        self::assertSame([200, 100], [$odd->zoom, $odd->mobileZoom]);
        self::assertSame(100, ResponsiveImage::fromRow(['image_zoom' => 7], self::slot())->zoom);
        self::assertNull(ResponsiveImage::fromRow(['image_mobile_zoom' => 150], self::slot())->mobileZoom, 'no phone point, no phone zoom');
    }

    // ------------------------------------------------------------ rendering

    public function testAtOneHundredThePictureIsExactlyTheOneItAlwaysWas(): void
    {
        $legacy = '<img src="/assets/media/a.jpg" alt="Werkplaats" width="1600" height="900" loading="lazy" style="object-position: 20% 80%;">';
        self::assertSame($legacy, responsive_image_html((new ResponsiveImage(20, 80))->forRender(self::image())));
        self::assertSame($legacy, responsive_image_html((new ResponsiveImage(20, 80, zoom: 100))->forRender(self::image())));

        // A picture rendered by a caller that never heard of zoom (no key).
        $old = (new ResponsiveImage(20, 80))->forRender(self::image());
        unset($old['zoom'], $old['mobile_zoom']);
        self::assertSame($legacy, responsive_image_html($old));
    }

    public function testAZoomIsTheScaleAroundTheFocusPoint(): void
    {
        self::assertSame(['1', '1.25', '1.5', '1.33', '2'], array_map([ResponsiveImage::class, 'scale'], [100, 125, 150, 133, 200]));

        self::assertStringContainsString(
            'style="object-position: 20% 80%; scale: 1.25; transform-origin: 20% 80%;"',
            responsive_image_html((new ResponsiveImage(20, 80, zoom: 125))->forRender(self::image()))
        );
        self::assertStringContainsString(
            'style="object-position: 0% 0%; scale: 2; transform-origin: 0% 0%;"',
            responsive_image_html((new ResponsiveImage(0, 0, zoom: 200))->forRender(self::image())),
            'a preset (top left) at the maximum'
        );
        self::assertStringContainsString(
            'style="object-position: 100% 100%; scale: 1.5; transform-origin: 100% 100%;"',
            responsive_image_html((new ResponsiveImage(100, 100, zoom: 150))->forRender(self::image()))
        );

        // The middle is the browser's own origin, as it is its own position.
        self::assertStringContainsString('style="scale: 1.5;"', responsive_image_html((new ResponsiveImage(zoom: 150))->forRender(self::image())));

        // Every preset works at every zoom: the point stays the point.
        foreach (ImageFocus::keys() as $key) {
            [$x, $y] = ImageFocus::point($key);
            $html = responsive_image_html((new ResponsiveImage($x, $y, zoom: 140))->forRender(self::image()));
            self::assertStringContainsString('scale: 1.4;', $html, $key);
            if ([$x, $y] !== [50, 50]) {
                self::assertStringContainsString('transform-origin: ' . $x . '% ' . $y . '%;', $html, $key);
            }
        }
    }

    public function testTheZoomStaysInItsFrameWithoutMovingAnythingAroundIt(): void
    {
        $html = responsive_image_html((new ResponsiveImage(30, 40, zoom: 200))->forRender(self::image()), ['class' => 'hover-card__image']);

        // The same one <img>, the same size attributes that reserve its room:
        // no wrapper, no other width or height, no margin, no position.
        self::assertSame(1, substr_count($html, '<img'));
        self::assertStringStartsWith('<img class="hover-card__image" src="/assets/media/a.jpg" alt="Werkplaats" width="1600" height="900"', $html);
        self::assertDoesNotMatchRegularExpression('/(?<![-\w])(width|height|margin|top|left|position):/', str_replace(['object-position', 'transform-origin'], '', $html));
        // `scale`, not `transform`: a block's own transform (hover zoom) composes with it.
        self::assertStringNotContainsString('transform:', $html);
    }

    public function testAContainedPictureIsNeverZoomedButKeepsItsZoom(): void
    {
        $contained = new ResponsiveImage(30, 40, fit: ResponsiveImage::FIT_CONTAIN, zoom: 180);
        $picture = $contained->forRender(self::image());

        self::assertSame(100, $picture['zoom']);
        self::assertSame('<img src="/assets/media/a.jpg" alt="Werkplaats" width="1600" height="900" loading="lazy" style="object-position: 30% 40%; object-fit: contain;">', responsive_image_html($picture));
        self::assertSame(180, $contained->zoom, 'stored for when it goes back to cover');
        self::assertSame(180, ResponsiveImage::fromRow($contained->toRow(self::slot()), self::slot())->zoom);
        self::assertSame(180, (new ResponsiveImage(30, 40, zoom: 180))->forRender(self::image())['zoom'], 'back to cover: the old zoom is back');
    }

    public function testAPhoneWithItsOwnPointHasItsOwnZoomElseTheDesktopOne(): void
    {
        // The desktop picture reused, the desktop point followed: no phone
        // zoom of its own, the desktop zoom everywhere.
        $reused = (new ResponsiveImage(20, 80, zoom: 150))->forRender(self::image());
        self::assertSame([150, null], [$reused['zoom'], $reused['mobile_zoom']]);
        self::assertStringNotContainsString('data-rm-mobile', responsive_image_html($reused));

        // Its own point on the desktop picture: its own zoom, switched on for a phone.
        $own = (new ResponsiveImage(20, 80, null, 60, 10, zoom: 150, mobileZoom: 200))->forRender(self::image());
        self::assertSame([150, 200], [$own['zoom'], $own['mobile_zoom']]);
        self::assertStringContainsString('style="object-position: 20% 80%; scale: 1.5; transform-origin: 20% 80%; --rm-mobile-position: 60% 10%; --rm-mobile-zoom: 2;" data-rm-mobile-position data-rm-mobile-zoom>', responsive_image_html($own));

        // Only a phone zooms: the origin is printed for it all the same.
        $phoneOnly = (new ResponsiveImage(20, 80, null, 20, 80, mobileZoom: 130))->forRender(self::image());
        self::assertStringContainsString('style="object-position: 20% 80%; transform-origin: 20% 80%; --rm-mobile-zoom: 1.3;" data-rm-mobile-zoom>', responsive_image_html($phoneOnly));

        // Its own picture: its own point and zoom, in a <picture> with one <source>.
        $picture = (new ResponsiveImage(20, 80, 7, 50, 0, zoom: 120, mobileZoom: 170))->forRender(self::image());
        self::assertSame([120, 170], [$picture['zoom'], $picture['mobile_zoom']]);
        self::assertStringStartsWith('<picture class="rm-picture"><source media="(max-width: 640px)" srcset="/assets/media/phone.jpg"', responsive_image_html($picture));

        // Same zoom on both: nothing to switch.
        self::assertNull((new ResponsiveImage(20, 80, null, 60, 10, zoom: 150, mobileZoom: 150))->forRender(self::image())['mobile_zoom']);

        // A phone that contains the picture is never zoomed there.
        $phoneContains = (new ResponsiveImage(20, 80, null, 60, 10, mobileFit: ResponsiveImage::FIT_CONTAIN, zoom: 150, mobileZoom: 180))->forRender(self::image());
        self::assertSame(100, $phoneContains['mobile_zoom']);
        self::assertStringContainsString('--rm-mobile-zoom: 1;', responsive_image_html($phoneContains));

        // A slide of a sequence keeps the desktop picture, point and zoom on a phone.
        $slide = (new ResponsiveImage(20, 80, 7, 50, 0, zoom: 120, mobileZoom: 170))->forRender(self::image(), false);
        self::assertSame([null, 120], [$slide['mobile'], $slide['zoom']]);
    }

    public function testACompactPlaceTakesThePhonesZoomEverywhere(): void
    {
        $compact = ResponsiveImage::compact((new ResponsiveImage(20, 80, 7, 50, 0, zoom: 120, mobileZoom: 170))->forRender(self::image()));
        self::assertSame([170, null, '50% 0%'], [$compact['zoom'], $compact['mobile_zoom'], $compact['position']]);
        self::assertStringContainsString('style="object-position: 50% 0%; scale: 1.7; transform-origin: 50% 0%;"', responsive_image_html($compact));

        $sameAsDesktop = ResponsiveImage::compact((new ResponsiveImage(20, 80, 7, 20, 80, zoom: 120, mobileZoom: 120))->forRender(self::image()));
        self::assertSame(120, $sameAsDesktop['zoom']);
    }

    public function testTheBreakpointIsTheOneOfThePhonesPointAndFit(): void
    {
        $css = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 3) . '/assets/css/responsive-media.css'));
        preg_match('/@media \(max-width: (\d+)px\)\{(.*?)\n\}/s', $css, $phone);

        self::assertSame((string) ResponsiveImage::MOBILE_MAX_WIDTH, $phone[1] ?? null);
        self::assertStringContainsString('img[data-rm-mobile-zoom]{ scale: var(--rm-mobile-zoom) !important; }', $phone[2]);
        self::assertStringContainsString('transform-origin: var(--rm-mobile-position) !important;', $phone[2], 'a phone\'s own point is the origin of its zoom');
    }
}
