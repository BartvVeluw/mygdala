<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 3A on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md):
 *
 *   20260917160000_create_the_block_translations_table.php     the table
 *   20260917170000_move_rich_text_cta_band_and_contact_card_…  the words of
 *                                                              three block types,
 *                                                              and their old columns gone
 *
 *   fresh      every migration from zero
 *   upgraded   an installation that stood just before this phase, with Tekstblok,
 *              Oproep met knop and Contactkaart instances in every state their
 *              Dutch/English columns could be in, on a page a user made
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: the same schema everywhere; Dutch stays Dutch and English
 * stays English, field by field; NULL, '' and whitespace of any kind are no
 * words and get no row; words are copied byte for byte; ids, page, key,
 * URLs and is_active do not change; no other block type is touched; a second
 * run changes nothing; and words that cannot be moved stop the migration
 * before anything is dropped.
 *
 * Every database stops at MOVE: phase 3B moves the other block types in later
 * migrations (Tests\Install\RemainingBlockWordsMigrationTest), and "no other
 * block type is touched" is a statement about this migration.
 */
#[Group('migration-backfill')]
final class BlockTranslationMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_block_translations_fresh';
    private const UPGRADED = 'mygdala_scratch_block_translations_upgraded';
    private const BROKEN = 'mygdala_scratch_block_translations_broken';

    /** The last migration before this phase. */
    private const BEFORE = '20260917150000';

    private const SCHEMA = '20260917160000';
    private const MOVE = '20260917170000';

    /** A page an editor made, with a slug no migration could know. */
    private const PAGE = 'zz-eigen-pagina-van-een-redacteur';

    private const LEGACY_COLUMNS = [
        'rich_text_sections' => ['content_html', 'content_html_en'],
        'cta_bands' => ['eyebrow_nl', 'eyebrow_en', 'title_nl', 'title_en', 'lead_nl', 'lead_en', 'primary_label_nl', 'primary_label_en', 'secondary_label_nl', 'secondary_label_en'],
        'contact_cards' => ['title_nl', 'title_en', 'body_nl', 'body_en', 'button_label_nl', 'button_label_en'],
    ];

    /** The language-neutral columns that must come through untouched. */
    private const NEUTRAL_COLUMNS = [
        'rich_text_sections' => 'id, page_slug, section_key, is_active, created_at, updated_at',
        'cta_bands' => 'id, page_slug, section_key, primary_url, secondary_url, is_active, created_at, updated_at',
        'contact_cards' => 'id, page_slug, section_key, button_url, is_active, created_at, updated_at',
    ];

    private const RICH_BOTH = '<h2>Één kop</h2><p>Tekst &amp; meer, met <strong>vet</strong>.</p>';
    private const RICH_ENGLISH = '<p>English <em>body</em></p>';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $neutralBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $otherBlocksBefore = [];

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
        self::seed(self::$upgraded);
        foreach (self::NEUTRAL_COLUMNS as $table => $columns) {
            self::$neutralBefore[$table] = self::$upgraded->rows("SELECT {$columns} FROM {$table} ORDER BY id");
        }
        self::$otherBlocksBefore = self::$upgraded->rows('SELECT * FROM feature_grids ORDER BY id');
        self::$upgraded->catchUp(self::MOVE);

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        $broken->pdo()->exec("DELETE FROM page_translations WHERE language_code = 'en'");
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = self::columns($broken, 'rich_text_sections');
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
        foreach (['block_translations', 'rich_text_sections', 'cta_bands', 'contact_cards'] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        self::assertSame(
            ['id', 'owner_table', 'owner_id', 'language_code', 'field', 'value', 'created_at', 'updated_at'],
            array_keys(self::shape(self::$fresh, 'block_translations'))
        );
    }

    public function testTheOldWordColumnsOfTheThreeBlocksAreGoneAndNothingElseIs(): void
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

        self::assertContains('title_nl', self::columns(self::$upgraded, 'feature_grids'), 'a block type outside phase 3A keeps its columns');
    }

    // ------------------------------------------------------------ the words

    public function testARichTextBodyMovesByteForByteIntoItsOwnLanguage(): void
    {
        self::assertSame(['en' => ['body' => self::RICH_ENGLISH], 'nl' => ['body' => self::RICH_BOTH]], self::words('rich_text_sections', 'zz-rich-both'));
        self::assertSame(['nl' => ['body' => self::RICH_BOTH]], self::words('rich_text_sections', 'zz-rich-dutch'));
        self::assertSame(['en' => ['body' => self::RICH_ENGLISH]], self::words('rich_text_sections', 'zz-rich-english'));
    }

    public function testNullEmptyAndWhitespaceOfAnyKindAreNoWords(): void
    {
        self::assertSame(['nl' => ['body' => self::RICH_BOTH]], self::words('rich_text_sections', 'zz-rich-blank-english'), "a tab, a line break, spaces and '' are no English");
        self::assertSame([], self::words('rich_text_sections', 'zz-rich-empty'), 'a block without words has no rows');
    }

    public function testACtaBandMovesFieldByField(): void
    {
        self::assertSame(
            [
                'en' => ['eyebrow' => 'New', 'title' => 'Get in touch', 'primary_label' => 'Contact us', 'secondary_label' => 'Our work'],
                'nl' => ['eyebrow' => 'Nieuw', 'title' => ' Neem contact op ', 'lead' => "Twee regels\nlead", 'primary_label' => 'Contact', 'secondary_label' => 'Ons werk'],
            ],
            self::words('cta_bands', 'zz-cta-full'),
            'surrounding spaces and line breaks inside words are kept as stored'
        );

        self::assertSame(
            ['nl' => ['eyebrow' => 'Alleen NL', 'title' => 'Titel', 'primary_label' => 'Knop']],
            self::words('cta_bands', 'zz-cta-dutch'),
            'a tab-only lead and an empty English are no words'
        );
    }

    public function testAContactCardMovesFieldByField(): void
    {
        self::assertSame(
            [
                'en' => ['title' => 'Rather email?', 'button_label' => 'Email us'],
                'nl' => ['title' => 'Liever mailen?', 'body' => 'Wij antwoorden snel.', 'button_label' => 'Mail ons'],
            ],
            self::words('contact_cards', 'zz-card')
        );
    }

    public function testNothingLanguageNeutralAboutABlockChanged(): void
    {
        foreach (self::NEUTRAL_COLUMNS as $table => $columns) {
            self::assertNotSame([], self::$neutralBefore[$table], $table);
            self::assertSame(self::$neutralBefore[$table], self::$upgraded->rows("SELECT {$columns} FROM {$table} ORDER BY id"), $table);
        }

        self::assertSame(self::$otherBlocksBefore, self::$upgraded->rows('SELECT * FROM feature_grids ORDER BY id'));
    }

    public function testOnlyTheThreeBlockTablesOwnWords(): void
    {
        $owners = array_column(
            self::$upgraded->rows('SELECT DISTINCT owner_table AS owner_table FROM block_translations ORDER BY owner_table'),
            'owner_table'
        );

        self::assertSame([], array_values(array_diff($owners, ['contact_cards', 'cta_bands', 'rich_text_sections'])));
        self::assertSame(
            [],
            self::$upgraded->rows("SELECT field FROM block_translations WHERE field LIKE '%\\_nl' OR field LIKE '%\\_en' OR language_code NOT IN ('nl', 'en')"),
            'field keys carry no language, and only the two V1 languages were moved'
        );
    }

    public function testEveryMovedWordHasAnOwner(): void
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
    }

    public function testRunningBothAgainChangesNothing(): void
    {
        $query = 'SELECT owner_table, owner_id, language_code, field, value FROM block_translations ORDER BY owner_table, owner_id, language_code, field';
        $before = self::$upgraded->rows($query);
        $shapes = array_map(static fn (string $table): array => self::shape(self::$upgraded, $table), array_keys(self::LEGACY_COLUMNS));

        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);

        self::assertSame($before, self::$upgraded->rows($query));
        self::assertSame($shapes, array_map(static fn (string $table): array => self::shape(self::$upgraded, $table), array_keys(self::LEGACY_COLUMNS)));
    }

    public function testWordsThatCannotBeMovedStopTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure, 'the migration must refuse');
        self::assertStringContainsString('could not be moved into block_translations', (string) self::$brokenFailure);
        foreach (self::LEGACY_COLUMNS['rich_text_sections'] as $column) {
            self::assertContains($column, self::$brokenColumnsAfterFailure, $column . ' is still there to move later');
        }
    }

    // ------------------------------------------------------------ helpers

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();

        $rich = $pdo->prepare(
            'INSERT INTO rich_text_sections (page_slug, section_key, content_html, content_html_en, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        foreach ([
            ['zz-rich-both', self::RICH_BOTH, self::RICH_ENGLISH, 1],
            ['zz-rich-dutch', self::RICH_BOTH, null, 1],
            ['zz-rich-english', '', self::RICH_ENGLISH, 0],
            ['zz-rich-blank-english', self::RICH_BOTH, " \t\r\n ", 1],
            ['zz-rich-empty', null, '', 1],
        ] as [$key, $dutch, $english, $active]) {
            $rich->execute([self::PAGE, $key, $dutch, $english, $active]);
        }

        $cta = $pdo->prepare(
            'INSERT INTO cta_bands
                (page_slug, section_key, eyebrow_nl, eyebrow_en, title_nl, title_en, lead_nl, lead_en,
                 primary_label_nl, primary_label_en, primary_url, secondary_label_nl, secondary_label_en, secondary_url,
                 is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $cta->execute([self::PAGE, 'zz-cta-full', 'Nieuw', 'New', ' Neem contact op ', 'Get in touch', "Twee regels\nlead", null,
            'Contact', 'Contact us', '/contact', 'Ons werk', 'Our work', '/werk', 1]);
        $cta->execute([self::PAGE, 'zz-cta-dutch', 'Alleen NL', '', 'Titel', null, "\t", '  ',
            'Knop', '', '/', null, null, null, 0]);

        $pdo->prepare(
            'INSERT INTO contact_cards
                (page_slug, section_key, title_nl, title_en, body_nl, body_en, button_label_nl, button_label_en, button_url, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([self::PAGE, 'zz-card', 'Liever mailen?', 'Rather email?', 'Wij antwoorden snel.', '', 'Mail ons', 'Email us', '']);
    }

    /** @return array<string, array<string, string>> language => field => words, languages and fields sorted */
    private static function words(string $table, string $sectionKey): array
    {
        $words = [];
        foreach (self::$upgraded->rows(
            "SELECT t.language_code AS language_code, t.field AS field, t.value AS value
               FROM block_translations t JOIN {$table} o ON o.id = t.owner_id
              WHERE t.owner_table = ? AND o.page_slug = ? AND o.section_key = ?
              ORDER BY t.language_code, t.id",
            [$table, self::PAGE, $sectionKey]
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
