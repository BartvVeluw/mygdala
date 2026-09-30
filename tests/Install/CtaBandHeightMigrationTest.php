<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The CTA minimum height migration (20261007100000) on a database built from
 * zero and on one upgraded from the migration before it, with two bands
 * seeded first (one plain, one with a picture-less CTA 2.0 look):
 *
 *   - every existing band gets 'auto' on both screens and no pixels, which is
 *     the band as it was;
 *   - no column the rows had changes;
 *   - both installs end on the same schema, and running it again changes
 *     nothing.
 */
#[Group('migration-backfill')]
final class CtaBandHeightMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_cta_height_fresh';
    private const UPGRADED = 'mygdala_scratch_cta_height_upgraded';

    /** The last migration before the minimum height. */
    private const BEFORE = '20261006100000';

    private const MIGRATION = '20261007100000';

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
            "INSERT INTO cta_bands (page_slug, section_key, primary_url, secondary_url, content_align, lead_width, full_width, text_panel, is_active, created_at, updated_at)
             VALUES ('zz-cta-height', 'zz-plain', '/contact', NULL, 'center', 'narrow', 0, 0, 1, NOW(), NOW()),
                    ('zz-cta-height', 'zz-styled', '/werk', '/over', 'left', 'wide', 1, 1, 1, NOW(), NOW())"
        );
        self::$before = self::$upgraded->rows('SELECT * FROM cta_bands ORDER BY id');
        self::$upgraded->catchUp(self::MIGRATION);
        self::$afterFirstRun = self::$upgraded->rows('SELECT * FROM cta_bands ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::$upgraded->rows('SELECT * FROM cta_bands ORDER BY id');
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

    public function testEveryExistingBandIsAutomaticOnBothScreens(): void
    {
        self::assertSame(
            array_fill(0, 2, ['min_height' => 'auto', 'min_height_px' => null, 'mobile_min_height' => 'auto', 'mobile_min_height_px' => null]),
            self::$upgraded->rows('SELECT min_height, min_height_px, mobile_min_height, mobile_min_height_px FROM cta_bands ORDER BY id')
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
            ['name' => 'min_height', 'type' => 'varchar(10)', 'nullable' => 'NO', 'default' => 'auto'],
            self::shape(self::$fresh)
        );
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'cta_bands' ORDER BY ordinal_position"
        );
    }
}
