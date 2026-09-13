<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\InvoiceService;
use App\Service\InvoiceStorage;
use App\Service\Mailer;
use App\Service\OrderConfirmationService;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests (real dev database, same convention as
 * tests/Repository/OrderSnapshotIntegrationTest.php) covering MAIN.MD
 * scenarios 14-16: the customer confirmation email attaches the PDF
 * invoice, the shop/internal notification email does not, automatic sending
 * stays idempotent, and an explicit admin resend sends again without
 * creating a second invoice. Mailer::send() is swapped for a capturing fake
 * (same technique as OrderPaymentSyncTest's fakeConfirmations()) so no real
 * SMTP/Mailpit connection is needed.
 */
final class OrderConfirmationInvoiceTest extends TestCase
{
    private const PRODUCT_SLUG = '__test_confirmation_invoice_product__';
    private const CUSTOMER_EMAIL = 'confirmation-invoice-test@__test__.invalid';
    private const SHOP_NOTIFICATION_EMAIL = 'confirmation-invoice-shop@__test__.invalid';

    private ?int $productId = null;
    private ?int $customerId = null;
    /** @var array<int, int> */
    private array $orderIds = [];

    /** What SHOP_NOTIFICATION_EMAIL held before this test; null when it was not set. */
    private ?string $previousNotificationEmail = null;

    /**
     * Without a shop address OrderConfirmationService sends nothing at all,
     * and every scenario here is about a shop that has one. Whether the
     * machine running the suite happens to have it configured is not this
     * test's business, so it configures its own.
     */
    protected function setUp(): void
    {
        $this->previousNotificationEmail = isset($_ENV['SHOP_NOTIFICATION_EMAIL'])
            ? (string) $_ENV['SHOP_NOTIFICATION_EMAIL']
            : null;
        $_ENV['SHOP_NOTIFICATION_EMAIL'] = self::SHOP_NOTIFICATION_EMAIL;
    }

    protected function tearDown(): void
    {
        if ($this->previousNotificationEmail === null) {
            unset($_ENV['SHOP_NOTIFICATION_EMAIL']);
        } else {
            $_ENV['SHOP_NOTIFICATION_EMAIL'] = $this->previousNotificationEmail;
        }

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
    }

    private function createPaidOrder(): int
    {
        $products = new ProductRepository();
        $orders = new OrderRepository();
        $customers = new CustomerRepository();

        $this->productId = $products->create([
            'name' => 'Confirmation Invoice Test Product',
            'name_en' => 'Confirmation Invoice Test Product',
            'slug' => self::PRODUCT_SLUG,
            'description' => null,
            'description_en' => null,
            'price' => 15.00,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);

        $this->customerId = $customers->findOrCreateByEmail([
            'name' => 'Confirmation Invoice Test',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $address = [
            'first_name' => 'Confirmation', 'last_name' => 'Test', 'company' => null,
            'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
            'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
        ];

        $orderId = $orders->create(
            $this->customerId,
            15.00,
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
            'unit_price' => 15.00,
            'product_name' => 'Confirmation Invoice Test Product',
            'product_name_en' => 'Confirmation Invoice Test Product',
        ]]);

        $orders->updateStatusFromMollie($orderId, 'paid', 'paid');

        return $orderId;
    }

    private function fakeMailer(): FakeInvoiceMailer
    {
        return new FakeInvoiceMailer();
    }

    public function testCustomerEmailAttachesTheInvoicePdfAndShopEmailDoesNot(): void
    {
        $orderId = $this->createPaidOrder();
        $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);
        $this->assertNotNull($invoice, 'precondition: invoice must exist');

        $mailer = $this->fakeMailer();
        $service = new OrderConfirmationService(null, null, $mailer);
        $service->sendForOrderIfNeeded($orderId);

        $this->assertCount(2, $mailer->calls, 'expected exactly one customer + one shop email');

        [$customerCall, $shopCall] = $mailer->calls;

        $this->assertNotEmpty($customerCall['attachments'], 'customer email must have the invoice PDF attached');
        $this->assertSame('application/pdf', $customerCall['attachments'][0]['mime']);
        $this->assertStringContainsString((string) $invoice['invoice_number'], $customerCall['attachments'][0]['name']);
        $this->assertFileExists($customerCall['attachments'][0]['path']);

        $this->assertSame(self::SHOP_NOTIFICATION_EMAIL, $shopCall['to']);
        $this->assertSame([], $shopCall['attachments'], 'shop/internal notification email must not get the invoice attached');
    }

    public function testSendingTwiceForTheSameOrderIsIdempotent(): void
    {
        $orderId = $this->createPaidOrder();
        (new InvoiceService())->issueForOrderIfNeeded($orderId);

        $mailer = $this->fakeMailer();
        $service = new OrderConfirmationService(null, null, $mailer);
        $service->sendForOrderIfNeeded($orderId);
        $service->sendForOrderIfNeeded($orderId);

        $this->assertCount(2, $mailer->calls, 'a second automatic sync must not send duplicate emails');
    }

    public function testConfirmationEmailIsDeferredWhenNoInvoiceExistsYet(): void
    {
        $orderId = $this->createPaidOrder();
        // Deliberately not issuing an invoice first.

        $mailer = $this->fakeMailer();
        $service = new OrderConfirmationService(null, null, $mailer);
        $service->sendForOrderIfNeeded($orderId);

        $this->assertCount(0, $mailer->calls, 'must not send the customer email without its invoice');

        $stmt = Database::connection()->prepare('SELECT confirmation_sent_at FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $this->assertNull($stmt->fetch()['confirmation_sent_at']);
    }

    public function testAdminResendSendsAgainWithoutCreatingASecondInvoice(): void
    {
        $orderId = $this->createPaidOrder();
        $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);

        $mailer = $this->fakeMailer();
        $service = new OrderConfirmationService(null, null, $mailer);
        $service->sendForOrderIfNeeded($orderId);
        $this->assertCount(2, $mailer->calls);

        $resent = $service->resend($orderId);

        $this->assertTrue($resent);
        $this->assertCount(4, $mailer->calls, 'resend must send two more emails (customer + shop)');

        $invoicesAfter = (new InvoiceRepository())->findByOrderId($orderId);
        $this->assertSame($invoice['id'], $invoicesAfter['id']);
        $this->assertSame($invoice['invoice_number'], $invoicesAfter['invoice_number']);

        $countStmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM invoices WHERE order_id = :id');
        $countStmt->execute(['id' => $orderId]);
        $this->assertSame(1, (int) $countStmt->fetch()['c']);
    }

    /**
     * A resent confirmation names the order exactly as the first one did,
     * even when the prefix setting changed in between: both read the number
     * stored on the order. The stored number cannot be rebuilt from the
     * order's id, its year or either prefix.
     */
    public function testAResentConfirmationCarriesTheStoredOrderNumberAfterThePrefixChanged(): void
    {
        $orderId = $this->createPaidOrder();
        $stored = 'HIST-1999-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
        Database::connection()
            ->prepare('UPDATE orders SET order_number = :order_number WHERE id = :id')
            ->execute(['order_number' => $stored, 'id' => $orderId]);
        (new InvoiceService())->issueForOrderIfNeeded($orderId);

        $mailer = $this->fakeMailer();
        $service = new OrderConfirmationService(null, null, $mailer);
        $service->sendForOrderIfNeeded($orderId);

        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);
        try {
            $this->assertTrue($service->resend($orderId));
        } finally {
            SiteSettings::overrideForTests(null);
        }

        $this->assertCount(4, $mailer->calls, 'the first customer + shop e-mail, and the resent pair');
        foreach ($mailer->calls as $index => $call) {
            $this->assertStringContainsString($stored, $call['subject'], 'e-mail ' . $index);
            $this->assertStringNotContainsString('SHOP-', $call['subject'], 'e-mail ' . $index);
        }
    }

    public function testResendFailsClearlyWhenThereIsNoInvoiceYet(): void
    {
        $orderId = $this->createPaidOrder();

        $service = new OrderConfirmationService(null, null, $this->fakeMailer());
        $resent = $service->resend($orderId);

        $this->assertFalse($resent);
    }
}

/**
 * Captures every Mailer::send() call instead of actually sending — same
 * "swap the concrete collaborator for a fake" technique as
 * OrderPaymentSyncTest's fakeConfirmations().
 */
final class FakeInvoiceMailer extends Mailer
{
    /** @var array<int, array{to:string, subject:string, attachments:array}> */
    public array $calls = [];

    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        array $attachments = []
    ): void {
        $this->calls[] = [
            'to' => $toEmail,
            'subject' => $subject,
            'attachments' => $attachments,
        ];
    }
}
