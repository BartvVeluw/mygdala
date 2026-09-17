<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the words of Core Forms out of their fixed Dutch/English columns, and
 * each choice field's `NL|EN` option lines into option rows, then drops the
 * old columns (Multilingual 2.0 phase 4 wave C; tables from 20260918140000).
 *
 *   forms.submit_label_nl / _en, success_message_nl / _en   -> form_translations        nl / en
 *   form_fields.label_nl / _en, placeholder_nl / _en,
 *              help_text_nl / _en                            -> form_field_translations  nl / en
 *   form_fields.options                                      -> form_field_options (+ option labels nl / en)
 *
 * The columns keep the meaning V1 gave them, whatever the default language
 * is. A value with words is copied byte for byte; NULL, '' and whitespace get
 * no row, and a language without any words for an owner gets no row.
 *
 * OPTIONS, parsed exactly as App\Service\Forms\FormFieldOptions read them
 * until this migration: one per line, the text before the first `|` is the
 * Dutch label and the rest the English one, both trimmed; a line without a
 * Dutch label, a Dutch label seen before on the same field, and everything
 * past fifty are no option. Each option becomes one row whose VALUE IS ITS
 * DUTCH LABEL, the string its public form posted, every stored submission
 * holds and form_fields.default_value names — so all three keep matching
 * without being rewritten. The Dutch label is its `nl` label, the English
 * half (when it has words) its `en` label; the position is the line's.
 * Submissions are not touched: they are snapshots.
 *
 * SAFE TO RUN TWICE: an existing row is never overwritten, a field that has
 * option rows already is skipped, and once the columns are gone there is
 * nothing left to do.
 *
 * REFUSES RATHER THAN LOSES: words in a language site_languages does not
 * have, or an option longer than its column, stop the migration before any
 * column is dropped.
 */
final class MoveFormWordsAndOptionsIntoTranslationTables extends AbstractMigration
{
    private const MAX_OPTIONS = 50;
    private const MAX_OPTION_LENGTH = 255;

    /**
     * [owner table, translation table, owner column, [field, Dutch column,
     * English column]…]. Lists rather than a column map, so nothing here reads
     * as a probe of a table called "nl".
     */
    private const MOVES = [
        ['forms', 'form_translations', 'form_id', [
            ['submit_label', 'submit_label_nl', 'submit_label_en'],
            ['success_message', 'success_message_nl', 'success_message_en'],
        ]],
        ['form_fields', 'form_field_translations', 'form_field_id', [
            ['label', 'label_nl', 'label_en'],
            ['placeholder', 'placeholder_nl', 'placeholder_en'],
            ['help_text', 'help_text_nl', 'help_text_en'],
        ]],
    ];

    /** The V1 language of the Dutch [1] and English [2] column position above. */
    private const LANGUAGES = [1 => 'nl', 2 => 'en'];

    public function up(): void
    {
        foreach (['site_languages', 'forms', 'form_fields', 'form_translations', 'form_field_translations', 'form_field_options', 'form_field_option_translations'] as $table) {
            if (!$this->hasTable($table)) {
                return;
            }
        }

        foreach (self::MOVES as [$ownerTable, $translationTable, $ownerColumn, $fields]) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->copyWords($ownerTable, $translationTable, $ownerColumn, $fields, $position, $code);
            }
        }

        $this->moveOptions();

        foreach (self::MOVES as [$ownerTable, $translationTable, $ownerColumn, $fields]) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->assertWordsArrived($ownerTable, $translationTable, $ownerColumn, $fields, $position, $code);
            }
        }
        $this->assertOptionsArrived();

        foreach (self::MOVES as [$ownerTable, , , $fields]) {
            $table = $this->table($ownerTable);
            $drop = [];
            foreach ($fields as $field) {
                foreach (self::LANGUAGES as $position => $code) {
                    if ($table->hasColumn($field[$position])) {
                        $drop[] = $field[$position];
                    }
                }
            }
            if ($ownerTable === 'form_fields' && $table->hasColumn('options')) {
                $drop[] = 'options';
            }
            foreach ($drop as $column) {
                $table->removeColumn($column);
            }
            if ($drop !== []) {
                $table->update();
            }
        }
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). The words and options live in
     * their own tables now.
     */
    public function down(): void
    {
    }

    /**
     * One INSERT … SELECT per language: every owner with words in at least one
     * of that language's columns and no row for it yet.
     *
     * @param list<array{0: string, 1: string, 2: string}> $fields
     */
    private function copyWords(string $ownerTable, string $translationTable, string $ownerColumn, array $fields, int $position, string $code): void
    {
        $table = $this->table($ownerTable);
        $targets = [];
        $selects = [];
        $hasWords = [];

        foreach ($fields as $field) {
            $targets[] = '`' . $field[0] . '`';
            if ($table->hasColumn($field[$position])) {
                $column = 'o.`' . $field[$position] . '`';
                $selects[] = 'CASE WHEN ' . $this->hasWords($column) . ' THEN ' . $column . ' ELSE NULL END';
                $hasWords[] = $this->hasWords($column);
            } else {
                $selects[] = 'NULL';
            }
        }

        if ($hasWords === []) {
            return;
        }

        $this->execute(
            'INSERT INTO `' . $translationTable . '` (`' . $ownerColumn . '`, language_code, ' . implode(', ', $targets) . ', created_at, updated_at)
             SELECT o.id, ' . $this->quote($code) . ', ' . implode(', ', $selects) . ',
                    COALESCE(o.created_at, NOW()), COALESCE(o.updated_at, NOW())
               FROM `' . $ownerTable . '` o
              WHERE (' . implode(' OR ', $hasWords) . ')
                AND EXISTS (SELECT 1 FROM site_languages l WHERE l.code = ' . $this->quote($code) . ')
                AND NOT EXISTS (
                    SELECT 1 FROM `' . $translationTable . '` t
                     WHERE t.`' . $ownerColumn . '` = o.id AND t.language_code = ' . $this->quote($code) . '
                )
              ORDER BY o.id'
        );
    }

    /** @param list<array{0: string, 1: string, 2: string}> $fields */
    private function assertWordsArrived(string $ownerTable, string $translationTable, string $ownerColumn, array $fields, int $position, string $code): void
    {
        $table = $this->table($ownerTable);
        $hasWords = [];
        foreach ($fields as $field) {
            if ($table->hasColumn($field[$position])) {
                $hasWords[] = $this->hasWords('o.`' . $field[$position] . '`');
            }
        }

        if ($hasWords === []) {
            return;
        }

        $row = $this->fetchRow(
            'SELECT COUNT(*) AS c FROM `' . $ownerTable . '` o
              WHERE (' . implode(' OR ', $hasWords) . ')
                AND NOT EXISTS (
                    SELECT 1 FROM `' . $translationTable . '` t
                     WHERE t.`' . $ownerColumn . '` = o.id AND t.language_code = ' . $this->quote($code) . '
                )'
        );

        if ((int) ($row['c'] ?? 0) > 0) {
            throw new \RuntimeException(sprintf(
                '%d row(s) of %s have words in language "%s" that could not be moved into %s, most likely because '
                . 'site_languages has no row for "%s". Nothing was dropped.',
                (int) $row['c'],
                $ownerTable,
                $code,
                $translationTable,
                $code
            ));
        }
    }

    /** Every field's option lines, as the old read model parsed them, into rows. */
    private function moveOptions(): void
    {
        if (!$this->table('form_fields')->hasColumn('options')) {
            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $languages = array_column($this->fetchAll('SELECT code FROM site_languages'), 'code');
        $insertOption = $pdo->prepare(
            'INSERT INTO form_field_options (form_field_id, value, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $insertLabel = $pdo->prepare(
            'INSERT INTO form_field_option_translations (form_field_option_id, language_code, label, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($this->fetchAll('SELECT id, options, created_at, updated_at FROM form_fields WHERE options IS NOT NULL ORDER BY id') as $field) {
            $options = self::parse((string) $field['options']);
            if ($options === []) {
                continue;
            }

            $existing = $this->fetchRow('SELECT COUNT(*) AS c FROM form_field_options WHERE form_field_id = ' . (int) $field['id']);
            if ((int) ($existing['c'] ?? 0) > 0) {
                continue;
            }

            foreach ($options as [$dutch, $english]) {
                if (mb_strlen($dutch) > self::MAX_OPTION_LENGTH || mb_strlen($english) > self::MAX_OPTION_LENGTH) {
                    throw new \RuntimeException(sprintf(
                        'Form field %d has an option longer than %d characters, which no public form could ever have accepted. '
                        . 'Shorten it in form_fields.options and run the migration again. Nothing was dropped.',
                        (int) $field['id'],
                        self::MAX_OPTION_LENGTH
                    ));
                }
                if (!in_array('nl', $languages, true) || ($english !== '' && !in_array('en', $languages, true))) {
                    // Left for assertOptionsArrived() to report; nothing is dropped.
                    return;
                }
            }

            $created = $field['created_at'] ?? date('Y-m-d H:i:s');
            $updated = $field['updated_at'] ?? $created;

            foreach ($options as $position => [$dutch, $english]) {
                $insertOption->execute([(int) $field['id'], $dutch, $position, $created, $updated]);
                $optionId = (int) $pdo->lastInsertId();

                $insertLabel->execute([$optionId, 'nl', $dutch, $created, $updated]);
                if ($english !== '') {
                    $insertLabel->execute([$optionId, 'en', $english, $created, $updated]);
                }
            }
        }
    }

    private function assertOptionsArrived(): void
    {
        if (!$this->table('form_fields')->hasColumn('options')) {
            return;
        }

        foreach ($this->fetchAll('SELECT id, options FROM form_fields WHERE options IS NOT NULL ORDER BY id') as $field) {
            $expected = count(self::parse((string) $field['options']));
            $stored = $this->fetchRow('SELECT COUNT(*) AS c FROM form_field_options WHERE form_field_id = ' . (int) $field['id']);

            if ((int) ($stored['c'] ?? 0) !== $expected) {
                throw new \RuntimeException(sprintf(
                    'The %d option(s) of form field %d could not be moved into form_field_options, most likely because '
                    . 'site_languages has no row for "nl" or "en". Nothing was dropped.',
                    $expected,
                    (int) $field['id']
                ));
            }
        }
    }

    /**
     * The old FormFieldOptions::fromStored(), frozen here: this migration must
     * read the column exactly as the code that wrote it did, whatever that
     * class becomes.
     *
     * @return list<array{0: string, 1: string}> [Dutch label, English half or '']
     */
    private static function parse(string $stored): array
    {
        $options = [];
        $seen = [];

        foreach (preg_split('/\R/', $stored) ?: [] as $line) {
            [$nl, $en] = array_pad(explode('|', $line, 2), 2, null);
            $dutch = trim((string) $nl);
            $english = trim((string) $en);

            if ($dutch === '' || isset($seen[$dutch])) {
                continue;
            }

            $seen[$dutch] = true;
            $options[] = [$dutch, $english];

            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $options;
    }

    /** Words are anything other than spaces, tabs, line breaks, NUL and vertical tabs (the rule of 20260917180000). */
    private function hasWords(string $column): string
    {
        $expression = "COALESCE({$column}, '')";
        foreach ([9, 10, 13, 0, 11] as $byte) {
            $expression = "REPLACE({$expression}, CHAR({$byte} USING utf8mb4), ' ')";
        }

        return "TRIM({$expression}) <> ''";
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
