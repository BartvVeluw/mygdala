<?php

/**
 * One contender in Tests\Service\InventoryTest's race for the last units:
 * waits until an agreed moment, then reserves inside its own transaction and
 * holds the row for a moment before committing, the way a checkout writes
 * its order after taking the units. Prints "ok" or "refused".
 *
 * Usage: php tests/Support/stock-race.php <product id> <quantity> <start at, unix time with microseconds>
 * The database is whatever DB_DATABASE the test hands this process.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

[, $productId, $quantity, $startAt] = $argv + [null, '0', '0', '0'];

$db = App\Database::connection();

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$db->beginTransaction();
try {
    (new App\Service\Inventory\Inventory($db))->reserve([
        ['product_id' => (int) $productId, 'variant_id' => null, 'quantity' => (int) $quantity],
    ]);
    usleep(300000);
    $db->commit();
    echo 'ok';
} catch (App\Service\Inventory\InsufficientStockException) {
    $db->rollBack();
    echo 'refused';
}
