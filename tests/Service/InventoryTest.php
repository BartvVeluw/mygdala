<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\InventoryRepository;
use App\Repository\OrderRepository;
use App\Service\CartAvailability;
use App\Service\Inventory\InsufficientStockException;
use App\Service\Inventory\Inventory;
use App\Service\Inventory\ProductStock;
use App\Service\Inventory\StockUnit;
use App\Service\InvoiceService;
use App\Service\OrderConfirmationService;
use App\Service\OrderPaymentStartFailure;
use App\Service\OrderPaymentSync;
use App\Service\Payment\PaymentSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\Support\ShopStockFixture;

/**
 * Stock per sellable unit (Shop Product & Ordering 2.0, MODULES.md
 * "Voorraad"), against the real database:
 *
 *   - tracking off is unlimited, and nothing is taken or recorded;
 *   - a product without variants takes from its own stock, a product with
 *     variants from the chosen variant's, and the two never compete;
 *   - stock 0, too few left, two lines of one unit together, and a tracked
 *     variant product without a variant are all refused, with nothing taken;
 *   - of two checkouts for the last unit exactly one gets it: a stale read,
 *     and two real processes at the same moment;
 *   - failed, canceled and expired give the reserved units back exactly once,
 *     however often the sync runs; paid and pending give nothing back;
 *   - a payment that could not be started marks the order failed and gives
 *     its units back at once;
 *   - CartAvailability answers per line, adding up lines of one unit.
 */
final class InventoryTest extends TestCase
{
    private ShopStockFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ShopStockFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
    }

    public function testAnUntrackedProductIsUnlimitedAndReservesNothing(): void
    {
        $product = $this->fixture->product('Onbeperkt');
        $unit = (new Inventory())->forProduct($product)->unitFor(null);

        self::assertNotNull($unit);
        self::assertFalse($unit->tracked);
        self::assertNull($unit->available(), 'unlimited');
        self::assertTrue($unit->allows(1000));

        $reserved = $this->inTransaction(fn (Inventory $inventory): array => $inventory->reserve([
            ['product_id' => $product, 'variant_id' => null, 'quantity' => 50],
        ]));

        self::assertSame([['stock_reserved' => 0, 'stock_source' => null]], $reserved);
        self::assertSame(0, $this->fixture->productStock($product), 'the unused column is not touched');
    }

    public function testAProductWithoutVariantsTakesFromItsOwnStock(): void
    {
        $product = $this->fixture->product('Eigen voorraad', 5);

        $reserved = $this->inTransaction(fn (Inventory $inventory): array => $inventory->reserve([
            ['product_id' => $product, 'variant_id' => null, 'quantity' => 2],
        ]));

        self::assertSame([['stock_reserved' => 2, 'stock_source' => StockUnit::SOURCE_PRODUCT]], $reserved);
        self::assertSame(3, $this->fixture->productStock($product));
    }

    public function testAVariantProductTakesFromTheChosenVariantOnly(): void
    {
        $made = $this->fixture->variantProduct('Varianten', ['A' => 0, 'B' => 3]);
        (new InventoryRepository())->setProductStock($made['product'], 9, null);

        $stock = (new Inventory())->forProduct($made['product']);
        self::assertTrue($stock->hasVariants());
        self::assertNull($stock->unitFor(null), 'no unit without a variant: the product stock does not compete');
        self::assertTrue($stock->unitFor($made['variants']['A'])->isSoldOut());
        self::assertFalse($stock->unitFor($made['variants']['B'])->isSoldOut());

        $reserved = $this->inTransaction(fn (Inventory $inventory): array => $inventory->reserve([
            ['product_id' => $made['product'], 'variant_id' => $made['variants']['B'], 'quantity' => 2],
        ]));

        self::assertSame([['stock_reserved' => 2, 'stock_source' => StockUnit::SOURCE_VARIANT]], $reserved);
        self::assertSame(1, $this->fixture->variantStock($made['variants']['B']));
        self::assertSame(0, $this->fixture->variantStock($made['variants']['A']));
        self::assertSame(9, $this->fixture->productStock($made['product']), 'the product stock is ignored, never taken');
    }

    public function testSoldOutTooFewAndTwoLinesOfOneUnitAreRefusedWithNothingTaken(): void
    {
        $empty = $this->fixture->product('Leeg', 0);
        $two = $this->fixture->product('Twee', 2);

        $this->assertRefused([['product_id' => $empty, 'variant_id' => null, 'quantity' => 1]], 0, 0);
        $this->assertRefused([['product_id' => $two, 'variant_id' => null, 'quantity' => 3]], 0, 2);
        $this->assertRefused([
            ['product_id' => $two, 'variant_id' => null, 'quantity' => 1],
            ['product_id' => $two, 'variant_id' => null, 'quantity' => 2],
        ], 0, 2);

        self::assertSame(0, $this->fixture->productStock($empty));
        self::assertSame(2, $this->fixture->productStock($two), 'a refused reservation takes nothing');
    }

    public function testATrackedVariantProductNeedsAVariant(): void
    {
        $made = $this->fixture->variantProduct('Kies eerst', ['A' => 5]);

        $this->assertRefused([['product_id' => $made['product'], 'variant_id' => null, 'quantity' => 1]], 0, 0);
        self::assertSame(5, $this->fixture->variantStock($made['variants']['A']));
    }

    public function testOfTwoCheckoutsThatSawTheLastUnitOnlyOneGetsIt(): void
    {
        $product = $this->fixture->product('Laatste', 1);

        // Both "saw" one left.
        $sawFirst = (new Inventory())->forProduct($product)->unitFor(null);
        $sawSecond = (new Inventory())->forProduct($product)->unitFor(null);
        self::assertTrue($sawFirst->allows(1));
        self::assertTrue($sawSecond->allows(1));

        $first = $this->inTransaction(fn (Inventory $inventory): array => $inventory->reserve([
            ['product_id' => $product, 'variant_id' => null, 'quantity' => 1],
        ]));
        self::assertSame(1, $first[0]['stock_reserved']);

        $this->assertRefused([['product_id' => $product, 'variant_id' => null, 'quantity' => 1]], 0, 0);
        self::assertSame(0, $this->fixture->productStock($product), 'never below zero');
    }

    public function testTwoProcessesRacingForTheLastUnitNeverOversell(): void
    {
        $product = $this->fixture->product('Race', 1);
        $database = (string) Database::connection()->query('SELECT DATABASE()')->fetchColumn();
        $startAt = sprintf('%.6F', microtime(true) + 1.5);
        $script = dirname(__DIR__) . '/Support/stock-race.php';

        $environment = getenv();
        $environment['DB_DATABASE'] = $database;

        $processes = [];
        foreach ([1, 2] as $contender) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, $script, (string) $product, '1', $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $answers = [];
        foreach ($processes as [$process, $pipes]) {
            $answers[] = trim((string) stream_get_contents($pipes[1]));
            $errors = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            self::assertSame('', $errors, 'a contender must not crash');
        }

        sort($answers);
        self::assertSame(['ok', 'refused'], $answers, 'exactly one of two simultaneous checkouts gets the last unit');
        self::assertSame(0, $this->fixture->productStock($product));
    }

    public function testFailedCanceledAndExpiredGiveTheUnitsBackExactlyOnce(): void
    {
        foreach (['failed', 'canceled', 'expired'] as $status) {
            $product = $this->fixture->product('Terug ' . $status, 0);
            $order = $this->fixture->order([
                ['product_id' => $product, 'quantity' => 2, 'stock_reserved' => 2, 'stock_source' => StockUnit::SOURCE_PRODUCT],
            ], 'tr_zzstock' . $status . bin2hex(random_bytes(3)), $status);

            $cameBack = (new Inventory())->releaseForOrder($order);
            self::assertSame(2, $this->fixture->productStock($product), $status . ': given back');
            self::assertCount(1, $cameBack, $status . ': the unit was sold out and is orderable again');
            self::assertSame($product, $cameBack[0]->productId);
            self::assertNotNull($this->fixture->orderRow($order)['stock_released_at']);

            self::assertSame([], (new Inventory())->releaseForOrder($order), $status . ': a second release does nothing');
            self::assertSame(2, $this->fixture->productStock($product), $status . ': exactly once');
        }
    }

    public function testPaidAndPendingOrdersGiveNothingBack(): void
    {
        $product = $this->fixture->product('Verkocht', 3);
        foreach (['paid', 'pending'] as $status) {
            $order = $this->fixture->order([
                ['product_id' => $product, 'quantity' => 1, 'stock_reserved' => 1, 'stock_source' => StockUnit::SOURCE_PRODUCT],
            ], 'tr_zzstock' . $status . bin2hex(random_bytes(3)), $status);

            self::assertSame([], (new Inventory())->releaseForOrder($order));
            self::assertNull($this->fixture->orderRow($order)['stock_released_at'], $status . ': no marker');
        }
        self::assertSame(3, $this->fixture->productStock($product));
    }

    public function testAnOrderThatReservedNothingNeverGetsAMarker(): void
    {
        $product = $this->fixture->product('Onbeperkt verkocht');
        $order = $this->fixture->order([['product_id' => $product, 'quantity' => 1]], 'tr_zzstocknone' . bin2hex(random_bytes(3)), 'canceled');

        self::assertSame([], (new Inventory())->releaseForOrder($order));
        self::assertNull($this->fixture->orderRow($order)['stock_released_at'], 'an order from before stock tracking is never written to');
    }

    public function testAVariantReservationGoesBackToThatVariantAndADeletedOneIsSkipped(): void
    {
        $made = $this->fixture->variantProduct('Terug variant', ['A' => 1, 'B' => 0]);
        $order = $this->fixture->order([
            ['product_id' => $made['product'], 'variant_id' => $made['variants']['A'], 'quantity' => 2, 'stock_reserved' => 2, 'stock_source' => StockUnit::SOURCE_VARIANT],
            ['product_id' => $made['product'], 'variant_id' => $made['variants']['B'], 'quantity' => 1, 'stock_reserved' => 1, 'stock_source' => StockUnit::SOURCE_VARIANT],
        ], 'tr_zzstockvar' . bin2hex(random_bytes(3)), 'expired');

        // The variant B is deleted after the order: its line keeps a NULL id.
        Database::connection()->prepare('DELETE FROM product_variants WHERE id = :id')->execute(['id' => $made['variants']['B']]);

        $cameBack = (new Inventory())->releaseForOrder($order);

        self::assertSame(3, $this->fixture->variantStock($made['variants']['A']));
        self::assertSame([], array_map(static fn (StockUnit $unit): ?int => $unit->variantId, $cameBack), 'A was not sold out, B is gone');
    }

    public function testTheSyncGivesBackOnceHoweverOftenMollieReportsTheEnd(): void
    {
        $product = $this->fixture->product('Webhook', 0);
        $paymentId = 'tr_zzstocksync' . bin2hex(random_bytes(3));
        $order = $this->fixture->order([
            ['product_id' => $product, 'quantity' => 1, 'stock_reserved' => 1, 'stock_source' => StockUnit::SOURCE_PRODUCT],
        ], $paymentId);

        $sync = new OrderPaymentSync(new OrderRepository(), $this->noConfirmations(), $this->noInvoices(), new Inventory());
        $snapshot = new PaymentSnapshot($paymentId, PaymentSnapshot::CANCELED, 'canceled', false, 0.0, static fn (): array => []);

        $sync->sync($snapshot);
        $sync->sync($snapshot);
        $sync->sync($snapshot);

        self::assertSame('canceled', $this->fixture->orderRow($order)['status']);
        self::assertSame(1, $this->fixture->productStock($product), 'three deliveries, one release');
    }

    public function testAPaidSyncKeepsTheReservation(): void
    {
        $product = $this->fixture->product('Betaald', 0);
        $paymentId = 'tr_zzstockpaid' . bin2hex(random_bytes(3));
        $this->fixture->order([
            ['product_id' => $product, 'quantity' => 1, 'stock_reserved' => 1, 'stock_source' => StockUnit::SOURCE_PRODUCT],
        ], $paymentId);

        $sync = new OrderPaymentSync(new OrderRepository(), $this->noConfirmations(), $this->noInvoices(), new Inventory());
        $sync->sync(new PaymentSnapshot($paymentId, PaymentSnapshot::PAID, 'paid', false, 0.0, static fn (): array => []));
        // A late "expired" can never undo a paid order, so it gives nothing back either.
        $sync->sync(new PaymentSnapshot($paymentId, PaymentSnapshot::EXPIRED, 'expired', false, 0.0, static fn (): array => []));

        self::assertSame(0, $this->fixture->productStock($product));
    }

    public function testAPaymentThatCouldNotStartMarksTheOrderFailedAndGivesItsUnitsBack(): void
    {
        $product = $this->fixture->product('Mollie weigert', 0);
        $order = $this->fixture->order([
            ['product_id' => $product, 'quantity' => 1, 'stock_reserved' => 1, 'stock_source' => StockUnit::SOURCE_PRODUCT],
        ]);

        $cameBack = OrderPaymentStartFailure::handle($order);

        self::assertSame('failed', $this->fixture->orderRow($order)['status']);
        self::assertSame(1, $this->fixture->productStock($product), 'the unit is not lost');
        self::assertCount(1, $cameBack);

        OrderPaymentStartFailure::handle($order);
        self::assertSame(1, $this->fixture->productStock($product), 'exactly once');
    }

    public function testAnOrderWithAPaymentIsNotMarkedFailedByTheStartFailurePath(): void
    {
        $product = $this->fixture->product('Heeft betaling', 0);
        $order = $this->fixture->order([
            ['product_id' => $product, 'quantity' => 1, 'stock_reserved' => 1, 'stock_source' => StockUnit::SOURCE_PRODUCT],
        ], 'tr_zzstockhas' . bin2hex(random_bytes(3)));

        OrderPaymentStartFailure::handle($order);

        self::assertSame('pending', $this->fixture->orderRow($order)['status'], 'Mollie settles it');
        self::assertSame(0, $this->fixture->productStock($product));
    }

    public function testCartAvailabilityAnswersPerLineAndAddsUpOneUnit(): void
    {
        $unlimited = $this->fixture->product('Altijd');
        $three = $this->fixture->product('Drie', 3);
        $empty = $this->fixture->product('Nul', 0);
        $made = $this->fixture->variantProduct('Kleur', ['A' => 0, 'B' => 2]);

        $result = (new CartAvailability())->check([
            ['id' => $unlimited, 'variant_id' => null, 'qty' => 40],
            ['id' => $three, 'variant_id' => null, 'qty' => 2],
            ['id' => $three, 'variant_id' => null, 'qty' => 2],
            ['id' => $empty, 'variant_id' => null, 'qty' => 1],
            ['id' => $made['product'], 'variant_id' => $made['variants']['A'], 'qty' => 1],
            ['id' => $made['product'], 'variant_id' => $made['variants']['B'], 'qty' => 2],
            ['id' => $made['product'], 'variant_id' => null, 'qty' => 1],
            ['id' => 999999999, 'variant_id' => null, 'qty' => 1],
        ]);

        self::assertSame([
            ['status' => CartAvailability::OK, 'available' => null],
            ['status' => CartAvailability::INSUFFICIENT, 'available' => 3],
            ['status' => CartAvailability::INSUFFICIENT, 'available' => 3],
            ['status' => CartAvailability::SOLD_OUT, 'available' => 0],
            ['status' => CartAvailability::SOLD_OUT, 'available' => 0],
            ['status' => CartAvailability::OK, 'available' => 2],
            ['status' => CartAvailability::UNAVAILABLE, 'available' => null],
            ['status' => CartAvailability::UNAVAILABLE, 'available' => null],
        ], $result);
    }

    public function testCameBackComparesUnitsBeforeAndAfter(): void
    {
        $before = [7 => new ProductStock(7, true, 0, [])];
        self::assertCount(1, Inventory::cameBack($before, [7 => new ProductStock(7, true, 2, [])]), '0 to 2');
        self::assertCount(1, Inventory::cameBack($before, [7 => new ProductStock(7, false, 0, [])]), 'tracking switched off: orderable again');
        self::assertCount(0, Inventory::cameBack([7 => new ProductStock(7, true, 1, [])], [7 => new ProductStock(7, true, 2, [])]), '1 to 2 is not back');
        self::assertCount(0, Inventory::cameBack($before, [7 => new ProductStock(7, true, 0, [])]));

        $variants = [8 => new ProductStock(8, true, 0, [81 => ['stock' => 0, 'active' => true], 82 => ['stock' => 0, 'active' => true]])];
        $after = [8 => new ProductStock(8, true, 0, [81 => ['stock' => 4, 'active' => true], 82 => ['stock' => 0, 'active' => true]])];
        $back = Inventory::cameBack($variants, $after);
        self::assertCount(1, $back);
        self::assertSame(81, $back[0]->variantId, 'only the variant that came back');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param callable(Inventory): array $work
     * @return array<int, mixed>
     */
    private function inTransaction(callable $work): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $result = $work(new Inventory($db));
            $db->commit();

            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** @param list<array{product_id: int, variant_id: ?int, quantity: int}> $lines */
    private function assertRefused(array $lines, int $lineIndex, int $available): void
    {
        try {
            $this->inTransaction(fn (Inventory $inventory): array => $inventory->reserve($lines));
            self::fail('the reservation should have been refused');
        } catch (InsufficientStockException $e) {
            self::assertSame($lineIndex, $e->lineIndex);
            self::assertSame($available, $e->available);
        }
    }

    private function noConfirmations(): OrderConfirmationService
    {
        return new class () extends OrderConfirmationService {
            public function __construct()
            {
            }

            public function sendForOrderIfNeeded(int $orderId): void
            {
            }
        };
    }

    private function noInvoices(): InvoiceService
    {
        return new class () extends InvoiceService {
            public function __construct()
            {
            }

            public function issueForOrderIfNeeded(int $orderId): ?array
            {
                return null;
            }
        };
    }
}
