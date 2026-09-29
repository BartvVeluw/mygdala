<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Migration 20260929100000 (Shop Admin UX & Order Fields 2.0: the image
 * order question) on a database built from zero and on one upgraded from
 * the v0.1.12 feature branch's schema before it (v0.1.11 plus Responsive
 * Media 2.0, 20260928230000) with a shop that already asks a question and
 * has an order that answered it:
 *
 *   - every existing question keeps its type, length and words, and gets no
 *     file size (NULL);
 *   - every existing answer and order line stays exactly as it was;
 *   - order_field_uploads starts empty;
 *   - a picture's product is SET NULL, its answer RESTRICT;
 *   - the fresh and the upgraded installation end on the same columns;
 *   - running it again changes nothing, and it touches no file.
 */
#[Group('migration-backfill')]
final class ImageOrderFieldUploadsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_ofu_fresh';
    private const UPGRADED = 'mygdala_scratch_ofu_upgraded';

    private const BEFORE = '20260928230000';
    private const MIGRATION = '20260929100000';

    private const TABLES = ['products', 'product_order_fields', 'product_order_field_translations', 'orders', 'order_items', 'order_item_fields'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    private static bool $hadTable = true;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$hadTable = self::$upgraded->hasTable('order_field_uploads');
        self::seed(self::$upgraded);
        self::$before = self::rows(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::rows(self::$upgraded);
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::rows(self::$upgraded);
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

    public function testEveryExistingQuestionAndAnswerStaysAsItWas(): void
    {
        self::assertCount(1, self::$after['product_order_fields']);
        self::assertNull(self::$after['product_order_fields'][0]['max_file_size_mb'], 'no file size for a text question');
        self::assertSame(
            self::$before['product_order_fields'],
            array_map(static fn (array $row): array => array_diff_key($row, ['max_file_size_mb' => true]), self::$after['product_order_fields'])
        );

        foreach (['products', 'product_order_field_translations', 'orders', 'order_items', 'order_item_fields'] as $table) {
            self::assertSame(self::$before[$table], self::$after[$table], $table);
        }
        self::assertCount(1, self::$after['order_item_fields']);
        self::assertSame('Luna', self::$after['order_item_fields'][0]['value']);
    }

    public function testTheUploadTableIsNewAndEmpty(): void
    {
        self::assertFalse(self::$hadTable);
        self::assertSame(0, self::$upgraded->count('order_field_uploads'));
        self::assertSame(0, self::$fresh->count('order_field_uploads'));
    }

    public function testAPicturesProductIsSetNullAndItsAnswerRestrict(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            $rules = $install->rows(
                "SELECT constraint_name AS name, delete_rule AS rule FROM information_schema.referential_constraints
                  WHERE constraint_schema = DATABASE() AND table_name = 'order_field_uploads' ORDER BY constraint_name"
            );
            self::assertSame([
                ['name' => 'fk_order_field_uploads_answer', 'rule' => 'RESTRICT'],
                ['name' => 'fk_order_field_uploads_product', 'rule' => 'SET NULL'],
            ], $rules);
        }

        $indexes = self::$upgraded->rows(
            "SELECT index_name AS name, non_unique AS non_unique FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'order_field_uploads' AND seq_in_index = 1 ORDER BY index_name"
        );
        $unique = array_column(array_filter($indexes, static fn (array $row): bool => (int) $row['non_unique'] === 0), 'name');
        foreach (['uq_order_field_uploads_token', 'uq_order_field_uploads_storage', 'uq_order_field_uploads_answer'] as $name) {
            self::assertContains($name, $unique, 'one row per token, per file and per answer');
        }
        self::assertNotContains('idx_order_field_uploads_sweep', $unique);
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameColumns(): void
    {
        foreach (['product_order_fields', 'order_field_uploads'] as $table) {
            self::assertSame(self::columns(self::$fresh, $table), self::columns(self::$upgraded, $table), $table);
        }
    }

    public function testRunningItAgainChangesNothingAndItTouchesNoFile(): void
    {
        self::assertSame(self::$after, self::$afterReplay);

        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260929100000_add_image_order_field_uploads.php');
        foreach (['file_put_contents', 'mkdir', 'unlink', 'rename(', 'fopen'] as $call) {
            self::assertStringNotContainsString($call, $source, 'no filesystem work in a migration');
        }
    }

    /* ------------------------------------------------------------------ */

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $at = "'2026-09-25 10:00:00'";

        $pdo->exec("INSERT INTO products (slug, price, shipping_profile, shipping_weight_grams, requires_parcel, active, in_shop, order_fields_enabled, created_at, updated_at)
                    VALUES ('zz-naambord-ofu', '24.95', 'parcel', 900, 1, 1, 1, 1, {$at}, {$at})");
        $product = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO product_order_fields (product_id, field_type, is_required, max_length, sort_order, created_at, updated_at)
                    VALUES ({$product}, 'text', 1, 12, 0, {$at}, {$at})");
        $field = (int) $pdo->lastInsertId();
        $language = (string) $pdo->query('SELECT code FROM site_languages ORDER BY is_default DESC, id ASC LIMIT 1')->fetchColumn();
        $pdo->exec("INSERT INTO product_order_field_translations (field_id, language_code, label, help_text, created_at, updated_at)
                    VALUES ({$field}, '{$language}', 'Naam op het bord', NULL, {$at}, {$at})");

        $pdo->exec("INSERT INTO customers (name, email, country, created_at, updated_at) VALUES ('ZZ Ofu', 'zz-ofu@example.invalid', 'NL', {$at}, {$at})");
        $customer = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO orders (order_number, customer_id, status, total, refunded_amount, shipping_cost, currency, created_at, updated_at)
                    VALUES ('ORD-2026-000951', {$customer}, 'paid', '24.95', '0.00', '0.00', 'EUR', {$at}, {$at})");
        $order = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, created_at, updated_at)
                    VALUES ({$order}, {$product}, 'ZZ Naambord', 1, '24.95', {$at}, {$at})");
        $item = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO order_item_fields (order_item_id, field_id, field_type, label, value, option_id, sort_order, created_at)
                    VALUES ({$item}, {$field}, 'text', 'Naam op het bord', 'Luna', NULL, 0, {$at})");
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function rows(ScratchInstall $install): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $install->rows('SELECT * FROM `' . $table . '` ORDER BY 1');
        }

        return $rows;
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
