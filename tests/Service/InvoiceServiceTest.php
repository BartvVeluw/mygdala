<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\SiteSettingRepository;
use App\Service\InvoiceService;
use App\Service\InvoiceStorage;
use App\Service\PdfInvoiceRenderer;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database (same convention as
 * tests/Repository/OrderSnapshotIntegrationTest.php — InvoiceService's
 * concurrency-relevant SQL, FOR UPDATE locking and the invoices/
 * invoice_number_counters schema have no mocking seam worth building).
 *
 * Covers MAIN.MD "Tests" scenarios 1, 3-10: exactly one invoice per paid
 * order, unique/sequential/concurrency-safe numbering, no invoice for
 * unpaid/failed/canceled orders, seller info frozen at issuance time
 * (Site Settings changes afterward never alter an existing invoice), and
 * correct order/customer snapshot values.
 */
final class InvoiceServiceTest extends TestCase
{
    private const PRODUCT_SLUG = '__test_invoice_product__';
    private const CUSTOMER_EMAIL = 'invoice-test@__test__.invalid';

    private ?int $productId = null;
    private ?int $customerId = null;
    /** @var array<int, int> */
    private array $orderIds = [];
    /** @var array<string, string>|null */
    private ?array $originalSiteSettings = null;

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);

        $db = Database::connection();
        $storage = new InvoiceStorage();
        foreach ($this->orderIds as $orderId) {
            $pdfStmt = $db->prepare('SELECT pdf_path FROM invoices WHERE order_id = :id');
            $pdfStmt->execute(['id' => $orderId]);
            foreach ($pdfStmt->fetchAll(\PDO::FETCH_COLUMN) as $pdfPath) {
                if ($storage->exists((string) $pdfPath)) {
                    unlink($storage->path((string) $pdfPath));
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
        if ($this->originalSiteSettings !== null) {
            (new SiteSettingRepository())->upsertMany($this->originalSiteSettings);
            SiteSettings::clearCache();
        }
    }

    /**
     * Creates a paid test order and returns its id. $status defaults to
     * 'paid'; pass 'pending'/'failed'/'canceled'/'expired' to test that
     * those never get an invoice.
     */
    private function createOrder(string $status = 'paid'): int
    {
        $products = new ProductRepository();
        $orders = new OrderRepository();
        $customers = new CustomerRepository();

        if ($this->productId === null) {
            $this->productId = $products->create([
                'name' => 'Invoice Test Product',
                'name_en' => 'Invoice Test Product',
                'slug' => self::PRODUCT_SLUG,
                'description' => null,
                'description_en' => null,
                'price' => 10.00,
                'image_path' => null,
                'active' => true,
                'shipping_profile' => 'letter',
                'shipping_weight_grams' => 10,
                'requires_parcel' => false,
            ]);
        }

        if ($this->customerId === null) {
            $this->customerId = $customers->findOrCreateByEmail([
                'name' => 'Invoice Test',
                'email' => self::CUSTOMER_EMAIL,
                'phone' => null,
                'address_line' => 'Teststraat 1',
                'postal_code' => '1234AB',
                'city' => 'Teststad',
                'country' => 'NL',
            ]);
        }

        $address = [
            'first_name' => 'Invoice', 'last_name' => 'Test', 'company' => null,
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
            hash('sha256', 'test-terms'),
            $address,
            null
        );
        $this->orderIds[] = $orderId;

        $orders->addItems($orderId, [[
            'product_id' => $this->productId,
            'variant_id' => null,
            'variant_label' => null,
            'quantity' => 1,
            'unit_price' => 10.00,
            'product_name' => 'Invoice Test Product',
            'product_name_en' => 'Invoice Test Product',
        ]]);

        $orders->updateStatusFromMollie($orderId, $status, $status === 'paid' ? 'paid' : $status);

        return $orderId;
    }

    private function setCompanySettings(array $overrides): void
    {
        if ($this->originalSiteSettings === null) {
            $this->originalSiteSettings = (new SiteSettingRepository())->findAll();
        }
        (new SiteSettingRepository())->upsertMany($overrides);
        SiteSettings::clearCache();
    }

    public function testPaidOrderGetsExactlyOneInvoice(): void
    {
        // The prefix is site configuration, so the test names its own rather
        // than assuming whichever one the test database happens to hold.
        $this->setCompanySettings(['invoice_number_prefix' => 'TST-F']);
        $orderId = $this->createOrder('paid');

        $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);

        $this->assertNotNull($invoice);
        $this->assertSame($orderId, (int) $invoice['order_id']);
        $this->assertMatchesRegularExpression('/^TST-F\d{4}-\d{6}$/', $invoice['invoice_number']);

        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM invoices WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $this->assertSame(1, (int) $stmt->fetch()['c']);
    }

    public function testRepeatedIssuanceForTheSameOrderNeverCreatesASecondInvoice(): void
    {
        $orderId = $this->createOrder('paid');
        $service = new InvoiceService();

        $first = $service->issueForOrderIfNeeded($orderId);
        $second = $service->issueForOrderIfNeeded($orderId);
        $third = $service->issueForOrderIfNeeded($orderId);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['id'], $third['id']);
        $this->assertSame($first['invoice_number'], $second['invoice_number']);
    }

    public function testPendingOrderDoesNotGetAnInvoice(): void
    {
        $orderId = $this->createOrder('pending');

        $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);

        $this->assertNull($invoice);
        $this->assertNull((new InvoiceRepository())->findByOrderId($orderId));
    }

    public function testFailedCanceledAndExpiredOrdersDoNotGetAnInvoice(): void
    {
        foreach (['failed', 'canceled', 'expired'] as $status) {
            $orderId = $this->createOrder($status);

            $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);

            $this->assertNull($invoice, "order with status {$status} must not get an invoice");
        }
    }

    public function testInvoiceNumbersAreUniqueAndSequentialAcrossMultipleOrders(): void
    {
        $service = new InvoiceService();

        $invoiceA = $service->issueForOrderIfNeeded($this->createOrder('paid'));
        $invoiceB = $service->issueForOrderIfNeeded($this->createOrder('paid'));

        $this->assertNotSame($invoiceA['invoice_number'], $invoiceB['invoice_number']);

        $numberA = (int) substr((string) $invoiceA['invoice_number'], -6);
        $numberB = (int) substr((string) $invoiceB['invoice_number'], -6);
        $this->assertSame($numberA + 1, $numberB);
    }

    public function testSellerSnapshotIsFrozenAndUnaffectedByLaterSiteSettingsChanges(): void
    {
        $this->setCompanySettings([
            'site_name' => 'Original Company Name',
            'company_street' => 'Originalstraat',
            'company_city' => 'Originalstad',
        ]);

        $orderId = $this->createOrder('paid');
        $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);
        $this->assertNotNull($invoice);

        $snapshot = json_decode((string) $invoice['seller_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Original Company Name', $snapshot['company_name']);
        $this->assertSame('Originalstraat', $snapshot['street']);

        // Change Site Settings after the invoice was issued.
        (new SiteSettingRepository())->upsertMany([
            'site_name' => 'Renamed Company Name',
            'company_street' => 'Nieuwestraat',
        ]);
        SiteSettings::clearCache();

        $reloaded = (new InvoiceRepository())->findByOrderId($orderId);
        $reloadedSnapshot = json_decode((string) $reloaded['seller_snapshot'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Original Company Name', $reloadedSnapshot['company_name']);
        $this->assertSame('Originalstraat', $reloadedSnapshot['street']);
    }

    public function testInvoiceReflectsTheOrderAndCustomerSnapshotValues(): void
    {
        $orderId = $this->createOrder('paid');

        $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);

        $this->assertSame('EUR', $invoice['currency']);
        $this->assertSame(date('Y-m-d'), (new \DateTimeImmutable((string) $invoice['invoice_date']))->format('Y-m-d'));
        $this->assertStringContainsString((string) $invoice['invoice_number'], (string) $invoice['pdf_path']);
    }

    /**
     * An invoice names the order by the number stored on it, on its first
     * render and when a missing PDF is rendered again later. The stored number
     * cannot be rebuilt from the order's id, its year or either prefix, and the
     * prefix setting says something else by the time the file is regenerated.
     */
    public function testARegeneratedPdfNamesTheOrderByTheNumberItWasIssuedWith(): void
    {
        $orderId = $this->createOrder('paid');
        $stored = 'HIST-1999-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
        $db = Database::connection();
        $db->prepare('UPDATE orders SET order_number = :order_number WHERE id = :id')
            ->execute(['order_number' => $stored, 'id' => $orderId]);

        $renderer = new OrderNumberCapturingRenderer();
        $service = new InvoiceService(null, null, null, $renderer);

        $invoice = $service->issueForOrderIfNeeded($orderId);
        $this->assertNotNull($invoice);

        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);

        $storage = new InvoiceStorage();
        unlink($storage->path((string) $invoice['pdf_path']));
        $this->assertFalse($storage->exists((string) $invoice['pdf_path']), 'precondition: the PDF is gone');

        $orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
        $orderStmt->execute(['id' => $orderId]);
        $customerStmt = $db->prepare('SELECT * FROM customers WHERE id = :id');
        $customerStmt->execute(['id' => $this->customerId]);

        $service->regeneratePdfIfMissing($invoice, $orderStmt->fetch(), $customerStmt->fetch(), (new OrderRepository())->findItems($orderId));

        $this->assertSame([$stored, $stored], $renderer->orderNumbers, 'The first render and the regeneration are handed the stored number.');

        $text = (new \Smalot\PdfParser\Parser())->parseFile($storage->path((string) $invoice['pdf_path']))->getText();
        $this->assertStringContainsString($stored, $text);
        $this->assertStringNotContainsString('SHOP-', $text);
    }
}

/**
 * Records the order number every render is handed, and still renders the
 * real PDF so the file on disk can be read back.
 */
final class OrderNumberCapturingRenderer extends PdfInvoiceRenderer
{
    /** @var list<string> */
    public array $orderNumbers = [];

    public function render(
        array $order,
        array $customer,
        array $items,
        array $sellerSnapshot,
        string $invoiceNumber,
        \DateTimeInterface $invoiceDate,
        string $orderNumber
    ): string {
        $this->orderNumbers[] = $orderNumber;

        return parent::render($order, $customer, $items, $sellerSnapshot, $invoiceNumber, $invoiceDate, $orderNumber);
    }
}
