<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Uitgelicht product's migration (20260928160000) on a database built from
 * zero and on one upgraded from the migration before it:
 *
 *   - both end on the same `featured_products` table, with every default a
 *     new block starts from (no product, name/price/text/button on,
 *     specifications off, gallery on the left at medium size, text left,
 *     direct ordering, shown);
 *   - one row per (page_slug, section_key); the product is a SET NULL
 *     foreign key, so deleting a product empties the block and is never
 *     stopped by it;
 *   - no column holds anything of the product itself;
 *   - the upgrade touches nothing that was there (pages, page sections,
 *     products), and running it again changes nothing, a block stored in
 *     between included.
 */
#[Group('migration-backfill')]
final class FeaturedProductMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_featured_product_fresh';
    private const UPGRADED = 'mygdala_scratch_featured_product_upgraded';

    /** The last migration before it (Shop Product & Ordering 2.0's system pages). */
    private const BEFORE = '20260928150000';

    private const MIGRATION = '20260928160000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var list<array<string, mixed>> */
    private static array $blocksBeforeReplay = [];

    /** @var list<array<string, mixed>> */
    private static array $blocksAfterReplay = [];

    private static bool $tableBefore = true;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$tableBefore = self::$upgraded->hasTable('featured_products');
        self::$before = self::untouched(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::untouched(self::$upgraded);

        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO products (slug, price, active, created_at, updated_at) VALUES ('zz-featured-migration', 10.00, 1, NOW(), NOW())");
        $product = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO featured_products (page_slug, section_key, product_id, image_position, ordering, created_at, updated_at)
                    VALUES ('zz-featured', 'custom-a', {$product}, 'right', 'view', NOW(), NOW())");
        self::$blocksBeforeReplay = self::$upgraded->rows('SELECT * FROM featured_products ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$blocksAfterReplay = self::$upgraded->rows('SELECT * FROM featured_products ORDER BY id');
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

    public function testTheTableIsNewAndStartsEveryBlockEmptyWithItsDefaults(): void
    {
        self::assertFalse(self::$tableBefore, 'the migration before it has no such table');

        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO featured_products (page_slug, section_key, created_at, updated_at) VALUES ('zz-featured', 'custom-new', NOW(), NOW())");

        $row = self::$fresh->rows("SELECT product_id, show_name, show_price, show_description, show_specifications, image_mode, image_position, image_size, content_align, ordering, show_product_link, is_active FROM featured_products WHERE section_key = 'custom-new'")[0];
        self::assertSame(
            ['product_id' => null, 'show_name' => 1, 'show_price' => 1, 'show_description' => 1, 'show_specifications' => 0, 'image_mode' => 'gallery', 'image_position' => 'left', 'image_size' => 'medium', 'content_align' => 'left', 'ordering' => 'direct', 'show_product_link' => 1, 'is_active' => 1],
            array_map(static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, $row)
        );
    }

    public function testOneRowPerInstance(): void
    {
        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO featured_products (page_slug, section_key) VALUES ('zz-featured', 'custom-twice')");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO featured_products (page_slug, section_key) VALUES ('zz-featured', 'custom-twice')");
    }

    public function testDeletingTheProductEmptiesTheBlockAndIsNeverStoppedByIt(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame(
                [['column_name' => 'product_id', 'referenced_table_name' => 'products', 'delete_rule' => 'SET NULL']],
                $install->rows(
                    "SELECT k.column_name AS column_name, k.referenced_table_name AS referenced_table_name, r.delete_rule AS delete_rule
                       FROM information_schema.key_column_usage k
                       JOIN information_schema.referential_constraints r
                         ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                      WHERE k.table_schema = DATABASE() AND k.table_name = 'featured_products' AND k.referenced_table_name IS NOT NULL"
                )
            );
        }

        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO products (slug, price, active, created_at, updated_at) VALUES ('zz-featured-delete', 5.00, 1, NOW(), NOW())");
        $product = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO featured_products (page_slug, section_key, product_id) VALUES ('zz-featured', 'custom-delete', {$product})");
        $pdo->exec("DELETE FROM products WHERE id = {$product}");

        self::assertNull(self::$fresh->rows("SELECT product_id FROM featured_products WHERE section_key = 'custom-delete'")[0]['product_id']);
    }

    public function testNoColumnHoldsAnythingOfTheProduct(): void
    {
        $columns = array_column(self::shape(self::$fresh), 'name');

        foreach (['name', 'title', 'price', 'image_path', 'media_id', 'description', 'stock', 'slug'] as $productData) {
            self::assertNotContains($productData, $columns, $productData);
        }
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
        self::assertNotSame([], self::shape(self::$fresh));
    }

    public function testTheUpgradeTouchesNothingThatWasThere(): void
    {
        self::assertNotSame([], self::$before['pages']);
        self::assertSame(self::$before, self::$after);
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertCount(1, self::$blocksBeforeReplay);
        self::assertSame(self::$blocksBeforeReplay, self::$blocksAfterReplay);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function untouched(ScratchInstall $install): array
    {
        return [
            'pages' => $install->rows('SELECT * FROM pages ORDER BY id'),
            'page_sections' => $install->rows('SELECT * FROM page_sections ORDER BY id'),
            'products' => $install->rows('SELECT * FROM products ORDER BY id'),
            'block_translations' => $install->rows('SELECT * FROM block_translations ORDER BY id'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'featured_products' ORDER BY ordinal_position"
        );
    }
}
