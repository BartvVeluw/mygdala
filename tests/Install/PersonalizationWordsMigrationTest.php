<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 5 wave D on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md, MODULES.md "Personalisatie"):
 *
 *   20260918240000_create_the_personalization_translation_tables.php
 *   20260918250000_move_personalization_words_into_translation_tables.php
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before wave D, with settings,
 *              views and zones in every state their columns could be in
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: Dutch stays Dutch and English stays English, byte for byte;
 * an empty or whitespace value gets no row; three fields of one zone share one
 * row per language; and above all THE CONFIGURATION DOES NOT MOVE — every
 * `view_key` and `zone_key` (the keys an order line points at), the geometry,
 * the switches, the surcharge, the preview image and every sort order keep
 * their exact values. A second run changes nothing, and words that cannot be
 * moved stop the migration before any column is dropped.
 *
 * NOTHING HERE ASKS WHETHER PERSONALISATIE IS ENABLED. A scratch install runs
 * with no MODULE_* variable set at all, so the module is off, and both
 * databases must still end on the same schema with every word moved.
 */
#[Group('migration-backfill')]
final class PersonalizationWordsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_pz_words_fresh';
    private const UPGRADED = 'mygdala_scratch_pz_words_upgraded';
    private const BROKEN = 'mygdala_scratch_pz_words_broken';

    /** The last migration before wave D. */
    private const BEFORE = '20260918230000';

    private const SCHEMA = '20260918240000';
    private const MOVE = '20260918250000';

    /** Everything that decides what a customer may DO. None of it may move. */
    private const NEUTRAL_SETTINGS_COLUMNS = 'id, product_id, is_enabled, personalization_mode';
    private const NEUTRAL_VIEW_COLUMNS = 'id, settings_id, view_key, preview_image_path, sort_order';
    private const NEUTRAL_ZONE_COLUMNS = 'id, settings_id, view_id, zone_key, allow_text, allow_image, '
        . 'is_enabled, is_required, allow_rotation, max_text_length, surcharge, '
        . 'area_x, area_y, area_width, area_height, sort_order';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $neutralBefore = [];

    /** @var array<string, int> */
    private static array $ids = [];

    private static int $rowsAfterFirstRun = 0;
    private static int $rowsAfterReplay = 0;

    private static ?string $brokenFailure = null;

    /** @var list<string> */
    private static array $brokenColumnsAfterFailure = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MOVE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$ids = self::seed(self::$upgraded);
        self::$neutralBefore['product_personalization_settings'] = self::$upgraded->rows(
            'SELECT ' . self::NEUTRAL_SETTINGS_COLUMNS . ' FROM product_personalization_settings ORDER BY id'
        );
        self::$neutralBefore['product_personalization_views'] = self::$upgraded->rows(
            'SELECT ' . self::NEUTRAL_VIEW_COLUMNS . ' FROM product_personalization_views ORDER BY id'
        );
        self::$neutralBefore['product_personalization_zones'] = self::$upgraded->rows(
            'SELECT ' . self::NEUTRAL_ZONE_COLUMNS . ' FROM product_personalization_zones ORDER BY id'
        );
        self::$upgraded->catchUp(self::MOVE);
        self::$rowsAfterFirstRun = self::rowCount(self::$upgraded);
        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$rowsAfterReplay = self::rowCount(self::$upgraded);

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        // Every typed translation table this installation has by now, asked of
        // the schema rather than listed, so a later wave cannot forget one.
        foreach ($broken->rows(
            "SELECT table_name AS name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name LIKE '%\\_translations'"
        ) as $row) {
            $broken->pdo()->exec('DELETE FROM `' . $row['name'] . "` WHERE language_code = 'en'");
        }
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = array_column(
            $broken->rows('SHOW COLUMNS FROM product_personalization_zones'),
            'Field'
        );
        $broken->drop();
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

    // ------------------------------------------------------------ the schema

    public function testBothDatabasesEndWithTheSameSchemaAndNoWordColumnsLeft(): void
    {
        foreach ([
            'product_personalization_settings',
            'product_personalization_views',
            'product_personalization_zones',
            'product_personalization_translations',
            'product_personalization_view_translations',
            'product_personalization_zone_translations',
        ] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        foreach ([
            'product_personalization_settings' => ['instructions', 'instructions_en'],
            'product_personalization_views' => ['label', 'label_en'],
            'product_personalization_zones' => ['label', 'label_en', 'instructions', 'instructions_en', 'placeholder', 'placeholder_en'],
        ] as $table => $gone) {
            $columns = array_keys(self::shape(self::$fresh, $table));

            foreach ($gone as $column) {
                self::assertNotContains($column, $columns, $table . '.' . $column . ' should be gone');
            }
        }
    }

    /**
     * THE KEYS STAYED, and that is what keeps a historical order readable: an
     * order line records `view_key` and `zone_key`, so neither may ever become
     * a word or change value.
     */
    public function testEveryViewAndZoneKeptItsKey(): void
    {
        self::assertArrayHasKey('view_key', self::shape(self::$fresh, 'product_personalization_views'));
        self::assertArrayHasKey('zone_key', self::shape(self::$fresh, 'product_personalization_zones'));

        foreach ([
            'product_personalization_view_translations',
            'product_personalization_zone_translations',
        ] as $table) {
            $columns = array_keys(self::shape(self::$fresh, $table));

            self::assertNotContains('view_key', $columns, $table);
            self::assertNotContains('zone_key', $columns, $table);
        }
    }

    public function testEachTableHasItsOwnerLanguageUniqueAndItsTwoForeignKeys(): void
    {
        foreach ([
            'product_personalization_translations' => ['settings_id', 'product_personalization_settings'],
            'product_personalization_view_translations' => ['view_id', 'product_personalization_views'],
            'product_personalization_zone_translations' => ['zone_id', 'product_personalization_zones'],
        ] as $table => [$ownerColumn, $ownerTable]) {
            $unique = self::$fresh->rows(
                "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_index
                   FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 AND index_name <> 'PRIMARY'
                  GROUP BY index_name",
                [$table]
            );
            self::assertSame([['columns_in_index' => $ownerColumn . ',language_code']], $unique, $table);

            $keys = self::$fresh->rows(
                "SELECT k.column_name AS own, k.referenced_table_name AS points_at, r.delete_rule AS on_delete
                   FROM information_schema.key_column_usage k
                   JOIN information_schema.referential_constraints r
                     ON r.constraint_name = k.constraint_name AND r.constraint_schema = k.constraint_schema
                  WHERE k.table_schema = DATABASE() AND k.table_name = ?
                  ORDER BY k.column_name",
                [$table]
            );

            self::assertEqualsCanonicalizing(
                [
                    ['own' => 'language_code', 'points_at' => 'site_languages', 'on_delete' => 'RESTRICT'],
                    ['own' => $ownerColumn, 'points_at' => $ownerTable, 'on_delete' => 'CASCADE'],
                ],
                $keys,
                $table . ': the owner cascades, the language is refused'
            );
        }
    }

    // ------------------------------------------------------------- the words

    public function testAZoneKeepsEveryFieldPerLanguageInOneRow(): void
    {
        self::assertSame(
            [
                [
                    'language_code' => 'en',
                    'label' => 'Name',
                    'instructions' => 'Up to 30 characters.',
                    'placeholder' => null,
                ],
                [
                    'language_code' => 'nl',
                    'label' => 'Naam',
                    'instructions' => 'Maximaal 30 tekens.',
                    'placeholder' => 'Bijv. Bart',
                ],
            ],
            self::$upgraded->rows(
                'SELECT language_code, label, instructions, placeholder
                   FROM product_personalization_zone_translations WHERE zone_id = ? ORDER BY language_code',
                [self::$ids['zone']]
            ),
            'three fields of one zone share one row per language, whitespace excluded'
        );
    }

    public function testAViewAndTheSettingsKeepTheirOneField(): void
    {
        self::assertSame(
            [
                ['language_code' => 'en', 'label' => 'Front'],
                ['language_code' => 'nl', 'label' => 'Voorkant'],
            ],
            self::$upgraded->rows(
                'SELECT language_code, label FROM product_personalization_view_translations
                  WHERE view_id = ? ORDER BY language_code',
                [self::$ids['view']]
            )
        );

        self::assertSame(
            [['language_code' => 'nl', 'instructions' => 'Personaliseer dit product met een naam.']],
            self::$upgraded->rows(
                'SELECT language_code, instructions FROM product_personalization_translations
                  WHERE settings_id = ? ORDER BY language_code',
                [self::$ids['settings']]
            ),
            'whitespace is no translation'
        );
    }

    /** A zone with nothing said about it in any language has no row at all. */
    public function testAWordlessZoneHasNoRow(): void
    {
        self::assertSame(
            [],
            self::$upgraded->rows(
                'SELECT language_code FROM product_personalization_zone_translations WHERE zone_id = ?',
                [self::$ids['bare_zone']]
            )
        );
    }

    /** A value is copied, never trimmed or re-encoded on the way. */
    public function testWordsAreCopiedByteForByte(): void
    {
        self::assertSame(
            [['label' => " Ruimte  eromheen \u{00a0}", 'instructions' => 'Ampersand &amp; "quotes"']],
            self::$upgraded->rows(
                "SELECT label, instructions FROM product_personalization_zone_translations
                  WHERE zone_id = ? AND language_code = 'nl'",
                [self::$ids['exotic_zone']]
            )
        );
    }

    // ------------------------------------------------- what must not change

    /**
     * The assertion this wave is judged by: not one key, coordinate, switch,
     * surcharge, preview image or sort order changed.
     */
    public function testTheConfigurationItselfIsUntouched(): void
    {
        foreach ([
            'product_personalization_settings' => self::NEUTRAL_SETTINGS_COLUMNS,
            'product_personalization_views' => self::NEUTRAL_VIEW_COLUMNS,
            'product_personalization_zones' => self::NEUTRAL_ZONE_COLUMNS,
        ] as $table => $columns) {
            self::assertSame(
                self::$neutralBefore[$table],
                self::$upgraded->rows('SELECT ' . $columns . ' FROM ' . $table . ' ORDER BY id'),
                $table . ': keys, geometry, switches, surcharges and orders are untouched'
            );
        }
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertGreaterThan(0, self::$rowsAfterFirstRun);
        self::assertSame(self::$rowsAfterFirstRun, self::$rowsAfterReplay);
    }

    // ------------------------------------------------------- refuses to lose

    public function testWordsInALanguageTheRegistryDoesNotHaveStopTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure, 'the migration should have refused');
        self::assertStringContainsString('product_personalization', (string) self::$brokenFailure);
        self::assertStringContainsString('"en"', (string) self::$brokenFailure);

        foreach (['label', 'label_en', 'instructions', 'instructions_en', 'placeholder_en'] as $column) {
            self::assertContains($column, self::$brokenColumnsAfterFailure, $column . ' must still be there');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * One configured product with one view and three zones, in every state
     * their columns could be in: both languages, Dutch only, empty,
     * whitespace, and one with characters a careless copy would change.
     *
     * @return array<string, int>
     */
    private static function seed(ScratchInstall $install): array
    {
        $ids = [];
        $pdo = $install->pdo();

        $pdo->prepare(
            'INSERT INTO products (slug, price, stock, active, in_shop, in_personalization_catalog,
                                   shipping_profile, shipping_weight_grams, requires_parcel, created_at, updated_at)
             VALUES (?, ?, ?, 1, 1, 1, ?, ?, 0, NOW(), NOW())'
        )->execute(['zz-pz-words-product', '24.95', 5, 'letter', 30]);
        $productId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO product_personalization_settings
                (product_id, is_enabled, personalization_mode, instructions, instructions_en, created_at, updated_at)
             VALUES (?, 1, ?, ?, ?, NOW(), NOW())'
        )->execute([$productId, 'optional', 'Personaliseer dit product met een naam.', "  \n "]);
        $ids['settings'] = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO product_personalization_views
                (settings_id, view_key, label, label_en, preview_image_path, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $ids['settings'], 'front', 'Voorkant', 'Front',
            'assets/images/personalization/zz-front.png', 0,
        ]);
        $ids['view'] = (int) $pdo->lastInsertId();

        $zone = $pdo->prepare(
            'INSERT INTO product_personalization_zones
                (settings_id, view_id, zone_key, label, label_en, instructions, instructions_en,
                 placeholder, placeholder_en, allow_text, allow_image, is_enabled, is_required,
                 allow_rotation, max_text_length, surcharge,
                 area_x, area_y, area_width, area_height, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 1, 1, 1, 30, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );

        $zone->execute([
            $ids['settings'], $ids['view'], 'name',
            'Naam', 'Name',
            'Maximaal 30 tekens.', 'Up to 30 characters.',
            'Bijv. Bart', '   ',
            '2.50', '25.000', '35.000', '50.000', '30.000', 0,
        ]);
        $ids['zone'] = (int) $pdo->lastInsertId();

        $zone->execute([
            $ids['settings'], $ids['view'], 'blank',
            null, '', null, "\t", '', "\r\n",
            '0.00', '10.000', '10.000', '20.000', '20.000', 1,
        ]);
        $ids['bare_zone'] = (int) $pdo->lastInsertId();

        $zone->execute([
            $ids['settings'], $ids['view'], 'exotic',
            " Ruimte  eromheen \u{00a0}", null,
            'Ampersand &amp; "quotes"', null,
            null, null,
            '99999.99', '0.000', '0.000', '100.000', '100.000', 2,
        ]);
        $ids['exotic_zone'] = (int) $pdo->lastInsertId();

        return $ids;
    }

    private static function rowCount(ScratchInstall $install): int
    {
        return $install->count('product_personalization_translations')
            + $install->count('product_personalization_view_translations')
            + $install->count('product_personalization_zone_translations');
    }

    /** @return array<string, string> column => type */
    private static function shape(ScratchInstall $install, string $table): array
    {
        $shape = [];
        foreach ($install->rows('SHOW COLUMNS FROM ' . $table) as $column) {
            $shape[(string) $column['Field']] = (string) $column['Type'];
        }

        return $shape;
    }
}
