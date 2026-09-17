<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 4 wave B on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md):
 *
 *   20260918120000_create_the_site_setting_translations_table.php
 *   20260918130000_move_localized_site_settings_into_site_setting_translations.php
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before wave B, with the six
 *              localized setting rows in every state they could be in, the
 *              eight legacy header_cta_* rows, and settings that must not move
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: Dutch stays Dutch and English stays English, byte for byte;
 * an empty or whitespace value gets no row; exactly the six localized rows
 * and the eight header_cta_* rows leave site_settings, and every other
 * setting — the Shop's related-products heading pair included — is left as
 * it was; a second run changes nothing; words that cannot be moved stop the
 * migration before anything is removed.
 */
#[Group('migration-backfill')]
final class LocalizedSiteSettingMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_localized_settings_fresh';
    private const UPGRADED = 'mygdala_scratch_localized_settings_upgraded';
    private const BROKEN = 'mygdala_scratch_localized_settings_broken';

    /** The last migration before wave B. */
    private const BEFORE = '20260918110000';

    private const SCHEMA = '20260918120000';
    private const MOVE = '20260918130000';

    private const ODD = " <b>Één</b> & \"quotes\" &amp;\nline two";

    /** What the upgraded installation holds before the move. */
    private const SEEDED = [
        'city_nl' => 'Nijmegen, Nederland',
        'city_en' => 'Nijmegen, the Netherlands',
        'footer_description_nl' => self::ODD,
        'footer_description_en' => " \t\n",
        'footer_slogan_nl' => '',
        'footer_slogan_en' => 'Designed with care',
        'header_cta_enabled' => '1',
        'header_cta_label_nl' => 'Vraag offerte aan',
        'header_cta_label_en' => 'Request a quote',
        'header_cta_link_type' => 'external',
        'header_cta_target_page_id' => '',
        'header_cta_target_route' => '',
        'header_cta_external_url' => '/offerte',
        'header_cta_open_in_new_tab' => '0',
        'related_products_heading_nl' => 'Ook mooi',
        'related_products_heading_en' => 'Also nice',
        'footer_slogan_enabled' => '1',
        'site_name' => 'Migratietest',
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, string> */
    private static array $otherSettingsBefore = [];

    private static int $rowsAfterFirstRun = 0;
    private static int $rowsAfterReplay = 0;

    private static ?string $brokenFailure = null;

    /** @var list<string> */
    private static array $brokenKeysAfterFailure = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MOVE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$otherSettingsBefore = self::settings(self::$upgraded);
        foreach (self::movedOrRemovedKeys() as $key) {
            unset(self::$otherSettingsBefore[$key]);
        }
        self::$upgraded->catchUp(self::MOVE);
        self::$rowsAfterFirstRun = self::$upgraded->count('site_setting_translations');
        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$rowsAfterReplay = self::$upgraded->count('site_setting_translations');

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        foreach (['page_translations', 'block_translations', 'nav_item_translations', 'footer_column_translations', 'footer_link_translations'] as $table) {
            $broken->pdo()->exec("DELETE FROM {$table} WHERE language_code = 'en'");
        }
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenKeysAfterFailure = array_keys(self::settings($broken));
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

    public function testBothDatabasesEndWithTheSameTable(): void
    {
        self::assertSame(self::shape(self::$fresh), self::shape(self::$upgraded));
        self::assertSame(['id', 'setting_key', 'language_code', 'value', 'created_at', 'updated_at'], array_keys(self::shape(self::$fresh)));

        $unique = self::$fresh->rows(
            "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_index
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'site_setting_translations' AND non_unique = 0 AND index_name <> 'PRIMARY'
              GROUP BY index_name"
        );
        self::assertSame([['columns_in_index' => 'setting_key,language_code']], $unique);

        $keys = self::$fresh->rows(
            "SELECT k.referenced_table_name, r.delete_rule FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints r
                 ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
              WHERE k.table_schema = DATABASE() AND k.table_name = 'site_setting_translations'"
        );
        self::assertSame([['referenced_table_name' => 'site_languages', 'delete_rule' => 'RESTRICT']], array_map(static fn (array $row): array => array_change_key_case($row, CASE_LOWER), $keys));
    }

    public function testEveryLanguageKeepsItsOwnWordsByteForByteAndEmptyGetsNoRow(): void
    {
        self::assertSame(
            [
                ['setting_key' => 'city', 'language_code' => 'en', 'value' => 'Nijmegen, the Netherlands'],
                ['setting_key' => 'city', 'language_code' => 'nl', 'value' => 'Nijmegen, Nederland'],
                ['setting_key' => 'footer_description', 'language_code' => 'nl', 'value' => self::ODD],
                ['setting_key' => 'footer_slogan', 'language_code' => 'en', 'value' => 'Designed with care'],
            ],
            self::$upgraded->rows('SELECT setting_key, language_code, value FROM site_setting_translations ORDER BY setting_key, language_code')
        );
    }

    public function testExactlyTheLocalizedAndLegacyRowsLeaveSiteSettings(): void
    {
        $after = self::settings(self::$upgraded);

        foreach (self::movedOrRemovedKeys() as $key) {
            self::assertArrayNotHasKey($key, $after, $key . ' was moved or removed');
        }

        self::assertSame(self::$otherSettingsBefore, $after, 'every other setting is exactly as it was');
        self::assertSame('Ook mooi', $after['related_products_heading_nl'] ?? null, 'the Shop heading moves with the Shop, not here');
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertSame(4, self::$rowsAfterFirstRun);
        self::assertSame(self::$rowsAfterFirstRun, self::$rowsAfterReplay);
    }

    public function testWordsThatCannotBeMovedStopTheMigrationBeforeAnythingIsRemoved(): void
    {
        self::assertNotNull(self::$brokenFailure);
        self::assertStringContainsString('"en"', (string) self::$brokenFailure);
        self::assertContains('city_en', self::$brokenKeysAfterFailure);
        self::assertContains('header_cta_label_nl', self::$brokenKeysAfterFailure, 'nothing at all was removed');
    }

    public function testAFreshInstallHasNoLocalizedSettingsAndNoLegacyRows(): void
    {
        self::assertSame(0, self::$fresh->count('site_setting_translations'));
        foreach (self::movedOrRemovedKeys() as $key) {
            self::assertArrayNotHasKey($key, self::settings(self::$fresh), $key);
        }
    }

    // --------------------------------------------------------------- helpers

    private static function seed(ScratchInstall $install): void
    {
        $statement = $install->pdo()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach (self::SEEDED as $key => $value) {
            $statement->execute([$key, $value]);
        }
    }

    /** @return list<string> */
    private static function movedOrRemovedKeys(): array
    {
        return array_values(array_filter(
            array_keys(self::SEEDED),
            static fn (string $key): bool => preg_match('/^(city|footer_description|footer_slogan)_(nl|en)$|^header_cta_/', $key) === 1
        ));
    }

    /** @return array<string, string> */
    private static function settings(ScratchInstall $install): array
    {
        $settings = [];
        foreach ($install->rows('SELECT setting_key, setting_value FROM site_settings ORDER BY setting_key') as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $settings;
    }

    /** @return array<string, string> */
    private static function shape(ScratchInstall $install): array
    {
        $shape = [];
        foreach ($install->rows('SHOW FULL COLUMNS FROM site_setting_translations') as $column) {
            $shape[$column['Field']] = $column['Type'] . ' ' . $column['Null'] . ' ' . (string) $column['Collation'];
        }

        return $shape;
    }
}
