<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\OrderItemNameSnapshot;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database (same convention as
 * tests/Repository/PageSectionRepositoryTest.php — order/order_items/product
 * price-derivation logic has no mocking seam worth building, so this
 * exercises the actual schema/SQL). Covers MAIN.MD "Order snapshot
 * requirements" scenarios 1-2: an order's price, product title and variant
 * must never change just because the product they came from is edited later.
 *
 * It also guards the read model those scenarios end up in: the product list
 * OrderRepository::findForExport() hands to the bookkeeping CSV must be the
 * snapshot, and must be all of it.
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
            'slug' => self::PRODUCT_SLUG,
            'price' => 4.95,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);

        $this->nameProduct('Oorspronkelijke Productnaam', 'Original Product Name');

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
        ]]);

        // What api/checkout.php does after addItems(): the neutral snapshot is
        // on the line itself, the website's OTHER languages get a row of their
        // own (App\Service\OrderItemNameSnapshot).
        $items = $orders->findItems($this->orderId);
        OrderItemNameSnapshot::record((int) $items[0]['id'], 'en', 'Original Product Name');
        OrderItemNameSnapshot::clearCache();
    }

    /**
     * Names the test product in both website languages. A product's name is a
     * row in `product_translations` since Multilingual 2.0 phase 5 wave C, so
     * renaming it below never touches the `products` row at all — which is
     * exactly what makes the snapshot assertions below meaningful.
     */
    private function nameProduct(string $dutch, string $english): void
    {
        ShopLocalization::saveProduct((int) $this->productId, 'nl', [ShopLocalization::NAME => $dutch]);
        ShopLocalization::saveProduct((int) $this->productId, 'en', [ShopLocalization::NAME => $english]);
        ShopLocalization::clearCache();
    }

    public function testOrderItemPriceIsUnaffectedByALaterProductPriceChange(): void
    {
        $products = new ProductRepository();
        $orders = new OrderRepository();
        $this->placeTestOrder($products, $orders, new CustomerRepository());

        $products->update($this->productId, [
            'price' => 5.95,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
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

        $this->nameProduct('Gewijzigde Productnaam', 'Renamed Product');

        $items = $orders->findItems($this->orderId);
        OrderItemNameSnapshot::clearCache();

        $this->assertSame('Oorspronkelijke Productnaam', $items[0]['name']);
        $this->assertSame(
            'Original Product Name',
            OrderItemNameSnapshot::name((int) $items[0]['id'], 'en', (string) $items[0]['name']),
            'the English half of the snapshot is just as frozen as the neutral one'
        );
    }

    public function testExportSummaryAlsoUsesTheSnapshotNameNotTheLiveName(): void
    {
        $products = new ProductRepository();
        $orders = new OrderRepository();
        $this->placeTestOrder($products, $orders, new CustomerRepository());

        $this->nameProduct('Gewijzigde Productnaam', 'Renamed Product');

        $rows = array_filter($orders->findForExport(null, null), fn (array $row): bool => (int) $row['id'] === $this->orderId);
        $row = array_values($rows)[0];

        $this->assertStringContainsString('2x Oorspronkelijke Productnaam', $row['items_summary']);
        $this->assertStringNotContainsString('Gewijzigde', $row['items_summary']);
    }

    /**
     * The export summary used to be built with MySQL's GROUP_CONCAT(), which
     * stops at group_concat_max_len (1024 bytes by default) and reports
     * nothing when it does. An order with enough lines therefore reached the
     * bookkeeping CSV with part of its product list missing, and no one
     * downstream could tell. This fixture is deliberately well past that old
     * ceiling, so it fails the moment a ceiling comes back.
     */
    public function testExportSummaryKeepsEveryLineOfAnOrderWithManyItems(): void
    {
        $orders = new OrderRepository();
        $this->placeTestOrder(new ProductRepository(), $orders, new CustomerRepository());
        $orders->addItems($this->orderId, $this->manyLineItems());

        $rows = array_filter($orders->findForExport(null, null), fn (array $row): bool => (int) $row['id'] === $this->orderId);
        $summary = (string) array_values($rows)[0]['items_summary'];

        foreach ($this->manyLineItems() as $item) {
            $this->assertStringContainsString(
                $item['quantity'] . 'x ' . $item['product_name'] . ' (' . $item['variant_label'] . ')',
                $summary,
                'line ' . $item['quantity'] . ' is missing from the export summary'
            );
        }

        // Checked last, so a real truncation fails on the line above rather
        // than here: this only guards the fixture from shrinking below the
        // ceiling it exists to cross.
        $this->assertGreaterThan(
            1024,
            strlen($summary),
            'the fixture must exceed the default GROUP_CONCAT ceiling, or this test proves nothing'
        );
    }

    /**
     * Twenty extra lines on top of the one placeTestOrder() adds. Each name
     * and label stays inside the 255-character columns order_items really
     * has, while together they run to a few thousand bytes.
     *
     * @return list<array<string, mixed>>
     */
    private function manyLineItems(): array
    {
        $items = [];

        for ($line = 1; $line <= 20; $line++) {
            $number = str_pad((string) $line, 2, '0', STR_PAD_LEFT);

            $items[] = [
                'product_id' => $this->productId,
                'variant_id' => null,
                'variant_label' => 'Variant ' . $number . ' ' . str_repeat('V', 40),
                'quantity' => $line,
                'unit_price' => 4.95,
                'product_name' => 'Regel ' . $number . ' ' . str_repeat('P', 40),
            ];
        }

        return $items;
    }
}
