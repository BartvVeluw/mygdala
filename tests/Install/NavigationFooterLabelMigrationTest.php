<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 4 wave A on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md):
 *
 *   20260918100000_create_the_navigation_and_footer_translation_tables.php
 *   20260918110000_move_navigation_and_footer_labels_into_translation_tables.php
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before this phase, with menu
 *              items, header buttons, footer columns and links in every state
 *              their Dutch/English columns could be in
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: the same schema everywhere; Dutch stays Dutch and English
 * stays English; NULL, '' and whitespace of any kind are no words and get no
 * row; words are copied byte for byte; ids, destinations, presentation,
 * parents, order and visibility do not change; a second run changes nothing;
 * and words that cannot be moved stop the migration before anything is
 * dropped.
 */
#[Group('migration-backfill')]
final class NavigationFooterLabelMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_nav_footer_words_fresh';
    private const UPGRADED = 'mygdala_scratch_nav_footer_words_upgraded';
    private const BROKEN = 'mygdala_scratch_nav_footer_words_broken';

    /** The last migration before this phase. */
    private const BEFORE = '20260917200000';

    private const SCHEMA = '20260918100000';
    private const MOVE = '20260918110000';

    private const NEUTRAL_COLUMNS = [
        'nav_items' => 'id, link_type, target_page_id, target_route, external_url, open_in_new_tab, presentation, button_variant, parent_id, sort_order, is_visible, created_at, updated_at',
        'footer_columns' => 'id, sort_order, is_visible, created_at, updated_at',
        'footer_links' => 'id, column_id, link_type, target_page_id, target_route, external_url, action_key, open_in_new_tab, sort_order, is_visible, created_at, updated_at',
    ];

    /** Words that must arrive exactly as they were: markup-looking, entities, accents, leading space. */
    private const ODD = " <b>Één</b> & \"quotes\" &amp;";

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $neutralBefore = [];

    /** @var array<string, array<int, array{0: mixed, 1: mixed}>> table => id => [Dutch column, English column] */
    private static array $wordsBefore = [];

    /** @var array<string, int> */
    private static array $ids = [];

    private static ?string $brokenFailure = null;

    /** @var list<string> */
    private static array $brokenColumnsAfterFailure = [];

    private static int $rowsAfterFirstRun = 0;
    private static int $rowsAfterReplay = 0;

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MOVE);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        foreach (self::NEUTRAL_COLUMNS as $table => $columns) {
            self::$neutralBefore[$table] = self::$upgraded->rows("SELECT {$columns} FROM {$table} ORDER BY id");
        }
        foreach (['nav_items' => ['label_nl', 'label_en'], 'footer_columns' => ['title_nl', 'title_en'], 'footer_links' => ['label_nl', 'label_en']] as $table => [$dutch, $english]) {
            foreach (self::$upgraded->rows("SELECT id, {$dutch} AS d, {$english} AS e FROM {$table} ORDER BY id") as $row) {
                self::$wordsBefore[$table][(int) $row['id']] = [$row['d'], $row['e']];
            }
        }
        self::$upgraded->catchUp(self::MOVE);
        self::$rowsAfterFirstRun = self::translationRowCount(self::$upgraded);
        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$rowsAfterReplay = self::translationRowCount(self::$upgraded);

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        $broken->pdo()->exec("DELETE FROM page_translations WHERE language_code = 'en'");
        $broken->pdo()->exec("DELETE FROM block_translations WHERE language_code = 'en'");
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = array_column($broken->rows('SHOW COLUMNS FROM nav_items'), 'Field');
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

    public function testBothDatabasesEndWithTheSameSchemaAndNoLanguageColumns(): void
    {
        foreach (['nav_items', 'footer_columns', 'footer_links', 'nav_item_translations', 'footer_column_translations', 'footer_link_translations'] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        foreach (['nav_items', 'footer_columns', 'footer_links'] as $table) {
            foreach (array_keys(self::shape(self::$fresh, $table)) as $column) {
                self::assertDoesNotMatchRegularExpression('/_(nl|en)$/', $column, "{$table}.{$column} is a language column");
            }
        }

        self::assertSame(
            ['id', 'nav_item_id', 'language_code', 'label', 'created_at', 'updated_at'],
            array_keys(self::shape(self::$fresh, 'nav_item_translations'))
        );
    }

    public function testTheTablesHaveTheirKeysAndForeignKeys(): void
    {
        foreach ([
            'nav_item_translations' => ['nav_item_id', 'nav_items'],
            'footer_column_translations' => ['footer_column_id', 'footer_columns'],
            'footer_link_translations' => ['footer_link_id', 'footer_links'],
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
                'SELECT k.column_name, k.referenced_table_name, r.delete_rule
                   FROM information_schema.key_column_usage k
                   JOIN information_schema.referential_constraints r
                     ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                  WHERE k.table_schema = DATABASE() AND k.table_name = ?
                  ORDER BY k.column_name',
                [$table]
            );
            $byColumn = [];
            foreach ($keys as $key) {
                $key = array_change_key_case($key, CASE_LOWER);
                $byColumn[$key['column_name']] = [$key['referenced_table_name'], $key['delete_rule']];
            }
            ksort($byColumn);
            $expected = ['language_code' => ['site_languages', 'RESTRICT'], $ownerColumn => [$ownerTable, 'CASCADE']];
            ksort($expected);

            self::assertSame($expected, $byColumn, $table);
        }
    }

    // ------------------------------------------------------------- the words

    public function testEveryLanguageKeepsItsOwnWordsByteForByte(): void
    {
        foreach ([
            'nav_items' => ['nav_item_translations', 'nav_item_id', 'label'],
            'footer_columns' => ['footer_column_translations', 'footer_column_id', 'title'],
            'footer_links' => ['footer_link_translations', 'footer_link_id', 'label'],
        ] as $table => [$translations, $ownerColumn, $field]) {
            foreach (self::$wordsBefore[$table] as $id => [$dutch, $english]) {
                $rows = [];
                foreach (self::$upgraded->rows("SELECT language_code, {$field} AS words FROM {$translations} WHERE {$ownerColumn} = ?", [$id]) as $row) {
                    $rows[$row['language_code']] = $row['words'];
                }

                $expected = [];
                if (self::hasWords($dutch)) {
                    $expected['nl'] = $dutch;
                }
                if (self::hasWords($english)) {
                    $expected['en'] = $english;
                }
                ksort($rows);
                ksort($expected);

                self::assertSame($expected, $rows, "{$table} #{$id}");
            }
        }

        self::assertSame(self::ODD, self::$upgraded->rows("SELECT label FROM nav_item_translations WHERE nav_item_id = ? AND language_code = 'en'", [self::$ids['odd']])[0]['label']);
    }

    public function testEmptyAndWhitespaceWordsGetNoRow(): void
    {
        self::assertSame([], self::$upgraded->rows('SELECT * FROM nav_item_translations WHERE nav_item_id = ?', [self::$ids['blank']]));
        self::assertSame(
            ['nl'],
            array_column(self::$upgraded->rows('SELECT language_code FROM footer_link_translations WHERE footer_link_id = ?', [self::$ids['dutch_link']]), 'language_code')
        );
    }

    public function testNothingLanguageNeutralChanged(): void
    {
        foreach (self::NEUTRAL_COLUMNS as $table => $columns) {
            self::assertSame(self::$neutralBefore[$table], self::$upgraded->rows("SELECT {$columns} FROM {$table} ORDER BY id"), $table);
        }
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertGreaterThan(0, self::$rowsAfterFirstRun);
        self::assertSame(self::$rowsAfterFirstRun, self::$rowsAfterReplay);
    }

    public function testWordsThatCannotBeMovedStopTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure, 'the migration must refuse');
        self::assertStringContainsString('"en"', (string) self::$brokenFailure);
        self::assertContains('label_en', self::$brokenColumnsAfterFailure, 'nothing was dropped');
    }

    public function testAFreshInstallKeepsItsHomeItemInTheDefaultLanguage(): void
    {
        self::assertSame(
            [['language_code' => 'nl', 'label' => 'Home']],
            self::$fresh->rows(
                "SELECT t.language_code, t.label FROM nav_item_translations t
                   JOIN nav_items i ON i.id = t.nav_item_id
                  WHERE i.target_route = 'home' AND t.language_code = 'nl'"
            )
        );
    }

    // --------------------------------------------------------------- helpers

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $item = $pdo->prepare(
            "INSERT INTO nav_items (label_nl, label_en, link_type, external_url, presentation, button_variant, parent_id, sort_order, is_visible, created_at, updated_at)
             VALUES (?, ?, 'external', ?, ?, 'primary', ?, ?, 1, '2026-01-02 03:04:05', '2026-02-03 04:05:06')"
        );

        $item->execute(['zz Over ons', 'zz About us', '/a', 'link', null, 90]);
        self::$ids['both'] = (int) $pdo->lastInsertId();
        $item->execute(['zz Alleen NL', null, '/b', 'link', self::$ids['both'], 0]);
        self::$ids['dutch'] = (int) $pdo->lastInsertId();
        $item->execute(['', 'zz Only EN', '/c', 'button', null, 91]);
        self::$ids['english'] = (int) $pdo->lastInsertId();
        $item->execute(["  \t", "\n", '/d', 'link', null, 92]);
        self::$ids['blank'] = (int) $pdo->lastInsertId();
        $item->execute(['zz Vreemd', self::ODD, '/e', 'link', null, 93]);
        self::$ids['odd'] = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO footer_columns (title_nl, title_en, sort_order, is_visible, created_at, updated_at) VALUES ('zz Kolom', 'zz Column', 5, 1, '2026-01-02 03:04:05', '2026-01-02 03:04:05')");
        $column = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO footer_columns (title_nl, title_en, sort_order, is_visible, created_at, updated_at) VALUES (NULL, ' ', 6, 0, NULL, NULL)");

        $link = $pdo->prepare(
            "INSERT INTO footer_links (column_id, label_nl, label_en, link_type, external_url, action_key, sort_order, is_visible, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())"
        );
        $link->execute([$column, 'zz Voorwaarden', 'zz Terms', 'external', '/voorwaarden', null, 0]);
        $link->execute([$column, 'zz Cookie-instellingen', '', 'action', null, 'cookie_preferences', 1]);
        self::$ids['dutch_link'] = (int) $pdo->lastInsertId();
    }

    private static function translationRowCount(ScratchInstall $install): int
    {
        return $install->count('nav_item_translations') + $install->count('footer_column_translations') + $install->count('footer_link_translations');
    }

    private static function hasWords(mixed $value): bool
    {
        return $value !== null && trim((string) $value, " \t\n\r\0\x0B") !== '';
    }

    /** @return array<string, string> column => type and nullability */
    private static function shape(ScratchInstall $install, string $table): array
    {
        $shape = [];
        foreach ($install->rows("SHOW FULL COLUMNS FROM {$table}") as $column) {
            $shape[$column['Field']] = $column['Type'] . ' ' . $column['Null'] . ' ' . (string) $column['Collation'];
        }

        return $shape;
    }
}
