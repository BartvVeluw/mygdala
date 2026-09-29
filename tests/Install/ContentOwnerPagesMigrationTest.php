<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20260930100000_give_products_and_projects_content_pages.php
 * (Product & Portfolio Content Pages 1.0, Portfolio layout 2.0), on a fresh
 * installation and on an upgraded one with a page, its blocks, a product and
 * a project in it:
 *
 *   - every existing page, block, product and project reads exactly as
 *     before: the new columns are NULL (an ordinary page; a project that
 *     follows the Portfolio default), nothing else changed;
 *   - the two link tables start empty and hold real foreign keys, RESTRICT
 *     on both sides, one row per owner and per page;
 *   - the Projectinformatie table starts empty, one row per block instance;
 *   - fresh and upgraded end on the same columns, and running it again
 *     changes nothing.
 */
#[Group('migration-backfill')]
final class ContentOwnerPagesMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_cop_fresh';
    private const UPGRADED = 'mygdala_scratch_cop_upgraded';

    private const BEFORE = '20260929100000';
    private const MIGRATION = '20260930100000';

    private const TABLES = ['pages', 'page_sections', 'rich_text_sections', 'products', 'portfolio_gallery_items'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
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

    public function testEveryExistingRowReadsAsBefore(): void
    {
        $without = static fn (array $rows, string $column): array => array_map(
            static fn (array $row): array => array_diff_key($row, [$column => true]),
            $rows
        );

        self::assertNotSame([], self::$before['pages']);
        self::assertSame(self::$before['pages'], $without(self::$after['pages'], 'owner_type'));
        foreach (self::$after['pages'] as $page) {
            self::assertNull($page['owner_type'], 'every existing page stays an ordinary page');
        }

        self::assertCount(1, self::$after['portfolio_gallery_items']);
        self::assertSame(self::$before['portfolio_gallery_items'], $without(self::$after['portfolio_gallery_items'], 'project_layout'));
        self::assertNull(self::$after['portfolio_gallery_items'][0]['project_layout'], 'the project follows the Portfolio default');

        foreach (['page_sections', 'rich_text_sections', 'products'] as $table) {
            self::assertSame(self::$before[$table], self::$after[$table], $table);
        }
    }

    public function testTheLinkTablesAreEmptyAndHoldRealKeysOnBothSides(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            foreach (['product_content_pages', 'portfolio_content_pages', 'portfolio_project_infos'] as $table) {
                self::assertSame(0, $install->count($table), $table);
            }

            $rules = $install->rows(
                "SELECT constraint_name AS name, referenced_table_name AS target, delete_rule AS rule
                   FROM information_schema.referential_constraints
                  WHERE constraint_schema = DATABASE() AND table_name IN ('product_content_pages', 'portfolio_content_pages')
                  ORDER BY constraint_name"
            );
            self::assertSame([
                ['name' => 'fk_portfolio_content_pages_item', 'target' => 'portfolio_gallery_items', 'rule' => 'RESTRICT'],
                ['name' => 'fk_portfolio_content_pages_page', 'target' => 'pages', 'rule' => 'RESTRICT'],
                ['name' => 'fk_product_content_pages_page', 'target' => 'pages', 'rule' => 'RESTRICT'],
                ['name' => 'fk_product_content_pages_product', 'target' => 'products', 'rule' => 'RESTRICT'],
            ], $rules);

            $unique = array_column($install->rows(
                "SELECT index_name AS name FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND non_unique = 0 AND seq_in_index = 1
                    AND table_name IN ('product_content_pages', 'portfolio_content_pages', 'portfolio_project_infos')
                  ORDER BY index_name"
            ), 'name');
            foreach (['uq_portfolio_content_pages_page', 'uq_product_content_pages_page', 'uq_portfolio_project_infos_instance'] as $index) {
                self::assertContains($index, $unique, 'one page per owner, one row per instance');
            }
        }
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameColumns(): void
    {
        foreach (['pages', 'portfolio_gallery_items', 'product_content_pages', 'portfolio_content_pages', 'portfolio_project_infos'] as $table) {
            self::assertSame(self::columns(self::$fresh, $table), self::columns(self::$upgraded, $table), $table);
        }
    }

    public function testRunningItAgainChangesNothing(): void
    {
        self::assertSame(self::$after, self::$afterReplay);
    }

    /* ------------------------------------------------------------------ */

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $at = "'2026-09-28 10:00:00'";

        $pdo->exec("INSERT INTO pages (content_key, slug, status, is_system, sort_order, created_at, updated_at)
                    VALUES ('zz-cop-pagina', 'zz-cop-pagina', 'published', 0, 900, {$at}, {$at})");
        $page = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO rich_text_sections (page_slug, section_key, is_active, created_at, updated_at)
                    VALUES ('zz-cop-pagina', 'custom-zzcop001', 1, {$at}, {$at})");
        $section = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO page_sections (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
                    VALUES ({$page}, 'zz-cop-pagina', 'rich_text', 'custom-zzcop001', {$section}, 10, 1, {$at}, {$at})");

        $pdo->exec("INSERT INTO products (slug, price, shipping_profile, shipping_weight_grams, requires_parcel, active, in_shop, created_at, updated_at)
                    VALUES ('zz-cop-plank', '19.95', 'parcel', 900, 1, 1, 1, {$at}, {$at})");

        $pdo->exec("INSERT INTO portfolio_galleries (created_at, updated_at) VALUES ({$at}, {$at})");
        $gallery = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO portfolio_gallery_items (portfolio_gallery_id, image_path, sort_order, is_active, has_detail_page, slug, created_at, updated_at)
                    VALUES ({$gallery}, 'assets/images/sections/zz-cop.jpg', 0, 1, 1, 'zz-cop-project', {$at}, {$at})");
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
