<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20261001100000_create_page_themes.php (Page Themes 1.0), on a
 * fresh installation and on an upgraded one with pages in it:
 *
 *   - every existing page reads exactly as before, and has no theme
 *     (page_theme_id NULL): nothing looks different after the upgrade;
 *   - page_themes starts empty, with a unique name and a unique slug;
 *   - pages.page_theme_id is a real foreign key, RESTRICT on delete and
 *     CASCADE on update, and the database refuses deleting a theme in use;
 *   - fresh and upgraded end on the same columns, and running it again
 *     changes nothing.
 */
#[Group('migration-backfill')]
final class PageThemesMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_pth_fresh';
    private const UPGRADED = 'mygdala_scratch_pth_upgraded';

    private const BEFORE = '20260930140000';
    private const MIGRATION = '20261001100000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $pagesBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $pagesAfter = [];

    /** @var list<array<string, mixed>> */
    private static array $pagesAfterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        $at = "'2026-09-30 10:00:00'";
        self::$upgraded->pdo()->exec("INSERT INTO pages (content_key, slug, status, is_system, sort_order, created_at, updated_at)
                                      VALUES ('zz-pth-pagina', 'zz-pth-pagina', 'published', 0, 900, {$at}, {$at})");
        self::$pagesBefore = self::$upgraded->rows('SELECT * FROM pages ORDER BY id');
        self::$upgraded->catchUp(self::MIGRATION);
        self::$pagesAfter = self::$upgraded->rows('SELECT * FROM pages ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$pagesAfterReplay = self::$upgraded->rows('SELECT * FROM pages ORDER BY id');
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

    public function testEveryExistingPageReadsAsBeforeWithoutATheme(): void
    {
        self::assertNotSame([], self::$pagesBefore);
        self::assertSame(
            self::$pagesBefore,
            array_map(static fn (array $row): array => array_diff_key($row, ['page_theme_id' => true]), self::$pagesAfter)
        );

        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame(0, $install->count('page_themes'));
            self::assertSame(0, (int) $install->rows('SELECT COUNT(*) AS c FROM pages WHERE page_theme_id IS NOT NULL')[0]['c']);
        }
    }

    public function testTheRelationIsARealRestrictingForeignKey(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            $rules = $install->rows(
                "SELECT constraint_name AS name, referenced_table_name AS target, delete_rule AS on_delete, update_rule AS on_update
                   FROM information_schema.referential_constraints
                  WHERE constraint_schema = DATABASE() AND table_name = 'pages' AND referenced_table_name = 'page_themes'"
            );
            self::assertSame([['name' => 'fk_pages_page_theme', 'target' => 'page_themes', 'on_delete' => 'RESTRICT', 'on_update' => 'CASCADE']], $rules);

            $unique = array_column($install->rows(
                "SELECT index_name AS name FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = 'page_themes' AND non_unique = 0 AND seq_in_index = 1
                  ORDER BY index_name"
            ), 'name');
            self::assertSame(['PRIMARY', 'uq_page_themes_name', 'uq_page_themes_slug'], $unique);
        }

        $pdo = self::$upgraded->pdo();
        $pdo->exec("INSERT INTO page_themes (name, slug, primary_color, on_primary_color, background_color, surface_color, text_color, font_pairing)
                    VALUES ('Proef', 'proef', '#FF7518', '#111111', '#1A0F1F', '#2A1A30', '#F7F1E8', 'system')");
        $themeId = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE pages SET page_theme_id = {$themeId} WHERE content_key = 'zz-pth-pagina'");

        try {
            $pdo->exec("DELETE FROM page_themes WHERE id = {$themeId}");
            self::fail('a theme in use cannot be deleted');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        } finally {
            $pdo->exec("UPDATE pages SET page_theme_id = NULL WHERE content_key = 'zz-pth-pagina'");
            $pdo->exec("DELETE FROM page_themes WHERE id = {$themeId}");
        }
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameColumns(): void
    {
        foreach (['pages', 'page_themes'] as $table) {
            self::assertSame(self::columns(self::$fresh, $table), self::columns(self::$upgraded, $table), $table);
        }

        $themeColumns = array_column(self::columns(self::$fresh, 'page_themes'), 'type', 'name');
        self::assertSame('int unsigned', $themeColumns['id']);
        self::assertSame('char(7)', $themeColumns['primary_color']);
        self::assertSame('varchar(80)', $themeColumns['slug']);
        self::assertSame('int unsigned', array_column(self::columns(self::$fresh, 'pages'), 'type', 'name')['page_theme_id']);
    }

    public function testRunningItAgainChangesNothing(): void
    {
        self::assertSame(self::$pagesAfter, self::$pagesAfterReplay);
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
