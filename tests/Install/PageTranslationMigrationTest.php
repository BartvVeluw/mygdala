<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 2 on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md):
 *
 *   20260917140000_create_the_page_translations_table.php   the table
 *   20260917150000_move_page_text_into_page_translations.php the text, and the
 *                                                            old columns gone
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before this phase, with pages
 *              in every state their Dutch/English columns could be in
 *   broken     the same, with English text but no English row in the
 *              language registry
 *
 * What must hold: the same table everywhere, one row per page per language in
 * the schema, the page as a cascading and the language as a restricting
 * foreign key; Dutch stays Dutch and English stays English, empty stays
 * empty, nothing else about a page changes; a second run changes nothing; and
 * text that cannot be moved stops the migration before anything is dropped.
 */
#[Group('migration-backfill')]
final class PageTranslationMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_page_translations_fresh';
    private const UPGRADED = 'mygdala_scratch_page_translations_upgraded';
    private const BROKEN = 'mygdala_scratch_page_translations_broken';

    /** The last migration before this phase. */
    private const BEFORE = '20260917120000';

    private const SCHEMA = '20260917140000';
    private const MOVE = '20260917150000';

    private const LEGACY_COLUMNS = ['title', 'title_en', 'meta_title', 'meta_title_en', 'meta_description', 'meta_description_en'];

    /** The language-neutral columns that must come through untouched. */
    private const NEUTRAL_COLUMNS = 'id, content_key, slug, status, is_system, route_path, sort_order, noindex, show_breadcrumb, og_image_path, og_media_id, created_at, updated_at';

    /**
     * content_key => [title, title_en, meta_title, meta_title_en, meta_description, meta_description_en]
     *
     * @var array<string, list<?string>>
     */
    private const SEEDED = [
        'zz-both' => ['Over ons', 'About us', 'Over ons | Test', 'About us | Test', 'Wie wij zijn.', 'Who we are.'],
        'zz-dutch-only' => ['Alleen Nederlands', null, null, null, '', null],
        'zz-english-only' => ['', 'Only English', null, 'English SEO', null, null],
        'zz-empty-translation' => ['Lege vertaling', '   ', null, '', null, null],
        'zz-mixed' => ['Gemengd', null, null, 'Only an English SEO title', 'Alleen een Nederlandse omschrijving', null],
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var list<array<string, mixed>> */
    private static array $neutralBefore = [];

    private static ?string $brokenFailure = null;

    /** @var list<string> */
    private static array $brokenColumnsAfterFailure = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seed(self::$upgraded);
        self::$neutralBefore = self::$upgraded->rows('SELECT ' . self::NEUTRAL_COLUMNS . ' FROM pages ORDER BY id');
        self::$upgraded->catchUp();

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp();
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = self::columns($broken, 'pages');
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

    public function testBothDatabasesEndWithTheSameTable(): void
    {
        $fresh = self::shape(self::$fresh);

        self::assertSame($fresh, self::shape(self::$upgraded));
        self::assertSame(
            ['id', 'page_id', 'language_code', 'title', 'meta_title', 'meta_description', 'created_at', 'updated_at'],
            array_keys($fresh)
        );
        self::assertSame('int unsigned', $fresh['page_id']['column_type']);
        self::assertSame('NO', $fresh['page_id']['is_nullable']);
        self::assertSame('varchar(12)', $fresh['language_code']['column_type']);
        self::assertSame('ascii_bin', $fresh['language_code']['collation_name']);
        self::assertSame('NO', $fresh['language_code']['is_nullable']);

        foreach (['title' => 'varchar(200)', 'meta_title' => 'varchar(255)', 'meta_description' => 'varchar(500)'] as $column => $type) {
            self::assertSame($type, $fresh[$column]['column_type'], $column);
            self::assertSame('YES', $fresh[$column]['is_nullable'], $column . ' is NULL when a language has no words');
        }
    }

    public function testTheLanguageColumnMatchesTheRegistryItPointsAt(): void
    {
        $sql = 'SELECT column_type AS column_type, character_set_name AS charset, collation_name AS collation_name '
            . 'FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?';

        self::assertSame(
            self::$fresh->rows($sql, [self::FRESH, 'site_languages', 'code']),
            self::$fresh->rows($sql, [self::FRESH, 'page_translations', 'language_code'])
        );
    }

    public function testOnePageHasAtMostOneRowPerLanguage(): void
    {
        $unique = array_column(self::$fresh->rows(
            'SELECT index_name AS index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns '
            . 'FROM information_schema.statistics '
            . 'WHERE table_schema = ? AND table_name = ? AND non_unique = 0 GROUP BY index_name ORDER BY index_name',
            [self::FRESH, 'page_translations']
        ), 'columns', 'index_name');

        self::assertSame(['PRIMARY' => 'id', 'uq_page_translations_page_language' => 'page_id,language_code'], $unique);
    }

    public function testThePageCascadesAndTheLanguageRestricts(): void
    {
        foreach ([self::$fresh, self::$upgraded] as $install) {
            $keys = [];
            foreach ($install->rows(
                'SELECT rc.constraint_name AS name, rc.referenced_table_name AS referenced_table, '
                . 'rc.delete_rule AS delete_rule, kcu.column_name AS column_name, kcu.referenced_column_name AS referenced_column '
                . 'FROM information_schema.referential_constraints rc '
                . 'JOIN information_schema.key_column_usage kcu '
                . '  ON kcu.constraint_schema = rc.constraint_schema AND kcu.constraint_name = rc.constraint_name '
                . 'WHERE rc.constraint_schema = ? AND rc.table_name = ? ORDER BY rc.constraint_name',
                [$install->database, 'page_translations']
            ) as $row) {
                $keys[$row['name']] = [$row['column_name'], $row['referenced_table'], $row['referenced_column'], $row['delete_rule']];
            }

            self::assertSame([
                'fk_page_translations_language' => ['language_code', 'site_languages', 'code', 'RESTRICT'],
                'fk_page_translations_page' => ['page_id', 'pages', 'id', 'CASCADE'],
            ], $keys, $install->database);
        }
    }

    public function testTheOldTextColumnsAreGoneAndNothingElseIs(): void
    {
        $fresh = self::columns(self::$fresh, 'pages');

        self::assertSame($fresh, self::columns(self::$upgraded, 'pages'));
        self::assertSame([], array_values(array_intersect(self::LEGACY_COLUMNS, $fresh)));
        foreach (['id', 'content_key', 'slug', 'status', 'show_breadcrumb', 'noindex', 'og_image_path', 'og_media_id', 'is_system', 'route_path', 'sort_order'] as $column) {
            self::assertContains($column, $fresh);
        }
    }

    // ------------------------------------------------------------ the text

    public function testDutchStaysDutchAndEnglishStaysEnglish(): void
    {
        self::assertSame(
            [
                'nl' => ['Over ons', 'Over ons | Test', 'Wie wij zijn.'],
                'en' => ['About us', 'About us | Test', 'Who we are.'],
            ],
            self::text('zz-both')
        );
    }

    public function testAPageWithOnlyDutchGetsNoEnglishRowAndEmptyStaysEmpty(): void
    {
        self::assertSame(['nl' => ['Alleen Nederlands', null, null]], self::text('zz-dutch-only'), "'' becomes NULL");
    }

    public function testAPageWithOnlyEnglishGetsNoDutchRow(): void
    {
        self::assertSame(['en' => ['Only English', 'English SEO', null]], self::text('zz-english-only'));
    }

    public function testABlankTranslationIsNoTranslation(): void
    {
        self::assertSame(['nl' => ['Lege vertaling', null, null]], self::text('zz-empty-translation'), 'whitespace and empty English is no English row');
    }

    public function testFieldsMoveOneByOneIntoTheirOwnLanguage(): void
    {
        self::assertSame(
            [
                'nl' => ['Gemengd', null, 'Alleen een Nederlandse omschrijving'],
                'en' => [null, 'Only an English SEO title', null],
            ],
            self::text('zz-mixed')
        );
    }

    public function testNothingLanguageNeutralAboutAPageChanged(): void
    {
        self::assertNotSame([], self::$neutralBefore);
        self::assertSame(self::$neutralBefore, self::$upgraded->rows('SELECT ' . self::NEUTRAL_COLUMNS . ' FROM pages ORDER BY id'));
    }

    public function testAFreshInstallGivesItsHomepageADutchName(): void
    {
        $rows = self::$fresh->rows(
            "SELECT t.language_code AS language_code, t.title AS title FROM page_translations t
               JOIN pages p ON p.id = t.page_id WHERE p.content_key = 'index'"
        );

        self::assertSame([['language_code' => 'nl', 'title' => 'Homepage']], $rows);
        self::assertSame(
            self::$fresh->count('pages'),
            (int) self::$fresh->rows('SELECT COUNT(DISTINCT page_id) AS c FROM page_translations')[0]['c'],
            'every page of a fresh install has a name'
        );
    }

    public function testRunningBothAgainChangesNothing(): void
    {
        $before = self::$upgraded->rows('SELECT page_id, language_code, title, meta_title, meta_description FROM page_translations ORDER BY page_id, language_code');
        $shape = self::shape(self::$upgraded);

        self::$upgraded->replay(self::SCHEMA);
        self::$upgraded->replay(self::MOVE);

        self::assertSame($before, self::$upgraded->rows('SELECT page_id, language_code, title, meta_title, meta_description FROM page_translations ORDER BY page_id, language_code'));
        self::assertSame($shape, self::shape(self::$upgraded));
    }

    public function testTextThatCannotBeMovedStopsTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure, 'the migration must refuse');
        self::assertStringContainsString('could not be moved into page_translations', (string) self::$brokenFailure);
        foreach (self::LEGACY_COLUMNS as $column) {
            self::assertContains($column, self::$brokenColumnsAfterFailure, $column . ' is still there to move later');
        }
    }

    // ------------------------------------------------------------ helpers

    private static function seed(ScratchInstall $install): void
    {
        $insert = $install->pdo()->prepare(
            'INSERT INTO pages
                (content_key, slug, title, title_en, meta_title, meta_title_en, meta_description, meta_description_en,
                 status, is_system, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())'
        );

        $sort = 900;
        foreach (self::SEEDED as $key => $text) {
            $insert->execute(array_merge([$key, $key], $text, [$sort === 900 ? 'published' : 'draft', $sort++]));
        }
    }

    /** @return array<string, list<?string>> language code => [title, meta title, meta description] */
    private static function text(string $contentKey): array
    {
        $text = [];
        foreach (self::$upgraded->rows(
            'SELECT t.language_code AS language_code, t.title AS title, t.meta_title AS meta_title, t.meta_description AS meta_description
               FROM page_translations t JOIN pages p ON p.id = t.page_id
              WHERE p.content_key = ? ORDER BY t.id',
            [$contentKey]
        ) as $row) {
            $text[$row['language_code']] = [$row['title'], $row['meta_title'], $row['meta_description']];
        }

        return $text;
    }

    /** @return list<string> */
    private static function columns(ScratchInstall $install, string $table): array
    {
        return array_column($install->rows(
            'SELECT column_name AS column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$install->database, $table]
        ), 'column_name');
    }

    /** @return array<string, array{column_type: string, is_nullable: string, collation_name: ?string}> */
    private static function shape(ScratchInstall $install): array
    {
        $columns = [];
        foreach ($install->rows(
            'SELECT column_name AS column_name, column_type AS column_type, is_nullable AS is_nullable, '
            . 'collation_name AS collation_name FROM information_schema.columns '
            . 'WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$install->database, 'page_translations']
        ) as $row) {
            $columns[$row['column_name']] = [
                'column_type' => $row['column_type'],
                'is_nullable' => $row['is_nullable'],
                'collation_name' => $row['collation_name'],
            ];
        }

        return $columns;
    }
}
