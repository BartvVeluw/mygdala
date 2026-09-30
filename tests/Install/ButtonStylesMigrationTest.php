<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20261005100000_create_button_styles.php (Button Styles 2.0),
 * on a fresh installation and on an upgraded one that had chosen the
 * "rounded" button shape and has content blocks with buttons:
 *
 *   - the old setting is taken over: "Primair" and "Secundair" get the
 *     shape the site had, become the two defaults, and the button_shape row
 *     leaves theme_settings (one source of truth);
 *   - every existing block keeps NULL (the default) in every new column, so
 *     every existing button renders as before, and every other column of
 *     those rows is untouched;
 *   - a fresh install gets the same four designs with the shipped shape;
 *   - every choice is a real RESTRICT foreign key;
 *   - fresh and upgraded end on the same columns and indexes, and running it
 *     again changes nothing.
 */
#[Group('migration-backfill')]
final class ButtonStylesMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_buttons_fresh';
    private const UPGRADED = 'mygdala_scratch_buttons_upgraded';

    private const BEFORE = '20261004100000';
    private const MIGRATION = '20261005100000';

    /** Every slot the migration adds (ButtonStyleRepository::slots()). */
    private const SLOTS = [
        'homepage_hero' => ['primary_button_style_id', 'secondary_button_style_id'],
        'cta_bands' => ['primary_button_style_id', 'secondary_button_style_id'],
        'rich_text_sections' => ['button_style_id'],
        'text_image_split_items' => ['button_style_id'],
        'carousel_cards' => ['button_style_id'],
        'hover_card_grid_items' => ['button_style_id'],
        'detail_sections' => ['button_style_id'],
        'contact_cards' => ['button_style_id'],
        'item_galleries' => ['button_style_id'],
        'featured_products' => ['button_style_id'],
    ];

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
        $pdo->exec("INSERT INTO cta_bands (page_slug, section_key, primary_url, primary_link_type, is_active, created_at, updated_at)
                    VALUES ('zz-knoppen', 'cta', '/contact', 'url', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO contact_cards (page_slug, section_key, button_url, is_active, created_at, updated_at)
                    VALUES ('zz-knoppen', 'kaart', '/contact', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO rich_text_sections (page_slug, section_key, button_url, button_link_type, created_at, updated_at)
                    VALUES ('zz-knoppen', 'tekst', '/over', 'url', NOW(), NOW())");

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

    public function testTheOldButtonShapeIsTakenOverByTheDefaults(): void
    {
        $styles = array_column(self::$after['button_styles'], null, 'name');

        self::assertSame('rounded', $styles['Primair']['shape']);
        self::assertSame('rounded', $styles['Secundair']['shape']);
        self::assertSame('pill', $styles['Outline']['shape'], 'the extra designs are not the old setting');
        self::assertSame([
            ['role' => 'primary', 'button_style_id' => $styles['Primair']['id']],
            ['role' => 'secondary', 'button_style_id' => $styles['Secundair']['id']],
        ], self::$after['button_style_defaults']);

        self::assertSame([['setting_key' => 'font_pairing', 'setting_value' => 'poppins-inter']], self::$after['theme_settings'], 'no second copy of the shape');
    }

    public function testEveryExistingBlockKeepsItsButtonAsItWas(): void
    {
        foreach (['cta_bands', 'contact_cards', 'rich_text_sections'] as $table) {
            self::assertCount(1, self::$before[$table], $table);
            foreach (self::$before[$table] as $index => $row) {
                $after = self::$after[$table][$index];
                self::assertSame($row, array_intersect_key($after, $row), $table . ': every column it had');
                foreach (self::SLOTS[$table] as $column) {
                    self::assertNull($after[$column], $table . '.' . $column . ' = the default');
                }
            }
        }
    }

    public function testAFreshInstallGetsTheShippedDesigns(): void
    {
        $styles = self::$fresh->rows('SELECT * FROM button_styles ORDER BY id');

        self::assertSame(['Primair', 'Secundair', 'Outline', 'Tekstlink'], array_column($styles, 'name'));
        self::assertSame('pill', $styles[0]['shape']);
        self::assertSame('1', (string) $styles[0]['fill_gradient']);
        self::assertSame('glow', $styles[0]['hover_effect']);
        self::assertSame(['outline', 'line_strong', 'primary_wash'], [$styles[1]['appearance'], $styles[1]['border_color'], $styles[1]['hover_fill_color']]);
        self::assertSame(['text', 'arrow_right'], [$styles[3]['appearance'], $styles[3]['icon']]);
        self::assertSame(2, self::$fresh->count('button_style_defaults'));
        self::assertSame(0, (int) self::$fresh->rows("SELECT COUNT(*) AS c FROM theme_settings WHERE setting_key = 'button_shape'")[0]['c']);
    }

    public function testEveryChoiceIsARealForeignKey(): void
    {
        $expected = ['fk_button_style_defaults_style' => 'button_style_defaults:RESTRICT'];
        foreach (self::SLOTS as $table => $columns) {
            foreach ($columns as $column) {
                $expected['fk_' . $table . '_' . substr($column, 0, -3)] = $table . ':RESTRICT';
            }
        }
        ksort($expected);

        foreach ([self::$fresh, self::$upgraded] as $install) {
            $rules = [];
            foreach ($install->rows(
                "SELECT table_name AS t, constraint_name AS c, delete_rule AS d FROM information_schema.referential_constraints
                  WHERE constraint_schema = DATABASE() AND referenced_table_name = 'button_styles' ORDER BY constraint_name"
            ) as $row) {
                $rules[$row['c']] = $row['t'] . ':' . $row['d'];
            }

            self::assertSame($expected, $rules);
        }

        $pdo = self::$fresh->pdo();
        $outline = (int) $pdo->query("SELECT id FROM button_styles WHERE name = 'Outline'")->fetchColumn();
        $pdo->exec("INSERT INTO contact_cards (page_slug, section_key, button_style_id, is_active) VALUES ('zz', 'fk', {$outline}, 1)");

        try {
            $pdo->exec("DELETE FROM button_styles WHERE id = {$outline}");
            self::fail('a style a block uses must be refused');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        try {
            $pdo->exec('UPDATE contact_cards SET button_style_id = 999999 WHERE section_key = \'fk\'');
            self::fail('a choice of a style that does not exist must be refused');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        $pdo->exec("DELETE FROM contact_cards WHERE section_key = 'fk'");
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameSchema(): void
    {
        foreach (['button_styles', 'button_style_defaults', ...array_keys(self::SLOTS)] as $table) {
            self::assertSame(self::columns(self::$fresh, $table), self::columns(self::$upgraded, $table), $table);
            self::assertSame(self::indexes(self::$fresh, $table), self::indexes(self::$upgraded, $table), $table);
        }

        $types = array_column(self::columns(self::$fresh, 'cta_bands'), 'type', 'name');
        self::assertSame('int unsigned', $types['primary_button_style_id']);
        self::assertSame('int unsigned', $types['secondary_button_style_id']);
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
            'cta_bands' => $install->rows("SELECT * FROM cta_bands WHERE page_slug = 'zz-knoppen' ORDER BY id"),
            'contact_cards' => $install->rows("SELECT * FROM contact_cards WHERE page_slug = 'zz-knoppen' ORDER BY id"),
            'rich_text_sections' => $install->rows("SELECT * FROM rich_text_sections WHERE page_slug = 'zz-knoppen' ORDER BY id"),
        ];

        if ($install->hasTable('button_styles')) {
            $snapshot['button_styles'] = $install->rows('SELECT * FROM button_styles ORDER BY id');
            $snapshot['button_style_defaults'] = $install->rows('SELECT role, button_style_id FROM button_style_defaults ORDER BY role');
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
