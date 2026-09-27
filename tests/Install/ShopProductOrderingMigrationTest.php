<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The five Shop migrations of Shop Product & Ordering 2.0 (20260928100000
 * stock, 110000 back-in-stock requests, 120000 purchase mode, 130000 order
 * fields, 140000 specifications) on a database built from zero and on one
 * upgraded from v0.1.9 (186 migrations, 20260927160000) with a shop that
 * already sold something: a product without variants whose old, never-read
 * `stock` column says 7, a product with two variants, and a paid, a pending
 * and a canceled order with their lines.
 *
 *   - every existing product stays unlimited and ordered directly: stock
 *     not tracked, purchase mode `direct`, no order fields — so an existing
 *     shop works exactly as before without anyone changing anything;
 *   - every existing variant stays unlimited (its product tracks nothing);
 *   - no existing order or order line changes, and none reserved anything,
 *     so no old order — the canceled one included — can ever give stock
 *     back (Inventory::releaseForOrder() only releases reserved lines);
 *   - every new table starts empty: no request, question, answer or
 *     specification is invented;
 *   - the fresh and the upgraded installation end on the same columns;
 *   - running any of the five again changes nothing.
 */
#[Group('migration-backfill')]
final class ShopProductOrderingMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_shop2_fresh';
    private const UPGRADED = 'mygdala_scratch_shop2_upgraded';

    /** The last migration of v0.1.9. */
    private const BEFORE = '20260927160000';

    private const MIGRATIONS = ['20260928100000', '20260928110000', '20260928120000', '20260928130000', '20260928140000'];

    private const LAST = '20260928140000';

    private const TABLES = ['products', 'product_variants', 'product_options', 'product_option_values', 'product_variant_values', 'orders', 'order_items', 'customers'];

    private const NEW_TABLES = [
        'stock_notifications',
        'product_order_fields',
        'product_order_field_translations',
        'product_order_field_options',
        'product_order_field_option_translations',
        'order_item_fields',
        'product_specifications',
        'product_specification_translations',
        'product_specification_values',
        'product_specification_value_translations',
    ];

    /** The columns these migrations add, per table. */
    private const NEW_COLUMNS = [
        'products' => ['track_stock', 'purchase_mode', 'order_fields_enabled'],
        'product_variants' => ['stock'],
        'orders' => ['stock_released_at'],
        'order_items' => ['stock_reserved', 'stock_source'],
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> migration => rows after running it again */
    private static array $afterReplay = [];

    /** @var list<string> */
    private static array $tablesBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::LAST);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$tablesBefore = self::tables(self::$upgraded);
        self::seed(self::$upgraded);
        self::$before = self::rows(self::$upgraded);
        self::$upgraded->catchUp(self::LAST);
        self::$after = self::rows(self::$upgraded);

        foreach (self::MIGRATIONS as $migration) {
            self::$upgraded->replay($migration, self::LAST);
            self::$afterReplay[$migration] = self::rows(self::$upgraded);
        }
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$upgraded?->drop();
        self::$fresh = null;
        self::$upgraded = null;
    }

    public function testEveryExistingProductStaysUnlimitedAndOrderedDirectly(): void
    {
        self::assertCount(2, self::$after['products']);
        foreach (self::$after['products'] as $product) {
            self::assertSame(0, (int) $product['track_stock'], $product['slug'] . ': stock not tracked');
            self::assertSame('direct', $product['purchase_mode'], $product['slug']);
            self::assertSame(0, (int) $product['order_fields_enabled'], $product['slug']);
        }

        self::assertSame(
            self::$before['products'],
            self::without(self::$after['products'], self::NEW_COLUMNS['products']),
            'price, the old stock figure (7, still never read), slug, flags and dates as they were'
        );
    }

    public function testEveryExistingVariantStaysUnlimited(): void
    {
        self::assertCount(2, self::$after['product_variants']);
        self::assertSame([0, 0], array_map('intval', array_column(self::$after['product_variants'], 'stock')), 'a number that means nothing while its product tracks nothing');
        self::assertSame(self::$before['product_variants'], self::without(self::$after['product_variants'], self::NEW_COLUMNS['product_variants']));

        foreach (['product_options', 'product_option_values', 'product_variant_values'] as $table) {
            self::assertSame(self::$before[$table], self::$after[$table], $table);
        }
    }

    public function testNoExistingOrderOrLineChangesAndNoneCanGiveStockBack(): void
    {
        self::assertSame(['paid', 'pending', 'canceled'], array_column(self::$after['orders'], 'status'));
        self::assertSame([null, null, null], array_column(self::$after['orders'], 'stock_released_at'));
        self::assertSame(self::$before['orders'], self::without(self::$after['orders'], self::NEW_COLUMNS['orders']));

        self::assertCount(4, self::$after['order_items']);
        self::assertSame([0, 0, 0, 0], array_map('intval', array_column(self::$after['order_items'], 'stock_reserved')), 'nothing was reserved, so nothing can come back');
        self::assertSame([null, null, null, null], array_column(self::$after['order_items'], 'stock_source'));
        self::assertSame(self::$before['order_items'], self::without(self::$after['order_items'], self::NEW_COLUMNS['order_items']));

        self::assertSame(self::$before['customers'], self::$after['customers']);
    }

    public function testEveryNewTableStartsEmpty(): void
    {
        foreach (self::NEW_TABLES as $table) {
            self::assertNotContains($table, self::$tablesBefore, $table . ' is new');
            self::assertSame(0, self::$upgraded->count($table), $table . ' on the upgraded installation');
            self::assertSame(0, self::$fresh->count($table), $table . ' on the fresh installation');
        }
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameColumns(): void
    {
        foreach (array_merge(array_keys(self::NEW_COLUMNS), self::NEW_TABLES) as $table) {
            self::assertSame(self::columns(self::$fresh, $table), self::columns(self::$upgraded, $table), $table);
        }
    }

    public function testRunningAnyOfTheFiveAgainChangesNothing(): void
    {
        foreach (self::$afterReplay as $migration => $rows) {
            self::assertSame(self::$after, $rows, (string) $migration);
        }
    }

    /* ------------------------------------------------------------------ */

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $at = "'2026-09-20 10:00:00'";

        $pdo->exec("INSERT INTO products (slug, price, stock, shipping_profile, shipping_weight_grams, requires_parcel, active, in_shop, created_at, updated_at)
                    VALUES ('zz-onderzetter', '12.50', 7, 'letter', 40, 0, 1, 1, {$at}, {$at})");
        $plain = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO products (slug, price, stock, shipping_profile, shipping_weight_grams, requires_parcel, active, in_shop, created_at, updated_at)
                    VALUES ('zz-naambord', '24.95', 0, 'parcel', 900, 1, 1, 1, {$at}, {$at})");
        $withVariants = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO product_options (product_id, name, sort_order, created_at, updated_at) VALUES ({$withVariants}, 'Kleur', 0, {$at}, {$at})");
        $option = (int) $pdo->lastInsertId();
        $variants = [];
        foreach (['Eiken' => '24.95', 'Noten' => '29.95'] as $value => $price) {
            $pdo->exec("INSERT INTO product_option_values (product_option_id, value, sort_order, created_at, updated_at) VALUES ({$option}, '{$value}', 0, {$at}, {$at})");
            $valueId = (int) $pdo->lastInsertId();
            $pdo->exec("INSERT INTO product_variants (product_id, price, active, sort_order, created_at, updated_at) VALUES ({$withVariants}, '{$price}', 1, 0, {$at}, {$at})");
            $variantId = (int) $pdo->lastInsertId();
            $pdo->exec("INSERT INTO product_variant_values (variant_id, product_option_value_id) VALUES ({$variantId}, {$valueId})");
            $variants[$value] = $variantId;
        }

        $pdo->exec("INSERT INTO customers (name, email, country, created_at, updated_at) VALUES ('ZZ Shop2', 'zz-shop2@example.invalid', 'NL', {$at}, {$at})");
        $customer = (int) $pdo->lastInsertId();

        $orders = [];
        foreach ([['ORD-2026-000901', 'paid', 'tr_zzshop2paid', 'paid'], ['ORD-2026-000902', 'pending', 'tr_zzshop2open', 'open'], ['ORD-2026-000903', 'canceled', 'tr_zzshop2gone', 'canceled']] as [$number, $status, $payment, $mollie]) {
            $pdo->exec(
                "INSERT INTO orders (order_number, customer_id, status, mollie_payment_id, mollie_status, total, refunded_amount, shipping_cost, currency, created_at, updated_at)
                 VALUES ('{$number}', {$customer}, '{$status}', '{$payment}', '{$mollie}', '49.90', '0.00', '0.00', 'EUR', {$at}, {$at})"
            );
            $orders[$status] = (int) $pdo->lastInsertId();
        }

        foreach ([
            [$orders['paid'], $plain, 'NULL', 'NULL', 2, '12.50'],
            [$orders['paid'], $withVariants, (string) $variants['Noten'], "'Kleur: Noten'", 1, '29.95'],
            [$orders['pending'], $withVariants, (string) $variants['Eiken'], "'Kleur: Eiken'", 1, '24.95'],
            [$orders['canceled'], $plain, 'NULL', 'NULL', 3, '12.50'],
        ] as [$order, $product, $variant, $label, $quantity, $price]) {
            $pdo->exec(
                "INSERT INTO order_items (order_id, product_id, variant_id, variant_label, product_name, quantity, unit_price, created_at, updated_at)
                 VALUES ({$order}, {$product}, {$variant}, {$label}, 'ZZ regel', {$quantity}, '{$price}', {$at}, {$at})"
            );
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function rows(ScratchInstall $install): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $install->rows('SELECT * FROM `' . $table . '` ORDER BY 1, 2');
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private static function without(array $rows, array $columns): array
    {
        return array_map(static fn (array $row): array => array_diff_key($row, array_flip($columns)), $rows);
    }

    /** @return list<string> */
    private static function tables(ScratchInstall $install): array
    {
        return array_column($install->rows('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'), 'name');
    }

    /** @return list<array<string, mixed>> */
    private static function columns(ScratchInstall $install, string $table): array
    {
        return $install->rows(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS default_value
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?
              ORDER BY ordinal_position',
            [$table]
        );
    }
}
