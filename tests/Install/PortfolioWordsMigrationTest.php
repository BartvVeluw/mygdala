<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 5 wave A on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md, MODULES.md "Portfolio"):
 *
 *   20260918160000_create_the_portfolio_translation_tables.php
 *   20260918170000_move_portfolio_words_into_translation_tables.php
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before wave A, with categories,
 *              items and the old project page's photos in every state their
 *              Dutch/English columns could be in
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: Dutch stays Dutch and English stays English, byte for byte,
 * rich text included; an empty or whitespace value gets no row and a row is
 * only made for a language that has at least one word; SEVERAL FIELDS OF ONE
 * OWNER SHARE ONE ROW, which is what makes this migration different from the
 * one-field tables of phase 4; ids, slugs, image paths, categories, the linked
 * page and every flag and order do not change; a second run changes nothing;
 * and words that cannot be moved stop the migration before any column is
 * dropped.
 *
 * NOTHING HERE ASKS WHETHER THE PORTFOLIO IS ENABLED. A scratch install runs
 * with no MODULE_* variable set at all (Tests\Support\ScratchInstall), so the
 * module is off, and both databases must still end on the same schema with
 * every word moved — a switched-off module keeps its content.
 */
#[Group('migration-backfill')]
final class PortfolioWordsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_portfolio_words_fresh';
    private const UPGRADED = 'mygdala_scratch_portfolio_words_upgraded';
    private const BROKEN = 'mygdala_scratch_portfolio_words_broken';

    /** The last migration before wave A. */
    private const BEFORE = '20260918150000';

    private const SCHEMA = '20260918160000';
    private const MOVE = '20260918170000';

    private const NEUTRAL_ITEM_COLUMNS = 'id, portfolio_gallery_id, page_id, image_path, thumbnail_path, categories,'
        . ' sort_order, is_active, is_featured, featured_sort_order, has_detail_page, slug';

    private const NEUTRAL_CATEGORY_COLUMNS = 'id, slug, sort_order';

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
        self::$neutralBefore['portfolio_gallery_items'] = self::$upgraded->rows(
            'SELECT ' . self::NEUTRAL_ITEM_COLUMNS . ' FROM portfolio_gallery_items ORDER BY id'
        );
        self::$neutralBefore['portfolio_categories'] = self::$upgraded->rows(
            'SELECT ' . self::NEUTRAL_CATEGORY_COLUMNS . ' FROM portfolio_categories ORDER BY id'
        );
        self::$upgraded->catchUp(self::MOVE);
        self::$rowsAfterFirstRun = self::rowCount(self::$upgraded);
        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$rowsAfterReplay = self::rowCount(self::$upgraded);

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        foreach ([
            'page_translations',
            'block_translations',
            'nav_item_translations',
            'footer_column_translations',
            'footer_link_translations',
            'site_setting_translations',
            'form_translations',
            'form_field_translations',
            'form_field_option_translations',
        ] as $table) {
            $broken->pdo()->exec("DELETE FROM {$table} WHERE language_code = 'en'");
        }
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = array_column($broken->rows('SHOW COLUMNS FROM portfolio_gallery_items'), 'Field');
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

    public function testBothDatabasesEndWithTheSameSchemaAndNoLanguageColumnsLeft(): void
    {
        foreach ([
            'portfolio_categories',
            'portfolio_gallery_items',
            'portfolio_item_images',
            'portfolio_category_translations',
            'portfolio_item_translations',
            'portfolio_item_image_translations',
        ] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        foreach (['portfolio_categories', 'portfolio_gallery_items', 'portfolio_item_images'] as $table) {
            foreach (array_keys(self::shape(self::$fresh, $table)) as $column) {
                self::assertDoesNotMatchRegularExpression(
                    '/_(nl|en)$/',
                    $column,
                    $table . '.' . $column . ' is a language column'
                );
            }
        }
    }

    /** One row per owner per language, and only a language the registry has. */
    public function testEachTableHasItsOwnerLanguageUniqueAndItsTwoForeignKeys(): void
    {
        foreach ([
            'portfolio_category_translations' => ['portfolio_category_id', 'portfolio_categories'],
            'portfolio_item_translations' => ['portfolio_item_id', 'portfolio_gallery_items'],
            'portfolio_item_image_translations' => ['portfolio_item_image_id', 'portfolio_item_images'],
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

            self::assertSame(
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

    public function testAnItemKeepsEveryFieldPerLanguageInOneRow(): void
    {
        self::assertSame(
            [
                [
                    'language_code' => 'en',
                    'title' => 'Wooden sign',
                    'subtitle' => null,
                    'alt' => 'A wooden sign',
                    'intro' => '<p>Made of oak</p>',
                    'description' => null,
                ],
                [
                    'language_code' => 'nl',
                    'title' => 'Houten bord',
                    'subtitle' => 'Van eiken',
                    'alt' => 'Een houten bord',
                    'intro' => '<p>Van eiken hout</p>',
                    'description' => '<p>De hele beschrijving</p>',
                ],
            ],
            self::$upgraded->rows(
                'SELECT language_code, title, subtitle, alt, intro, description
                   FROM portfolio_item_translations WHERE portfolio_item_id = ? ORDER BY language_code',
                [self::$ids['item']]
            ),
            'five fields of one item share one row per language, whitespace excluded'
        );
    }

    /** An item without a single word in any language has no row at all. */
    public function testAnItemWithoutWordsHasNoRow(): void
    {
        self::assertSame(
            [],
            self::$upgraded->rows(
                'SELECT id FROM portfolio_item_translations WHERE portfolio_item_id = ?',
                [self::$ids['wordless_item']]
            )
        );
    }

    /** An item with ONLY English words gets an English row and no Dutch one. */
    public function testAnItemWithOnlyATranslationGetsOnlyThatLanguagesRow(): void
    {
        self::assertSame(
            [['language_code' => 'en', 'title' => 'English only', 'subtitle' => null, 'alt' => null]],
            self::$upgraded->rows(
                'SELECT language_code, title, subtitle, alt FROM portfolio_item_translations
                  WHERE portfolio_item_id = ? ORDER BY language_code',
                [self::$ids['english_item']]
            )
        );
    }

    public function testACategoryKeepsItsNamePerLanguage(): void
    {
        self::assertSame(
            [
                ['language_code' => 'en', 'name' => 'Wood'],
                ['language_code' => 'nl', 'name' => 'Hout'],
            ],
            self::$upgraded->rows(
                'SELECT language_code, name FROM portfolio_category_translations
                  WHERE portfolio_category_id = ? ORDER BY language_code',
                [self::$ids['category']]
            )
        );

        self::assertSame(
            [['language_code' => 'nl', 'name' => 'Metaal']],
            self::$upgraded->rows(
                'SELECT language_code, name FROM portfolio_category_translations
                  WHERE portfolio_category_id = ? ORDER BY language_code',
                [self::$ids['untranslated_category']]
            ),
            'whitespace is no translation'
        );
    }

    public function testAPhotoOfTheOldProjectPageKeepsItsAltTextPerLanguage(): void
    {
        self::assertSame(
            [
                ['language_code' => 'en', 'alt' => 'Detail photo'],
                ['language_code' => 'nl', 'alt' => 'Detailfoto'],
            ],
            self::$upgraded->rows(
                'SELECT language_code, alt FROM portfolio_item_image_translations
                  WHERE portfolio_item_image_id = ? ORDER BY language_code',
                [self::$ids['photo']]
            )
        );
    }

    /** A value is copied, never trimmed, sanitized or re-encoded on the way. */
    public function testWordsAreCopiedByteForByte(): void
    {
        self::assertSame(
            [['title' => " Ruimte  eromheen \u{00a0}", 'alt' => '<b>Ampersand &amp; "quotes"</b>']],
            self::$upgraded->rows(
                "SELECT title, alt FROM portfolio_item_translations
                  WHERE portfolio_item_id = ? AND language_code = 'nl'",
                [self::$ids['exotic_item']]
            )
        );
    }

    // ------------------------------------------------- what must not change

    public function testNothingButTheWordsMoved(): void
    {
        self::assertSame(
            self::$neutralBefore['portfolio_gallery_items'],
            self::$upgraded->rows('SELECT ' . self::NEUTRAL_ITEM_COLUMNS . ' FROM portfolio_gallery_items ORDER BY id'),
            'ids, the gallery, the linked page, image paths, flags and orders are untouched'
        );

        self::assertSame(
            self::$neutralBefore['portfolio_categories'],
            self::$upgraded->rows('SELECT ' . self::NEUTRAL_CATEGORY_COLUMNS . ' FROM portfolio_categories ORDER BY id'),
            'a category keeps its id and its slug, so no relationship and no address moves'
        );
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
        // The first move in the list is the one that reports: the check runs
        // over every table before a single column is dropped.
        self::assertStringContainsString('portfolio_categories', (string) self::$brokenFailure);
        self::assertStringContainsString('"en"', (string) self::$brokenFailure);

        foreach (['title_nl', 'title_en', 'alt_nl', 'alt_en', 'intro_nl', 'description_en'] as $column) {
            self::assertContains($column, self::$brokenColumnsAfterFailure, $column . ' must still be there');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Categories, items and one photo in every state their Dutch/English
     * columns could be in: both filled, Dutch only, English only, whitespace,
     * empty, and one with characters a careless copy would change.
     *
     * @return array<string, int>
     */
    private static function seed(ScratchInstall $install): array
    {
        $ids = [];
        $pdo = $install->pdo();

        $category = $pdo->prepare(
            'INSERT INTO portfolio_categories (name_nl, name_en, slug, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $category->execute(['Hout', 'Wood', 'zz-hout', 0, '2026-01-02 03:04:05', '2026-02-03 04:05:06']);
        $ids['category'] = (int) $pdo->lastInsertId();

        $category->execute(['Metaal', "  \t", 'zz-metaal', 1, '2026-01-02 03:04:05', '2026-01-02 03:04:05']);
        $ids['untranslated_category'] = (int) $pdo->lastInsertId();

        $pdo->exec('INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())');
        $galleryId = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            'INSERT INTO portfolio_gallery_items
                (portfolio_gallery_id, image_path, thumbnail_path, alt_nl, alt_en, title_nl, title_en,
                 subtitle_nl, subtitle_en, intro_nl, intro_en, description_nl, description_en,
                 sort_order, is_active, has_detail_page, slug, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $item->execute([
            $galleryId, 'assets/images/sections/zz-bord.jpg', 'assets/images/sections/zz-bord-thumb.jpg',
            'Een houten bord', 'A wooden sign', 'Houten bord', 'Wooden sign',
            'Van eiken', "  \n ", '<p>Van eiken hout</p>', '<p>Made of oak</p>', '<p>De hele beschrijving</p>', '',
            0, 1, 1, 'zz-houten-bord', '2026-01-02 03:04:05', '2026-02-03 04:05:06',
        ]);
        $ids['item'] = (int) $pdo->lastInsertId();

        $item->execute([
            $galleryId, 'assets/images/sections/zz-leeg.jpg', null,
            '', null, "\t", '   ', null, '', null, null, '', "\r\n",
            1, 1, 0, null, '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['wordless_item'] = (int) $pdo->lastInsertId();

        $item->execute([
            $galleryId, 'assets/images/sections/zz-engels.jpg', null,
            null, null, ' ', 'English only', null, null, null, null, null, null,
            2, 0, 0, null, '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['english_item'] = (int) $pdo->lastInsertId();

        $item->execute([
            $galleryId, 'assets/images/sections/zz-exotisch.jpg', null,
            '<b>Ampersand &amp; "quotes"</b>', null, " Ruimte  eromheen \u{00a0}", null, null, null, null, null, null, null,
            3, 1, 0, null, '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['exotic_item'] = (int) $pdo->lastInsertId();

        $photo = $pdo->prepare(
            'INSERT INTO portfolio_item_images (portfolio_item_id, image_path, alt_nl, alt_en, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $photo->execute([
            $ids['item'], 'assets/images/sections/zz-detail.jpg', 'Detailfoto', 'Detail photo', 0,
            '2026-01-02 03:04:05', '2026-01-02 03:04:05',
        ]);
        $ids['photo'] = (int) $pdo->lastInsertId();

        return $ids;
    }

    private static function rowCount(ScratchInstall $install): int
    {
        return $install->count('portfolio_category_translations')
            + $install->count('portfolio_item_translations')
            + $install->count('portfolio_item_image_translations');
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
