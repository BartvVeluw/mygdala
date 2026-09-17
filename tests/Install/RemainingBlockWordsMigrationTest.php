<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 3B on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md): the block types phase 3A left on their
 * Dutch/English columns move into `block_translations`, wave by wave.
 *
 *   20260917180000  wave A, blocks without child rows: Paginakop, Formulier,
 *                   Offerte-/contactformulier and the gallery table shared by
 *                   Galerij and Projecten; page_heroes.breadcrumb_label_*
 *                   dropped unread
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before phase 3B, with instances
 *              of every block type in the states their columns could be in
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold, per shape: the same schema everywhere; Dutch stays Dutch and
 * English stays English, field by field; NULL, '' and whitespace of any kind
 * are no words; words are copied byte for byte; ids and every
 * language-neutral column stay as they were; a second run changes nothing;
 * and words that cannot be moved stop the migration before anything is
 * dropped.
 */
#[Group('migration-backfill')]
final class RemainingBlockWordsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_remaining_block_words_fresh';
    private const UPGRADED = 'mygdala_scratch_remaining_block_words_upgraded';
    private const BROKEN_A = 'mygdala_scratch_remaining_block_words_broken_a';

    /** The last migration before phase 3B. */
    private const BEFORE = '20260917170000';

    private const WAVE_A = '20260917180000';

    /** A page an editor made, with a slug no migration could know. */
    private const PAGE = 'zz-eigen-pagina-van-een-redacteur';

    /** owner table => the columns the words used to live in. */
    private const LEGACY_COLUMNS = [
        'page_heroes' => ['eyebrow_nl', 'eyebrow_en', 'title_nl', 'title_en', 'lead_nl', 'lead_en', 'breadcrumb_label_nl', 'breadcrumb_label_en'],
        'form_blocks' => ['title_nl', 'title_en', 'intro_nl', 'intro_en'],
        'contact_form_sections' => ['title_nl', 'title_en'],
        'item_galleries' => ['eyebrow_nl', 'eyebrow_en', 'title_nl', 'title_en', 'lead_nl', 'lead_en', 'footer_note_nl', 'footer_note_en', 'button_label_nl', 'button_label_en'],
    ];

    /** The language-neutral columns that must come through untouched, in table order. */
    private const NEUTRAL_COLUMNS = [
        'page_heroes' => 'id, page_slug, media_id, content_position, title_size, text_size, is_active, created_at, updated_at',
        'form_blocks' => 'id, page_slug, section_key, form_id, is_active, created_at, updated_at',
        'contact_form_sections' => 'id, page_slug, section_key, is_active, created_at, updated_at, form_id, allow_attachment',
        'item_galleries' => 'id, page_slug, section_key, source_type, portfolio_scope, collection_id, max_items, show_filter_bar, enable_lightbox, fallback_link_url, button_url, background, tight_top, is_active, created_at, updated_at',
    ];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $neutralBefore = [];

    /** @var list<array<string, mixed>> the words phase 3A had already moved */
    private static array $earlierWordsBefore = [];

    /** @var array<string, string|null> wave => the message its broken database stopped with */
    private static array $brokenFailure = [];

    /** @var array<string, list<string>> table => its columns after the refused migration */
    private static array $brokenColumnsAfterFailure = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::seedWaveA(self::$upgraded);
        foreach (self::NEUTRAL_COLUMNS as $table => $columns) {
            self::$neutralBefore[$table] = self::$upgraded->rows("SELECT {$columns} FROM {$table} ORDER BY id");
        }
        self::$earlierWordsBefore = self::$upgraded->rows('SELECT * FROM block_translations ORDER BY id');
        self::$upgraded->catchUp();

        $broken = ScratchInstall::upTo(self::BROKEN_A, self::BEFORE);
        self::seedWaveA($broken);
        self::withoutEnglish($broken);
        try {
            $broken->catchUp(self::WAVE_A);
            self::$brokenFailure['A'] = null;
        } catch (\RuntimeException $e) {
            self::$brokenFailure['A'] = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure['page_heroes'] = self::columns($broken, 'page_heroes');
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

    public function testBothDatabasesEndWithTheSameSchema(): void
    {
        foreach (array_merge(['block_translations'], array_keys(self::LEGACY_COLUMNS)) as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }
    }

    public function testTheOldWordColumnsAreGoneAndEveryLanguageNeutralColumnStays(): void
    {
        foreach (self::LEGACY_COLUMNS as $table => $legacy) {
            $columns = self::columns(self::$upgraded, $table);

            self::assertSame([], array_values(array_intersect($legacy, $columns)), $table);
            self::assertSame(
                array_map('trim', explode(',', self::NEUTRAL_COLUMNS[$table])),
                $columns,
                $table . ' keeps exactly its language-neutral columns'
            );
        }
    }

    // ------------------------------------------------------------ wave A: parent rows

    public function testAPageHeroMovesFieldByFieldAndItsBreadcrumbLabelIsNotMoved(): void
    {
        self::assertSame(
            [
                'en' => ['eyebrow' => 'About', 'title' => 'Who we are'],
                'nl' => ['eyebrow' => 'Over ons', 'title' => ' Wie wij zijn ', 'lead' => "Twee regels\nlead"],
            ],
            self::words('page_heroes', 'zz-hero-both'),
            'surrounding spaces and line breaks inside words are kept as stored; the breadcrumb label is nobody\'s words'
        );
        self::assertSame(['nl' => ['title' => 'Alleen Nederlands']], self::words('page_heroes', 'zz-hero-dutch'), 'a tab-only lead and an empty English are no words');
        self::assertSame(['en' => ['title' => 'Only English']], self::words('page_heroes', 'zz-hero-english'));
        self::assertSame([], self::words('page_heroes', 'zz-hero-empty'), 'a block without words has no rows');
    }

    public function testTheFormBlocksMoveOnlyTheirOwnHeadingAndIntroduction(): void
    {
        self::assertSame(
            ['en' => ['title' => 'Send us a message', 'intro' => 'We answer within a day.'], 'nl' => ['title' => 'Stuur ons een bericht']],
            self::words('form_blocks', 'zz-form')
        );
        self::assertSame(['nl' => ['title' => 'Vraag een offerte aan']], self::words('contact_form_sections', 'zz-contact'));
    }

    public function testTheSharedGalleryTableMovesTheWordsOfBothBlockTypes(): void
    {
        self::assertSame(
            [
                'en' => ['eyebrow' => 'Work', 'title' => 'Our projects', 'footer_note' => 'And more.', 'button_label' => 'All work'],
                'nl' => ['eyebrow' => 'Werk', 'title' => 'Onze projecten', 'lead' => 'Een greep.', 'footer_note' => 'En meer.', 'button_label' => 'Al het werk'],
            ],
            self::words('item_galleries', 'zz-gallery'),
            'a Galerij row'
        );
        self::assertSame(['nl' => ['title' => 'Projecten', 'lead' => 'Wat wij maakten.']], self::words('item_galleries', 'zz-projects'), 'a Projecten row');
    }

    // ------------------------------------------------------------ for every wave

    public function testNothingLanguageNeutralAboutABlockChanged(): void
    {
        foreach (self::NEUTRAL_COLUMNS as $table => $columns) {
            self::assertNotSame([], self::$neutralBefore[$table], $table);
            self::assertSame(self::$neutralBefore[$table], self::$upgraded->rows("SELECT {$columns} FROM {$table} ORDER BY id"), $table);
        }
    }

    public function testTheWordsPhase3AMovedStayAsTheyWere(): void
    {
        $earlier = array_values(array_filter(
            self::$upgraded->rows('SELECT * FROM block_translations ORDER BY id'),
            static fn (array $row): bool => in_array($row['owner_table'], ['rich_text_sections', 'cta_bands', 'contact_cards'], true)
        ));

        self::assertSame(self::$earlierWordsBefore, $earlier);
    }

    public function testEveryMovedWordHasAnOwnerAndNoLanguageInItsKey(): void
    {
        foreach (array_keys(self::LEGACY_COLUMNS) as $table) {
            self::assertSame(
                [],
                self::$upgraded->rows(
                    "SELECT t.id FROM block_translations t LEFT JOIN {$table} o ON o.id = t.owner_id
                      WHERE t.owner_table = ? AND o.id IS NULL",
                    [$table]
                ),
                $table
            );
        }

        self::assertSame(
            [],
            self::$upgraded->rows("SELECT field FROM block_translations WHERE field LIKE '%\\_nl' OR field LIKE '%\\_en' OR language_code NOT IN ('nl', 'en')"),
            'field keys carry no language, and only the two V1 languages were moved'
        );
    }

    public function testRunningAgainChangesNothing(): void
    {
        $query = 'SELECT owner_table, owner_id, language_code, field, value, created_at, updated_at FROM block_translations ORDER BY owner_table, owner_id, language_code, field';
        $before = self::$upgraded->rows($query);
        $shapes = array_map(static fn (string $table): array => self::shape(self::$upgraded, $table), array_keys(self::LEGACY_COLUMNS));

        self::$upgraded->replay(self::WAVE_A);

        self::assertSame($before, self::$upgraded->rows($query));
        self::assertSame($shapes, array_map(static fn (string $table): array => self::shape(self::$upgraded, $table), array_keys(self::LEGACY_COLUMNS)));
    }

    public function testWordsThatCannotBeMovedStopTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure['A'] ?? null, 'wave A must refuse');
        self::assertStringContainsString('could not be moved into block_translations', (string) self::$brokenFailure['A']);
        foreach (self::LEGACY_COLUMNS['page_heroes'] as $column) {
            self::assertContains($column, self::$brokenColumnsAfterFailure['page_heroes'], $column . ' is still there to move later');
        }
    }

    // ------------------------------------------------------------ helpers

    /** The wave A tables, in every state their columns could be in, on a page an editor made. */
    private static function seedWaveA(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $hero = $pdo->prepare(
            'INSERT INTO page_heroes
                (page_slug, eyebrow_nl, eyebrow_en, title_nl, title_en, lead_nl, lead_en, breadcrumb_label_nl, breadcrumb_label_en,
                 content_position, title_size, text_size, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        foreach ([
            ['zz-hero-both', 'Over ons', 'About', ' Wie wij zijn ', 'Who we are', "Twee regels\nlead", null, 'Kruimel', 'Crumb', 'center', 'large', 'small', 1],
            ['zz-hero-dutch', '', '', 'Alleen Nederlands', null, "\t", '  ', '', null, 'left', 'normal', 'normal', 1],
            ['zz-hero-english', null, null, '', 'Only English', null, null, '', null, 'right', 'small', 'large', 0],
            ['zz-hero-empty', null, '', " \r\n ", null, null, null, 'Alleen een kruimel', null, 'left', 'normal', 'normal', 1],
        ] as $row) {
            $hero->execute($row);
        }

        $pdo->prepare(
            'INSERT INTO form_blocks (page_slug, section_key, form_id, title_nl, title_en, intro_nl, intro_en, is_active, created_at, updated_at)
             VALUES (?, ?, NULL, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([self::PAGE, 'zz-form', 'Stuur ons een bericht', 'Send us a message', null, 'We answer within a day.']);

        $pdo->prepare(
            'INSERT INTO contact_form_sections (page_slug, section_key, title_nl, title_en, form_id, allow_attachment, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, 1, 0, NOW(), NOW())'
        )->execute([self::PAGE, 'zz-contact', 'Vraag een offerte aan', '']);

        $gallery = $pdo->prepare(
            'INSERT INTO item_galleries
                (page_slug, section_key, source_type, portfolio_scope, max_items, show_filter_bar, enable_lightbox, fallback_link_url,
                 eyebrow_nl, eyebrow_en, title_nl, title_en, lead_nl, lead_en, footer_note_nl, footer_note_en,
                 button_label_nl, button_label_en, button_url, background, tight_top, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $gallery->execute([self::PAGE, 'zz-gallery', 'portfolio', 'all', 12, 1, 1, '/werk',
            'Werk', 'Work', 'Onze projecten', 'Our projects', 'Een greep.', null, 'En meer.', 'And more.',
            'Al het werk', 'All work', '/werk', 'soft', 1, 1]);
        $gallery->execute([self::PAGE, 'zz-projects', 'portfolio', 'featured', null, 0, 0, null,
            '', '', 'Projecten', '', 'Wat wij maakten.', '', '', '',
            '', '', null, 'default', 0, 1]);
    }

    /** No English in the registry, and nothing that still points at it. */
    private static function withoutEnglish(ScratchInstall $install): void
    {
        $install->pdo()->exec("DELETE FROM page_translations WHERE language_code = 'en'");
        $install->pdo()->exec("DELETE FROM block_translations WHERE language_code = 'en'");
        $install->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
    }

    /**
     * The words of one owner row, found by its section key (or, for a
     * Paginakop, its page slug).
     *
     * @return array<string, array<string, string>> language => field => words, languages sorted, fields in stored order
     */
    private static function words(string $table, string $key): array
    {
        $match = $table === 'page_heroes' ? 'o.page_slug = ?' : 'o.page_slug = ? AND o.section_key = ?';
        $parameters = $table === 'page_heroes' ? [$table, $key] : [$table, self::PAGE, $key];

        $words = [];
        foreach (self::$upgraded->rows(
            "SELECT t.language_code AS language_code, t.field AS field, t.value AS value
               FROM block_translations t JOIN {$table} o ON o.id = t.owner_id
              WHERE t.owner_table = ? AND {$match}
              ORDER BY t.language_code, t.id",
            $parameters
        ) as $row) {
            $words[$row['language_code']][$row['field']] = $row['value'];
        }

        return $words;
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
    private static function shape(ScratchInstall $install, string $table): array
    {
        $columns = [];
        foreach ($install->rows(
            'SELECT column_name AS column_name, column_type AS column_type, is_nullable AS is_nullable, '
            . 'collation_name AS collation_name FROM information_schema.columns '
            . 'WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$install->database, $table]
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
