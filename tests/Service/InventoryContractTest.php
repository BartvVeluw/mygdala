<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Where stock is decided, read from the source (Shop Product & Ordering 2.0,
 * MODULES.md "Voorraad"). The behaviour itself is proven against the
 * database in Tests\Service\InventoryTest and over HTTP in
 * ProductInventoryHttpTest; this pins the WIRING a behavioural test cannot
 * reach without a real payment page or Turnstile:
 *
 *   - api/checkout.php takes the units inside its order transaction, before
 *     the order row exists, and a refusal rolls the whole order back;
 *   - both ways a payment start can fail hand the order to
 *     OrderPaymentStartFailure, so its units never stay taken;
 *   - OrderPaymentSync gives units back for failed, canceled and expired
 *     only — never for paid or pending;
 *   - no stock counter is changed outside App\Repository\InventoryRepository
 *     (no "read, then write" anywhere else);
 *   - the public product rows do not carry the stock figure.
 */
final class InventoryContractTest extends TestCase
{
    public function testTheCheckoutReservesInsideItsTransactionBeforeTheOrderExists(): void
    {
        $checkout = self::read('api/checkout.php');

        $begin = strpos($checkout, '$db->beginTransaction();');
        $reserve = strpos($checkout, '(new Inventory($db))->reserve(');
        $create = strpos($checkout, '$orderId = $orderRepository->create(');
        $commit = strpos($checkout, '$db->commit();');

        self::assertNotFalse($begin);
        self::assertNotFalse($reserve);
        self::assertNotFalse($create);
        self::assertTrue($begin < $reserve && $reserve < $create && $create < $commit, 'begin, reserve, create the order, commit');

        self::assertMatchesRegularExpression('/\} catch \(InsufficientStockException \$e\) \{\s*\/\/[^\n]*\n\s*\/\/[^\n]*\n\s*\$db->rollBack\(\);\s*fail\(409,/', $checkout, 'a refusal rolls back and answers 409');
        self::assertStringContainsString("\$orderItems[\$index] += \$reservation;", $checkout, 'what a line took is stored on it');
    }

    public function testTheCheckoutSaysStockProblemsBeforeItStoresAnything(): void
    {
        $checkout = self::read('api/checkout.php');

        $check = strpos($checkout, '(new CartAvailability())->check(');
        $begin = strpos($checkout, '$db->beginTransaction();');
        self::assertNotFalse($check);
        self::assertLessThan($begin, $check);
    }

    public function testEveryWayAPaymentStartCanFailGivesTheUnitsBack(): void
    {
        $checkout = self::read('api/checkout.php');
        $afterCommit = substr($checkout, (int) strpos($checkout, '$payment = $paymentProvider->createPayment('));

        self::assertSame(2, substr_count($afterCommit, 'OrderPaymentStartFailure::handle($orderId, $db);'), 'PaymentProviderException and every other failure');
    }

    public function testThePaymentSyncReleasesOnlyForAnEndWithoutMoney(): void
    {
        $sync = self::read('src/Service/OrderPaymentSync.php');

        self::assertStringContainsString(
            'if (in_array($localStatus, [PaymentSnapshot::FAILED, PaymentSnapshot::CANCELED, PaymentSnapshot::EXPIRED], true)) {',
            $sync
        );
        self::assertSame(1, substr_count($sync, 'releaseForOrder('));
    }

    public function testNoStockCounterIsWrittenOutsideTheInventoryRepository(): void
    {
        $offenders = [];
        $pattern = '/(UPDATE\s+products\s+SET[^;]*\bstock\s*=|UPDATE\s+product_variants[^;]*\bstock\s*=)/i';

        foreach (['src', 'api', 'admin'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen(self::root()) + 1));
                if ($path === 'src/Repository/InventoryRepository.php') {
                    continue;
                }
                if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = $path;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testThePublicProductRowsDoNotCarryTheStockFigure(): void
    {
        $repository = self::read('src/Repository/ProductRepository.php');

        foreach (['findAllActive', 'findActiveById'] as $method) {
            $start = strpos($repository, 'public function ' . $method . '(');
            $end = strpos($repository, 'public function ', (int) $start + 10);
            self::assertStringNotContainsString('stock', substr($repository, (int) $start, (int) $end - (int) $start), $method);
        }

        self::assertStringNotContainsString('stock', self::between(self::read('src/Repository/ProductVariantRepository.php'), 'public function findActiveByProductId(', 'public function findDefaultForProduct('));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::root() . '/' . $relative));
    }

    private static function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        $end = strpos($text, $to, (int) $start);

        return substr($text, (int) $start, (int) $end - (int) $start);
    }
}
