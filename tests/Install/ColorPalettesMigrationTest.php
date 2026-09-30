<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20261003100000_create_color_palettes.php (Branding & Design
 * 2.0), on a fresh installation and on an upgraded one that had chosen its
 * own colours:
 *
 *   - the upgraded site gets ONE palette, "Standaard", active, holding the
 *     colours it showed: each stored colour normalised like ThemeSettings
 *     does (#abc, lowercase, no hash), the shipped default for a colour that
 *     was never chosen or no longer validates;
 *   - the colour rows leave theme_settings (one source of truth), the font
 *     pairing and the button shape stay;
 *   - a fresh install gets "Standaard" with the shipped default;
 *   - "at most one active" is a unique index the database enforces;
 *   - fresh and upgraded end on the same columns and indexes, and running it
 *     again changes nothing.
 */
#[Group('migration-backfill')]
final class ColorPalettesMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_pal_fresh';
    private const UPGRADED = 'mygdala_scratch_pal_upgraded';

    private const BEFORE = '20261002100000';
    private const MIGRATION = '20261003100000';

    private const DEFAULTS = [
        'primary_color' => '#C9A063',
        'on_primary_color' => '#1B140D',
        'background_color' => '#120D09',
        'surface_color' => '#1C150E',
        'text_color' => '#F5EFE4',
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $palettesAfter = [];

    /** @var list<array<string, mixed>> */
    private static array $palettesAfterReplay = [];

    /** @var list<array<string, mixed>> */
    private static array $settingsAfterReplay = [];

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
        foreach ([
            'primary_color' => '2b6cb0',         // no hash, lowercase
            'background_color' => '#fff',        // three digits
            'surface_color' => 'red;}body{x:y',  // a hand edit that no longer validates
            'text_color' => '#1A202C',
            'font_pairing' => 'poppins-inter',
            'button_shape' => 'rounded',
        ] as $key => $value) {
            $insert->execute([$key, $value]);
        }

        self::$upgraded->catchUp(self::MIGRATION);
        self::$palettesAfter = self::$upgraded->rows('SELECT * FROM color_palettes ORDER BY id');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$palettesAfterReplay = self::$upgraded->rows('SELECT * FROM color_palettes ORDER BY id');
        self::$settingsAfterReplay = self::$upgraded->rows('SELECT setting_key, setting_value FROM theme_settings ORDER BY setting_key');
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

    public function testTheUpgradedSiteKeepsTheColoursItShowedInOneActivePalette(): void
    {
        self::assertCount(1, self::$palettesAfter);
        $palette = self::$palettesAfter[0];

        self::assertSame('Standaard', $palette['name']);
        self::assertSame(1, (int) $palette['is_active']);
        self::assertSame('#2B6CB0', $palette['primary_color']);
        self::assertSame(self::DEFAULTS['on_primary_color'], $palette['on_primary_color'], 'never chosen: the shipped default');
        self::assertSame('#FFFFFF', $palette['background_color']);
        self::assertSame(self::DEFAULTS['surface_color'], $palette['surface_color'], 'no longer valid: the default the site showed');
        self::assertSame('#1A202C', $palette['text_color']);
    }

    public function testTheColourRowsLeaveTheThemeTableAndTheRestStays(): void
    {
        self::assertSame(
            [
                ['setting_key' => 'button_shape', 'setting_value' => 'rounded'],
                ['setting_key' => 'font_pairing', 'setting_value' => 'poppins-inter'],
            ],
            self::$settingsAfterReplay
        );
        self::assertSame([], self::$fresh->rows(
            "SELECT setting_key FROM theme_settings WHERE setting_key LIKE '%\\_color'"
        ));
    }

    public function testAFreshInstallStartsWithTheShippedDefaultAsItsOnlyPalette(): void
    {
        $palettes = self::$fresh->rows('SELECT name, primary_color, on_primary_color, background_color, surface_color, text_color, is_active FROM color_palettes');

        self::assertSame([['name' => 'Standaard'] + self::DEFAULTS + ['is_active' => 1]], array_map(
            static function (array $row): array {
                $row['is_active'] = (int) $row['is_active'];

                return $row;
            },
            $palettes
        ));
    }

    public function testTheDatabaseHoldsAtMostOneActivePalette(): void
    {
        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO color_palettes (name, primary_color, on_primary_color, background_color, surface_color, text_color, is_active)
                    VALUES ('Tweede', '#FF7518', '#111111', '#1A0F1F', '#2A1A30', '#F7F1E8', NULL)");
        $id = (int) $pdo->lastInsertId();

        try {
            $pdo->exec("UPDATE color_palettes SET is_active = 1 WHERE id = {$id}");
            self::fail('a second active palette must be refused by the unique index');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        } finally {
            $pdo->exec("DELETE FROM color_palettes WHERE id = {$id}");
        }

        foreach ([self::$fresh, self::$upgraded] as $install) {
            $unique = array_column($install->rows(
                "SELECT index_name AS name FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = 'color_palettes' AND non_unique = 0 AND seq_in_index = 1
                  ORDER BY index_name"
            ), 'name');
            self::assertSame(['PRIMARY', 'uq_color_palettes_active', 'uq_color_palettes_name'], $unique);
        }
    }

    public function testTheFreshAndTheUpgradedInstallationEndOnTheSameColumns(): void
    {
        self::assertSame(self::columns(self::$fresh, 'color_palettes'), self::columns(self::$upgraded, 'color_palettes'));

        $types = array_column(self::columns(self::$fresh, 'color_palettes'), 'type', 'name');
        self::assertSame('int unsigned', $types['id']);
        self::assertSame('varchar(80)', $types['name']);
        self::assertSame('char(7)', $types['text_color']);
        self::assertSame('tinyint(1)', $types['is_active']);
    }

    public function testRunningItAgainChangesNothing(): void
    {
        self::assertSame(self::$palettesAfter, self::$palettesAfterReplay);
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
