<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Mail\OrderConfirmationBuilder;
use App\Repository\DashboardRepository;
use App\Repository\OrderRepository;
use App\Service\InvoiceService;
use App\Service\Mailer;
use App\Service\OrderConfirmationService;
use App\Service\OrderCsvExport;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvoiceOrderFixture;

/**
 * Test orders (`orders.payment_mode = 'test'`, MODULES.md "Betalingen") on
 * the test database, next to a live order and an order from before the mode
 * was recorded (NULL):
 *
 *  - the mode is stored with the payment id, and only 'test' or 'live';
 *  - a test order gets NO invoice: the counter of the real, gapless
 *    sequence is not touched, no row, no PDF; live and NULL orders are
 *    invoiced as before;
 *  - its confirmation still goes out, without an attachment, "[TEST]" in
 *    both subjects and a notice in both mails;
 *  - revenue, order count and average (DashboardRepository::orderTotalsBetween())
 *    leave test orders out and keep live and NULL ones;
 *  - the CSV export names the mode;
 *  - a final payment status never falls back (updateStatusFromMollie()).
 *
 * Invoices go to a storage directory of this test's own; the fixture removes
 * its orders, invoices and files and puts the invoice counter back.
 */
final class TestOrderTest extends TestCase
{
    private const SHOP_EMAIL = 'test-order-shop@example.invalid';

    private InvoiceOrderFixture $fixture;
    private string $storage;
    private ?string $storageBefore = null;
    private ?string $shopEmailBefore = null;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/mygdala-test-order-' . bin2hex(random_bytes(6));
        $this->storageBefore = $_ENV['INVOICE_STORAGE_PATH'] ?? null;
        $_ENV['INVOICE_STORAGE_PATH'] = $this->storage;
        $this->shopEmailBefore = $_ENV['SHOP_NOTIFICATION_EMAIL'] ?? null;
        $_ENV['SHOP_NOTIFICATION_EMAIL'] = self::SHOP_EMAIL;

        $this->fixture = new InvoiceOrderFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();

        foreach (['INVOICE_STORAGE_PATH' => $this->storageBefore, 'SHOP_NOTIFICATION_EMAIL' => $this->shopEmailBefore] as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        $this->remove($this->storage);
    }

    /** A paid order whose payment was made in $mode (null: before the mode was recorded). */
    private function paidOrder(?string $mode): int
    {
        $orderId = $this->fixture->order('paid');
        (new OrderRepository())->setMolliePaymentId($orderId, 'tr_testorder' . $orderId, $mode);

        return $orderId;
    }

    private function counter(): ?int
    {
        $stmt = Database::connection()->prepare('SELECT last_number FROM invoice_number_counters WHERE year = :year');
        $stmt->execute(['year' => (int) date('Y')]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function invoiceCount(int $orderId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM invoices WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);

        return (int) $stmt->fetchColumn();
    }

    public function testThePaymentModeIsStoredWithThePaymentAndOnlyTestOrLive(): void
    {
        $orders = new OrderRepository();

        foreach (['test' => 'test', 'live' => 'live', 'production' => null, '' => null] as $given => $stored) {
            $orderId = $this->fixture->order('pending');
            $orders->setMolliePaymentId($orderId, 'tr_mode' . $orderId, $given === '' ? null : $given);

            $order = $orders->findById($orderId);
            $this->assertSame('tr_mode' . $orderId, $order['mollie_payment_id']);
            $this->assertSame($stored, $order['payment_mode'], var_export($given, true));
            $this->assertSame($stored === 'test', OrderRepository::isTestOrder($order));
        }
    }

    public function testATestOrderNeverConsumesARealInvoiceNumber(): void
    {
        $testOrder = $this->paidOrder('test');
        $counterBefore = $this->counter();

        $this->assertNull((new InvoiceService())->issueForOrderIfNeeded($testOrder));

        $this->assertSame($counterBefore, $this->counter(), 'the real counter is not touched');
        $this->assertSame(0, $this->invoiceCount($testOrder), 'no invoice row');
        $this->assertSame([], glob($this->storage . '/invoices/*/*') ?: [], 'no PDF');
    }

    public function testLiveAndLegacyOrdersAreInvoicedAsBefore(): void
    {
        foreach (['live', null] as $mode) {
            $orderId = $this->paidOrder($mode);
            $counterBefore = (int) $this->counter();

            $invoice = (new InvoiceService())->issueForOrderIfNeeded($orderId);

            $this->assertNotNull($invoice, var_export($mode, true));
            $this->assertSame($counterBefore + 1, (int) $this->counter());
            $this->assertSame(1, $this->invoiceCount($orderId));
            $this->assertFileExists($this->storage . '/invoices/' . $invoice['pdf_path']);
        }
    }

    public function testATestOrderIsConfirmedWithoutAnInvoiceAndSaysItIsATest(): void
    {
        $orderId = $this->paidOrder('test');
        (new InvoiceService())->issueForOrderIfNeeded($orderId);

        $mailer = new TestOrderMailer();
        $this->assertTrue((new OrderConfirmationService(null, null, $mailer))->resend($orderId));

        $this->assertCount(2, $mailer->calls);
        foreach ($mailer->calls as $call) {
            $this->assertStringStartsWith('[TEST] ', $call['subject']);
            $this->assertSame([], $call['attachments'], 'no invoice attached');
            $this->assertStringContainsString('Dit is een testbestelling', $call['text']);
            $this->assertStringContainsString('Dit is een testbestelling', $call['html']);
        }
        $this->assertSame(0, $this->invoiceCount($orderId), 'and still no invoice');
        $this->assertNotNull((new OrderRepository())->findById($orderId)['confirmation_sent_at']);
    }

    public function testALiveOrdersConfirmationIsUnchanged(): void
    {
        $orderId = $this->paidOrder('live');
        (new InvoiceService())->issueForOrderIfNeeded($orderId);

        $mailer = new TestOrderMailer();
        (new OrderConfirmationService(null, null, $mailer))->sendForOrderIfNeeded($orderId);

        $this->assertCount(2, $mailer->calls);
        $this->assertStringNotContainsString('[TEST]', $mailer->calls[0]['subject']);
        $this->assertStringNotContainsString('testbestelling', $mailer->calls[0]['text']);
        $this->assertCount(1, $mailer->calls[0]['attachments'], 'the invoice is attached');
    }

    public function testTheBuilderMarksOnlyATestOrder(): void
    {
        $order = ['id' => 1, 'order_number' => 'ORD-2026-000001', 'created_at' => '2026-09-27 10:00:00', 'total' => '10.00', 'shipping_cost' => '0.00', 'shipping_method' => 'afhalen', 'billing_same_as_shipping' => 1];
        $customer = ['name' => 'Klant', 'email' => 'klant@example.invalid'];

        $test = OrderConfirmationBuilder::build($order + ['payment_mode' => 'test'], $customer, []);
        $live = OrderConfirmationBuilder::build($order + ['payment_mode' => 'live'], $customer, []);
        $legacy = OrderConfirmationBuilder::build($order, $customer, []);

        $this->assertStringStartsWith('[TEST] ', $test['customer']['subject']);
        $this->assertStringStartsWith('[TEST] Nieuwe betaalde bestelling', $test['shop']['subject']);
        $this->assertSame($live, $legacy, 'a live order reads exactly like one from before the mode was recorded');
        $this->assertStringNotContainsString('[TEST]', $live['customer']['subject']);
    }

    public function testRevenueLeavesTestOrdersOutAndKeepsLiveAndLegacyOnes(): void
    {
        $dashboard = new DashboardRepository();
        $from = date('Y-m-d 00:00:00');
        $until = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $before = $dashboard->orderTotalsBetween(['paid'], $from, $until);

        $this->paidOrder('test');
        $afterTest = $dashboard->orderTotalsBetween(['paid'], $from, $until);
        $this->assertSame($before, $afterTest, 'a paid test order adds no count and no money');

        $this->paidOrder('live');
        $this->paidOrder(null);
        $after = $dashboard->orderTotalsBetween(['paid'], $from, $until);

        $this->assertSame($before['order_count'] + 2, $after['order_count']);
        $this->assertEqualsWithDelta((float) $before['gross_total'] + 2 * 39.20, (float) $after['gross_total'], 0.001);
    }

    public function testTheExportNamesTheMode(): void
    {
        $this->assertSame('Betaalmodus', OrderCsvExport::header()[count(OrderCsvExport::header()) - 1]);

        $row = ['order_number' => 'ORD-2026-000001', 'created_at' => '2026-09-27 10:00:00', 'customer_name' => 'K', 'customer_email' => 'k@example.invalid', 'total' => '10.00', 'shipping_cost' => '0.00', 'currency' => 'EUR', 'status' => 'paid', 'fulfilment_status' => 'open', 'mollie_payment_id' => 'tr_x'];
        $this->assertSame('test', OrderCsvExport::row($row + ['payment_mode' => 'test'])[14]);
        $this->assertSame('', OrderCsvExport::row($row)[14]);
    }

    public function testAFinalPaymentStatusNeverFallsBack(): void
    {
        $orders = new OrderRepository();

        $paid = $this->fixture->order('paid');
        $this->assertFalse($orders->updateStatusFromMollie($paid, 'pending', 'open'), 'a slower sync cannot write pending over paid');
        $this->assertSame(['paid', 'paid'], [$orders->findById($paid)['status'], $orders->findById($paid)['mollie_status']]);
        $this->assertTrue($orders->updateStatusFromMollie($paid, 'paid', 'paid'), 'the same final status is accepted again');

        foreach (['failed', 'canceled', 'expired'] as $final) {
            $orderId = $this->fixture->order($final);
            $this->assertFalse($orders->updateStatusFromMollie($orderId, 'pending', 'open'), $final);
            $this->assertSame($final, $orders->findById($orderId)['status']);
        }

        $pending = $this->fixture->order('pending');
        $this->assertTrue($orders->updateStatusFromMollie($pending, 'paid', 'paid'), 'pending still moves');
        $this->assertSame('paid', $orders->findById($pending)['status']);
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

/** Records the mails OrderConfirmationService would send (the shape of FakeInvoiceMailer, with the bodies). */
final class TestOrderMailer extends Mailer
{
    /** @var list<array{to: string, subject: string, html: string, text: string, attachments: array}> */
    public array $calls = [];

    public function __construct()
    {
    }

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
        $this->calls[] = ['to' => $toEmail, 'subject' => $subject, 'html' => $html, 'text' => $text, 'attachments' => $attachments];
    }
}
