<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20260930120000_let_detail_section_gallery_items_show_an_entity.php
 * (Detailsectie 2.0), on a fresh installation and on an upgraded one with a
 * Detailsectie and a gallery picture in it: every existing gallery picture
 * stays a library picture (source_type and source_id NULL, nothing else
 * changed), fresh and upgraded end on the same columns, and running it again
 * changes nothing.
 */
#[Group('migration-backfill')]
final class DetailSectionSourcesMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_dss_fresh';
    private const UPGRADED = 'mygdala_scratch_dss_upgraded';

    private const BEFORE = '20260930100000';
    private const MIGRATION = '20260930120000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $before = [];

    /** @var list<array<string, mixed>> */
    private static array $after = [];

    /** @var list<array<string, mixed>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO detail_sections (page_slug, section_key, anchor, image_position, is_active, created_at, updated_at)
                    VALUES ('zz-dss', 'custom-zzdss001', 'hout', 'image_left', 1, '2026-09-28 10:00:00', '2026-09-28 10:00:00')");
        $section = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO detail_section_images (section_id, image_path, sort_order, created_at, updated_at)
                    VALUES ({$section}, 'assets/images/sections/zz-dss.jpg', 0, '2026-09-28 10:00:00', '2026-09-28 10:00:00')");

        self::$before = self::$upgraded->rows('SELECT * FROM detail_section_images ORDER BY id');
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::$upgraded->rows('SELECT * FROM detail_section_images ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::$upgraded->rows('SELECT * FROM detail_section_images ORDER BY id');
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

    public function testAnExistingGalleryPictureStaysALibraryPicture(): void
    {
        self::assertCount(1, self::$after);
        self::assertNull(self::$after[0]['source_type']);
        self::assertNull(self::$after[0]['source_id']);
        self::assertSame(
            self::$before,
            array_map(static fn (array $row): array => array_diff_key($row, ['source_type' => true, 'source_id' => true]), self::$after)
        );
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameColumns(): void
    {
        $columns = static fn (ScratchInstall $install): array => $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS default_value
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'detail_section_images'
              ORDER BY ordinal_position"
        );

        self::assertSame($columns(self::$fresh), $columns(self::$upgraded));
        self::assertContains('source_type', array_column($columns(self::$fresh), 'name'));
    }

    public function testRunningItAgainChangesNothing(): void
    {
        self::assertSame(self::$after, self::$afterReplay);
    }
}
