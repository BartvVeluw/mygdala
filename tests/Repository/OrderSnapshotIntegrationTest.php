<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database (same convention as
 * tests/Repository/PageSectionRepositoryTest.php — order/order_items/product
 * price-derivation logic has no mocking seam worth building, so this
 * exercises the actual schema/SQL). Covers MAIN.MD "Order snapshot
 * requirements" scenarios 1-2: an order's price, product title and variant
 * must never change just because the product they came from is edited later.
 *
 * Every row created here uses an obviously-fake product slug/customer email
 * ('__test_snapshot_product__' / a @__test__.invalid address) so it can never
 * collide with real content; tearDown() removes everything this test created.
 */
final class OrderSnapshotIntegrationTest extends TestCase
{
    private const PRODUCT_SLUG = '__test_snapshot_product__';
    private const CUSTOMER_EMAIL = 'snapshot-test@__test__.invalid';

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
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $this->productId]);
        }
    }

    private function placeTestOrder(ProductRepository $products, OrderRepository $orders, CustomerRepository $customers): void
    {
        $this->productId = $products->create([
            'name' => 'Oorspronkelijke Productnaam',
            'name_en' => 'Original Product Name',
            'slug' => self::PRODUCT_SLUG,
            'description' => null,
            'description_en' => null,
            'price' => 4.95,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);

        $this->customerId = $customers->findOrCreateByEmail([
            'name' => 'Snapshot Test',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $address = [
            'first_name' => 'Snapshot', 'last_name' => 'Test', 'company' => null,
            'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
            'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
        ];

        $this->orderId = $orders->create(
            $this->customerId,
            4.95,
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
            'product_id' => $this->productId,
            'variant_id' => null,
            'variant_label' => null,
            'quantity' => 2,
            'unit_price' => 4.95,
            'product_name' => 'Oorspronkelijke Productnaam',
            'product_name_en' => 'Original Product Name',
        ]]);
    }

    public function testOrderItemPriceIsUnaffectedByALaterProductPriceChange(): void
    {
        $products = new ProductRepository();
        $orders = new OrderRepository();
        $this->placeTestOrder($products, $orders, new CustomerRepository());

        $products->update($this->productId, [
            'name' => 'Oorspronkelijke Productnaam',
            'name_en' => 'Original Product Name',
            'description' => null,
            'description_en' => null,
            'price' => 5.95,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);

        $items = $orders->findItems($this->orderId);

        $this->assertSame('4.95', $items[0]['unit_price']);
    }

    public function testOrderItemProductTitleIsUnaffectedByALaterProductRename(): void
    {
        $products = new ProductRepository();
        $orders = new OrderRepository();
        $this->placeTestOrder($products, $orders, new CustomerRepository());

        $products->update($this->productId, [
            'name' => 'Gewijzigde Productnaam',
            'name_en' => 'Renamed Product',
            'description' => null,
            'description_en' => null,
            'price' => 4.95,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);

        $items = $orders->findItems($this->orderId);

        $this->assertSame('Oorspronkelijke Productnaam', $items[0]['name']);
        $this->assertSame('Original Product Name', $items[0]['name_en']);
    }

    public function testExportSummaryAlsoUsesTheSnapshotNameNotTheLiveName(): void
    {
        $products = new ProductRepository();
        $orders = new OrderRepository();
        $this->placeTestOrder($products, $orders, new CustomerRepository());

        $products->update($this->productId, [
            'name' => 'Gewijzigde Productnaam',
            'name_en' => 'Renamed Product',
            'description' => null,
            'description_en' => null,
            'price' => 4.95,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);

        $rows = array_filter($orders->findForExport(null, null), fn (array $row): bool => (int) $row['id'] === $this->orderId);
        $row = array_values($rows)[0];

        $this->assertStringContainsString('2x Oorspronkelijke Productnaam', $row['items_summary']);
        $this->assertStringNotContainsString('Gewijzigde', $row['items_summary']);
    }
}
