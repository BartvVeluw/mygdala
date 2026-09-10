<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers the admin's manual Open/Afgehandeld handling workflow — MAIN.MD
 * "Afhandelingsstatus". Integration tests against the real dev database
 * (same convention as tests/Repository/OrderSnapshotIntegrationTest.php):
 * markHandled()/markOpen() are deliberately guarded at the SQL level, so
 * testing them without the actual schema would test nothing.
 *
 * The two things that must always hold:
 *   1. handling status is the admin's own state — changing it must never
 *      touch the Mollie-driven payment status, the invoice or the refunds;
 *   2. an order that was never paid must never become "Afgehandeld".
 *
 * Every row created here uses an obviously-fake customer email
 * (@__test__.invalid) so it can never collide with real data; tearDown()
 * removes everything this test created.
 */
final class OrderHandlingStatusTest extends TestCase
{
    private const CUSTOMER_EMAIL = 'handling-test@__test__.invalid';

    /** @var list<int> */
    private array $orderIds = [];
    private ?int $customerId = null;

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->orderIds as $orderId) {
            $db->prepare('DELETE FROM invoices WHERE order_id = :id')->execute(['id' => $orderId]);
            $db->prepare('DELETE FROM order_refunds WHERE order_id = :id')->execute(['id' => $orderId]);
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $orderId]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $orderId]);
        }
        $this->orderIds = [];

        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
            $this->customerId = null;
        }
    }

    /**
     * Creates an order and puts it in $paymentStatus. The payment status is
     * set directly rather than through updateStatusFromMollie() so a test can
     * produce a "failed"/"expired" order without a Mollie payment existing.
     */
    private function createOrder(string $paymentStatus): int
    {
        $this->customerId ??= (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Handling Test',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $orderId = (new OrderRepository())->create(
            $this->customerId,
            19.95,
            0.00,
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            [
                'first_name' => 'Handling', 'last_name' => 'Test', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );

        Database::connection()
            ->prepare('UPDATE orders SET status = :status WHERE id = :id')
            ->execute(['status' => $paymentStatus, 'id' => $orderId]);

        $this->orderIds[] = $orderId;

        return $orderId;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $orderId): array
    {
        $order = (new OrderRepository())->findById($orderId);
        $this->assertNotNull($order);

        return $order;
    }

    public function testANewOrderStartsOutOpen(): void
    {
        $order = $this->row($this->createOrder('paid'));

        $this->assertSame(OrderRepository::FULFILMENT_OPEN, $order['fulfilment_status']);
        $this->assertNull($order['handled_at']);
    }

    public function testAPaidOpenOrderCanBeMarkedHandledAndRecordsTheTimestamp(): void
    {
        $orders = new OrderRepository();
        $orderId = $this->createOrder('paid');

        $this->assertTrue($orders->markHandled($orderId));

        $order = $this->row($orderId);
        $this->assertSame(OrderRepository::FULFILMENT_HANDLED, $order['fulfilment_status']);
        $this->assertNotNull($order['handled_at'], 'handled_at must be stamped when an order is marked handled');
        $this->assertNotFalse(strtotime((string) $order['handled_at']));
    }

    public function testAHandledOrderCanBeReopenedAndTheTimestampIsCleared(): void
    {
        $orders = new OrderRepository();
        $orderId = $this->createOrder('paid');
        $orders->markHandled($orderId);

        $this->assertTrue($orders->markOpen($orderId));

        $order = $this->row($orderId);
        $this->assertSame(OrderRepository::FULFILMENT_OPEN, $order['fulfilment_status']);
        $this->assertNull($order['handled_at'], 'reopening must clear handled_at');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unpayableStatuses(): array
    {
        return [['pending'], ['failed'], ['canceled'], ['expired']];
    }

    /**
     * @dataProvider unpayableStatuses
     */
    public function testAnOrderThatIsNotPaidCannotBeMarkedHandled(string $paymentStatus): void
    {
        $orders = new OrderRepository();
        $orderId = $this->createOrder($paymentStatus);

        $this->assertFalse($orders->markHandled($orderId), "a {$paymentStatus} order must not become handled");

        $order = $this->row($orderId);
        $this->assertSame(OrderRepository::FULFILMENT_OPEN, $order['fulfilment_status']);
        $this->assertNull($order['handled_at']);
        // ... and the attempt must not have altered the payment status either.
        $this->assertSame($paymentStatus, $order['status']);
    }

    public function testHandlingChangesNeverTouchThePaymentStatus(): void
    {
        $orders = new OrderRepository();
        $orderId = $this->createOrder('paid');
        $orders->updateStatusFromMollie($orderId, 'paid', 'paid');

        $orders->markHandled($orderId);
        $afterHandled = $this->row($orderId);
        $this->assertSame('paid', $afterHandled['status']);
        $this->assertSame('paid', $afterHandled['mollie_status']);

        $orders->markOpen($orderId);
        $afterReopen = $this->row($orderId);
        $this->assertSame('paid', $afterReopen['status']);
        $this->assertSame('paid', $afterReopen['mollie_status']);
    }

    public function testHandlingChangesNeverTouchInvoiceOrRefundData(): void
    {
        $orders = new OrderRepository();
        $invoices = new InvoiceRepository();
        $orderId = $this->createOrder('paid');

        $invoices->create(
            $orderId,
            'VLD-TEST-0001',
            new \DateTimeImmutable('2026-09-07'),
            'EUR',
            ['name' => 'Van Veluw Laserdesign'],
            'invoices/test.pdf'
        );
        $orders->upsertRefund($orderId, 're_test_handling', 5.00, 'refunded', 'Deelretour', new \DateTimeImmutable('2026-09-07 10:00:00'));
        $orders->setRefundedAmount($orderId, 5.00);

        $invoiceBefore = $invoices->findByOrderId($orderId);
        $refundsBefore = $orders->findRefunds($orderId);
        $refundedBefore = $this->row($orderId)['refunded_amount'];

        $orders->markHandled($orderId);
        $orders->markOpen($orderId);
        $orders->markHandled($orderId);

        $this->assertEquals($invoiceBefore, $invoices->findByOrderId($orderId));
        $this->assertEquals($refundsBefore, $orders->findRefunds($orderId));
        $this->assertSame($refundedBefore, $this->row($orderId)['refunded_amount']);
    }

    /**
     * A refund is recorded against the order without ever moving it back onto
     * the working list — the two concepts are independent (MAIN.MD).
     */
    public function testRecordingARefundDoesNotReopenAHandledOrder(): void
    {
        $orders = new OrderRepository();
        $orderId = $this->createOrder('paid');
        $orders->markHandled($orderId);

        $orders->upsertRefund($orderId, 're_test_no_reopen', 19.95, 'refunded', null, new \DateTimeImmutable());
        $orders->setRefundedAmount($orderId, 19.95);

        $order = $this->row($orderId);
        $this->assertSame(OrderRepository::FULFILMENT_HANDLED, $order['fulfilment_status']);
        $this->assertNotNull($order['handled_at']);
    }

    public function testTheOpenFilterReturnsOnlyOpenOrders(): void
    {
        $orders = new OrderRepository();
        $openId = $this->createOrder('paid');
        $handledId = $this->createOrder('paid');
        $orders->markHandled($handledId);

        $ids = $this->idsFrom($orders->findAllForAdmin(OrderRepository::FULFILMENT_OPEN));

        $this->assertContains($openId, $ids);
        $this->assertNotContains($handledId, $ids);
    }

    public function testTheHandledFilterReturnsOnlyHandledOrders(): void
    {
        $orders = new OrderRepository();
        $openId = $this->createOrder('paid');
        $handledId = $this->createOrder('paid');
        $orders->markHandled($handledId);

        $ids = $this->idsFrom($orders->findAllForAdmin(OrderRepository::FULFILMENT_HANDLED));

        $this->assertContains($handledId, $ids);
        $this->assertNotContains($openId, $ids);
    }

    public function testNoFilterReturnsBothOpenAndHandledOrders(): void
    {
        $orders = new OrderRepository();
        $openId = $this->createOrder('paid');
        $handledId = $this->createOrder('paid');
        $orders->markHandled($handledId);

        $ids = $this->idsFrom($orders->findAllForAdmin(null));

        $this->assertContains($openId, $ids);
        $this->assertContains($handledId, $ids);
    }

    /**
     * An unrecognised filter value must not silently return an empty list —
     * admin/orders.php already maps anything unknown to "all", and the
     * repository is deliberately forgiving in the same way. 'Verzonden' is
     * one of the pre-2026-09-08 four-state values, the realistic case of a
     * stale bookmark hitting this code.
     */
    public function testAnUnknownFilterValueIsIgnoredRatherThanReturningNothing(): void
    {
        $orders = new OrderRepository();
        $openId = $this->createOrder('paid');

        $ids = $this->idsFrom($orders->findAllForAdmin('Verzonden'));

        $this->assertContains($openId, $ids);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<int>
     */
    private function idsFrom(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
