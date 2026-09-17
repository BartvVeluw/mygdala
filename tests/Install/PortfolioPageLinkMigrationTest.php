<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The link from a portfolio item to a CMS page, however the database came to
 * be: db/migrations/20260914200000_link_a_portfolio_item_to_a_page.php.
 *
 * Three throwaway databases (Tests\Support\ScratchInstall), the three
 * PortfolioCatalogueSchemaTest uses: one built from zero, one caught up as an
 * existing installation, and one standing where a deployed site stood the
 * migration before — holding a catalogue and an item with an old project page
 * (slug, texts, one extra photo). That one catches up, and then runs the
 * migration a second time, as `phinx migrate` would if its log lost the line.
 *
 * What it proves: one nullable `int unsigned` column, one index and one key
 * that lets a page go without taking the item (SET NULL), identical on all
 * three databases; the old project page and its photo untouched; and no
 * second index or key after the replay.
 */
#[Group('migration-backfill')]
final class PortfolioPageLinkMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_portfolio_link_fresh';
    private const LEGACY = 'mygdala_scratch_portfolio_link_legacy';
    private const DEPLOYED = 'mygdala_scratch_portfolio_link_deployed';

    /** The migration before the link: where a deployed site stood. */
    private const BEFORE_LINK = '20260914170000';

    private const LINK = '20260914200000';

    /** What the old project page kept on the item, and must go on keeping. */
    private const OLD_PROJECT_PAGE_COLUMNS = ['has_detail_page', 'slug', 'intro_nl', 'intro_en', 'description_nl', 'description_en'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $legacy = null;
    private static ?ScratchInstall $deployed = null;

    /** @var array{items: list<array<string, mixed>>, images: list<array<string, mixed>>} */
    private static array $deployedBefore = ['items' => [], 'images' => []];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);
        self::$legacy = ScratchInstall::legacy(self::LEGACY);

        // A site deployed the migration before, in use: the catalogue, one item
        // with the project page the Portfolio used to own, and one extra photo.
        self::$deployed = ScratchInstall::upTo(self::DEPLOYED, self::BEFORE_LINK);
        $pdo = self::$deployed->pdo();

        $pdo->exec("INSERT INTO portfolio_galleries (created_at, updated_at) VALUES ('2026-09-01 10:00:00', '2026-09-01 10:00:00')");
        $catalogueId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO portfolio_gallery_items
                (portfolio_gallery_id, image_path, thumbnail_path, alt_nl, title_nl, subtitle_nl, sort_order, is_active, is_featured,
                 has_detail_page, slug, intro_nl, description_nl, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, 1, 0, 1, ?, ?, ?, ?, ?)'
        )->execute([
            $catalogueId,
            'assets/images/zz-portfolio/skyline.jpg',
            'assets/images/zz-portfolio/thumbs/skyline.webp',
            'Houten skyline van een stad',
            'Skyline',
            'Wanddecoratie, hout',
            'skyline',
            '<p>Intro van het project</p>',
            '<p>Beschrijving van het project</p>',
            '2026-09-01 10:05:00',
            '2026-09-01 10:05:00',
        ]);
        $itemId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO portfolio_item_images (portfolio_item_id, image_path, thumbnail_path, alt_nl, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        )->execute([
            $itemId,
            'assets/images/zz-portfolio/detail.jpg',
            'assets/images/zz-portfolio/thumbs/detail.webp',
            'Detail van de skyline',
            '2026-09-01 10:06:00',
            '2026-09-01 10:06:00',
        ]);

        self::$deployedBefore = self::data(self::$deployed);

        self::$deployed->catchUp();
        self::$deployed->replay(self::LINK);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$fresh, self::$legacy, self::$deployed] as $install) {
            $install?->drop();
        }

        self::$fresh = null;
        self::$legacy = null;
        self::$deployed = null;
    }

    public function testAFreshInstallGetsAnOptionalLinkThatLetsThePageGo(): void
    {
        $this->assertSame(
            [
                'column' => 'int unsigned NULL DEFAULT NULL',
                'indexes' => 1,
                'keys' => [['ref_table' => 'pages', 'ref_column' => 'id', 'delete_rule' => 'SET NULL', 'update_rule' => 'CASCADE']],
            ],
            $this->linkSchema($this->install(self::$fresh))
        );
    }

    /**
     * An existing installation and a deployed one that already ran the
     * migration once end exactly where a new installation does — the replay
     * added no second index and no second key.
     */
    public function testEveryInstallationEndsWithTheSameLink(): void
    {
        $fresh = $this->linkSchema($this->install(self::$fresh));

        $this->assertSame($fresh, $this->linkSchema($this->install(self::$legacy)), 'an existing installation');
        $this->assertSame($fresh, $this->linkSchema($this->install(self::$deployed)), 'a deployed site, after a second run');
    }

    public function testDeletingAPageKeepsTheItemWithoutALink(): void
    {
        $pdo = $this->install(self::$fresh)->pdo();

        $pdo->exec("INSERT INTO pages (content_key, slug, status, created_at, updated_at) VALUES ('zz-project', 'zz-project', 'published', NOW(), NOW())");
        $pageId = (int) $pdo->lastInsertId();

        $pdo->exec('INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())');
        $pdo->prepare(
            "INSERT INTO portfolio_gallery_items (portfolio_gallery_id, page_id, image_path, alt_nl, title_nl, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, 'assets/images/zz-portfolio/werk.jpg', '', 'ZZ Werk', 0, 1, NOW(), NOW())"
        )->execute([(int) $pdo->lastInsertId(), $pageId]);
        $itemId = (int) $pdo->lastInsertId();

        $pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$pageId]);

        $rows = $this->install(self::$fresh)->rows('SELECT id, page_id FROM portfolio_gallery_items WHERE id = ?', [$itemId]);
        $this->assertSame([['id' => $itemId, 'page_id' => null]], $rows, 'the item stays, without a link');
    }

    /**
     * The migration only adds. The item a deployed site holds keeps every
     * value — its old project page's slug and texts included — and starts
     * without a link; its extra photo is still there; and every installation
     * still has the old project page's columns.
     */
    public function testTheOldProjectPageAndItsPhotosAreKeptAsTheyWere(): void
    {
        $after = self::data($this->install(self::$deployed));

        $this->assertNotSame([], self::$deployedBefore['items']);
        $this->assertSame(
            self::$deployedBefore['items'],
            array_map(static fn (array $row): array => array_diff_key($row, ['page_id' => true]), $after['items']),
            'adding the link must not change one value of an existing item'
        );
        $this->assertSame([null], array_column($after['items'], 'page_id'), 'an existing item starts without a page');
        $this->assertSame(self::$deployedBefore['images'], $after['images'], 'portfolio_item_images is left alone');

        foreach ([self::$fresh, self::$legacy, self::$deployed] as $install) {
            $columns = array_column($this->install($install)->rows(
                'SELECT COLUMN_NAME AS name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
                [$this->install($install)->database, 'portfolio_gallery_items']
            ), 'name');

            foreach (self::OLD_PROJECT_PAGE_COLUMNS as $column) {
                $this->assertContains($column, $columns, $this->install($install)->database . ' keeps ' . $column);
            }

            $this->assertTrue($this->install($install)->hasTable('portfolio_item_images'));
        }
    }

    // --------------------------------------------------------------- helpers

    private function install(?ScratchInstall $install): ScratchInstall
    {
        if ($install === null) {
            $this->markTestSkipped(
                'Building installations from zero needs the MySQL root account (DB_ROOT_PASSWORD in .env).'
            );
        }

        return $install;
    }

    /**
     * The column's full definition, how many indexes cover it, and every key
     * it is part of — what "the same link" means.
     *
     * @return array{column: string, indexes: int, keys: list<array<string, string>>}
     */
    private function linkSchema(ScratchInstall $install): array
    {
        $column = $install->rows(
            'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS fallback
               FROM information_schema.columns
              WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$install->database, 'portfolio_gallery_items', 'page_id']
        );
        $this->assertCount(1, $column, $install->database . ' has a page_id column');

        $indexes = $install->rows(
            'SELECT INDEX_NAME AS name
               FROM information_schema.statistics
              WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$install->database, 'portfolio_gallery_items', 'page_id']
        );

        $keys = $install->rows(
            'SELECT k.REFERENCED_TABLE_NAME AS ref_table, k.REFERENCED_COLUMN_NAME AS ref_column,
                    r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [$install->database, 'portfolio_gallery_items', 'page_id']
        );

        return [
            'column' => implode(' ', [
                $column[0]['type'],
                $column[0]['nullable'] === 'YES' ? 'NULL' : 'NOT NULL',
                'DEFAULT ' . ($column[0]['fallback'] === null ? 'NULL' : var_export($column[0]['fallback'], true)),
            ]),
            'indexes' => count($indexes),
            'keys' => array_map(
                static fn (array $key): array => array_map('strval', $key),
                $keys
            ),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, images: list<array<string, mixed>>}
     */
    private static function data(ScratchInstall $install): array
    {
        return [
            'items' => $install->rows('SELECT * FROM portfolio_gallery_items ORDER BY id'),
            'images' => $install->rows('SELECT * FROM portfolio_item_images ORDER BY id'),
        ];
    }
}
