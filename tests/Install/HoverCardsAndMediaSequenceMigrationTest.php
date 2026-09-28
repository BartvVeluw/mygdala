<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The two migrations of this content-blocks series after Uitgelicht product,
 * on a database built from zero and on one upgraded from the migration before
 * them:
 *
 *   20260928170000  the Hover kaarten grid: hover_card_grids (one row per
 *                   instance, every choice at its default) and
 *                   hover_card_grid_items (cascading from the grid, both
 *                   pictures RESTRICT);
 *   20260928180000  the media sequence: slide_transition and slide_duration
 *                   on page_heroes and media_banners, slide_controls on
 *                   media_banners, and the child tables page_hero_images and
 *                   media_banner_items (cascading from their block, the item
 *                   RESTRICT).
 *
 *   - a fresh install and an upgrade end on the same schema;
 *   - the upgrade keeps every existing header and banner exactly as it was —
 *     one picture, every old column the same — with the new choices at their
 *     defaults and no further items;
 *   - running both again changes nothing, rows stored in between included;
 *   - deleting a block takes its further items along and never a library item.
 */
#[Group('migration-backfill')]
final class HoverCardsAndMediaSequenceMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_hover_sequence_fresh';
    private const UPGRADED = 'mygdala_scratch_hover_sequence_upgraded';

    /** The last migration before these two (Uitgelicht product). */
    private const BEFORE = '20260928160000';

    private const HOVER_CARDS = '20260928170000';

    private const SEQUENCE = '20260928180000';

    private const TABLES = ['hover_card_grids', 'hover_card_grid_items', 'page_heroes', 'page_hero_images', 'media_banners', 'media_banner_items'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, mixed> */
    private static array $heroBefore = [];

    /** @var array<string, mixed> */
    private static array $heroAfter = [];

    /** @var array<string, mixed> */
    private static array $bannerBefore = [];

    /** @var array<string, mixed> */
    private static array $bannerAfter = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $untouchedBefore = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $untouchedAfter = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $beforeReplay = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $afterReplay = [];

    /** @var array<string, bool> */
    private static array $tablesBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::SEQUENCE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        foreach (['hover_card_grids', 'hover_card_grid_items', 'page_hero_images', 'media_banner_items'] as $table) {
            self::$tablesBefore[$table] = self::$upgraded->hasTable($table);
        }

        // An installation's own header and banner, as they stood before.
        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO media (path, mime_type, alt_text, created_at, updated_at) VALUES ('assets/media/zz-hero.jpg', 'image/jpeg', 'Werkplaats', NOW(), NOW())");
        $picture = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO media (path, mime_type, created_at, updated_at) VALUES ('assets/media/zz-banner.mp4', 'video/mp4', NOW(), NOW())");
        $video = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO page_heroes (page_slug, media_id, content_position, title_size, text_size, image_mode, hero_height, image_focus, is_active, created_at, updated_at)
                    VALUES ('zz-hero', {$picture}, 'center', 'large', 'small', 'background', 'large', 'top', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO media_banners (page_slug, section_key, media_id, width, height, video_autoplay, video_loop, video_controls, created_at, updated_at)
                    VALUES ('zz-banner', 'custom-a', {$video}, 'full', 'xlarge', 1, 1, 0, NOW(), NOW())");

        self::$heroBefore = self::$upgraded->rows("SELECT * FROM page_heroes WHERE page_slug = 'zz-hero'")[0];
        self::$bannerBefore = self::$upgraded->rows("SELECT * FROM media_banners WHERE page_slug = 'zz-banner'")[0];
        self::$untouchedBefore = self::untouched(self::$upgraded);

        self::$upgraded->catchUp(self::SEQUENCE);

        self::$heroAfter = self::$upgraded->rows("SELECT * FROM page_heroes WHERE page_slug = 'zz-hero'")[0];
        self::$bannerAfter = self::$upgraded->rows("SELECT * FROM media_banners WHERE page_slug = 'zz-banner'")[0];
        self::$untouchedAfter = self::untouched(self::$upgraded);

        // Rows written in between, then both migrations once more.
        $heroId = (int) self::$heroAfter['id'];
        $bannerId = (int) self::$bannerAfter['id'];
        $pdo->exec("INSERT INTO page_hero_images (page_hero_id, media_id, sort_order) VALUES ({$heroId}, {$picture}, 0)");
        $pdo->exec("INSERT INTO media_banner_items (media_banner_id, media_id, sort_order) VALUES ({$bannerId}, {$picture}, 0)");
        $pdo->exec("UPDATE media_banners SET slide_transition = 'slide', slide_duration = 8, slide_controls = 'dots' WHERE id = {$bannerId}");
        $pdo->exec("INSERT INTO hover_card_grids (page_slug, section_key, layout, shape, created_at, updated_at) VALUES ('zz-cards', 'custom-b', 'open', 'organic', NOW(), NOW())");
        $gridId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO hover_card_grid_items (hover_card_grid_id, media_id, hover_media_id, link_type, link_url, sort_order) VALUES ({$gridId}, {$picture}, {$picture}, 'url', '/x', 0)");

        self::$beforeReplay = self::contentOf(self::$upgraded);
        self::$upgraded->replay(self::HOVER_CARDS, self::SEQUENCE);
        self::$upgraded->replay(self::SEQUENCE, self::SEQUENCE);
        self::$afterReplay = self::contentOf(self::$upgraded);
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

    public function testTheNewTablesDidNotExistBefore(): void
    {
        self::assertSame(['hover_card_grids' => false, 'hover_card_grid_items' => false, 'page_hero_images' => false, 'media_banner_items' => false], self::$tablesBefore);
    }

    public function testANewGridStartsWithEveryChoiceAtItsDefault(): void
    {
        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO hover_card_grids (page_slug, section_key) VALUES ('zz-cards', 'custom-new')");
        $gridId = (int) $pdo->lastInsertId();

        $row = self::$fresh->rows("SELECT layout, shape, columns, overlay, effect, header_align, is_active FROM hover_card_grids WHERE id = {$gridId}")[0];
        $row['is_active'] = (int) $row['is_active'];
        self::assertSame(
            ['layout' => 'overlay', 'shape' => 'rounded', 'columns' => '3', 'overlay' => 'medium', 'effect' => 'normal', 'header_align' => 'left', 'is_active' => 1],
            $row
        );

        // A card can be written with its grid alone (Tests\Service\BlockTranslationIntegrityTest does).
        $pdo->exec("INSERT INTO hover_card_grid_items (hover_card_grid_id) VALUES ({$gridId})");
        self::assertSame(1, (int) self::$fresh->rows("SELECT COUNT(*) AS n FROM hover_card_grid_items WHERE hover_card_grid_id = {$gridId}")[0]['n']);

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO hover_card_grids (page_slug, section_key) VALUES ('zz-cards', 'custom-new')");
    }

    public function testEveryReferenceIsTheRightKindOfForeignKey(): void
    {
        $expected = [
            'hover_card_grid_items' => [
                ['column_name' => 'hover_card_grid_id', 'referenced_table_name' => 'hover_card_grids', 'delete_rule' => 'CASCADE'],
                ['column_name' => 'hover_media_id', 'referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT'],
                ['column_name' => 'media_id', 'referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT'],
            ],
            'page_hero_images' => [
                ['column_name' => 'media_id', 'referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT'],
                ['column_name' => 'page_hero_id', 'referenced_table_name' => 'page_heroes', 'delete_rule' => 'CASCADE'],
            ],
            'media_banner_items' => [
                ['column_name' => 'media_banner_id', 'referenced_table_name' => 'media_banners', 'delete_rule' => 'CASCADE'],
                ['column_name' => 'media_id', 'referenced_table_name' => 'media', 'delete_rule' => 'RESTRICT'],
            ],
        ];

        foreach ([self::$fresh, self::$upgraded] as $install) {
            foreach ($expected as $table => $keys) {
                self::assertSame($keys, $install->rows(
                    "SELECT k.column_name AS column_name, k.referenced_table_name AS referenced_table_name, r.delete_rule AS delete_rule
                       FROM information_schema.key_column_usage k
                       JOIN information_schema.referential_constraints r
                         ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                      WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.referenced_table_name IS NOT NULL
                      ORDER BY k.column_name",
                    [$table]
                ), $table);
            }
        }
    }

    public function testAFreshInstallAndAnUpgradeEndOnTheSameSchema(): void
    {
        foreach (self::TABLES as $table) {
            self::assertNotSame([], self::shape(self::$fresh, $table), $table);
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }
    }

    public function testAnExistingHeaderAndBannerStayExactlyAsTheyWere(): void
    {
        foreach (self::$heroBefore as $column => $value) {
            self::assertSame($value, self::$heroAfter[$column], 'page_heroes.' . $column);
        }
        self::assertSame(['fade', 5], [self::$heroAfter['slide_transition'], (int) self::$heroAfter['slide_duration']]);

        foreach (self::$bannerBefore as $column => $value) {
            self::assertSame($value, self::$bannerAfter[$column], 'media_banners.' . $column);
        }
        self::assertSame(['fade', 5, 'both'], [self::$bannerAfter['slide_transition'], (int) self::$bannerAfter['slide_duration'], self::$bannerAfter['slide_controls']]);

        self::assertSame(self::$untouchedBefore, self::$untouchedAfter, 'page sections and the library as they were');
    }

    public function testRunningBothAgainChangesNothing(): void
    {
        self::assertCount(1, self::$beforeReplay['hover_card_grid_items']);
        self::assertCount(1, self::$beforeReplay['page_hero_images']);
        self::assertSame(self::$beforeReplay, self::$afterReplay);
    }

    public function testDeletingABlockTakesItsFurtherItemsButNeverALibraryItem(): void
    {
        $pdo = self::$upgraded->pdo();
        $pdo->exec("DELETE FROM page_heroes WHERE page_slug = 'zz-hero'");
        $pdo->exec("DELETE FROM media_banners WHERE page_slug = 'zz-banner'");
        $pdo->exec("DELETE FROM hover_card_grids WHERE page_slug = 'zz-cards'");

        self::assertSame(0, self::$upgraded->count('page_hero_images'));
        self::assertSame(0, self::$upgraded->count('media_banner_items'));
        self::assertSame(0, self::$upgraded->count('hover_card_grid_items'));
        self::assertSame(1, (int) self::$upgraded->rows("SELECT COUNT(*) AS n FROM media WHERE path = 'assets/media/zz-hero.jpg'")[0]['n'], 'the picture stays in the library');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function untouched(ScratchInstall $install): array
    {
        return [
            'page_sections' => $install->rows('SELECT * FROM page_sections ORDER BY id'),
            'media' => $install->rows('SELECT * FROM media ORDER BY id'),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function contentOf(ScratchInstall $install): array
    {
        $content = [];
        foreach (self::TABLES as $table) {
            $content[$table] = $install->rows("SELECT * FROM `{$table}` ORDER BY id");
        }

        return $content;
    }

    /** @return list<array<string, mixed>> */
    private static function shape(ScratchInstall $install, string $table): array
    {
        return $install->rows(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS `default` FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table]
        );
    }
}
