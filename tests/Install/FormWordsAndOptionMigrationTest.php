<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Multilingual 2.0 phase 4 wave C on the kinds of database it meets
 * (docs/multilingual/ARCHITECTURE.md, FORMS.md):
 *
 *   20260918140000_create_the_form_translation_and_option_tables.php
 *   20260918150000_move_form_words_and_options_into_translation_tables.php
 *
 *   fresh      every migration from zero, up to MOVE
 *   upgraded   an installation that stood just before wave C, with forms and
 *              fields in every state their Dutch/English columns could be in
 *              and option lines in every state the old parser accepted
 *   broken     the same, with English words but no English row in the registry
 *
 * What must hold: Dutch stays Dutch and English stays English, byte for byte;
 * an empty or whitespace value gets no row; EVERY OPTION KEEPS ITS DUTCH
 * LABEL AS ITS VALUE, which is what its public form posted, its submissions
 * hold and its default names, so `default_value` keeps matching without being
 * rewritten; the old parser's rules (a line without a Dutch half, a duplicate,
 * everything past fifty) are applied exactly as they were; ids, keys, types,
 * requiredness and order do not change; a second run changes nothing; and
 * words that cannot be moved stop the migration before any column is dropped.
 */
#[Group('migration-backfill')]
final class FormWordsAndOptionMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_form_words_fresh';
    private const UPGRADED = 'mygdala_scratch_form_words_upgraded';
    private const BROKEN = 'mygdala_scratch_form_words_broken';

    /** The last migration before wave C. */
    private const BEFORE = '20260918130000';

    private const SCHEMA = '20260918140000';
    private const MOVE = '20260918150000';

    private const NEUTRAL_FIELD_COLUMNS = 'id, form_id, field_key, field_type, is_required, sort_order, default_value';
    private const NEUTRAL_FORM_COLUMNS = 'id, name, internal_key, is_active, notification_email, reply_to_field_key, store_submissions';

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
        self::$neutralBefore['forms'] = self::$upgraded->rows('SELECT ' . self::NEUTRAL_FORM_COLUMNS . ' FROM forms ORDER BY id');
        self::$neutralBefore['form_fields'] = self::$upgraded->rows('SELECT ' . self::NEUTRAL_FIELD_COLUMNS . ' FROM form_fields ORDER BY id');
        self::$upgraded->catchUp(self::MOVE);
        self::$rowsAfterFirstRun = self::rowCount(self::$upgraded);
        self::$upgraded->replay(self::SCHEMA, self::MOVE);
        self::$upgraded->replay(self::MOVE, self::MOVE);
        self::$rowsAfterReplay = self::rowCount(self::$upgraded);

        $broken = ScratchInstall::upTo(self::BROKEN, self::SCHEMA);
        self::seed($broken);
        foreach (['page_translations', 'block_translations', 'nav_item_translations', 'footer_column_translations', 'footer_link_translations', 'site_setting_translations'] as $table) {
            $broken->pdo()->exec("DELETE FROM {$table} WHERE language_code = 'en'");
        }
        $broken->pdo()->exec("DELETE FROM site_languages WHERE code = 'en'");
        try {
            $broken->catchUp(self::MOVE);
        } catch (\RuntimeException $e) {
            self::$brokenFailure = $e->getMessage();
        }
        self::$brokenColumnsAfterFailure = array_column($broken->rows('SHOW COLUMNS FROM form_fields'), 'Field');
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

    public function testBothDatabasesEndWithTheSameSchemaAndNoWordsInTheOldTables(): void
    {
        foreach (['forms', 'form_fields', 'form_translations', 'form_field_translations', 'form_field_options', 'form_field_option_translations'] as $table) {
            self::assertSame(self::shape(self::$fresh, $table), self::shape(self::$upgraded, $table), $table);
        }

        foreach (['forms', 'form_fields'] as $table) {
            foreach (array_keys(self::shape(self::$fresh, $table)) as $column) {
                self::assertDoesNotMatchRegularExpression('/_(nl|en)$/', $column, $table . '.' . $column . ' is a language column');
                self::assertNotSame('options', $column, 'the option lines are rows now');
            }
        }

        self::assertSame(
            ['id', 'form_field_id', 'value', 'sort_order', 'created_at', 'updated_at'],
            array_keys(self::shape(self::$fresh, 'form_field_options'))
        );
    }

    public function testAnOptionValueIsUniquePerFieldAndComparedExactly(): void
    {
        $index = self::$fresh->rows(
            "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_index
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'form_field_options' AND non_unique = 0 AND index_name <> 'PRIMARY'
              GROUP BY index_name"
        );
        self::assertSame([['columns_in_index' => 'form_field_id,value']], $index);

        $collation = self::$fresh->rows(
            "SELECT collation_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'form_field_options' AND column_name = 'value'"
        );
        self::assertSame('utf8mb4_bin', (string) ($collation[0]['collation_name'] ?? $collation[0]['COLLATION_NAME'] ?? ''));
    }

    // ------------------------------------------------------------- the words

    public function testEveryFormKeepsItsWordsPerLanguageAndAnEmptyOneGetsNoRow(): void
    {
        self::assertSame(
            [
                ['language_code' => 'en', 'submit_label' => 'Send request', 'success_message' => null],
                ['language_code' => 'nl', 'submit_label' => 'Verstuur aanvraag', 'success_message' => 'Bedankt!'],
            ],
            self::$upgraded->rows(
                'SELECT language_code, submit_label, success_message FROM form_translations WHERE form_id = ? ORDER BY language_code',
                [self::$ids['form']]
            )
        );

        self::assertSame(
            [],
            self::$upgraded->rows('SELECT id FROM form_translations WHERE form_id = ?', [self::$ids['empty_form']]),
            'a form whose words were all empty has no row in any language'
        );
    }

    public function testEveryFieldKeepsItsWordsPerLanguage(): void
    {
        self::assertSame(
            [
                ['language_code' => 'en', 'label' => 'Who for', 'placeholder' => null, 'help_text' => 'Choose one'],
                ['language_code' => 'nl', 'label' => 'Voor wie', 'placeholder' => 'Van vroeger', 'help_text' => 'Kies er een'],
            ],
            self::$upgraded->rows(
                'SELECT language_code, label, placeholder, help_text FROM form_field_translations WHERE form_field_id = ? ORDER BY language_code',
                [self::$ids['choice']]
            )
        );

        self::assertSame(
            [['language_code' => 'nl', 'label' => 'Naam', 'placeholder' => null, 'help_text' => null]],
            self::$upgraded->rows(
                'SELECT language_code, label, placeholder, help_text FROM form_field_translations WHERE form_field_id = ? ORDER BY language_code',
                [self::$ids['text']]
            ),
            'whitespace is no translation'
        );
    }

    // ----------------------------------------------------------- the options

    public function testEveryOptionKeepsItsDutchLabelAsItsValueAndItsTranslation(): void
    {
        self::assertSame(
            [
                ['value' => 'Particulier', 'sort_order' => 0, 'nl' => 'Particulier', 'en' => 'Personal'],
                ['value' => 'Zakelijk', 'sort_order' => 1, 'nl' => 'Zakelijk', 'en' => null],
                ['value' => 'Overig', 'sort_order' => 2, 'nl' => 'Overig', 'en' => 'Other'],
            ],
            self::options(self::$ids['choice']),
            'the parser rules of the column it came from: trimmed, a line without a Dutch half and a duplicate dropped'
        );
    }

    public function testTheStoredDefaultStillNamesAnOption(): void
    {
        $field = self::$upgraded->rows('SELECT default_value FROM form_fields WHERE id = ?', [self::$ids['choice']]);

        self::assertSame('Zakelijk', (string) $field[0]['default_value'], 'the default was not rewritten');
        self::assertContains('Zakelijk', array_column(self::options(self::$ids['choice']), 'value'), 'and it still names one of the options');
    }

    public function testTheFiftyOptionCapIsTheOneTheParserApplied(): void
    {
        self::assertCount(50, self::options(self::$ids['long']));
    }

    public function testAFieldWithoutOptionsGetsNone(): void
    {
        self::assertSame([], self::options(self::$ids['text']));
    }

    // --------------------------------------------------------------- the rest

    public function testNothingLanguageNeutralChanged(): void
    {
        self::assertSame(self::$neutralBefore['forms'], self::$upgraded->rows('SELECT ' . self::NEUTRAL_FORM_COLUMNS . ' FROM forms ORDER BY id'));
        self::assertSame(self::$neutralBefore['form_fields'], self::$upgraded->rows('SELECT ' . self::NEUTRAL_FIELD_COLUMNS . ' FROM form_fields ORDER BY id'));
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertGreaterThan(0, self::$rowsAfterFirstRun);
        self::assertSame(self::$rowsAfterFirstRun, self::$rowsAfterReplay);
    }

    public function testWordsThatCannotBeMovedStopTheMigrationBeforeTheDrop(): void
    {
        self::assertNotNull(self::$brokenFailure, 'the migration must refuse');
        self::assertStringContainsString('"en"', (string) self::$brokenFailure, 'and say which language it was');
        self::assertContains('label_en', self::$brokenColumnsAfterFailure, 'nothing was dropped');
        self::assertContains('options', self::$brokenColumnsAfterFailure);
    }

    public function testAFreshInstallHasNoFormsAndSoNoWords(): void
    {
        self::assertSame(0, self::$fresh->count('form_translations'));
        self::assertSame(0, self::$fresh->count('form_field_options'));
    }

    // --------------------------------------------------------------- helpers

    /** @return array<string, int> the id of every row it placed */
    private static function seed(ScratchInstall $install): array
    {
        $ids = [];
        $pdo = $install->pdo();

        $form = $pdo->prepare(
            'INSERT INTO forms (name, internal_key, is_active, submit_label_nl, submit_label_en, success_message_nl, success_message_en, store_submissions, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, 1, ?, ?)'
        );
        $form->execute(['zz Aanvraag', 'zz-aanvraag', 'Verstuur aanvraag', 'Send request', 'Bedankt!', "  \t", '2026-01-02 03:04:05', '2026-02-03 04:05:06']);
        $ids['form'] = (int) $pdo->lastInsertId();

        $form->execute(['zz Leeg', 'zz-leeg', '', null, '   ', '', '2026-01-02 03:04:05', '2026-01-02 03:04:05']);
        $ids['empty_form'] = (int) $pdo->lastInsertId();

        $field = $pdo->prepare(
            'INSERT INTO form_fields (form_id, field_key, field_type, label_nl, label_en, placeholder_nl, placeholder_en, help_text_nl, help_text_en, is_required, sort_order, options, default_value, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );

        $field->execute([
            $ids['form'], 'voor-wie', 'radio', 'Voor wie', 'Who for', 'Van vroeger', null, 'Kies er een', 'Choose one', 1, 0,
            "Particulier|Personal\nZakelijk\n\n  Overig | Other  \nParticulier|Dubbel\n   ",
            'Zakelijk',
        ]);
        $ids['choice'] = (int) $pdo->lastInsertId();

        $field->execute([$ids['form'], 'naam', 'text', 'Naam', "  \n", null, ' ', null, null, 1, 1, null, null]);
        $ids['text'] = (int) $pdo->lastInsertId();

        $lines = [];
        for ($i = 0; $i < 70; $i++) {
            $lines[] = 'Optie ' . $i;
        }
        $field->execute([$ids['form'], 'lange-lijst', 'select', 'Lange lijst', null, null, null, null, null, 0, 2, implode("\n", $lines), null]);
        $ids['long'] = (int) $pdo->lastInsertId();

        return $ids;
    }

    /** @return list<array{value: string, sort_order: int, nl: ?string, en: ?string}> */
    private static function options(int $fieldId): array
    {
        return array_map(
            static fn (array $row): array => [
                'value' => (string) $row['value'],
                'sort_order' => (int) $row['sort_order'],
                'nl' => $row['nl'],
                'en' => $row['en'],
            ],
            self::$upgraded->rows(
                "SELECT o.value, o.sort_order, nl.label AS nl, en.label AS en
                   FROM form_field_options o
                   LEFT JOIN form_field_option_translations nl ON nl.form_field_option_id = o.id AND nl.language_code = 'nl'
                   LEFT JOIN form_field_option_translations en ON en.form_field_option_id = o.id AND en.language_code = 'en'
                  WHERE o.form_field_id = ?
                  ORDER BY o.sort_order, o.id",
                [$fieldId]
            )
        );
    }

    private static function rowCount(ScratchInstall $install): int
    {
        return $install->count('form_translations')
            + $install->count('form_field_translations')
            + $install->count('form_field_options')
            + $install->count('form_field_option_translations');
    }

    /** @return array<string, string> */
    private static function shape(ScratchInstall $install, string $table): array
    {
        $shape = [];
        foreach ($install->rows("SHOW FULL COLUMNS FROM {$table}") as $column) {
            $shape[$column['Field']] = $column['Type'] . ' ' . $column['Null'] . ' ' . (string) $column['Collation'];
        }

        return $shape;
    }
}
