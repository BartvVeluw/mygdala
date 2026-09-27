<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Repository\OrderRepository;
use App\Service\Inventory\Inventory;
use App\Service\Inventory\StockUnit;
use PDO;

/**
 * What happens to an order when its payment could not be started
 * (api/checkout.php, after the order transaction committed): the provider
 * refused, timed out, or the payment id could not be stored.
 *
 * Nobody can pay such an order: the customer got no payment page, and no
 * payment id is stored for a webhook to find. So it becomes `failed`
 * (OrderRepository::markPaymentStartFailed()) and whatever stock it reserved
 * goes back at once, exactly once (App\Service\Inventory\Inventory). Without
 * this the units would be gone for good while the order sat on "pending"
 * forever. The customer's cart is still in their browser, so trying again
 * makes a new order.
 *
 * Never throws: it runs on the error path of the checkout, whose answer to
 * the customer must not change because the clean-up failed. A failure is
 * logged with the order id, for the owner to look into.
 */
final class OrderPaymentStartFailure
{
    /**
     * @return list<StockUnit> units that were sold out and are orderable again
     */
    public static function handle(int $orderId, ?PDO $db = null): array
    {
        $db ??= Database::connection();

        try {
            (new OrderRepository($db))->markPaymentStartFailed($orderId);

            return (new Inventory($db))->releaseForOrder($orderId);
        } catch (\Throwable $e) {
            error_log('[OrderPaymentStartFailure] order ' . $orderId . ': ' . $e->getMessage());

            return [];
        }
    }
}
