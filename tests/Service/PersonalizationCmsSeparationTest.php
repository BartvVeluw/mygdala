<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminNavigation;
use App\Database;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\ProductPersonalizationContent;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;

/**
 * The architectural line this refactor drew: `products` is the single source
 * of truth for everything a shop product IS, and a personalization
 * configuration is an optional satellite of one of those rows — never a copy
 * of it, never a second catalogue, and never something the product editor
 * also owns.
 *
 * Half of this is behaviour against the real database (enrol, refuse a
 * duplicate, remove a configuration without touching the product); the other
 * half is source inspection, the technique this project already uses where
 * there is no harness (see ProductDeletionAdminSecurityTest) — because "the
 * product editor no longer contains the builder" is a fact about a file, not
 * about a row.
 */
final class PersonalizationCmsSeparationTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-personalization-cms-';

    private ProductRepository $products;
    private ProductPersonalizationRepository $personalization;

    /** @var list<int> */
    private array $productIds = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        $this->personalization = new ProductPersonalizationRepository();
        ProductPersonalizationContent::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        $this->productIds = [];
        ProductPersonalizationContent::clearCache();
    }

    private static function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function createProduct(string $name = 'Testproduct personalisatie CMS'): int
    {
        $id = $this->products->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'price' => 21.5,
            'image_path' => 'assets/images/products/zz-test-gallery.png',
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);

        ShopLocalization::saveProduct($id, 'nl', [
            ShopLocalization::NAME => $name,
            ShopLocalization::DESCRIPTION => 'Beschrijving die niet mag veranderen.',
        ]);
        ShopLocalization::clearCache();

        $this->productIds[] = $id;

        return $id;
    }

    /* ------------------------------------------------------------------ */
    /* The product editor no longer owns personalization                   */
    /* ------------------------------------------------------------------ */

    public function testTheProductEditorNoLongerContainsThePersonalizationBuilder(): void
    {
        $form = self::sourceOf('admin/product-form.php');

        $this->assertStringNotContainsString('renderPersonalizationBuilder(', $form);
        $this->assertStringNotContainsString("_personalization_builder.php", $form);
        $this->assertStringNotContainsString('personalization-admin.js', $form);
        $this->assertStringNotContainsString('update-product-personalization.php', $form);
        $this->assertStringNotContainsString('create-personalization-view.php', $form);
        $this->assertStringNotContainsString('create-personalization-zone.php', $form);
    }

    /**
     * It may still SIGNPOST the dedicated section — that is the one thing it
     * is allowed to know about personalization.
     */
    public function testTheProductEditorOnlyLinksToTheDedicatedSection(): void
    {
        $form = self::sourceOf('admin/product-form.php');

        $this->assertStringContainsString(
            '/admin/personalization.php',
            self::dutchCatalogue()['shop.product_heeft_personalisatie_wil'] ?? ''
        );
        $this->assertStringContainsString('/admin/personalization-product.php?product_id=', $form);
        $this->assertStringContainsString('shop.personalisatie_beheren', $form);
    }

    /**
     * There is exactly ONE editor for a configuration. The builder include is
     * used by the dedicated page and by nothing else.
     */
    public function testThereIsExactlyOnePersonalizationEditor(): void
    {
        $root = dirname(__DIR__, 2);
        $includers = [];

        foreach (glob($root . '/admin/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            if (str_contains($source, "_personalization_builder.php") && !str_ends_with($path, '_personalization_builder.php')) {
                $includers[] = basename($path);
            }
        }

        $this->assertSame(['personalization-product.php'], $includers);
    }

    public function testPersonalizationHasItsOwnCmsNavigationEntry(): void
    {
        $module = self::sourceOf('src/Module/PersonalizationModule.php');

        $this->assertStringContainsString("'label' => 'Personalisatie'", $module);
        $this->assertStringContainsString("'url' => '/admin/personalization.php'", $module);

        // ...and in the assembled sidebar it still sits in the Shop group,
        // after Producten and Collecties, following the CMS's existing
        // structure. Two modules contribute those entries, so the order is
        // asserted on the merged list rather than on one file's text.
        $keys = array_column(AdminNavigation::items(), 'key');
        $catalogPos = array_search('catalog', $keys, true);
        $personalizationPos = array_search('personalization', $keys, true);

        $this->assertIsInt($catalogPos);
        $this->assertIsInt($personalizationPos);
        $this->assertGreaterThan($catalogPos, $personalizationPos);
    }

    /* ------------------------------------------------------------------ */
    /* Enrolling an existing product                                       */
    /* ------------------------------------------------------------------ */

    public function testAnExistingProductCanBeAddedToPersonalization(): void
    {
        $productId = $this->createProduct();

        $this->assertNull($this->personalization->findForProduct($productId));

        $settingsId = $this->personalization->createForProduct($productId);

        $this->assertIsInt($settingsId);

        $stored = $this->personalization->findForProduct($productId);
        $this->assertNotNull($stored);
        // A freshly enrolled product changes NOTHING about how it behaves:
        // switched off, optional, no views.
        $this->assertSame(0, (int) $stored['settings']['is_enabled']);
        $this->assertSame(PersonalizationRules::PURCHASE_OPTIONAL, $stored['settings']['personalization_mode']);
        $this->assertSame([], $stored['views']);
        $this->assertNull(ProductPersonalizationContent::forProduct($productId));
    }

    public function testTheSameProductCannotBeAddedTwice(): void
    {
        $productId = $this->createProduct();

        $this->assertIsInt($this->personalization->createForProduct($productId));
        $this->assertNull($this->personalization->createForProduct($productId), 'a second add must be refused');

        $count = Database::connection()->prepare(
            'SELECT COUNT(*) FROM product_personalization_settings WHERE product_id = :id'
        );
        $count->execute(['id' => $productId]);

        $this->assertSame(1, (int) $count->fetchColumn());
    }

    /**
     * A product that is already configured is not offered in the picker, so
     * the duplicate case cannot even be reached from the UI.
     */
    public function testAnAlreadyConfiguredProductIsNotOfferedInThePicker(): void
    {
        $productId = $this->createProduct();
        $this->personalization->createForProduct($productId);

        $available = array_column($this->personalization->findProductsWithoutConfiguration(), 'id');

        $this->assertNotContains($productId, array_map('intval', $available));
    }

    public function testTheOverviewListsEveryConfiguredProductWithItsCounts(): void
    {
        $productId = $this->createProduct('Testproduct overzicht');
        PersonalizationTestConfig::configure($productId, [
            ['view_key' => 'front', 'zones' => [PersonalizationTestConfig::zone('name')]],
            ['view_key' => 'back', 'image' => null, 'zones' => [PersonalizationTestConfig::zone('message')]],
        ]);

        $rows = array_column($this->personalization->findAllConfigured(), null, 'product_id');

        $this->assertArrayHasKey($productId, $rows);
        $row = $rows[$productId];

        // The overview names a product through App\Service\ShopLocalization
        // since Multilingual 2.0 phase 5 wave C; the query carries ids and
        // counts, not words.
        $this->assertArrayNotHasKey('product_name', $row);
        $this->assertSame('Testproduct overzicht', ShopLocalization::productName($productId));
        $this->assertSame(2, (int) $row['view_count']);
        // One of the two views has no dedicated image, which is exactly what
        // the overview has to be able to show.
        $this->assertSame(1, (int) $row['view_with_image_count']);
        $this->assertSame(2, (int) $row['zone_count']);
    }

    /* ------------------------------------------------------------------ */
    /* Removing a configuration leaves the shop product alone              */
    /* ------------------------------------------------------------------ */

    public function testRemovingPersonalizationDoesNotDeleteTheShopProduct(): void
    {
        $productId = $this->createProduct('Testproduct blijft bestaan');
        PersonalizationTestConfig::singleZone($productId);

        $before = $this->products->findByIdForAdmin($productId);
        $this->assertNotNull($before);

        $orphaned = $this->personalization->deleteForProduct($productId);

        $after = $this->products->findByIdForAdmin($productId);

        $this->assertNotNull($after, 'the product itself must survive');
        // Every column of the product row is byte-for-byte what it was.
        $this->assertSame($before, $after);

        // ...and the configuration really is gone, views and zones with it.
        $this->assertNull($this->personalization->findForProduct($productId));
        ProductPersonalizationContent::clearCache();
        $this->assertNull(ProductPersonalizationContent::forProduct($productId));

        // The caller is handed the now-unreferenced preview images so it can
        // delete the files it owns.
        $this->assertSame([PersonalizationTestConfig::IMAGE], $orphaned);
    }

    public function testRemovingPersonalizationLeavesTheProductBehavingLikeAnyOther(): void
    {
        $productId = $this->createProduct();
        PersonalizationTestConfig::singleZone($productId);

        $this->personalization->deleteForProduct($productId);
        ProductPersonalizationContent::clearCache();

        // "No configuration row" is the same state every never-configured
        // product is in, and reads as "personalization disabled".
        $this->assertNull($this->personalization->findForProduct($productId));
        $this->assertNull(ProductPersonalizationContent::forProduct($productId));
    }

    public function testRemovingAConfigurationForAProductThatHasNoneIsHarmless(): void
    {
        $productId = $this->createProduct();

        $this->assertSame([], $this->personalization->deleteForProduct($productId));
        $this->assertNotNull($this->products->findByIdForAdmin($productId));
    }

    /* ------------------------------------------------------------------ */
    /* No duplicate shop products anywhere                                 */
    /* ------------------------------------------------------------------ */

    /**
     * The Personalisatie module never writes to `products`, and never copies
     * a product's own fields into its configuration — the product row stays
     * the one authority for name, price, variants and visibility.
     */
    public function testThePersonalizationModuleNeverWritesToTheProductsTable(): void
    {
        $sources = [
            'src/Repository/ProductPersonalizationRepository.php',
            'api/admin/create-product-personalization.php',
            'api/admin/delete-product-personalization.php',
            'admin/personalization.php',
            'admin/personalization-product.php',
        ];

        foreach ($sources as $file) {
            $source = self::sourceOf($file);

            $this->assertStringNotContainsString('INSERT INTO products', $source, $file);
            $this->assertStringNotContainsString('UPDATE products', $source, $file);
            $this->assertStringNotContainsString('DELETE FROM products', $source, $file);
        }
    }

    /**
     * And the configuration schema holds no product content: no price, no
     * name, no stock — only which product it belongs to.
     */
    public function testTheConfigurationSchemaHoldsNoProductContent(): void
    {
        $columns = Database::connection()
            ->query('SHOW COLUMNS FROM product_personalization_settings')
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach (['price', 'name', 'slug', 'stock', 'active', 'description'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, 'personalization must not duplicate ' . $forbidden);
        }

        $this->assertContains('product_id', $columns);
    }

    /**
     * A sentence that carries a link keeps that link INSIDE the catalogue
     * string: cutting the anchor out would leave a translator with two
     * fragments and no way to put them back in English word order
     * (MULTILINGUAL.md). So a test about what a screen links to asks the
     * catalogue, not the template.
     *
     * @return array<string, string>
     */
    private static function dutchCatalogue(): array
    {
        /** @var array<string, string> $messages */
        $messages = require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php';

        return $messages;
    }
}
