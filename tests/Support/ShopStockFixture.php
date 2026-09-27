<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\InventoryRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductOptionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\ShopLocalization;

/**
 * Products with and without stock, their variants, and orders that reserved
 * stock — for the tests of Shop Product & Ordering 2.0 (voorraad, back in
 * stock, op aanvraag, bestelvelden). Everything it makes carries a `__test_`
 * slug or a `@__test__.invalid` address, and cleanUp() removes all of it:
 * orders first (their lines and snapshots cascade), then the customer, then
 * the products (options, variants and every per-product row cascade).
 */
final class ShopStockFixture
{
    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $orderIds = [];

    private ?int $customerId = null;

    private string $email;

    public function __construct()
    {
        $this->email = 'shop-stock-' . bin2hex(random_bytes(4)) . '@__test__.invalid';
    }

    /**
     * A product without variants. $stock null leaves "Voorraad bijhouden" off.
     */
    public function product(string $name, ?int $stock = null, float $price = 10.00): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_stock_' . bin2hex(random_bytes(5)),
            'price' => $price,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        ShopLocalization::saveProduct($id, ShopLocalization::defaultLanguage(), [
            ShopLocalization::NAME => $name,
            ShopLocalization::DESCRIPTION => '',
            ShopLocalization::META_TITLE => '',
            ShopLocalization::META_DESCRIPTION => '',
        ]);
        ShopLocalization::clearCache();

        if ($stock !== null) {
            $inventory = new InventoryRepository();
            $inventory->setTracking($id, true);
            $inventory->setProductStock($id, $stock, null);
        }

        return $id;
    }

    /**
     * A product with one option ("Kleur") and a variant per value, each with
     * the stock given. $tracked false leaves the product untracked (the
     * variants still get their numbers, which then mean nothing).
     *
     * @param array<string, int> $stockByValue value name => stock
     * @return array{product: int, variants: array<string, int>} variant ids by value name
     */
    public function variantProduct(string $name, array $stockByValue, bool $tracked = true, float $price = 20.00): array
    {
        $id = $this->product($name, null, $price);
        $options = new ProductOptionRepository();
        $variants = new ProductVariantRepository();
        $inventory = new InventoryRepository();

        $optionId = $options->createOption($id, 'Kleur');
        $variantIds = [];
        foreach ($stockByValue as $value => $stock) {
            $valueId = $options->createValue($optionId, (string) $value);
            $variantId = $variants->create($id, [$valueId], null, true);
            $inventory->setVariantStock($variantId, $id, $stock, null);
            $variantIds[(string) $value] = $variantId;
        }

        if ($tracked) {
            $inventory->setTracking($id, true);
        }

        return ['product' => $id, 'variants' => $variantIds];
    }

    /**
     * A pending order with these lines, reserving what each line says it
     * reserved — the shape api/checkout.php leaves behind. $paymentId null
     * is an order whose payment was never started.
     *
     * @param list<array{product_id: int, variant_id?: ?int, quantity: int, stock_reserved?: int, stock_source?: ?string}> $lines
     */
    public function order(array $lines, ?string $paymentId = null, string $status = 'pending'): int
    {
        $orders = new OrderRepository();

        $this->customerId ??= (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Voorraad Klant',
            'email' => $this->email,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $address = [
            'first_name' => 'Voorraad', 'last_name' => 'Klant', 'company' => null,
            'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
            'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
        ];

        $orderId = $orders->create(
            $this->customerId,
            10.00,
            0.00,
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'shop-stock-terms'),
            $address,
            null
        );
        $this->orderIds[] = $orderId;

        $orders->addItems($orderId, array_map(static fn (array $line): array => [
            'product_id' => $line['product_id'],
            'variant_id' => $line['variant_id'] ?? null,
            'variant_label' => null,
            'product_name' => 'Voorraad testregel',
            'quantity' => $line['quantity'],
            'unit_price' => 10.00,
            'stock_reserved' => $line['stock_reserved'] ?? 0,
            'stock_source' => $line['stock_source'] ?? null,
        ], $lines));

        $db = Database::connection();
        $db->prepare('UPDATE orders SET status = :status, mollie_payment_id = :payment WHERE id = :id')
            ->execute(['status' => $status, 'payment' => $paymentId, 'id' => $orderId]);

        return $orderId;
    }

    public function productStock(int $productId): int
    {
        return (int) (new InventoryRepository())->productStock($productId);
    }

    public function variantStock(int $variantId): int
    {
        return (int) (new InventoryRepository())->variantStock($variantId);
    }

    /** @return array<string, mixed> */
    public function orderRow(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);

        return (array) $stmt->fetch();
    }

    public function trackOrder(int $orderId): void
    {
        $this->orderIds[] = $orderId;
    }

    public function trackProduct(int $productId): void
    {
        $this->productIds[] = $productId;
    }

    public function cleanUp(): void
    {
        $db = Database::connection();
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        foreach ($this->orderIds as $orderId) {
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $orderId]);
        }
        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
        }
        foreach ($this->productIds as $productId) {
            // Variants first: their values point at option values with
            // RESTRICT, so the product's cascade cannot remove the options
            // while a variant still uses them.
            $db->prepare('DELETE FROM product_variants WHERE product_id = :id')->execute(['id' => $productId]);
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
        }

        $this->orderIds = [];
        $this->productIds = [];
        $this->customerId = null;
        ShopLocalization::clearCache();
    }
}
