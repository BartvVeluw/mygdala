<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\OrderRepository;
use App\Service\OrderConfirmationService;
use App\Service\OrderPaymentSync;
use Mollie\Api\Contracts\Authenticator;
use Mollie\Api\Contracts\Connector;
use Mollie\Api\Contracts\HttpAdapterContract;
use Mollie\Api\Contracts\IdempotencyKeyGeneratorContract;
use Mollie\Api\Contracts\Repository as MollieRepository;
use Mollie\Api\Http\Middleware;
use Mollie\Api\Http\Request;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Resources\RefundCollection;
use PHPUnit\Framework\TestCase;

/**
 * Covers App\Service\OrderPaymentSync — the single piece of logic shared by
 * the Mollie webhook (api/mollie-webhook.php) and the browser-return status
 * page (api/order-status.php), per MAIN.MD "Payment status and webhook
 * flow" / "Refunds":
 *
 *  - a valid Mollie payment updates exactly the order it belongs to
 *  - repeated delivery (webhook retries, or webhook + return page racing) is
 *    idempotent — same end state, and confirmation emails are never sent twice
 *  - a payment id with no matching local order touches nothing (this is also
 *    what protects against "wrong Mollie payment/order combination": the
 *    match is always by mollie_payment_id set at payment-creation time, never
 *    by anything supplied on the request)
 *  - a payment that only ever reports "open"/"pending" never becomes "paid"
 *    locally — this is what makes the browser-return page safe: it can only
 *    ever mark an order paid via this same Mollie-API-backed sync, never from
 *    anything the browser itself asserts
 *  - refunds reported by Mollie are mirrored locally without altering the
 *    order's original total
 *
 * OrderRepository/OrderConfirmationService are both real classes with a
 * database-backed default constructor, so — same approach as
 * ShippingCalculationServiceTest — they're replaced here with lightweight
 * in-memory subclasses that never call the parent constructor and never
 * touch a database. Mollie's Payment/Refund/RefundCollection are real SDK
 * value objects built via reflection (skipping their constructor, which
 * needs a live API connector) with only the public properties the code under
 * test actually reads.
 */
final class OrderPaymentSyncTest extends TestCase
{
    private function payment(string $id, string $status, ?string $paidAt = null): Payment
    {
        $payment = (new \ReflectionClass(Payment::class))->newInstanceWithoutConstructor();
        $payment->id = $id;
        $payment->status = $status;
        $payment->paidAt = $paidAt;
        $payment->_links = (object) [];

        return $payment;
    }

    private function paymentWithRefund(string $id, string $refundId, string $amount, string $refundStatus): Payment
    {
        $payment = $this->payment($id, 'paid', '2026-03-14T10:30:00+00:00');
        $payment->amountRefunded = (object) ['value' => $amount, 'currency' => 'EUR'];
        $payment->_links = (object) ['refunds' => (object) ['href' => 'https://api.mollie.com/v2/payments/' . $id . '/refunds']];

        $refund = (new \ReflectionClass(Refund::class))->newInstanceWithoutConstructor();
        $refund->id = $refundId;
        $refund->amount = (object) ['value' => $amount, 'currency' => 'EUR'];
        $refund->status = $refundStatus;
        $refund->description = 'Test refund';
        $refund->createdAt = '2026-03-15T09:00:00+00:00';
        $refund->paymentId = $id;

        $connector = new FakeMollieConnector([$refund]);
        $connectorProperty = new \ReflectionProperty(\Mollie\Api\Resources\BaseResource::class, 'connector');
        $connectorProperty->setAccessible(true);
        $connectorProperty->setValue($payment, $connector);

        return $payment;
    }

    private function fakeConfirmations(): OrderConfirmationService
    {
        return new class () extends OrderConfirmationService {
            public array $sentFor = [];

            public function __construct()
            {
            }

            public function sendForOrderIfNeeded(int $orderId): void
            {
                $this->sentFor[] = $orderId;
            }
        };
    }

    public function testValidWebhookUpdatesTheMatchingOrderToPaid(): void
    {
        $repo = new InMemoryOrderRepository([
            10 => ['id' => 10, 'status' => 'pending', 'mollie_status' => 'open', 'mollie_payment_id' => 'tr_abc'],
        ]);
        $confirmations = $this->fakeConfirmations();
        $sync = new OrderPaymentSync($repo, $confirmations);

        $result = $sync->sync($this->payment('tr_abc', 'paid', '2026-03-14T10:00:00+00:00'));

        $this->assertNotNull($result);
        $this->assertSame(10, $result['id']);
        $this->assertSame('paid', $repo->orders[10]['status']);
        $this->assertSame('paid', $repo->orders[10]['mollie_status']);
        $this->assertSame(1, $repo->statusUpdateCount);
        $this->assertSame([10], $confirmations->sentFor);
    }

    public function testDuplicateWebhookDeliveryIsIdempotent(): void
    {
        $repo = new InMemoryOrderRepository([
            10 => ['id' => 10, 'status' => 'pending', 'mollie_status' => 'open', 'mollie_payment_id' => 'tr_abc'],
        ]);
        $confirmations = $this->fakeConfirmations();
        $sync = new OrderPaymentSync($repo, $confirmations);

        $sync->sync($this->payment('tr_abc', 'paid', '2026-03-14T10:00:00+00:00'));
        $sync->sync($this->payment('tr_abc', 'paid', '2026-03-14T10:00:00+00:00'));

        // Only the first call actually changed anything; the second is a no-op write.
        $this->assertSame(1, $repo->statusUpdateCount);
        $this->assertSame('paid', $repo->orders[10]['status']);
        // sendForOrderIfNeeded is still safely called every time (it has its own
        // idempotency guard in production) — both calls are recorded here.
        $this->assertSame([10, 10], $confirmations->sentFor);
    }

    public function testUnknownPaymentIdTouchesNoOrder(): void
    {
        $repo = new InMemoryOrderRepository([
            10 => ['id' => 10, 'status' => 'pending', 'mollie_status' => 'open', 'mollie_payment_id' => 'tr_abc'],
        ]);
        $sync = new OrderPaymentSync($repo, $this->fakeConfirmations());

        $result = $sync->sync($this->payment('tr_completely_different', 'paid', '2026-03-14T10:00:00+00:00'));

        $this->assertNull($result);
        $this->assertSame(0, $repo->statusUpdateCount);
        $this->assertSame('pending', $repo->orders[10]['status']);
    }

    public function testOpenPaymentNeverMarksAnOrderPaid(): void
    {
        $repo = new InMemoryOrderRepository([
            10 => ['id' => 10, 'status' => 'pending', 'mollie_status' => 'open', 'mollie_payment_id' => 'tr_abc'],
        ]);
        $confirmations = $this->fakeConfirmations();
        $sync = new OrderPaymentSync($repo, $confirmations);

        // This is exactly what the browser-return page relies on: it always
        // re-asks Mollie for the payment and runs it through this same sync —
        // an "open"/"pending" Mollie payment can never result in "paid" locally,
        // no matter what the browser itself claims.
        $result = $sync->sync($this->payment('tr_abc', 'open'));

        $this->assertSame('pending', $result['status']);
        $this->assertSame([], $confirmations->sentFor);
    }

    public function testRefundedPaymentUpdatesRefundedAmountWithoutChangingOrderTotal(): void
    {
        $repo = new InMemoryOrderRepository([
            10 => ['id' => 10, 'status' => 'paid', 'mollie_status' => 'paid', 'mollie_payment_id' => 'tr_abc', 'total' => '52.40'],
        ]);
        $sync = new OrderPaymentSync($repo, $this->fakeConfirmations());

        $sync->sync($this->paymentWithRefund('tr_abc', 're_123', '10.00', 'refunded'));

        $this->assertSame('10.00', $repo->orders[10]['refunded_amount']);
        // The original sale amount must remain untouched by the refund.
        $this->assertSame('52.40', $repo->orders[10]['total']);
        $this->assertCount(1, $repo->refunds[10]);
        $this->assertSame('re_123', $repo->refunds[10][0]['mollie_refund_id']);
        $this->assertSame('refunded', $repo->refunds[10][0]['status']);
    }

    public function testDuplicateRefundWebhookIsIdempotent(): void
    {
        $repo = new InMemoryOrderRepository([
            10 => ['id' => 10, 'status' => 'paid', 'mollie_status' => 'paid', 'mollie_payment_id' => 'tr_abc', 'total' => '52.40'],
        ]);
        $sync = new OrderPaymentSync($repo, $this->fakeConfirmations());

        $sync->sync($this->paymentWithRefund('tr_abc', 're_123', '10.00', 'refunded'));
        $sync->sync($this->paymentWithRefund('tr_abc', 're_123', '10.00', 'refunded'));

        // Same Mollie refund id upserted twice must still be exactly one row.
        $this->assertCount(1, $repo->refunds[10]);
        $this->assertSame('10.00', $repo->orders[10]['refunded_amount']);
    }
}

/**
 * In-memory stand-in for OrderRepository — see the class docblock above for
 * why a real (database-backed) repository isn't used here.
 */
final class InMemoryOrderRepository extends OrderRepository
{
    public int $statusUpdateCount = 0;

    /** @var array<int, array<string, mixed>> */
    public array $refunds = [];

    /**
     * @param array<int, array<string, mixed>> $orders keyed by order id
     */
    public function __construct(public array $orders)
    {
    }

    public function findByMolliePaymentId(string $paymentId): ?array
    {
        foreach ($this->orders as $order) {
            if ($order['mollie_payment_id'] === $paymentId) {
                return $order;
            }
        }
        return null;
    }

    public function updateStatusFromMollie(int $orderId, string $localStatus, string $mollieStatus): void
    {
        $this->statusUpdateCount++;
        $this->orders[$orderId]['status'] = $localStatus;
        $this->orders[$orderId]['mollie_status'] = $mollieStatus;
    }

    public function setRefundedAmount(int $orderId, float $refundedAmount): void
    {
        $this->orders[$orderId]['refunded_amount'] = number_format($refundedAmount, 2, '.', '');
    }

    public function upsertRefund(
        int $orderId,
        string $mollieRefundId,
        float $amount,
        string $status,
        ?string $description,
        \DateTimeInterface $createdAt
    ): void {
        $this->refunds[$orderId] ??= [];
        foreach ($this->refunds[$orderId] as $i => $existing) {
            if ($existing['mollie_refund_id'] === $mollieRefundId) {
                $this->refunds[$orderId][$i] = ['mollie_refund_id' => $mollieRefundId, 'amount' => number_format($amount, 2, '.', ''), 'status' => $status];
                return;
            }
        }
        $this->refunds[$orderId][] = ['mollie_refund_id' => $mollieRefundId, 'amount' => number_format($amount, 2, '.', ''), 'status' => $status];
    }
}

/**
 * Minimal fake of Mollie's Connector so a real Payment::refunds() call
 * (which needs $this->connector->send(...)) returns a pre-built
 * RefundCollection instead of making a network call. Every other interface
 * method is unused by the code paths under test and just throws/no-ops.
 */
final class FakeMollieConnector implements Connector
{
    /** @param array<int, Refund> $refundsToReturn */
    public function __construct(private array $refundsToReturn)
    {
    }

    public function send(Request $request)
    {
        return new RefundCollection($this, $this->refundsToReturn);
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.mollie.com';
    }

    public function headers(): MollieRepository
    {
        throw new \RuntimeException('not implemented in test double');
    }

    public function query(): MollieRepository
    {
        throw new \RuntimeException('not implemented in test double');
    }

    public function middleware(): Middleware
    {
        throw new \RuntimeException('not implemented in test double');
    }

    public function addVersionString($versionString): self
    {
        return $this;
    }

    public function getVersionStrings(): array
    {
        return [];
    }

    public function getHttpClient(): HttpAdapterContract
    {
        throw new \RuntimeException('not implemented in test double');
    }

    public function setApiKey(string $apiKey): self
    {
        return $this;
    }

    public function setAccessToken(string $accessToken): self
    {
        return $this;
    }

    public function getAuthenticator(): ?Authenticator
    {
        return null;
    }

    public function getIdempotencyKey(): ?string
    {
        return null;
    }

    public function resetIdempotencyKey(): self
    {
        return $this;
    }

    public function getIdempotencyKeyGenerator(): ?IdempotencyKeyGeneratorContract
    {
        return null;
    }

    public function debugRequest(?callable $debugger = null, bool $die = false): self
    {
        return $this;
    }

    public function debugResponse(?callable $debugger = null, bool $die = false): self
    {
        return $this;
    }

    public function debug(bool $die = false): self
    {
        return $this;
    }

    public function getTestmode(): ?bool
    {
        return true;
    }
}
