<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\Money;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationFontFixture;
use Tests\Support\PersonalizationTestConfig;

/**
 * Product personalization CONFIGURATION against the real dev database, same
 * convention as tests/Repository/ProductDeletionIntegrationTest.php: what is
 * worth proving here is schema behaviour (the settings/views/zones split, the
 * unique keys, the cascades) and the "off unless configured" default, none of
 * which a mock can exercise.
 *
 * Everything created here uses an obviously-fake slug so it can never collide
 * with the owner's real catalogue; tearDown() removes it.
 */
final class ProductPersonalizationRepositoryIntegrationTest extends TestCase
{
    private const SLUG_PREFIX = '__test_personalization_';

    private ?int $productId = null;
    private PersonalizationFontFixture $fonts;

    protected function setUp(): void
    {
        $this->fonts = new PersonalizationFontFixture();
        PersonalizationFonts::clearCache();
        ProductPersonalizationContent::clearCache();
    }

    protected function tearDown(): void
    {
        $this->fonts->remove();

        if ($this->productId !== null) {
            Database::connection()
                ->prepare('DELETE FROM products WHERE id = :id')
                ->execute(['id' => $this->productId]);
            $this->productId = null;
        }

        ProductPersonalizationContent::clearCache();
    }

    private function createProduct(): int
    {
        $this->productId = (new ProductRepository())->create([
            'name' => 'Testproduct personalisatie',
            'name_en' => 'Personalization test product',
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)) . '__',
            'description' => 'Beschrijving die niet mag veranderen.',
            'description_en' => null,
            'price' => 14.95,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);

        return $this->productId;
    }

    /* ------------------------------------------------------------------ */
    /* Defaults                                                            */
    /* ------------------------------------------------------------------ */

    public function testANewProductHasNoPersonalizationRowAtAll(): void
    {
        $productId = $this->createProduct();

        $this->assertNull((new ProductPersonalizationRepository())->findForProduct($productId));
        $this->assertNull(ProductPersonalizationContent::forProduct($productId));
    }

    /* ------------------------------------------------------------------ */
    /* A Phase 1 shaped product still works exactly as it did              */
    /* ------------------------------------------------------------------ */

    public function testASingleViewSingleZoneProductResolvesLikeItAlwaysDid(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['max_text_length' => 18]);

        $config = ProductPersonalizationContent::forProduct($productId);

        $this->assertNotNull($config);
        $this->assertCount(1, $config['views']);
        $this->assertSame(PersonalizationRules::DEFAULT_VIEW_KEY, $config['views'][0]['view_key']);
        $this->assertCount(1, $config['views'][0]['zones']);
        $this->assertSame(PersonalizationRules::DEFAULT_ZONE_KEY, $config['views'][0]['zones'][0]['zone_key']);
        $this->assertSame(18, $config['views'][0]['zones'][0]['max_text_length']);
        $this->assertSame(PersonalizationTestConfig::IMAGE, $config['views'][0]['preview_image_path']);
    }

    /**
     * Fonts are GLOBAL as of Phase 3: a text zone offers exactly the fonts
     * that are ACTIVE in the shop's library, whatever the product is and
     * whatever its own (legacy) font columns happen to contain.
     */
    public function testATextZoneOffersEveryActiveLibraryFont(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId);

        $config = ProductPersonalizationContent::forProduct($productId);
        $zone = $config['views'][0]['zones'][0];

        $this->assertSame(PersonalizationFonts::activeKeys(), $zone['fonts']);
        $this->assertNotEmpty($zone['fonts'], 'the shop must always offer at least one engraving font');
        $this->assertSame(PersonalizationFonts::fallbackKey(), $zone['default_font']);

        // Resolved once for the whole product too, because it is a property
        // of the shop and not of any zone.
        $this->assertSame($zone['fonts'], $config['fonts']);
        $this->assertSame($zone['default_font'], $config['default_font']);
    }

    /**
     * The whole point of the global library: switching a font off removes it
     * from every product at once, with no per-product edit.
     */
    public function testAnInactiveLibraryFontIsNeverOffered(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId);

        $active = $this->fonts->create('Fixture Actief', true);
        $inactive = $this->fonts->create('Fixture Inactief', false);

        ProductPersonalizationContent::clearCache();
        $zone = ProductPersonalizationContent::forProduct($productId)['views'][0]['zones'][0];

        $this->assertContains($active['font_key'], $zone['fonts']);
        $this->assertNotContains($inactive['font_key'], $zone['fonts']);

        // ...and switching one on is equally global.
        $this->fonts->setActive($inactive['id'], true);
        ProductPersonalizationContent::clearCache();
        $zone = ProductPersonalizationContent::forProduct($productId)['views'][0]['zones'][0];

        $this->assertContains($inactive['font_key'], $zone['fonts']);
    }

    /* ------------------------------------------------------------------ */
    /* Multiple views and zones                                            */
    /* ------------------------------------------------------------------ */

    public function testMultipleViewsAndZonesPersistWithTheirOwnProperties(): void
    {
        $productId = $this->createProduct();

        PersonalizationTestConfig::configure($productId, [
            [
                'view_key' => 'front', 'label' => 'Voorkant', 'label_en' => 'Front',
                'zones' => [
                    PersonalizationTestConfig::zone('name', [
                        'label' => 'Naam', 'label_en' => 'Name',
                        'allow_image' => false, 'is_required' => true, 'max_text_length' => 20,
                    ]),
                    PersonalizationTestConfig::zone('logo', [
                        'label' => 'Logo', 'allow_text' => false, 'surcharge_cents' => 500,
                    ]),
                ],
            ],
            [
                'view_key' => 'back', 'label' => 'Achterkant', 'label_en' => 'Back',
                'zones' => [
                    PersonalizationTestConfig::zone('message', [
                        'label' => 'Bericht', 'allow_image' => false, 'surcharge_cents' => 750,
                    ]),
                ],
            ],
        ]);

        $config = ProductPersonalizationContent::forProduct($productId);

        $this->assertCount(2, $config['views']);
        $this->assertSame('front', $config['views'][0]['view_key']);
        $this->assertSame('Voorkant', $config['views'][0]['label']);
        $this->assertSame('Front', $config['views'][0]['label_en']);
        $this->assertSame('back', $config['views'][1]['view_key']);

        $this->assertCount(2, $config['views'][0]['zones']);
        $this->assertCount(1, $config['views'][1]['zones']);

        $name = $config['views'][0]['zones'][0];
        $this->assertSame('Naam', $name['label']);
        $this->assertTrue($name['is_required']);
        $this->assertSame(PersonalizationRules::MODE_TEXT, $name['mode']);
        $this->assertSame(PersonalizationFonts::activeKeys(), $name['fonts']);
        $this->assertSame(0, $name['surcharge_cents']);

        $logo = $config['views'][0]['zones'][1];
        $this->assertSame(PersonalizationRules::MODE_IMAGE, $logo['mode']);
        $this->assertSame(500, $logo['surcharge_cents']);
        // An image-only zone offers no fonts at all.
        $this->assertSame([], $logo['fonts']);
        $this->assertNull($logo['default_font']);

        $this->assertSame(750, $config['views'][1]['zones'][0]['surcharge_cents']);
    }

    public function testViewOrderPersistsAndCanBeChanged(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'front', 'zones' => [PersonalizationTestConfig::zone('a')]],
            ['view_key' => 'back', 'zones' => [PersonalizationTestConfig::zone('b')]],
        ]);

        $repository = new ProductPersonalizationRepository();
        $this->assertSame(['front', 'back'], $this->viewKeys($productId));

        $repository->moveView($built['view_ids']['back'], 'up');
        ProductPersonalizationContent::clearCache();

        $this->assertSame(['back', 'front'], $this->viewKeys($productId));
    }

    public function testZoneOrderPersistsAndCanBeChanged(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::configure($productId, [[
            'view_key' => 'front',
            'zones' => [
                PersonalizationTestConfig::zone('one'),
                PersonalizationTestConfig::zone('two'),
                PersonalizationTestConfig::zone('three'),
            ],
        ]]);

        $this->assertSame(['one', 'two', 'three'], $this->zoneKeys($productId));

        (new ProductPersonalizationRepository())->moveZone($built['zone_ids']['three'], 'up');
        ProductPersonalizationContent::clearCache();

        $this->assertSame(['one', 'three', 'two'], $this->zoneKeys($productId));
    }

    /* ------------------------------------------------------------------ */
    /* Uniqueness                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Zone keys must be unique across the WHOLE product, not merely within a
     * view: an order line's personalization is keyed by zone_key alone, so
     * the same key on two views would make a historical order ambiguous.
     */
    public function testAZoneKeyIsDetectedAsTakenEvenOnAnotherView(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'front', 'zones' => [PersonalizationTestConfig::zone('name')]],
            ['view_key' => 'back', 'zones' => []],
        ]);

        $repository = new ProductPersonalizationRepository();

        $this->assertTrue($repository->zoneKeyExists($built['settings_id'], 'name'));
        $this->assertFalse($repository->zoneKeyExists($built['settings_id'], 'message'));
    }

    public function testAViewKeyIsDetectedAsTaken(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'front', 'zones' => [PersonalizationTestConfig::zone('name')]],
        ]);

        $repository = new ProductPersonalizationRepository();

        $this->assertTrue($repository->viewKeyExists($built['settings_id'], 'front'));
        $this->assertFalse($repository->viewKeyExists($built['settings_id'], 'back'));
        // Its own id is excluded, so renaming a view to its current key is fine.
        $this->assertFalse($repository->viewKeyExists($built['settings_id'], 'front', $built['view_ids']['front']));
    }

    /* ------------------------------------------------------------------ */
    /* Persistence details                                                 */
    /* ------------------------------------------------------------------ */

    public function testTheEngravingAreaPersistsAsRelativePercentages(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, [
            'area_x' => 12.5, 'area_y' => 63.25, 'area_width' => 30.125, 'area_height' => 11.5,
        ]);

        $zone = (new ProductPersonalizationRepository())->findForProduct($productId)['views'][0]['zones'][0];

        $this->assertSame(12.5, (float) $zone['area_x']);
        $this->assertSame(63.25, (float) $zone['area_y']);
        $this->assertSame(30.125, (float) $zone['area_width']);
        $this->assertSame(11.5, (float) $zone['area_height']);
    }

    public function testASurchargePersistsToTheExactCent(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['surcharge_cents' => 750]);

        $zone = (new ProductPersonalizationRepository())->findForProduct($productId)['views'][0]['zones'][0];

        $this->assertSame('7.50', (string) $zone['surcharge']);
        $this->assertSame(750, Money::toCents($zone['surcharge']));
        $this->assertSame(750, ProductPersonalizationContent::forProduct($productId)['views'][0]['zones'][0]['surcharge_cents']);
    }

    public function testRequiredAndEnabledStatesPersistIndependently(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::singleZone($productId, ['is_required' => true]);

        $repository = new ProductPersonalizationRepository();
        $zone = $repository->findZoneById($built['zone_ids']['default']);
        $this->assertSame(1, (int) $zone['is_required']);
        $this->assertSame(1, (int) $zone['is_enabled']);

        $repository->updateZone(
            $built['zone_ids']['default'],
            PersonalizationTestConfig::zone('default', ['is_required' => false, 'is_enabled' => false])
        );

        $zone = $repository->findZoneById($built['zone_ids']['default']);
        $this->assertSame(0, (int) $zone['is_required']);
        $this->assertSame(0, (int) $zone['is_enabled']);
    }

    /**
     * A disabled zone is configuration that still exists but must never be
     * offered to a customer.
     */
    public function testADisabledZoneIsNotOfferedToTheCustomer(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::configure($productId, [[
            'view_key' => 'front',
            'zones' => [
                PersonalizationTestConfig::zone('name'),
                PersonalizationTestConfig::zone('hidden', ['is_enabled' => false]),
            ],
        ]]);

        $zones = ProductPersonalizationContent::forProduct($productId)['views'][0]['zones'];

        $this->assertCount(1, $zones);
        $this->assertSame('name', $zones[0]['zone_key']);
    }

    /**
     * A Phase 2 zone still carries its own `allowed_fonts`/`default_font`
     * columns. They are LEGACY: nothing reads them any more, so a stale or
     * forged value in them can neither narrow nor widen what a customer is
     * offered.
     */
    public function testALegacyPerZoneFontListIsIgnored(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::singleZone($productId);

        Database::connection()
            ->prepare('UPDATE product_personalization_zones SET allowed_fonts = :fonts, default_font = :default WHERE id = :id')
            ->execute([
                'fonts' => 'trirong,comic_sans_hacker',
                'default' => 'comic_sans_hacker',
                'id' => $built['zone_ids']['default'],
            ]);
        ProductPersonalizationContent::clearCache();

        $zone = ProductPersonalizationContent::forProduct($productId)['views'][0]['zones'][0];

        $this->assertSame(PersonalizationFonts::activeKeys(), $zone['fonts']);
        $this->assertNotContains('comic_sans_hacker', $zone['fonts']);
        $this->assertSame(PersonalizationFonts::fallbackKey(), $zone['default_font']);
    }

    /* ------------------------------------------------------------------ */
    /* Resolution rules                                                    */
    /* ------------------------------------------------------------------ */

    public function testAViewWithoutAPreviewImageIsNotOffered(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'front', 'image' => null, 'zones' => [PersonalizationTestConfig::zone('name')]],
            ['view_key' => 'back', 'zones' => [PersonalizationTestConfig::zone('message')]],
        ]);

        $config = ProductPersonalizationContent::forProduct($productId);

        $this->assertCount(1, $config['views']);
        $this->assertSame('back', $config['views'][0]['view_key']);
    }

    public function testAProductWithNoUsableViewResolvesToNothing(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, [], [], null);

        $this->assertNull(ProductPersonalizationContent::forProduct($productId));
    }

    public function testADisabledProductResolvesToNothingEvenWhenFullyConfigured(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, [], ['is_enabled' => false]);

        $this->assertNull(ProductPersonalizationContent::forProduct($productId));
    }

    public function testAZoneThatAllowsNothingIsNotOffered(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId, ['allow_text' => false, 'allow_image' => false]);

        $this->assertNull(ProductPersonalizationContent::forProduct($productId));
    }

    /* ------------------------------------------------------------------ */
    /* Not breaking the product itself                                     */
    /* ------------------------------------------------------------------ */

    public function testConfiguringPersonalizationLeavesEveryProductFieldUntouched(): void
    {
        $productId = $this->createProduct();
        $products = new ProductRepository();
        $before = $products->findByIdForAdmin($productId);

        PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'front', 'zones' => [PersonalizationTestConfig::zone('name')]],
            ['view_key' => 'back', 'zones' => [PersonalizationTestConfig::zone('message')]],
        ]);

        $after = $products->findByIdForAdmin($productId);

        unset($before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
    }

    /* ------------------------------------------------------------------ */
    /* Cascades                                                            */
    /* ------------------------------------------------------------------ */

    public function testDeletingAViewRemovesItsZonesButLeavesTheOthers(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'front', 'zones' => [PersonalizationTestConfig::zone('name')]],
            ['view_key' => 'back', 'zones' => [PersonalizationTestConfig::zone('message')]],
        ]);

        $repository = new ProductPersonalizationRepository();
        $this->assertTrue($repository->deleteView($built['view_ids']['front']));
        ProductPersonalizationContent::clearCache();

        $config = ProductPersonalizationContent::forProduct($productId);
        $this->assertCount(1, $config['views']);
        $this->assertSame('back', $config['views'][0]['view_key']);
        $this->assertNull($repository->findZoneById($built['zone_ids']['name']), 'the view\'s zones must cascade away');
        $this->assertNotNull($repository->findZoneById($built['zone_ids']['message']));
    }

    public function testDeletingTheProductRemovesItsWholePersonalizationConfiguration(): void
    {
        $productId = $this->createProduct();
        $built = PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'front', 'zones' => [PersonalizationTestConfig::zone('name')]],
        ]);

        Database::connection()->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
        $this->productId = null;

        $db = Database::connection();

        $views = $db->prepare('SELECT COUNT(*) FROM product_personalization_views WHERE settings_id = :id');
        $views->execute(['id' => $built['settings_id']]);
        $this->assertSame(0, (int) $views->fetchColumn(), 'views must cascade away with the product');

        $zones = $db->prepare('SELECT COUNT(*) FROM product_personalization_zones WHERE settings_id = :id');
        $zones->execute(['id' => $built['settings_id']]);
        $this->assertSame(0, (int) $zones->fetchColumn(), 'zones must cascade away with the product');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function viewKeys(int $productId): array
    {
        $stored = (new ProductPersonalizationRepository())->findForProduct($productId);

        return array_map(static fn (array $view): string => (string) $view['view_key'], $stored['views']);
    }

    /** @return list<string> */
    private function zoneKeys(int $productId): array
    {
        $stored = (new ProductPersonalizationRepository())->findForProduct($productId);
        $keys = [];
        foreach ($stored['views'] as $view) {
            foreach ($view['zones'] as $zone) {
                $keys[] = (string) $zone['zone_key'];
            }
        }

        return $keys;
    }
}
