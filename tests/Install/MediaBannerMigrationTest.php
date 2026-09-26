<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Mediabanner's migration (20260926130000) on a database built from zero and
 * on one upgraded from the migration before it:
 *
 *   - both end on the same `media_banners` table, with every default a new
 *     banner starts from (no media, content width, medium, centre, no
 *     autoplay, no loop, controls on, no poster, shown);
 *   - one row per (page_slug, section_key), and both library references are
 *     RESTRICT foreign keys, so a used item cannot be deleted;
 *   - the upgrade touches nothing that was there: the page sections and CTA
 *     bands of the installation are byte-identical after it;
 *   - running it again changes nothing, a banner stored in between included.
 */
#[Group('migration-backfill')]
final class MediaBannerMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_media_banner_fresh';
    private const UPGRADED = 'mygdala_scratch_media_banner_upgraded';

    /** The last migration before the Mediabanner (CTA 2.0). */
    private const BEFORE = '20260926120000';

    private const MIGRATION = '20260926130000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    /** @var list<array<string, mixed>> */
    private static array $bannersBeforeReplay = [];

    /** @var list<array<string, mixed>> */
    private static array $bannersAfterReplay = [];

    private static bool $tableBefore = true;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$tableBefore = self::$upgraded->rows("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'media_banners'")[0]['n'] > 0;
        self::$before = self::untouched(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::untouched(self::$upgraded);

        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO media (path, mime_type, created_at, updated_at) VALUES ('assets/media/zz-banner.mp4', 'video/mp4', NOW(), NOW())");
        $video = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO media_banners (page_slug, section_key, media_id, width, height, video_autoplay, video_controls, created_at, updated_at)
                    VALUES ('zz-banner', 'custom-a', {$video}, 'full', 'xlarge', 1, 0, NOW(), NOW())");
        self::$bannersBeforeReplay = self::$upgraded->rows('SELECT * FROM media_banners ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$bannersAfterReplay = self::$upgraded->rows('SELECT * FROM media_banners ORDER BY id');
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

    public function testTheTableIsNewAndStartsEveryBannerEmptyWithSafeDefaults(): void
    {
        self::assertFalse(self::$tableBefore, 'the migration before it has no such table');

        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO media_banners (page_slug, section_key, created_at, updated_at) VALUES ('zz-banner', 'custom-new', NOW(), NOW())");

        $row = self::$fresh->rows("SELECT media_id, width, height, image_focus, video_autoplay, video_loop, video_controls, poster_media_id, is_active FROM media_banners WHERE section_key = 'custom-new'")[0];
        self::assertSame(
            ['media_id' => null, 'width' => 'content', 'height' => 'medium', 'image_focus' => 'center', 'video_autoplay' => 0, 'video_loop' => 0, 'video_controls' => 1, 'poster_media_id' => null, 'is_active' => 1],
            array_map(static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, $row)
        );
    }

    public function testOneRowPerInstance(): void
    {
        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO media_banners (page_slug, section_key) VALUES ('zz-banner', 'custom-twice')");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO media_banners (page_slug, section_key) VALUES ('zz-banner', 'custom-twice')");
    }

    public function testBothLibraryReferencesAreRestrictingForeignKeys(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame(
                [
                    ['column_name' => 'media_id', 'referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT'],
                    ['column_name' => 'poster_media_id', 'referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT'],
                ],
                $install->rows(
                    "SELECT k.column_name AS column_name, k.referenced_table_name AS referenced_table_name, r.delete_rule AS delete_rule
                       FROM information_schema.key_column_usage k
                       JOIN information_schema.referential_constraints r
                         ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                      WHERE k.table_schema = DATABASE() AND k.table_name = 'media_banners' AND k.referenced_table_name IS NOT NULL
                      ORDER BY k.column_name"
                )
            );
        }

        $this->expectException(\PDOException::class);
        self::$upgraded->pdo()->exec("DELETE FROM media WHERE path = 'assets/media/zz-banner.mp4'");
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
        self::assertNotSame([], self::shape(self::$fresh));
    }

    public function testTheUpgradeTouchesNothingThatWasThere(): void
    {
        self::assertNotSame([], self::$before['page_sections']);
        self::assertSame(self::$before, self::$after);
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertCount(1, self::$bannersBeforeReplay);
        self::assertSame(self::$bannersBeforeReplay, self::$bannersAfterReplay);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function untouched(ScratchInstall $install): array
    {
        return [
            'page_sections' => $install->rows('SELECT * FROM page_sections ORDER BY id'),
            'cta_bands' => $install->rows('SELECT * FROM cta_bands ORDER BY id'),
            'media' => $install->rows('SELECT * FROM media ORDER BY id'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install): array
    {
        return $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'media_banners' ORDER BY ordinal_position"
        );
    }
}
