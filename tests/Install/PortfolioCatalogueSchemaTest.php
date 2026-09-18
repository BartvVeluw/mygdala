<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * One schema for `portfolio_galleries`, however the database came to be.
 *
 * The table stopped being a page section in
 * 20260908290000_turn_the_portfolio_blocks_into_one_reusable_block.php: its
 * `page_slug`/`section_key` identity and its `is_active` flag moved onto the
 * item_gallery block. That migration dropped them AFTER its fresh-install
 * return, so an upgraded installation lost them and a new one kept them. A
 * fresh-install guard may skip one site's content; it may never make the
 * schema depend on which kind of database ran the migrations
 * (INSTALL-BOOTSTRAP.md).
 *
 * Three throwaway databases (Tests\Support\ScratchInstall): one built from
 * zero, one caught up as an existing installation, and one standing where an
 * already-deployed fresh install stood before the correction — holding a
 * catalogue and an item of its own, and already missing one of the three
 * columns, the way a hand-repaired database might.
 */
#[Group('migration-backfill')]
final class PortfolioCatalogueSchemaTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_portfolio_fresh';
    private const LEGACY = 'mygdala_scratch_portfolio_legacy';
    private const DEPLOYED = 'mygdala_scratch_portfolio_deployed';

    /** The last migration before the correction: where a deployed fresh install stood. */
    private const BEFORE_CORRECTION = '20260911200000';

    private const CORRECTION = '20260912100000';

    /** What the catalogue carried while it was still a page section. */
    private const RETIRED_COLUMNS = ['page_slug', 'section_key', 'is_active'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $legacy = null;
    private static ?ScratchInstall $deployed = null;

    /** @var array{catalogue: list<array<string, mixed>>, items: list<array<string, mixed>>} */
    private static array $deployedDataBefore = ['catalogue' => [], 'items' => []];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);
        self::$legacy = ScratchInstall::legacy(self::LEGACY);

        // A fresh install deployed before the correction, in use: the
        // catalogue the Portfolio screen lazily creates, one item on it —
        // and `is_active` already gone.
        self::$deployed = ScratchInstall::upTo(self::DEPLOYED, self::BEFORE_CORRECTION);
        $pdo = self::$deployed->pdo();

        $pdo->exec("INSERT INTO portfolio_galleries (created_at, updated_at) VALUES ('2026-09-01 10:00:00', '2026-09-01 10:00:00')");
        $catalogueId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO portfolio_gallery_items
                (portfolio_gallery_id, image_path, alt_nl, title_nl, sort_order, is_active, is_featured, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, 1, 1, ?, ?)'
        )->execute([$catalogueId, 'assets/images/zz-portfolio/werk.jpg', 'Eigen werk', 'Eerste project', '2026-09-01 10:05:00', '2026-09-01 10:05:00']);

        $pdo->exec('ALTER TABLE portfolio_galleries DROP COLUMN is_active');

        self::$deployedDataBefore = self::catalogueData(self::$deployed);

        self::$deployed->catchUp();
        // And once more, as `phinx migrate` would if its log lost the line.
        self::$deployed->replay(self::CORRECTION);
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

    public function testAFreshInstallEndsWithoutTheRetiredCatalogueColumns(): void
    {
        $schema = $this->catalogueSchema($this->install(self::$fresh));

        $this->assertSame(
            [],
            array_values(array_intersect(self::RETIRED_COLUMNS, array_keys($schema['columns']))),
            'the catalogue is no page section: a brand-new installation must not carry its old identity'
        );
        $this->assertSame([], $this->retiredIndexes($schema), 'the (page_slug, section_key) index goes with its columns');
    }

    public function testAnUpgradedInstallationEndsWithTheSameCatalogueSchemaAsAFreshOne(): void
    {
        $this->assertSame(
            $this->catalogueSchema($this->install(self::$fresh)),
            $this->catalogueSchema($this->install(self::$legacy)),
            'a fresh install and an upgraded one must end with exactly the same portfolio_galleries'
        );
    }

    /**
     * An installation deployed before the correction, with one column already
     * gone, is brought to the same schema — the migration drops whatever is
     * still there and trips over nothing that is not, also on a second run.
     */
    public function testAnAlreadyDeployedFreshInstallIsCorrectedEvenWithAColumnAlreadyMissing(): void
    {
        $this->assertSame(
            $this->catalogueSchema($this->install(self::$fresh)),
            $this->catalogueSchema($this->install(self::$deployed))
        );
    }

    public function testTheCorrectionKeepsTheCatalogueAndEveryItemOnIt(): void
    {
        $after = self::catalogueData($this->install(self::$deployed));

        $this->assertNotSame([], self::$deployedDataBefore['items']);

        // Compared on the columns the item has in BOTH states: catching up
        // also runs every later migration, and one that ADDS a column
        // (page_id, in 20260914200000) or MOVES one out of the row (the words,
        // in 20260918170000) changes no value this correction must keep.
        $shared = static fn (array $row, array $other): array => array_intersect_key($row, $other);

        $this->assertSame(
            array_map($shared, self::$deployedDataBefore['items'], $after['items']),
            array_map($shared, $after['items'], self::$deployedDataBefore['items']),
            'dropping section columns must not touch a single portfolio item'
        );

        $keep = static fn (array $row): array => array_diff_key($row, array_flip(self::RETIRED_COLUMNS));
        $this->assertSame(
            array_map($keep, self::$deployedDataBefore['catalogue']),
            $after['catalogue'],
            'the catalogue row keeps its id and timestamps, so every item still hangs off it'
        );
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
     * Every column with its full definition, and every index by name and
     * column order — what "the same schema" means for this table.
     *
     * @return array{columns: array<string, string>, indexes: list<string>}
     */
    private function catalogueSchema(ScratchInstall $install): array
    {
        $columns = [];
        foreach ($install->rows(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
               FROM information_schema.columns
              WHERE table_schema = ? AND table_name = ?
              ORDER BY ORDINAL_POSITION',
            [$install->database, 'portfolio_galleries']
        ) as $column) {
            $columns[(string) $column['COLUMN_NAME']] = implode(' ', [
                $column['COLUMN_TYPE'],
                $column['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL',
                'DEFAULT ' . var_export($column['COLUMN_DEFAULT'], true),
                $column['EXTRA'],
            ]);
        }

        $indexes = array_map(
            static fn (array $index): string => $index['INDEX_NAME'] . ':' . $index['COLUMN_NAME'],
            $install->rows(
                'SELECT INDEX_NAME, COLUMN_NAME
                   FROM information_schema.statistics
                  WHERE table_schema = ? AND table_name = ?
                  ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [$install->database, 'portfolio_galleries']
            )
        );

        return ['columns' => $columns, 'indexes' => $indexes];
    }

    /**
     * @param array{columns: array<string, string>, indexes: list<string>} $schema
     *
     * @return list<string>
     */
    private function retiredIndexes(array $schema): array
    {
        return array_values(array_filter(
            $schema['indexes'],
            static fn (string $index): bool => in_array(explode(':', $index)[1], self::RETIRED_COLUMNS, true)
        ));
    }

    /**
     * @return array{catalogue: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    private static function catalogueData(ScratchInstall $install): array
    {
        return [
            'catalogue' => $install->rows('SELECT * FROM portfolio_galleries ORDER BY id'),
            'items' => $install->rows('SELECT * FROM portfolio_gallery_items ORDER BY id'),
        ];
    }
}
