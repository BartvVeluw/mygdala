<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The card presentation migration (20261010100000) on a database built from
 * zero and on one upgraded from the migration before it, with two galleries
 * seeded first (a Portfolio gallery with its filter bar and a collection
 * gallery with a lightbox and a soft background):
 *
 *   - every existing block gets 'default', which is its cards as they were;
 *   - no column the rows had changes;
 *   - both installs end on the same schema, and running it again changes
 *     nothing.
 */
#[Group('migration-backfill')]
final class CardPresentationMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_card_presentation_fresh';
    private const UPGRADED = 'mygdala_scratch_card_presentation_upgraded';

    /** The last migration before the card presentation. */
    private const BEFORE = '20261009100000';

    private const MIGRATION = '20261010100000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $before = [];

    /** @var list<array<string, mixed>> */
    private static array $afterFirstRun = [];

    /** @var list<array<string, mixed>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$upgraded->pdo()->exec(
            "INSERT INTO item_galleries (page_slug, section_key, source_type, portfolio_scope, item_sort, show_filter_bar, enable_lightbox, background, tight_top, is_active, created_at, updated_at)
             VALUES ('zz-card-presentation', 'zz-portfolio', 'portfolio', 'all', 'source', 1, 0, 'default', 0, 1, NOW(), NOW()),
                    ('zz-card-presentation', 'zz-collection', 'collection', 'all', 'newest', 0, 1, 'soft', 1, 1, NOW(), NOW())"
        );
        self::$before = self::$upgraded->rows('SELECT * FROM item_galleries ORDER BY id');
        self::$upgraded->catchUp(self::MIGRATION);
        self::$afterFirstRun = self::$upgraded->rows('SELECT * FROM item_galleries ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::$upgraded->rows('SELECT * FROM item_galleries ORDER BY id');
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

    public function testEveryExistingBlockKeepsItsOwnCards(): void
    {
        self::assertSame(
            [['card_presentation' => 'default'], ['card_presentation' => 'default']],
            self::$upgraded->rows('SELECT card_presentation FROM item_galleries ORDER BY id')
        );
    }

    public function testNoColumnTheRowsHadChanges(): void
    {
        // Compared on the columns the rows had before (a new column is not a change).
        $columns = array_keys(self::$before[0]);
        $after = array_map(static fn (array $row): array => array_intersect_key($row, array_flip($columns)), self::$afterFirstRun);

        self::assertSame(self::$before, $after);
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertCount(2, self::$afterFirstRun);
        self::assertSame(self::$afterFirstRun, self::$afterReplay);
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
        self::assertContains(
            ['name' => 'card_presentation', 'type' => 'varchar(10)', 'nullable' => 'NO', 'default' => 'default'],
            self::shape(self::$fresh)
        );
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'item_galleries' ORDER BY ordinal_position"
        );
    }
}
