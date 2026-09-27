<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\ProductSpecificationRepository;
use App\Service\ProductSpecificationEditor;
use App\Service\ProductSpecifications;
use App\Service\ShopLocalization;
use App\Service\SpecificationLibraryEditor;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\ShopStockFixture;

/**
 * Reusable product specifications (Shop Product & Ordering 2.0, MODULES.md
 * "Specificaties"), against the real database and over real HTTP:
 *
 *   - the library stores properties with a name per language and an
 *     optional unit, in screen order, and refuses a missing name or an
 *     unusable unit;
 *   - the product editor adds properties from the library with a value per
 *     language, in its own order, once each; a property not in the library
 *     is refused;
 *   - the product page lists only the properties with a value, with the
 *     unit, in the language of the page, never an empty row;
 *   - removing a property from the library, or deleting the product, takes
 *     the values with it.
 */
final class ProductSpecificationsTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private ShopStockFixture $fixture;
    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $specificationIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->fixture = new ShopStockFixture();
        $this->accounts = new AdminTestSession();
        $this->specificationIds = array_column((new ProductSpecificationRepository())->all(), 'id');
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
        $this->accounts->forget();

        // Only the properties this test made; a library the database had stays.
        $db = Database::connection();
        foreach ((new ProductSpecificationRepository())->all() as $specification) {
            if (!in_array($specification['id'], $this->specificationIds, true)) {
                $db->prepare('DELETE FROM product_specifications WHERE id = :id')->execute(['id' => $specification['id']]);
            }
        }
        ShopLocalization::clearCache();
    }

    public function testTheLibraryStoresNamesPerLanguageAndUnitsInScreenOrder(): void
    {
        $ids = $this->library();
        $all = array_values(array_filter((new ProductSpecificationRepository())->all(), static fn (array $s): bool => in_array($s['id'], $ids, true)));

        self::assertSame(['mm', 'cm', ''], array_column($all, 'unit'));
        self::assertSame('Dikte', ShopLocalization::specificationName($ids[0], 'nl'));
        self::assertSame('Dikte', ShopLocalization::specificationName($ids[0], 'en'), 'an untranslated name falls back');

        $this->saveLibrary('en', [
            (string) $ids[2] => ['name' => 'Material', 'unit' => ''],
            (string) $ids[0] => ['name' => 'Thickness', 'unit' => 'mm'],
            (string) $ids[1] => ['name' => '', 'unit' => 'cm'],
        ]);
        self::assertSame('Thickness', ShopLocalization::specificationName($ids[0], 'en'));
        self::assertSame('Dikte', ShopLocalization::specificationName($ids[0], 'nl'), 'the Dutch name stays');
        self::assertSame('Hoogte', ShopLocalization::specificationName($ids[1], 'en'), 'an empty translation falls back');

        $order = array_column(array_values(array_filter((new ProductSpecificationRepository())->all(), static fn (array $s): bool => in_array($s['id'], $ids, true))), 'id');
        self::assertSame([$ids[2], $ids[0], $ids[1]], $order, 'the order on screen');

        $refused = SpecificationLibraryEditor::fromRequest(['specifications' => [
            'new0' => ['name' => '', 'unit' => 'kg'],
            'new1' => ['name' => 'Gewicht', 'unit' => '<script>'],
        ]], 'nl', Database::connection());
        $errors = $refused->validate();
        self::assertArrayHasKey('specifications[new0][name]', $errors);
        self::assertArrayHasKey('specifications[new1][unit]', $errors);
    }

    public function testAProductGetsValuesFromTheLibraryOncePerPropertyInItsOwnOrder(): void
    {
        [$thickness, $height, $material] = $this->library();
        $product = $this->fixture->product('ZZ Specs Plank');

        $this->saveProduct($product, 'nl', [
            'new0' => ['specification_id' => (string) $material, 'value' => 'Berken multiplex'],
            'new1' => ['specification_id' => (string) $thickness, 'value' => '3'],
            'new2' => ['specification_id' => (string) $height, 'value' => ''],
        ]);

        $rows = (new ProductSpecificationRepository())->valuesForProduct($product);
        self::assertSame([$material, $thickness, $height], array_column($rows, 'specification_id'));

        self::assertSame([
            ['name' => 'Materiaal', 'value' => 'Berken multiplex', 'unit' => ''],
            ['name' => 'Dikte', 'value' => '3', 'unit' => 'mm'],
        ], (new ProductSpecifications())->forProduct($product, 'nl'), 'no empty row for Hoogte');

        // English: the material is translated, the number is not needed twice.
        $this->saveProduct($product, 'en', [
            (string) $rows[0]['id'] => ['value' => 'Birch plywood'],
            (string) $rows[1]['id'] => ['value' => ''],
            (string) $rows[2]['id'] => ['value' => ''],
        ]);
        self::assertSame([
            ['name' => 'Materiaal', 'value' => 'Birch plywood', 'unit' => ''],
            ['name' => 'Dikte', 'value' => '3', 'unit' => 'mm'],
        ], (new ProductSpecifications())->forProduct($product, 'en'));

        // Reorder and remove: Dikte first, Materiaal gone.
        $this->saveProduct($product, 'nl', [
            (string) $rows[1]['id'] => ['value' => '4'],
            (string) $rows[2]['id'] => ['value' => '60'],
        ]);
        self::assertSame([
            ['name' => 'Dikte', 'value' => '4', 'unit' => 'mm'],
            ['name' => 'Hoogte', 'value' => '60', 'unit' => 'cm'],
        ], (new ProductSpecifications())->forProduct($product, 'nl'));

        $editor = ProductSpecificationEditor::fromRequest(['specifications_present' => '1', 'product_specifications' => [
            (string) $rows[1]['id'] => ['value' => '4'],
            'new0' => ['specification_id' => (string) $thickness, 'value' => '5'],
            'new1' => ['specification_id' => '999999999', 'value' => 'x'],
        ]], $product, 'nl', Database::connection());
        $errors = $editor->validate();
        self::assertArrayHasKey('product_specifications[new0][specification_id]', $errors, 'a property once');
        self::assertArrayHasKey('product_specifications[new1][specification_id]', $errors, 'only from the library');
    }

    public function testRemovingAPropertyOrTheProductTakesTheValuesWithIt(): void
    {
        [$thickness, $height] = $this->library();
        $product = $this->fixture->product('ZZ Specs Weg');
        $this->saveProduct($product, 'nl', [
            'new0' => ['specification_id' => (string) $thickness, 'value' => '3'],
            'new1' => ['specification_id' => (string) $height, 'value' => '40'],
        ]);

        $usage = array_column((new ProductSpecificationRepository())->all(), 'usage', 'id');
        self::assertSame(1, $usage[$thickness]);

        (new ProductSpecificationRepository())->delete($thickness);
        self::assertSame([$height], array_column((new ProductSpecificationRepository())->valuesForProduct($product), 'specification_id'));

        Database::connection()->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $product]);
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM product_specification_values WHERE product_id = :id');
        $count->execute(['id' => $product]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testTheScreensAndTheProductPage(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);

        $library = self::$server->request('GET', '/admin/product-specifications.php', $session);
        self::assertSame(200, $library['status']);
        self::assertStringContainsString('data-row-list-template="specifications"', $library['body']);

        // Whatever the library already holds is posted along, so it stays.
        $existing = [];
        foreach ((new ProductSpecificationRepository())->all() as $specification) {
            $existing[(string) $specification['id']] = ['present' => '1', 'name' => ShopLocalization::rawSpecification($specification['id'], 'nl'), 'unit' => $specification['unit']];
        }

        $saved = self::$server->request('POST', '/api/admin/update-product-specifications.php', $session, [
            'csrf_token' => $csrf, 'language_code' => 'nl',
            'specifications' => $existing + ['new0' => ['present' => '1', 'name' => 'ZZ Dikte', 'unit' => 'mm']],
        ], [], ['Accept: application/json']);
        self::assertSame(200, $saved['status'], $saved['body']);
        $thickness = $this->newest();

        $refused = self::$server->request('POST', '/api/admin/update-product-specifications.php', $session, [
            'csrf_token' => $csrf, 'language_code' => 'nl',
            'specifications' => [(string) $thickness => ['present' => '1', 'name' => '', 'unit' => 'mm']] + $existing,
        ], [], ['Accept: application/json']);
        self::assertSame(422, $refused['status']);

        $product = $this->fixture->product('ZZ Specs Pagina');
        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, [
            'csrf_token' => $csrf, 'id' => (string) $product, 'language_code' => 'nl', 'name' => 'ZZ Specs Pagina',
            'description' => '', 'price' => '10', 'active' => '1', 'in_shop' => '1',
            'shipping_profile' => 'letter', 'shipping_weight_grams' => '20',
            'specifications_present' => '1',
            'product_specifications' => ['new0' => ['present' => '1', 'specification_id' => (string) $thickness, 'value' => '3']],
        ], [], ['Accept: application/json']);
        self::assertSame(200, $response['status'], $response['body']);

        $form = self::$server->request('GET', '/admin/product-form.php?id=' . $product, $session);
        self::assertStringContainsString('data-admin-editor-region="specifications"', $form['body']);
        self::assertStringContainsString('<strong>ZZ Dikte</strong>', $form['body']);

        $page = self::$server->request('GET', '/product.php?id=' . $product);
        self::assertMatchesRegularExpression('#<dt>ZZ Dikte</dt>\s*<dd>3 mm</dd>#', $page['body']);
    }

    /* ------------------------------------------------------------------ */

    /** @return list<int> Dikte (mm), Hoogte (cm), Materiaal */
    private function library(): array
    {
        $this->saveLibrary('nl', [
            'new0' => ['name' => 'Dikte', 'unit' => 'mm'],
            'new1' => ['name' => 'Hoogte', 'unit' => 'cm'],
            'new2' => ['name' => 'Materiaal', 'unit' => ''],
        ], true);

        $new = array_values(array_filter(
            array_column((new ProductSpecificationRepository())->all(), 'id'),
            fn (int $id): bool => !in_array($id, $this->specificationIds, true)
        ));
        sort($new);

        return $new;
    }

    /**
     * Saves the library as given, keeping every property this test did not
     * make (a real library may exist in the database).
     *
     * @param array<string, array{name: string, unit: string}> $rows
     */
    private function saveLibrary(string $language, array $rows, bool $append = false): void
    {
        $keep = [];
        foreach ((new ProductSpecificationRepository())->all() as $specification) {
            if (in_array($specification['id'], $this->specificationIds, true)) {
                $keep[(string) $specification['id']] = ['name' => ShopLocalization::rawSpecification($specification['id'], $language), 'unit' => $specification['unit']];
            } elseif ($append) {
                $keep[(string) $specification['id']] = ['name' => ShopLocalization::rawSpecification($specification['id'], $language), 'unit' => $specification['unit']];
            }
        }

        $db = Database::connection();
        $editor = SpecificationLibraryEditor::fromRequest(['specifications' => $keep + $rows], $language, $db);
        self::assertSame([], $editor->validate());
        $db->beginTransaction();
        $editor->save();
        $db->commit();
        ShopLocalization::clearCache();
    }

    /** @param array<string, array<string, string>> $rows */
    private function saveProduct(int $product, string $language, array $rows): void
    {
        $db = Database::connection();
        $editor = ProductSpecificationEditor::fromRequest(['specifications_present' => '1', 'product_specifications' => $rows], $product, $language, $db);
        self::assertSame([], $editor->validate());
        $db->beginTransaction();
        $editor->save();
        $db->commit();
        ShopLocalization::clearCache();
    }

    private function newest(): int
    {
        return (int) max(array_column((new ProductSpecificationRepository())->all(), 'id'));
    }
}
