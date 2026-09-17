<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 3: ONE generic table for the words of every content
 * block, in every website language (docs/multilingual/ARCHITECTURE.md).
 *
 * THE SHAPE: one row per stored word-field, per language:
 *
 *   owner_table    the block's content table ('cta_bands'); for a block's
 *                  child rows (phase 3B) the child table
 *   owner_id       that table's row id
 *   language_code  site_languages.code
 *   field          the field key the block definition declares ('title')
 *   value          the words; plain text or sanitized HTML, as the
 *                  definition declares
 *
 * Why this and not one JSON payload per block per language: a field is a row,
 * so the UNIQUE key below guards every single field, a field no definition
 * declares any more is findable with SQL, a migration is a plain
 * INSERT … SELECT per old column on MySQL 5.7 and MariaDB alike (no JSON
 * functions), and every field carries its own updated_at.
 *
 * Why not a typed `<block>_translations` table per block type: blocks are
 * never routed, sorted or searched by their words, and a new block type must
 * cost no translation migration at all. The field list lives in
 * App\Service\Blocks\BlockDefinition::translatableFields().
 *
 * A FIELD WITHOUT WORDS HAS NO ROW. `value` is therefore NOT NULL: "not
 * translated" and "translated as nothing" are one state, stored one way.
 *
 * INTEGRITY. `owner_id` points into whichever table `owner_table` names, so
 * it cannot be a foreign key. What guards it instead (the phase-0 contract):
 * App\Service\Blocks\BlockLocalization is the only writer and deletes an
 * owner's rows in the same transaction as the block
 * (App\Service\SectionRegistry::delete()), `owner_table` is only ever a table
 * the closed block registry declares, and BlockLocalization::orphans() /
 * scripts/block-translation-orphans.php find and remove what slipped past.
 * The LANGUAGE is a real foreign key, RESTRICT, exactly like
 * page_translations: a language that still has block text cannot be deleted.
 *
 * SCHEMA ONLY. Text arrives per block type, in the migration of the phase
 * that converts that block type, together with the drop of its old columns.
 */
final class CreateTheBlockTranslationsTable extends AbstractMigration
{
    private const TABLE = 'block_translations';

    public function up(): void
    {
        if ($this->hasTable(self::TABLE) || !$this->hasTable('site_languages')) {
            return;
        }

        $this->table(self::TABLE, ['id' => true])
            ->addColumn('owner_table', 'string', [
                'limit' => 64,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'content table the words belong to; from the block registry, never from a request',
            ])
            ->addColumn('owner_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'row id in owner_table; no foreign key, see App\Service\Blocks\BlockLocalization',
            ])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ])
            ->addColumn('field', 'string', [
                'limit' => 64,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'field key declared by BlockDefinition::translatableFields()',
            ])
            ->addColumn('value', 'text', [
                'limit' => MysqlAdapter::TEXT_MEDIUM,
                'null' => false,
                'comment' => 'the words; a field without words has no row',
            ])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['owner_table', 'owner_id', 'language_code', 'field'], [
                'unique' => true,
                'name' => 'uq_block_translations_owner_language_field',
            ])
            ->addIndex(['language_code'], ['name' => 'idx_block_translations_language'])
            ->addForeignKey('language_code', 'site_languages', 'code', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_block_translations_language',
            ])
            ->create();
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). Once a block type has moved its
     * words in here, dropping this table would delete them.
     */
    public function down(): void
    {
    }
}
