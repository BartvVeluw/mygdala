<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 4 wave C: the words of Core Forms per website
 * language, and a choice field's options as rows with a stable value
 * (docs/multilingual/ARCHITECTURE.md, FORMS.md).
 *
 *   form_translations               form_id              submit_label, success_message
 *   form_field_translations         form_field_id        label, placeholder, help_text
 *   form_field_options              form_field_id        value, sort_order
 *   form_field_option_translations  form_field_option_id label
 *
 * THE TRANSLATION TABLES have the shape of every typed translation table
 * (App\Service\Language\TranslationTable): the owner a foreign key with ON
 * DELETE CASCADE, the language a foreign key on site_languages.code with ON
 * DELETE RESTRICT (same type), UNIQUE(owner, language_code), and no row for a
 * language without words.
 *
 * AN OPTION IS A ROW, not a line of text: its `value` is its identity — what a
 * visitor's browser posts, what validation compares, what a submission
 * stores and what form_fields.default_value names — and it is the same in
 * every language. Only its label is translated. `value` is utf8mb4_bin and
 * UNIQUE per field: validation compares values exactly, so the database does
 * too. An option goes with its field (CASCADE), its labels with the option.
 *
 * WHAT STAYS where it is: a form's name, key, status, recipient, Reply-To and
 * storage switch; a field's key, type, required flag, position and default.
 * Submissions (form_submissions, form_submission_values) are snapshots and
 * are not touched.
 *
 * SCHEMA ONLY. The words and options arrive with 20260918150000.
 */
final class CreateTheFormTranslationAndOptionTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_languages') || !$this->hasTable('forms') || !$this->hasTable('form_fields')) {
            return;
        }

        // Each name written out in $this->table('…'), so the schema check in
        // Tests\Install\MigrationTableNamesTest can see it being created.
        if (!$this->hasTable('form_translations')) {
            $this->translationTable($this->table('form_translations', ['id' => true]), 'forms', 'form_id')
                ->addColumn('submit_label', 'string', ['limit' => 150, 'null' => true, 'default' => null])
                ->addColumn('success_message', 'text', ['null' => true, 'default' => null])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->create();
        }

        if (!$this->hasTable('form_field_translations')) {
            $this->translationTable($this->table('form_field_translations', ['id' => true]), 'form_fields', 'form_field_id')
                ->addColumn('label', 'string', ['limit' => 200, 'null' => true, 'default' => null])
                ->addColumn('placeholder', 'string', ['limit' => 200, 'null' => true, 'default' => null])
                ->addColumn('help_text', 'string', ['limit' => 500, 'null' => true, 'default' => null])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->create();
        }

        if (!$this->hasTable('form_field_options')) {
            $this->table('form_field_options', ['id' => true])
                ->addColumn('form_field_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('value', 'string', [
                    'limit' => 255,
                    'null' => false,
                    'collation' => 'utf8mb4_bin',
                    'comment' => 'the option\'s language-neutral identity: posted, validated, stored in submissions; never changed',
                ])
                ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['form_field_id', 'value'], ['unique' => true, 'name' => 'uq_form_field_options_field_value'])
                ->addIndex(['form_field_id', 'sort_order'], ['name' => 'idx_form_field_options_field_order'])
                ->addForeignKey('form_field_id', 'form_fields', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_form_field_options_field',
                ])
                ->create();
        }

        if (!$this->hasTable('form_field_option_translations')) {
            $this->translationTable($this->table('form_field_option_translations', ['id' => true]), 'form_field_options', 'form_field_option_id')
                ->addColumn('label', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->create();
        }
    }

    /**
     * The shared shape: owner and language columns, their unique pair and both
     * foreign keys. The caller adds the localized fields and creates it.
     */
    private function translationTable(\Phinx\Db\Table $table, string $ownerTable, string $ownerColumn): \Phinx\Db\Table
    {
        $name = $table->getName();

        return $table
            ->addColumn($ownerColumn, 'integer', ['signed' => false, 'null' => false])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ])
            ->addIndex([$ownerColumn, 'language_code'], ['unique' => true, 'name' => 'uq_' . $name . '_owner_language'])
            ->addIndex(['language_code'], ['name' => 'idx_' . $name . '_language'])
            ->addForeignKey($ownerColumn, $ownerTable, 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_' . $name . '_owner',
            ])
            ->addForeignKey('language_code', 'site_languages', 'code', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_' . $name . '_language',
            ]);
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). Once 20260918150000 has run,
     * these tables are where every form's words and options live.
     */
    public function down(): void
    {
    }
}
