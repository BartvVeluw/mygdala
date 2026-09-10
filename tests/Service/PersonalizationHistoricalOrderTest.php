<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Personalization\PersonalizationFonts;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationFontFixture;

/**
 * Historical orders keep rendering. An order's personalization is a
 * SNAPSHOT — the customer's exact text, where they put it, and a copy of the
 * configuration that was in force — so nothing an administrator does
 * afterwards may change what the CMS order screen shows.
 *
 * Three generations have to render through the one code path in
 * admin/_order_personalization.php:
 *
 *   version 1 (Phase 1) — one zone, no view, no font, no surcharge;
 *   version 2 (Phase 2) — views, per-zone fonts, surcharges;
 *   version 3 (Phase 3) — the global font library, with the order carrying
 *                         its OWN copy of the font it was engraved in.
 *
 * The renderer is exercised directly (output buffered) rather than over HTTP:
 * the order screen needs an authenticated session, and what is worth proving
 * here is that each row SHAPE renders — not that the admin login works, which
 * tests/Service/AdminAccessControlTest.php already covers.
 */
final class PersonalizationHistoricalOrderTest extends TestCase
{
    private PersonalizationFontFixture $fonts;

    /** @var list<string> absolute paths written by a test */
    private array $previewFiles = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/_order_personalization.php';

        $this->fonts = new PersonalizationFontFixture();
        PersonalizationFonts::clearCache();
    }

    protected function tearDown(): void
    {
        // Files first: removing the font fixture goes through the database,
        // and if that throws — no database, a dropped connection — the images
        // would stay behind in assets/ for good.
        foreach ($this->previewFiles as $path) {
            @unlink($path);
        }
        $this->previewFiles = [];

        $this->fonts->remove();

        PersonalizationFonts::clearCache();
    }

    /**
     * A real preview image, because the CMS only RECONSTRUCTS the customer's
     * preview when the image the order was placed on still exists — and the
     * reconstruction is where the chosen font actually has to appear.
     */
    private function createPreviewImage(string $folder): string
    {
        $dir = dirname(__DIR__, 2) . '/' . $folder;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'zz-test-history-' . bin2hex(random_bytes(6)) . '.png';
        $path = $dir . $filename;

        $image = imagecreatetruecolor(120, 90);
        imagepng($image, $path);
        imagedestroy($image);

        $this->previewFiles[] = $path;

        return $folder . $filename;
    }

    /**
     * One `order_item_personalizations` row as findByOrderIdGrouped() returns
     * it, with everything a given generation would NOT have set left null.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $snapshot, array $overrides = []): array
    {
        return $overrides + [
            'id' => 4242,
            'order_item_id' => 99,
            'zone_key' => 'default',
            'view_key' => null,
            'upload_id' => null,
            'text_value' => 'Bart',
            'font_key' => null,
            'font_label' => null,
            'font_stack' => null,
            'font_file_path' => null,
            'surcharge' => '0.00',
            'transform_json' => '{"text":{"x":0.5,"y":0.5,"scale":1,"rotation":0}}',
            'config_snapshot_json' => json_encode($snapshot),
            'created_at' => '2026-09-08 06:28:35',
            'upload_token' => null,
            'original_filename' => null,
            'mime_type' => null,
            'image_width' => null,
            'image_height' => null,
            'byte_size' => null,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function render(array $rows): string
    {
        ob_start();
        renderOrderItemPersonalizations($rows);

        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /* Phase 1                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * A version-1 snapshot, byte-for-byte the shape the real order in this
     * database carries: no view, no font, no surcharge, and a preview image
     * that still lives in the product photo folder — which is exactly why
     * the Phase 3 migration COPIED those files instead of moving them.
     */
    public function testAPhase1OrderStillRenders(): void
    {
        $html = $this->render([$this->row([
            'version' => 1,
            'captured_at' => '2026-09-08T06:28:34+00:00',
            'preview_image_path' => 'assets/images/products/4fa991743c3de78fff7d0f4d72e2332b.png',
            'instructions' => null,
            'zone' => [
                'zone_key' => 'default',
                'label' => null,
                'allow_text' => true,
                'allow_image' => true,
                'max_text_length' => 30,
                'area' => ['x' => 24, 'y' => 11.3, 'width' => 52, 'height' => 77.5],
            ],
            'render' => ['text_base_height_ratio' => 0.3, 'image_base_width_ratio' => 0.6],
        ])]);

        $this->assertStringContainsString('Personalisatie', $html);
        $this->assertStringContainsString('Bart', $html);
        // No view heading, because a Phase 1 order had no views.
        $this->assertStringNotContainsString('admin-personalization__view-title', $html);
        // No font line either: it never recorded one.
        $this->assertStringNotContainsString('<dt>Lettertype</dt>', $html);
        // The area it was actually engraved in comes from the snapshot.
        $this->assertStringContainsString('24', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Phase 2                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * A version-2 order recorded only a font KEY. It still renders, because
     * every Phase 2 key was backfilled into the library.
     */
    public function testAPhase2OrderStillRendersItsFontFromTheLibrary(): void
    {
        $html = $this->render([$this->row([
            'version' => 2,
            'captured_at' => '2026-09-08T07:00:00+00:00',
            'preview_image_path' => $this->createPreviewImage('assets/images/products/'),
            'instructions' => null,
            'view' => ['view_key' => 'front', 'label' => 'Voorkant', 'label_en' => 'Front'],
            'zone' => [
                'zone_key' => 'name',
                'label' => 'Naam',
                'allow_text' => true,
                'allow_image' => false,
                'mode' => 'text',
                'is_required' => true,
                'allow_rotation' => true,
                'max_text_length' => 20,
                'allowed_fonts' => ['trirong'],
                'default_font' => 'trirong',
                'surcharge' => '0.00',
                'area' => ['x' => 10, 'y' => 10, 'width' => 50, 'height' => 20],
            ],
            'surcharge_charged' => '0.00',
            'render' => ['text_base_height_ratio' => 0.3, 'image_base_width_ratio' => 0.6],
        ], ['zone_key' => 'name', 'view_key' => 'front', 'font_key' => 'trirong'])]);

        $this->assertStringContainsString('Naam', $html);
        $this->assertStringContainsString('<dt>Lettertype</dt>', $html);
        $this->assertStringContainsString(PersonalizationFonts::label('trirong'), $html);
        $this->assertStringContainsString('font-family:', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Phase 3, and the reason it exists                                   */
    /* ------------------------------------------------------------------ */

    public function testAPhase3OrderRendersFromItsOwnCopyOfTheFont(): void
    {
        $font = $this->fonts->createUpload('Fixture Script', 'assets/fonts/personalization/hist.woff2', 'woff2');

        $row = $this->row([
            'version' => 3,
            'captured_at' => '2026-09-08T08:00:00+00:00',
            'preview_image_path' => $this->createPreviewImage('assets/images/personalization/'),
            'instructions' => null,
            'purchase_mode' => 'required',
            'font' => [
                'key' => $font['font_key'],
                'label' => 'Fixture Script',
                'stack' => "'vvld-" . $font['font_key'] . "', sans-serif",
                'source' => 'upload',
                'file_path' => 'assets/fonts/personalization/hist.woff2',
                'file_format' => 'woff2',
            ],
            'view' => ['view_key' => 'front', 'label' => 'Voorkant', 'label_en' => null],
            'zone' => [
                'zone_key' => 'name',
                'label' => 'Naam',
                'allow_text' => true,
                'allow_image' => false,
                'mode' => 'text',
                'is_required' => true,
                'allow_rotation' => true,
                'max_text_length' => 20,
                'allowed_fonts' => [$font['font_key']],
                'default_font' => $font['font_key'],
                'surcharge' => '0.00',
                'area' => ['x' => 10, 'y' => 10, 'width' => 50, 'height' => 20],
            ],
            'surcharge_charged' => '0.00',
            'render' => ['text_base_height_ratio' => 0.3, 'image_base_width_ratio' => 0.6],
        ], [
            'zone_key' => 'name',
            'view_key' => 'front',
            'font_key' => $font['font_key'],
            'font_label' => 'Fixture Script',
            'font_stack' => "'vvld-" . $font['font_key'] . "', sans-serif",
            'font_file_path' => 'assets/fonts/personalization/hist.woff2',
        ]);

        $html = $this->render([$row]);

        $this->assertStringContainsString('Fixture Script', $html);
        $this->assertStringContainsString('vvld-' . $font['font_key'], $html);

        // The @font-face the order screen emits comes from the ORDER's own
        // file path, so the face still loads.
        $css = orderPersonalizationFontFaceCss([99 => [$row]]);
        $this->assertStringContainsString("@font-face{font-family:'vvld-" . $font['font_key'] . "'", $css);
        $this->assertStringContainsString("url('/assets/fonts/personalization/hist.woff2')", $css);
        $this->assertStringContainsString("format('woff2')", $css);
    }

    /**
     * THE guarantee that makes the font library safely editable: an order
     * renders identically after the font it used has been deleted from the
     * library outright.
     */
    public function testDeletingAFontCannotBreakAnOrderThatUsedIt(): void
    {
        $font = $this->fonts->createUpload('Fixture Verdwenen', 'assets/fonts/personalization/gone.woff2', 'woff2');

        $row = $this->row([
            'version' => 3,
            'preview_image_path' => $this->createPreviewImage('assets/images/personalization/'),
            'font' => [
                'key' => $font['font_key'],
                'label' => 'Fixture Verdwenen',
                'stack' => "'vvld-" . $font['font_key'] . "', sans-serif",
                'source' => 'upload',
                'file_path' => 'assets/fonts/personalization/gone.woff2',
                'file_format' => 'woff2',
            ],
            'zone' => [
                'zone_key' => 'default',
                'label' => 'Naam',
                'allow_text' => true,
                'allow_image' => false,
                'max_text_length' => 20,
                'area' => ['x' => 10, 'y' => 10, 'width' => 50, 'height' => 20],
            ],
            'render' => ['text_base_height_ratio' => 0.3, 'image_base_width_ratio' => 0.6],
        ], [
            'font_key' => $font['font_key'],
            'font_label' => 'Fixture Verdwenen',
            'font_stack' => "'vvld-" . $font['font_key'] . "', sans-serif",
            'font_file_path' => 'assets/fonts/personalization/gone.woff2',
        ]);

        $before = $this->render([$row]);

        // The font is now gone from the library entirely.
        $this->fonts->remove();
        $this->assertFalse(PersonalizationFonts::isValid($font['font_key']));

        $after = $this->render([$row]);

        $this->assertSame($before, $after, 'a deleted font must not change what a placed order shows');
        $this->assertStringContainsString('Fixture Verdwenen', $after);
        $this->assertStringContainsString("url('/assets/fonts/personalization/gone.woff2')", orderPersonalizationFontFaceCss([99 => [$row]]));
    }

    /**
     * An order line with no personalization at all — every order placed
     * before this feature existed — renders nothing rather than an empty
     * block.
     */
    public function testAnOrderWithoutPersonalizationRendersNothing(): void
    {
        $this->assertSame('', $this->render([]));
    }
}
