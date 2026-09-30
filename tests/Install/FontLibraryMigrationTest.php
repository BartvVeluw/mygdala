<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20261004100000_create_font_library.php (Font Library 1.0),
 * on a fresh installation and on an upgraded one that had chosen its own
 * font pairing, button shape, palette and page theme:
 *
 *   - the upgraded site keeps every font it had: theme_settings, the colour
 *     palettes and each page theme's font_pairing are byte for byte what
 *     they were; no role is chosen and the library is empty, so no uploaded
 *     font is ever switched on by the upgrade;
 *   - a fresh install gets the same tables, empty;
 *   - every use is a real foreign key: RESTRICT for the website's roles and
 *     a page theme, CASCADE for a family's own files;
 *   - fresh and upgraded end on the same columns and indexes, and running
 *     it again changes nothing.
 */
#[Group('migration-backfill')]
final class FontLibraryMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_fonts_fresh';
    private const UPGRADED = 'mygdala_scratch_fonts_upgraded';

    private const BEFORE = '20261003100000';
    private const MIGRATION = '20261004100000';

    private const TABLES = ['font_families', 'font_files', 'theme_font_roles', 'page_themes'];

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
        $pdo = self::$upgraded->pdo();
        $pdo->exec('DELETE FROM theme_settings');
        $insert = $pdo->prepare('INSERT INTO theme_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
        $insert->execute(['font_pairing', 'poppins-inter']);
        $insert->execute(['button_shape', 'rounded']);
        $pdo->exec("UPDATE color_palettes SET primary_color = '#2B6CB0'");
        $pdo->exec("INSERT INTO page_themes (name, slug, primary_color, on_primary_color, background_color, surface_color, text_color, font_pairing, created_at, updated_at)
                    VALUES ('Actie', 'actie', '#FF7518', '#111111', '#1A0F1F', '#2A1A30', '#F7F1E8', 'lora-montserrat', NOW(), NOW())");

        self::$before = self::snapshot(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::snapshot(self::$upgraded);
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$afterReplay = self::snapshot(self::$upgraded);
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

    public function testTheUpgradedSiteKeepsEveryFontItHad(): void
    {
        self::assertSame(self::$before['theme_settings'], self::$after['theme_settings']);
        self::assertSame(self::$before['color_palettes'], self::$after['color_palettes']);

        // The page theme: every column it had, unchanged; the two new ones NULL.
        foreach (self::$before['page_themes'] as $index => $row) {
            $after = self::$after['page_themes'][$index];
            self::assertSame($row, array_intersect_key($after, $row));
            self::assertNull($after['heading_font_family_id']);
            self::assertNull($after['body_font_family_id']);
        }
        self::assertSame('lora-montserrat', self::$after['page_themes'][0]['font_pairing']);
    }

    public function testNoUploadedFontIsSwitchedOn(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame(0, $install->count('font_families'));
            self::assertSame(0, $install->count('font_files'));
            self::assertSame(0, $install->count('theme_font_roles'));
        }
    }

    public function testEveryUseIsARealForeignKey(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            $rules = [];
            foreach ($install->rows(
                "SELECT table_name AS t, constraint_name AS c, delete_rule AS d FROM information_schema.referential_constraints
                  WHERE constraint_schema = DATABASE() AND referenced_table_name = 'font_families' ORDER BY constraint_name"
            ) as $row) {
                $rules[$row['c']] = $row['t'] . ':' . $row['d'];
            }

            self::assertSame([
                'fk_font_files_family' => 'font_files:CASCADE',
                'fk_page_themes_body_font' => 'page_themes:RESTRICT',
                'fk_page_themes_heading_font' => 'page_themes:RESTRICT',
                'fk_theme_font_roles_family' => 'theme_font_roles:RESTRICT',
            ], $rules);
        }

        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO font_families (name, category) VALUES ('Proef', 'sans')");
        $id = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO font_files (font_family_id, weight, style, format, file_name, original_filename, byte_size)
                    VALUES ({$id}, 400, 'normal', 'woff2', '" . str_repeat('a', 32) . ".woff2', 'a.woff2', 10)");
        $pdo->exec("INSERT INTO theme_font_roles (role, font_family_id) VALUES ('body', {$id})");

        try {
            $pdo->exec("DELETE FROM font_families WHERE id = {$id}");
            self::fail('a family the website uses must be refused');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        try {
            $pdo->exec("INSERT INTO font_files (font_family_id, weight, style, format, file_name, original_filename, byte_size)
                        VALUES ({$id}, 400, 'normal', 'ttf', '" . str_repeat('b', 32) . ".ttf', 'b.ttf', 10)");
            self::fail('one file per weight and style');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        $pdo->exec('DELETE FROM theme_font_roles');
        $pdo->exec("DELETE FROM font_families WHERE id = {$id}");
        self::assertSame(0, self::$fresh->count('font_files'), 'a family\'s files go with it');
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameSchema(): void
    {
        foreach (self::TABLES as $table) {
            self::assertSame(self::columns(self::$fresh, $table), self::columns(self::$upgraded, $table), $table);
            self::assertSame(self::indexes(self::$fresh, $table), self::indexes(self::$upgraded, $table), $table);
        }

        $types = array_column(self::columns(self::$fresh, 'font_files'), 'type', 'name');
        self::assertSame('int unsigned', $types['font_family_id']);
        self::assertSame('smallint unsigned', $types['weight']);
        self::assertSame('varchar(40)', $types['file_name']);

        self::assertSame(
            ['PRIMARY', 'uq_font_files_file_name', 'uq_font_files_variant'],
            array_values(array_unique(array_column(array_filter(self::indexes(self::$fresh, 'font_files'), static fn (array $i): bool => (int) $i['non_unique'] === 0), 'name')))
        );
    }

    public function testRunningItAgainChangesNothing(): void
    {
        self::assertSame(self::$after, self::$afterReplay);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function snapshot(ScratchInstall $install): array
    {
        $snapshot = [
            'theme_settings' => $install->rows('SELECT setting_key, setting_value FROM theme_settings ORDER BY setting_key'),
            'color_palettes' => $install->rows('SELECT * FROM color_palettes ORDER BY id'),
            'page_themes' => $install->rows('SELECT * FROM page_themes ORDER BY id'),
        ];

        if ($install->hasTable('font_families')) {
            $snapshot['font_families'] = $install->rows('SELECT * FROM font_families ORDER BY id');
            $snapshot['theme_font_roles'] = $install->rows('SELECT * FROM theme_font_roles ORDER BY role');
        }

        return $snapshot;
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

    /** @return list<array<string, mixed>> */
    private static function indexes(ScratchInstall $install, string $table): array
    {
        return $install->rows(
            'SELECT index_name AS name, non_unique AS non_unique, seq_in_index AS seq, column_name AS col
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ?
              ORDER BY index_name, seq_in_index',
            [$table]
        );
    }
}
