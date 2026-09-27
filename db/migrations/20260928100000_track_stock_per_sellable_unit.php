<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shop Product & Ordering 2.0, voorraad: stock is tracked per SELLABLE UNIT,
 * and only for a product whose owner switched it on (App\Service\Inventory,
 * MODULES.md "Voorraad").
 *
 *   products.track_stock          0/1, default 0: "Voorraad bijhouden". Off is
 *                                 what every product was: unlimited, never
 *                                 checked. The existing, never-used
 *                                 `products.stock` becomes the stock of a
 *                                 product WITHOUT variants; it is read only
 *                                 while this is on
 *   product_variants.stock        the stock of one variant: for a tracked
 *                                 product WITH variants, the only stock that
 *                                 counts (the product's own is then ignored,
 *                                 so the two never compete)
 *   order_items.stock_reserved    how many units this line took from stock
 *                                 when the order was created (0: untracked)
 *   order_items.stock_source      'product' or 'variant': which counter it
 *                                 took them from, so a release gives them
 *                                 back to exactly that one
 *   orders.stock_released_at      set once, when a failed, canceled or
 *                                 expired order gave its units back — the
 *                                 marker that makes the release happen
 *                                 exactly once however often a webhook comes
 *
 * ADD-ONLY. Every existing product keeps `track_stock = 0`, so it stays
 * unlimited and orderable exactly as before; its `stock` value is not
 * touched. Every existing variant gets stock 0, which nothing reads while its
 * product is untracked. Every existing order line gets 0 reserved and no
 * source, every existing order no release: nothing was taken from stock
 * before this, so nothing can be given back. No row is updated. No
 * fresh-install guard: a new installation and an upgraded one end on the same
 * tables (db/migrations/CLAUDE.md). Idempotent: every column is checked first.
 */
final class TrackStockPerSellableUnit extends AbstractMigration
{
    public function up(): void
    {
        $products = $this->table('products');
        if (!$products->hasColumn('track_stock')) {
            $products->addColumn('track_stock', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'stock',
                'comment' => '1 = Voorraad bijhouden: stock counts (products.stock without variants, product_variants.stock with)',
            ])->update();
        }

        $variants = $this->table('product_variants');
        if (!$variants->hasColumn('stock')) {
            $variants->addColumn('stock', 'integer', [
                'signed' => false,
                'default' => 0,
                'null' => false,
                'after' => 'price',
                'comment' => 'Stock of this variant; counts only while products.track_stock = 1',
            ])->update();
        }

        $items = $this->table('order_items');
        if (!$items->hasColumn('stock_reserved')) {
            $items->addColumn('stock_reserved', 'integer', [
                'signed' => false,
                'default' => 0,
                'null' => false,
                'after' => 'quantity',
                'comment' => 'Units this line took from stock when the order was created',
            ])->update();
        }
        if (!$items->hasColumn('stock_source')) {
            $items->addColumn('stock_source', 'string', [
                'limit' => 8,
                'null' => true,
                'default' => null,
                'after' => 'stock_reserved',
                'comment' => "'product' or 'variant': the counter the reserved units came from",
            ])->update();
        }

        $orders = $this->table('orders');
        if (!$orders->hasColumn('stock_released_at')) {
            $orders->addColumn('stock_released_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'payment_mode',
                'comment' => 'When a failed, canceled or expired order gave its reserved stock back (once)',
            ])->update();
        }
    }

    public function down(): void
    {
        foreach ([
            ['orders', 'stock_released_at'],
            ['order_items', 'stock_source'],
            ['order_items', 'stock_reserved'],
            ['product_variants', 'stock'],
            ['products', 'track_stock'],
        ] as [$table, $column]) {
            if ($this->table($table)->hasColumn($column)) {
                $this->table($table)->removeColumn($column)->update();
            }
        }
    }
}
