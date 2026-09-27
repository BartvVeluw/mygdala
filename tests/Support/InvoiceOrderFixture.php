<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\InvoiceService;
use App\Service\InvoiceStorage;

/**
 * Paid test orders with a real issued invoice — the row, its number from the
 * real counter and its PDF in the real InvoiceStorage — for the tests of the
 * CMS's "Factuur bekijken" (Tests\Service\InvoicePreviewTest and
 * InvoicePreviewHttpTest).
 *
 * Every order has two lines (one with a variant label), shipping costs and a
 * separate billing address, so the preview is checked on more than one line
 * and on the billing address rather than the delivery address.
 *
 * cleanUp() removes everything this fixture made, the PDF files included, and
 * puts each year's invoice counter back where it was: issuing an invoice
 * spends a number, and a test run should not.
 */
final class InvoiceOrderFixture
{
    public const CUSTOMER_NAME = 'Fixture Klant';
    public const BILLING_NAME = 'Factuur Ontvanger';
    public const BILLING_STREET = 'Factuurlaan';
    public const BILLING_CITY = 'Facturendam';
    public const LINE_ONE = 'Invoice Preview Plank';
    public const LINE_ONE_VARIANT = 'Eiken, 60 cm';
    public const LINE_TWO = 'Invoice Preview Onderzetter';

    private ?int $productId = null;
    private ?int $customerId = null;
    private string $email;

    /** @var list<int> */
    private array $orderIds = [];

    /** @var array<int, int|null> year => last_number before this fixture, null when the year had no row */
    private array $counters = [];

    public function __construct()
    {
        $this->email = 'invoice-preview-' . bin2hex(random_bytes(4)) . '@__test__.invalid';
    }

    /**
     * A paid order: 2 x 12.50 plus 1 x 7.25, 6.95 shipping, total 39.20.
     */
    public function paidOrder(): int
    {
        return $this->order('paid');
    }

    public function order(string $status): int
    {
        $this->rememberCounter((int) date('Y'));

        $products = new ProductRepository();
        $orders = new OrderRepository();

        if ($this->productId === null) {
            $this->productId = $products->create([
                'name' => self::LINE_ONE,
                'name_en' => self::LINE_ONE,
                'slug' => '__test_invoice_preview_' . bin2hex(random_bytes(4)),
                'description' => null,
                'description_en' => null,
                'price' => 12.50,
                'image_path' => null,
                'active' => true,
                'shipping_profile' => 'letter',
                'shipping_weight_grams' => 10,
                'requires_parcel' => false,
            ]);
        }

        if ($this->customerId === null) {
            $this->customerId = (new CustomerRepository())->findOrCreateByEmail([
                'name' => self::CUSTOMER_NAME,
                'email' => $this->email,
                'phone' => null,
                'address_line' => 'Teststraat 1',
                'postal_code' => '1234AB',
                'city' => 'Teststad',
                'country' => 'NL',
            ]);
        }

        $shipping = [
            'first_name' => 'Bezorg', 'last_name' => 'Adres', 'company' => null,
            'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
            'house_number_addition' => null, 'street' => 'Bezorgstraat', 'city' => 'Bezorgdorp',
        ];
        $billing = [
            'first_name' => 'Factuur', 'last_name' => 'Ontvanger', 'company' => null,
            'country' => 'NL', 'postal_code' => '6511AA', 'house_number' => '42',
            'house_number_addition' => 'B', 'street' => self::BILLING_STREET, 'city' => self::BILLING_CITY,
        ];

        $orderId = $orders->create(
            $this->customerId,
            39.20,
            6.95,
            'letter',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'invoice-preview-terms'),
            $shipping,
            $billing
        );
        $this->orderIds[] = $orderId;

        $orders->addItems($orderId, [
            [
                'product_id' => $this->productId,
                'variant_id' => null,
                'variant_label' => self::LINE_ONE_VARIANT,
                'quantity' => 2,
                'unit_price' => 12.50,
                'product_name' => self::LINE_ONE,
                'product_name_en' => self::LINE_ONE,
            ],
            [
                'product_id' => $this->productId,
                'variant_id' => null,
                'variant_label' => null,
                'quantity' => 1,
                'unit_price' => 7.25,
                'product_name' => self::LINE_TWO,
                'product_name_en' => self::LINE_TWO,
            ],
        ]);

        $orders->updateStatusFromMollie($orderId, $status, $status);

        return $orderId;
    }

    /**
     * A paid order with its invoice issued the way OrderPaymentSync issues it.
     *
     * @return array{0: int, 1: array<string, mixed>} order id, invoice row
     */
    public function invoicedOrder(): array
    {
        $orderId = $this->paidOrder();
        $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);

        if ($invoice === null) {
            throw new \RuntimeException('precondition: the fixture order did not get an invoice');
        }

        return [$orderId, $invoice];
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function productId(): ?int
    {
        return $this->productId;
    }

    public function cleanUp(): void
    {
        $db = Database::connection();
        $storage = new InvoiceStorage();

        foreach ($this->orderIds as $orderId) {
            $paths = $db->prepare('SELECT pdf_path FROM invoices WHERE order_id = :id');
            $paths->execute(['id' => $orderId]);
            foreach ($paths->fetchAll(\PDO::FETCH_COLUMN) as $path) {
                if ($storage->exists((string) $path)) {
                    unlink($storage->path((string) $path));
                }
            }
            $db->prepare('DELETE FROM invoices WHERE order_id = :id')->execute(['id' => $orderId]);
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $orderId]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $orderId]);
        }

        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
        }
        if ($this->productId !== null) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $this->productId]);
        }

        foreach ($this->counters as $year => $lastNumber) {
            if ($lastNumber === null) {
                $db->prepare('DELETE FROM invoice_number_counters WHERE year = :year')->execute(['year' => $year]);
            } else {
                $db->prepare('UPDATE invoice_number_counters SET last_number = :n WHERE year = :year')
                    ->execute(['n' => $lastNumber, 'year' => $year]);
            }
        }

        $this->orderIds = [];
        $this->customerId = null;
        $this->productId = null;
        $this->counters = [];
    }

    private function rememberCounter(int $year): void
    {
        if (array_key_exists($year, $this->counters)) {
            return;
        }

        $stmt = Database::connection()->prepare('SELECT last_number FROM invoice_number_counters WHERE year = :year');
        $stmt->execute(['year' => $year]);
        $value = $stmt->fetchColumn();

        $this->counters[$year] = $value === false ? null : (int) $value;
    }
}
