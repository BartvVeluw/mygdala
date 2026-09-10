<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductImageRepository;
use App\Repository\ProductOptionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\ProductDeletionService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database, same convention as
 * tests/Repository/OrderSnapshotIntegrationTest.php — the whole point of
 * product deletion is the schema behaviour (cascades, ON DELETE SET NULL,
 * the RESTRICT collision between the option-value and variant-value foreign
 * keys), which no mock can meaningfully exercise.
 *
 * Covers MAIN.MD "Product verwijderen": a catalog product can be permanently
 * removed, its own child rows go with it, and a historical order that
 * contains it keeps its title, variant, quantity and price exactly as they
 * were.
 *
 * Everything created here uses an obviously-fake slug ('__test_delete_*__')
 * and a @__test__.invalid customer address so it can never collide with real
 * content; tearDown() removes whatever a test did not delete itself.
 */
final class ProductDeletionIntegrationTest extends TestCase
{
    private const SLUG = '__test_delete_product__';
    private const CUSTOMER_EMAIL = 'delete-test@__test__.invalid';

    private ?int $productId = null;
    private ?int $customerId = null;
    private ?int $orderId = null;

    protected function tearDown(): void
    {
        $db = Database::connection();

        if ($this->orderId !== null) {
            $db->prepare('DELETE FROM order_refunds WHERE order_id = :id')->execute(['id' => $this->orderId]);
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $this->orderId]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $this->orderId]);
        }
        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
        }
        if ($this->productId !== null) {
            $db->prepare('DELETE FROM product_variants WHERE product_id = :id')->execute(['id' => $this->productId]);
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $this->productId]);
        }

        $this->orderId = null;
        $this->customerId = null;
        $this->productId = null;
    }

    private function createProduct(string $name = 'Te verwijderen testproduct'): int
    {
        $this->productId = (new ProductRepository())->create([
            'name' => $name,
            'name_en' => null,
            'slug' => self::SLUG,
            'description' => null,
            'description_en' => null,
            'price' => 12.50,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);

        return $this->productId;
    }

    /**
     * Builds the full product-owned graph: one gallery image, one option with
     * one value, and one variant carrying that value plus its own image.
     *
     * @return array{option:int, value:int, variant:int, image:int}
     */
    private function attachVariantGraph(int $productId): array
    {
        $imageId = (new ProductImageRepository())->create($productId, 'assets/images/products/__test_delete__.webp', true);

        $options = new ProductOptionRepository();
        $optionId = $options->createOption($productId, 'Kleur');
        $valueId = $options->createValue($optionId, 'Noten');

        $variantId = (new ProductVariantRepository())->create($productId, [$valueId], 13.50, true);

        Database::connection()
            ->prepare('INSERT INTO variant_images (variant_id, image_path, sort_order, created_at, updated_at)
                       VALUES (:v, :p, 0, NOW(), NOW())')
            ->execute(['v' => $variantId, 'p' => 'assets/images/products/__test_delete_variant__.webp']);

        return ['option' => $optionId, 'value' => $valueId, 'variant' => $variantId, 'image' => $imageId];
    }

    private function placeOrderFor(int $productId, ?int $variantId, ?string $variantLabel): int
    {
        $customers = new CustomerRepository();
        $orders = new OrderRepository();

        $this->customerId = $customers->findOrCreateByEmail([
            'name' => 'Delete Test',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $address = [
            'first_name' => 'Delete', 'last_name' => 'Test', 'company' => null,
            'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
            'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
        ];

        $this->orderId = $orders->create(
            $this->customerId,
            25.00,
            0.00,
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            $address,
            null
        );

        $orders->addItems($this->orderId, [[
            'product_id' => $productId,
            'variant_id' => $variantId,
            'variant_label' => $variantLabel,
            'quantity' => 2,
            'unit_price' => 12.50,
            'product_name' => 'Historische Productnaam',
            'product_name_en' => 'Historic Product Name',
        ]]);

        return $this->orderId;
    }

    // ---------------------------------------------------------------- 1, 2, 3

    public function testAProductCanBeDeleted(): void
    {
        $productId = $this->createProduct();

        $this->assertTrue((new ProductDeletionService())->delete($productId));
        $this->assertNull((new ProductRepository())->findByIdForAdmin($productId));

        $this->productId = null;
    }

    public function testDeletedProductNoLongerAppearsInTheAdminProductList(): void
    {
        $productId = $this->createProduct();

        (new ProductDeletionService())->delete($productId);

        $ids = array_map('intval', array_column((new ProductRepository())->findAllForAdmin(), 'id'));
        $this->assertNotContains($productId, $ids);

        $this->productId = null;
    }

    public function testDeletedProductNoLongerAppearsInThePublicShopOrOnItsDetailUrl(): void
    {
        $productId = $this->createProduct();

        (new ProductDeletionService())->delete($productId);

        $repository = new ProductRepository();

        $ids = array_map('intval', array_column($repository->findAllActive(), 'id'));
        $this->assertNotContains($productId, $ids, 'deleted product must be gone from the public shop listing');

        // The public product detail page resolves by id through this method —
        // a NULL here is what makes /product.php?id=N stop exposing it.
        $this->assertNull($repository->findActiveById($productId));
        $this->assertSame([], $repository->findActiveByIds([$productId]), 'checkout must not be able to re-add it');

        $this->productId = null;
    }

    // ------------------------------------------------------------------- 4, 5

    public function testVariantsAndEveryProductOwnedRelationAreRemovedWithoutOrphans(): void
    {
        $productId = $this->createProduct();
        $graph = $this->attachVariantGraph($productId);

        $this->assertTrue(
            (new ProductDeletionService())->delete($productId),
            'a product with variants must be deletable — the products cascade collides with the '
            . 'RESTRICT on product_variant_values.product_option_value_id unless variants go first'
        );

        $db = Database::connection();

        $countWhere = static function (string $sql, array $params) use ($db): int {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        };

        $this->assertSame(0, $countWhere('SELECT COUNT(*) FROM product_variants WHERE product_id = :id', ['id' => $productId]));
        $this->assertSame(0, $countWhere('SELECT COUNT(*) FROM product_images WHERE product_id = :id', ['id' => $productId]));
        $this->assertSame(0, $countWhere('SELECT COUNT(*) FROM product_options WHERE product_id = :id', ['id' => $productId]));
        $this->assertSame(0, $countWhere('SELECT COUNT(*) FROM product_option_values WHERE product_option_id = :id', ['id' => $graph['option']]));
        $this->assertSame(0, $countWhere('SELECT COUNT(*) FROM product_variant_values WHERE variant_id = :id', ['id' => $graph['variant']]));
        $this->assertSame(0, $countWhere('SELECT COUNT(*) FROM variant_images WHERE variant_id = :id', ['id' => $graph['variant']]));

        $this->productId = null;
    }

    // ------------------------------------------------------- 9 (unknown id)

    public function testAnUnknownProductIdFailsSafely(): void
    {
        $service = new ProductDeletionService();

        $this->assertFalse($service->delete(999999999), 'a non-existent id must be a no-op, not an error');
        $this->assertFalse($service->delete(0));
        $this->assertFalse($service->delete(-1));
    }

    public function testDeletingTheSameProductTwiceIsHarmless(): void
    {
        $productId = $this->createProduct();
        $service = new ProductDeletionService();

        $this->assertTrue($service->delete($productId));
        $this->assertFalse($service->delete($productId), 'a double-submitted delete must not throw');

        $this->productId = null;
    }

    // ------------------------------------------------------ 10, 11, 12, 13, 14

    public function testAProductOnAHistoricalOrderCanStillBeDeleted(): void
    {
        $productId = $this->createProduct();
        $this->placeOrderFor($productId, null, null);

        $this->assertTrue(
            (new ProductDeletionService())->delete($productId),
            'the order_items snapshot architecture is what makes this allowed'
        );

        $this->productId = null;
    }

    public function testTheHistoricalOrderRemainsIntactAfterTheProductIsDeleted(): void
    {
        $productId = $this->createProduct();
        $graph = $this->attachVariantGraph($productId);
        $orderId = $this->placeOrderFor($productId, $graph['variant'], 'Kleur: Noten');

        $orders = new OrderRepository();
        $before = $orders->findById($orderId);

        (new ProductDeletionService())->delete($productId);
        $this->productId = null;

        $items = $orders->findItems($orderId);

        // The order line still exists at all — an INNER JOIN to products would
        // have made it vanish the moment product_id became NULL.
        $this->assertCount(1, $items, 'the order line must survive the product being deleted');

        $item = $items[0];

        $this->assertSame('Historische Productnaam', $item['name'], 'historical product name must be unchanged');
        $this->assertSame('Historic Product Name', $item['name_en']);
        $this->assertSame('Kleur: Noten', $item['variant_label'], 'historical variant must be unchanged');
        $this->assertSame(2, (int) $item['quantity'], 'historical quantity must be unchanged');
        $this->assertSame('12.50', $item['unit_price'], 'historical price must be unchanged');

        // The order itself: total, status and every other field untouched.
        $after = $orders->findById($orderId);
        $this->assertNotNull($after);
        $this->assertSame($before['total'], $after['total'], 'the order total must not be rewritten');
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame($before['created_at'], $after['created_at']);

        // order_items rows are detached, never deleted.
        $stmt = Database::connection()->prepare('SELECT product_id, variant_id FROM order_items WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'the order_items row itself must still exist');
        $this->assertNull($row['product_id'], 'product_id must be detached (ON DELETE SET NULL), not cascaded');
        $this->assertNull($row['variant_id'], 'variant_id must be detached the same way');
    }

    public function testTheCsvExportSummaryStillShowsTheDeletedProductsHistoricalName(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->placeOrderFor($productId, null, null);

        $orders = new OrderRepository();

        (new ProductDeletionService())->delete($productId);
        $this->productId = null;

        $rows = array_filter($orders->findForExport(null, null), fn (array $row): bool => (int) $row['id'] === $orderId);
        $this->assertNotEmpty($rows, 'the order must still be exportable');

        $row = array_values($rows)[0];
        $this->assertStringContainsString('2x Historische Productnaam', $row['items_summary']);
    }

    // --------------------------------------------------------------------- 15

    public function testAnExistingInvoiceIsUnchangedByTheProductDeletion(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->placeOrderFor($productId, null, null);

        $db = Database::connection();
        $db->prepare(
            "INSERT INTO invoices (order_id, invoice_number, invoice_date, currency, seller_snapshot, pdf_path, created_at, updated_at)
             VALUES (:order_id, :number, CURDATE(), 'EUR', :snapshot, :pdf, NOW(), NOW())"
        )->execute([
            'order_id' => $orderId,
            'number' => '__TEST__-' . $orderId,
            'snapshot' => '{"company":"Test"}',
            'pdf' => 'storage/invoices/__test__.pdf',
        ]);

        $read = static function () use ($db, $orderId) {
            $stmt = $db->prepare('SELECT invoice_number, invoice_date, currency, seller_snapshot, pdf_path FROM invoices WHERE order_id = :id');
            $stmt->execute(['id' => $orderId]);

            return $stmt->fetch();
        };

        $before = $read();

        (new ProductDeletionService())->delete($productId);
        $this->productId = null;

        $after = $read();

        $this->assertNotFalse($after, 'the invoice must still exist');
        $this->assertSame($before, $after, 'no invoice field may change because a product was deleted');

        $db->prepare('DELETE FROM invoices WHERE order_id = :id')->execute(['id' => $orderId]);
    }

    /**
     * The transaction guarantee: if the database work fails partway, nothing at
     * all is removed — not the variants that are deleted first, not the product.
     */
    public function testAFailureRollsBackTheWholeDeletion(): void
    {
        $productId = $this->createProduct();
        $graph = $this->attachVariantGraph($productId);

        $failingProducts = new class () extends ProductRepository {
            public function delete(int $id): bool
            {
                throw new \RuntimeException('simulated failure after the variants were removed');
            }
        };

        $service = new ProductDeletionService(Database::connection(), $failingProducts);

        try {
            $service->delete($productId);
            $this->fail('the simulated failure should have propagated');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('simulated failure', $e->getMessage());
        }

        $db = Database::connection();

        $stmt = $db->prepare('SELECT COUNT(*) FROM product_variants WHERE id = :id');
        $stmt->execute(['id' => $graph['variant']]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'the variant deletion must have been rolled back');

        $this->assertNotNull((new ProductRepository())->findByIdForAdmin($productId), 'the product must still exist');
    }
}
