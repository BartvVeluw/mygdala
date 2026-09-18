<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 5 wave D: the visitor-facing words of the
 * Personalisatie module, one row per owner per website language
 * (docs/multilingual/ARCHITECTURE.md, MODULES.md "Personalisatie").
 *
 *   product_personalization_translations       instructions
 *   product_personalization_view_translations  label
 *   product_personalization_zone_translations  label, instructions, placeholder
 *
 * TYPED TABLES, the same shape as every other one since phase 2
 * (App\Service\Language\TranslationTable):
 *
 *   - the owner is a foreign key with ON DELETE CASCADE;
 *   - the language is a foreign key on site_languages.code with ON DELETE
 *     RESTRICT, the same type as that column (ascii, ascii_bin, 12 wide);
 *   - UNIQUE(owner, language_code): one row per owner per language.
 *
 * WHAT IS NOT HERE IS THE CONFIGURATION ITSELF, and that is the whole point.
 * `view_key` and `zone_key` are the keys an order line and the browser refer
 * to a zone by; the geometry (`area_x`, `area_y`, `area_width`,
 * `area_height`), `allow_text`, `allow_image`, `is_enabled`, `is_required`,
 * `allow_rotation`, `max_text_length`, `surcharge`, `personalization_mode`,
 * `preview_image_path` and every sort order decide what a customer may DO.
 * None of it is a word, none of it moves, and a language switch therefore
 * cannot change what may be engraved, where, or what it costs.
 *
 * NEITHER IS AN ORDER. What a zone was called when somebody bought it lives
 * in that order line's own `config_snapshot_json` (version 3), which keeps
 * exactly the shape it has — `label`/`label_en` and all — so a document from
 * before this wave still reads, and one written after it reads the same way.
 * Nothing here is ever consulted for a historical order.
 *
 * Every field is plain text with no markup: an instruction, a label and a
 * placeholder are printed escaped, so none of them is rich and none of them
 * needs MEDIUMTEXT. Their lengths are the lengths of the columns they
 * replace.
 *
 * NOT A MODULE QUESTION. These tables are created whether Personalisatie is
 * on or off: a switched-off module keeps its content, and a fresh install
 * with it off must end on the same schema as one with it on.
 *
 * SCHEMA ONLY. The words arrive with 20260918250000, in the same commit that
 * switches every reader over and drops the old columns.
 */
final class CreateThePersonalizationTranslationTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_languages')) {
            return;
        }

        // Each name written out in $this->table('…'), so the schema check in
        // Tests\Install\MigrationTableNamesTest can see it being created.
        if (!$this->hasTable('product_personalization_translations')
            && $this->hasTable('product_personalization_settings')) {
            $this->create(
                $this->table('product_personalization_translations', ['id' => true]),
                'product_personalization_settings',
                'settings_id',
                ['instructions' => 500]
            );
        }

        if (!$this->hasTable('product_personalization_view_translations')
            && $this->hasTable('product_personalization_views')) {
            $this->create(
                $this->table('product_personalization_view_translations', ['id' => true]),
                'product_personalization_views',
                'view_id',
                ['label' => 100]
            );
        }

        if (!$this->hasTable('product_personalization_zone_translations')
            && $this->hasTable('product_personalization_zones')) {
            $this->create(
                $this->table('product_personalization_zone_translations', ['id' => true]),
                'product_personalization_zones',
                'zone_id',
                ['label' => 100, 'instructions' => 500, 'placeholder' => 100]
            );
        }
    }

    /**
     * One typed translation table. A field's length is the length of the
     * column it replaces.
     *
     * @param array<string, int> $fields field => maximum length
     */
    private function create(\Phinx\Db\Table $table, string $ownerTable, string $ownerColumn, array $fields): void
    {
        $name = $table->getName();

        $table
            ->addColumn($ownerColumn, 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ]);

        foreach ($fields as $field => $maxLength) {
            $table->addColumn($field, 'string', [
                'limit' => $maxLength,
                'null' => true,
                'default' => null,
                'comment' => 'The words in this language; a language without words has no row',
            ]);
        }

        $table
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
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
            ])
            ->create();
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). Once 20260918250000 has run,
     * these tables are where the module's words live.
     */
    public function down(): void
    {
    }
}
