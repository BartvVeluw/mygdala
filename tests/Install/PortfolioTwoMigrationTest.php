<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Portfolio 2.0's two migrations, on the kinds of database they meet:
 *
 *   20260925160000  portfolio_item_images.media_id (RESTRICT)
 *   20260925170000  the Portfolio overview's route_path /portfolio.php -> /portfolio
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before it, with an item that
 *              has an old project page: a slug, has_detail_page and one extra
 *              photo on Portfolio's own path
 *
 * What must hold: the old photo is not pointed at the library and keeps every
 * column byte for byte; a library item a photo uses cannot be deleted;
 * deleting the portfolio item takes its photo rows along but never the
 * library item; the overview page of an existing site moves to the module
 * root and no other page's route moves; and a second run changes nothing.
 */
#[Group('migration-backfill')]
final class PortfolioTwoMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_portfolio_2_fresh';
    private const UPGRADED = 'mygdala_scratch_portfolio_2_upgraded';

    /** The last migration before this one. */
    private const BEFORE = '20260925140000';

    private const PHOTOS = '20260925160000';

    private const ROOT = '20260925170000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, int> */
    private static array $ids = [];

    /** @var array<string, array<string, mixed>> */
    private static array $before = [];

    /** @var array<string, array<string, mixed>> */
    private static array $afterFirstRun = [];

    /** @var array<string, array<string, mixed>> */
    private static array $afterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::ROOT);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$before = self::snapshot(self::$upgraded);

        self::$upgraded->catchUp(self::ROOT);
        self::$afterFirstRun = self::snapshot(self::$upgraded);

        self::$upgraded->replay(self::PHOTOS, self::ROOT);
        self::$upgraded->replay(self::ROOT, self::ROOT);
        self::$afterReplay = self::snapshot(self::$upgraded);
    }

    protected function setUp(): void
    {
        if (self::$fresh === null || self::$upgraded === null) {
            $this->markTestSkipped('scratch databases need DB_ROOT_PASSWORD (TESTING.md)');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$upgraded?->drop();
    }

    public function testAFreshInstallHasTheNullableRestrictedReference(): void
    {
        $this->assertSame(['YES'], self::columnNullability(self::$fresh, 'portfolio_item_images', 'media_id'));
        $this->assertSame('RESTRICT', self::deleteRule(self::$fresh, 'portfolio_item_images', 'media_id'));
    }

    public function testTheOldPhotoIsNotPointedAtTheLibraryAndKeepsEverything(): void
    {
        $this->assertNull(self::$afterFirstRun['photo']['media_id']);

        foreach (self::$before['photo'] as $column => $value) {
            $this->assertSame($value, self::$afterFirstRun['photo'][$column], 'portfolio_item_images.' . $column);
        }

        $this->assertSame(self::$before['item'], self::$afterFirstRun['item'], 'the item row is untouched');
        $this->assertSame(self::$before['words'], self::$afterFirstRun['words'], 'its words are untouched');
    }

    public function testALibraryItemAPhotoUsesCannotBeDeleted(): void
    {
        $pdo = self::$fresh->pdo();
        [$itemId, $mediaId] = self::itemWithLibraryPhoto($pdo);

        try {
            $pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$mediaId]);
            $this->fail('the RESTRICT key must refuse this delete');
        } catch (\PDOException) {
            $this->assertCount(1, self::$fresh->rows('SELECT id FROM media WHERE id = ?', [$mediaId]));
        }

        $pdo->prepare('DELETE FROM portfolio_gallery_items WHERE id = ?')->execute([$itemId]);
        $this->assertSame([], self::$fresh->rows('SELECT id FROM portfolio_item_images WHERE portfolio_item_id = ?', [$itemId]), 'the photo rows go with the item');
        $this->assertCount(1, self::$fresh->rows('SELECT id FROM media WHERE id = ?', [$mediaId]), 'the library item stays');
    }

    public function testTheOverviewMovesToTheModuleRootAndNothingElseMoves(): void
    {
        $this->assertSame('/portfolio.php', self::$before['overview']['route_path'], 'the fixture starts where an existing site stands');
        $this->assertSame('/portfolio', self::$afterFirstRun['overview']['route_path']);
        $this->assertSame(self::$before['other']['route_path'], self::$afterFirstRun['other']['route_path'], 'a page with another route keeps it');

        foreach (['content_key', 'status', 'is_system', 'slug'] as $column) {
            $this->assertSame(self::$before['overview'][$column], self::$afterFirstRun['overview'][$column], 'pages.' . $column);
        }
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->assertSame(self::$afterFirstRun, self::$afterReplay);
        $this->assertSame(1, self::countForeignKeys(self::$upgraded, 'portfolio_item_images', 'media_id'));
    }

    /* ------------------------------------------------------------------ */

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $pdo->exec("INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())");
        $galleryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO portfolio_gallery_items (portfolio_gallery_id, image_path, thumbnail_path, has_detail_page, slug, sort_order, is_active, created_at, updated_at)
             VALUES (?, 'assets/images/sections/zz-pf2-oud.webp', NULL, 1, 'zz-pf2-oud-project', 1, 1, NOW(), NOW())"
        )->execute([$galleryId]);
        self::$ids['item'] = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO portfolio_item_translations (portfolio_item_id, language_code, title, intro, description, created_at, updated_at)
             VALUES (?, 'nl', 'Oud project', '<p>Intro</p>', '<p>Lang verhaal</p>', NOW(), NOW())"
        )->execute([self::$ids['item']]);

        $pdo->prepare(
            "INSERT INTO portfolio_item_images (portfolio_item_id, image_path, thumbnail_path, sort_order, created_at, updated_at)
             VALUES (?, 'assets/images/portfolio/zz-pf2-extra.webp', 'assets/images/portfolio/thumbs/zz-pf2-extra.webp', 0, NOW(), NOW())"
        )->execute([self::$ids['item']]);
        self::$ids['photo'] = (int) $pdo->lastInsertId();

        // The overview page an existing site has, bound to the old template,
        // and a page on another fixed route that must not move.
        $pdo->exec("INSERT INTO pages (content_key, slug, status, is_system, route_path, created_at, updated_at)
                    VALUES ('portfolio', NULL, 'published', 1, '/portfolio.php', NOW(), NOW())");
        self::$ids['overview'] = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO pages (content_key, slug, status, is_system, route_path, created_at, updated_at)
                    VALUES ('zz-pf2-andere-route', NULL, 'published', 1, '/zz-pf2-andere.php', NOW(), NOW())");
        self::$ids['other'] = (int) $pdo->lastInsertId();
    }

    /** @return array{0: int, 1: int} [item id, media id] */
    private static function itemWithLibraryPhoto(\PDO $pdo): array
    {
        $pdo->exec(
            "INSERT INTO media (path, thumbnail_path, original_filename, display_name, mime_type, width, height, file_size, alt_text, checksum, created_at, updated_at)
             VALUES ('assets/media/zz-pf2-bibliotheek.webp', NULL, 'b.jpg', 'b.webp', 'image/webp', 10, 10, 100, '', NULL, NOW(), NOW())"
        );
        $mediaId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())");
        $galleryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO portfolio_gallery_items (portfolio_gallery_id, image_path, sort_order, is_active, created_at, updated_at)
             VALUES (?, 'assets/media/zz-pf2-hoofd.webp', 1, 1, NOW(), NOW())"
        )->execute([$galleryId]);
        $itemId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO portfolio_item_images (portfolio_item_id, media_id, image_path, sort_order, created_at, updated_at)
             VALUES (?, ?, 'assets/media/zz-pf2-bibliotheek.webp', 0, NOW(), NOW())"
        )->execute([$itemId, $mediaId]);

        return [$itemId, $mediaId];
    }

    /** @return array<string, array<string, mixed>> */
    private static function snapshot(ScratchInstall $install): array
    {
        $one = static fn (string $sql, int $id): array => $install->rows($sql, [$id])[0] ?? [];

        return [
            'item' => $one('SELECT * FROM portfolio_gallery_items WHERE id = ?', self::$ids['item']),
            'words' => $one('SELECT title, intro, description FROM portfolio_item_translations WHERE portfolio_item_id = ?', self::$ids['item']),
            'photo' => $one('SELECT * FROM portfolio_item_images WHERE id = ?', self::$ids['photo']),
            'overview' => $one('SELECT content_key, slug, status, is_system, route_path FROM pages WHERE id = ?', self::$ids['overview']),
            'other' => $one('SELECT content_key, route_path FROM pages WHERE id = ?', self::$ids['other']),
        ];
    }

    /** @return list<string> */
    private static function columnNullability(ScratchInstall $install, string $table, string $column): array
    {
        return array_column($install->rows(
            'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        ), 'IS_NULLABLE');
    }

    private static function deleteRule(ScratchInstall $install, string $table, string $column): string
    {
        $rows = $install->rows(
            'SELECT rc.DELETE_RULE
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints rc
                 ON rc.constraint_schema = k.constraint_schema AND rc.constraint_name = k.constraint_name
              WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.column_name = ? AND k.referenced_table_name IS NOT NULL',
            [$table, $column]
        );

        return (string) ($rows[0]['DELETE_RULE'] ?? '');
    }

    private static function countForeignKeys(ScratchInstall $install, string $table, string $column): int
    {
        return count($install->rows(
            'SELECT constraint_name FROM information_schema.key_column_usage
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND referenced_table_name IS NOT NULL',
            [$table, $column]
        ));
    }
}
