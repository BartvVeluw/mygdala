<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ProductRepository;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;
use Tests\Support\TestEnvironment;

/**
 * Front and back are two SEPARATE previews, each with its own uploaded image
 * and its own zones — not two zones drawn on one picture.
 *
 * The data model has allowed that since Phase 2, but "allowed" was not
 * enough: the CMS made adding a zone and adding a preview look like the same
 * kind of action, and a real configuration came out with a zone called
 * "achterkant" sitting on the front photo. So half of what is proved here is
 * about the RENDERED page (each preview draws only its own zones, and the
 * others are hidden), and half is about the CMS making the difference
 * impossible to miss.
 *
 * The HTTP half uses the same helper and skip-when-unreachable guard as
 * tests/Service/PersonalizationProductPageTest.php.
 */
final class PersonalizationViewSeparationTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-view-separation-';

    private ProductRepository $products;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<string> */
    private array $previewFiles = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        ProductPersonalizationContent::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->previewFiles as $path) {
            @unlink($path);
        }

        $this->productIds = [];
        $this->previewFiles = [];
        ProductPersonalizationContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private static function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function skipUnlessServerReachable(): void
    {
        if ($this->request('/product.php') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    /**
     * @return array{status: int, body: string}|null
     */
    private function request(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);

        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    private function createProduct(): int
    {
        $id = $this->products->create([
            'name' => 'Testproduct weergaven',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 18.0,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    /** A real file, because the resolver only offers a view whose image exists. */
    private function createPreviewImage(): string
    {
        $dir = dirname(__DIR__, 2) . '/assets/images/personalization/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'zz-test-viewsep-' . bin2hex(random_bytes(6)) . '.png';
        $path = $dir . $filename;

        $image = imagecreatetruecolor(120, 90);
        imagepng($image, $path);
        imagedestroy($image);

        $this->previewFiles[] = $path;

        return 'assets/images/personalization/' . $filename;
    }

    /** @return array{id: int, front: string, back: string} */
    private function createTwoViewProduct(): array
    {
        $productId = $this->createProduct();
        $frontImage = $this->createPreviewImage();
        $backImage = $this->createPreviewImage();

        PersonalizationTestConfig::configure($productId, [
            [
                'view_key' => 'voorkant', 'label' => 'Voorkant', 'image' => $frontImage,
                'zones' => [PersonalizationTestConfig::zone('naam', ['label' => 'Naam'])],
            ],
            [
                'view_key' => 'achterkant', 'label' => 'Achterkant', 'image' => $backImage,
                'zones' => [PersonalizationTestConfig::zone('bericht', ['label' => 'Bericht'])],
            ],
        ]);

        return ['id' => $productId, 'front' => $frontImage, 'back' => $backImage];
    }

    /**
     * The markup of one preview stage, so a test can assert on what that
     * stage does and does not contain.
     */
    private static function stageFor(string $body, string $viewKey): string
    {
        $start = strpos($body, 'data-personalizer-stage="' . $viewKey . '"');
        self::assertIsInt($start, 'no stage for view ' . $viewKey);

        $next = strpos($body, 'data-personalizer-stage="', $start + 10);
        $end = $next === false ? strpos($body, 'personalizer__hint', $start) : $next;
        self::assertIsInt($end);

        return substr($body, $start, $end - $start);
    }

    /* ------------------------------------------------------------------ */
    /* Two previews, two images                                            */
    /* ------------------------------------------------------------------ */

    public function testFrontAndBackAreRenderedWithDifferentImages(): void
    {
        $this->skipUnlessServerReachable();

        $built = $this->createTwoViewProduct();
        $body = $this->request('/product.php?id=' . $built['id'])['body'];

        $this->assertNotSame($built['front'], $built['back']);

        $front = self::stageFor($body, 'voorkant');
        $back = self::stageFor($body, 'achterkant');

        $this->assertStringContainsString($built['front'], $front);
        $this->assertStringContainsString($built['back'], $back);

        // ...and neither stage carries the other one's picture.
        $this->assertStringNotContainsString($built['back'], $front);
        $this->assertStringNotContainsString($built['front'], $back);
    }

    /**
     * The rule the misconfiguration broke: a zone belongs to ONE preview, and
     * its overlay is only ever drawn on that preview's image.
     */
    public function testEachPreviewDrawsOnlyItsOwnZones(): void
    {
        $this->skipUnlessServerReachable();

        $built = $this->createTwoViewProduct();
        $body = $this->request('/product.php?id=' . $built['id'])['body'];

        $front = self::stageFor($body, 'voorkant');
        $back = self::stageFor($body, 'achterkant');

        $this->assertStringContainsString('data-personalizer-zone="naam"', $front);
        $this->assertStringNotContainsString('data-personalizer-zone="bericht"', $front);

        $this->assertStringContainsString('data-personalizer-zone="bericht"', $back);
        $this->assertStringNotContainsString('data-personalizer-zone="naam"', $back);
    }

    /**
     * Only one preview is visible at a time, and the others are really
     * hidden — not merely stacked underneath.
     */
    public function testOnlyTheActivePreviewIsVisible(): void
    {
        $this->skipUnlessServerReachable();

        $built = $this->createTwoViewProduct();
        $body = $this->request('/product.php?id=' . $built['id'])['body'];

        $front = self::stageFor($body, 'voorkant');
        $back = self::stageFor($body, 'achterkant');

        // The first stage opens visible, every later one closed.
        $this->assertStringNotContainsString('hidden', explode('>', $front)[0]);
        $this->assertStringContainsString('hidden', explode('>', $back)[0]);

        // The same for the control panels, so the other view's fields are not
        // reachable either.
        $this->assertMatchesRegularExpression(
            '/data-personalizer-panel="achterkant"[^>]*hidden/',
            $body
        );

        // And a switcher exists, because this product really has two sides.
        $this->assertStringContainsString('data-personalizer-tab="voorkant"', $body);
        $this->assertStringContainsString('data-personalizer-tab="achterkant"', $body);
    }

    /**
     * A one-preview product grows no tab strip at all — switching only exists
     * where there is something to switch between.
     */
    public function testASinglePreviewProductHasNoSwitcher(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        PersonalizationTestConfig::configure($productId, [[
            'view_key' => 'voorkant', 'label' => 'Voorkant', 'image' => $this->createPreviewImage(),
            'zones' => [PersonalizationTestConfig::zone('naam')],
        ]]);

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('data-personalizer-stage="voorkant"', $body);
        $this->assertStringNotContainsString('data-personalizer-tab=', $body);
    }

    /* ------------------------------------------------------------------ */
    /* Switching preserves what the customer typed                         */
    /* ------------------------------------------------------------------ */

    /**
     * switchView() may only change VISIBILITY. The moment it touches
     * `state.zones`, a customer loses the front the instant they look at the
     * back — which is the whole reason this is asserted on the source rather
     * than left to a manual check.
     */
    public function testSwitchingViewsNeverTouchesCustomerState(): void
    {
        $editor = self::sourceOf('assets/js/personalization.js');

        $start = strpos($editor, 'function switchView(viewKey)');
        $this->assertIsInt($start);
        $end = strpos($editor, "\n  }", $start);
        $this->assertIsInt($end);

        $body = substr($editor, $start, $end - $start);

        // It hides and shows, and recomputes a text size that can only be
        // measured once visible...
        $this->assertStringContainsString('stage.hidden =', $body);
        $this->assertStringContainsString('panel.hidden =', $body);
        $this->assertStringContainsString('applyTextSize(zone.zone_key)', $body);

        // ...and it never writes a value.
        $this->assertStringNotContainsString('state.zones[', $body);
        $this->assertStringNotContainsString('neutralZoneState', $body);
        $this->assertStringNotContainsString('.value =', $body);
        $this->assertStringNotContainsString('resetZone', $body);
    }

    /**
     * State is keyed by ZONE, and a zone belongs to exactly one view, so
     * there is no shared slot two views could overwrite for each other.
     */
    public function testCustomerStateIsKeyedPerZoneNotPerView(): void
    {
        $editor = self::sourceOf('assets/js/personalization.js');

        $this->assertStringContainsString('var state = { zones: {} };', $editor);
        $this->assertStringContainsString('viewOfZone[zone.zone_key] = view;', $editor);
        $this->assertStringContainsString('state.zones[key] = neutralZoneState();', $editor);
    }

    /* ------------------------------------------------------------------ */
    /* The CMS makes the difference impossible to miss                     */
    /* ------------------------------------------------------------------ */

    public function testTheCmsOffersAddPreviewAsItsOwnPrimaryAction(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');

        // A first-class action, above and below the list, with its own anchor.
        $this->assertStringContainsString('+ Voorbeeld toevoegen', $builder);
        $this->assertStringContainsString('id="voorbeeld-toevoegen"', $builder);
        $this->assertStringContainsString('href="#voorbeeld-toevoegen"', $builder);
        $this->assertStringContainsString('create-personalization-view.php', $builder);

        // ...and it says in words what the mistake would be.
        $this->assertStringContainsString('Een achterkant is een nieuw', $builder);
        $this->assertStringContainsString('niet een tweede zone op de voorkant', $builder);
    }

    /**
     * Adding a zone is scoped to the preview it lands on, and says so — it
     * cannot read as "add another side of the product".
     */
    public function testAddingAZoneIsNamedAfterThePreviewItLandsOn(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');

        $this->assertStringContainsString('Zones op deze afbeelding', $builder);
        $this->assertStringContainsString('+ Zone op &ldquo;<?= $esc($viewName) ?>&rdquo;', $builder);
        $this->assertStringContainsString(
            'function renderPersonalizationZoneCreateForm(int $viewId, string $viewName',
            $builder
        );
    }

    /* ------------------------------------------------------------------ */
    /* ...and it is compact                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Every preview and every zone is a collapsible block whose closed
     * summary already answers what it is — "Voorkant · 2 zones · afbeelding
     * ingesteld", "Naam · Tekst · Verplicht".
     */
    public function testPreviewsAndZonesAreCollapsibleWithInformativeSummaries(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');

        $this->assertSame(
            2,
            substr_count($builder, '<details class="admin-pz-block'),
            'both a preview and a zone must be collapsible'
        );
        $this->assertStringContainsString('<summary class="admin-pz-block__summary">', $builder);

        // The preview summary: name, zone count, image state.
        $this->assertStringContainsString('zone<?= $zoneCount === 1 ? \'\' : \'s\' ?>', $builder);
        $this->assertStringContainsString('afbeelding ingesteld', $builder);
        $this->assertStringContainsString('geen afbeelding', $builder);

        // The zone summary: what it accepts, and whether it is required.
        $this->assertStringContainsString("'Tekst + afbeelding'", $builder);
        $this->assertStringContainsString("? 'Verplicht' : 'Optioneel'", $builder);

        // Closed by default once there is more than one to scan, and opened
        // again whenever that block's own save was rejected.
        $this->assertStringContainsString('$viewsOpenByDefault = count($views) <= 1;', $builder);
        $this->assertStringContainsString('$viewsOpenByDefault || $viewHasFlash', $builder);
    }

    /**
     * The form itself is a grid, not a stack of full-width rows — and the
     * four engraving-area percentages are one group, because they are one
     * thought.
     */
    public function testTheEditorUsesACompactGridInsteadOfFullWidthRows(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');
        $css = self::sourceOf('admin/assets/admin.css');

        $this->assertStringContainsString('class="admin-pz-grid"', $builder);
        $this->assertStringContainsString('<fieldset class="admin-pz-area">', $builder);
        foreach (['area_x', 'area_y', 'area_width', 'area_height'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $builder);
        }

        // The old one-field-per-row layout is gone from this screen.
        $this->assertStringNotContainsString('admin-form-row--split', $builder);

        $this->assertMatchesRegularExpression(
            '/\.admin-pz-grid\{[^}]*grid-template-columns:\s*repeat\(auto-fit/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-pz-area\{[^}]*grid-template-columns:\s*repeat\(4/s',
            $css
        );
    }

    /** The visual drag/resize editor survived the compaction. */
    public function testTheVisualZoneEditorIsStillThere(): void
    {
        $builder = self::sourceOf('admin/_personalization_builder.php');

        $this->assertStringContainsString('data-zone-editor', $builder);
        $this->assertStringContainsString('data-zone-stage', $builder);
        $this->assertStringContainsString('data-zone-box=', $builder);
        $this->assertStringContainsString('data-zone-handle="nw"', $builder);
        // ...and the numeric fields are still the source of truth behind it.
        $this->assertStringContainsString('data-zone-input="x" data-zone-id="<?= $zoneId ?>"', $builder);
    }
}
