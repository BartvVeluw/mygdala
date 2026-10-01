<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Search 2.0 (v0.1.15, phase 7, SEARCH.md "De tekst van de blokken"): the
 * site search finds a page, product, project, blog post or article by the
 * words in its content blocks.
 *
 *   search_block_texts   per placed block (page_sections row) and per website
 *                        language: the visible words a visitor can find it
 *                        by, as plain text — its headings apart (they weigh
 *                        more) and all of its words in reading order (for
 *                        matching and for the excerpt). Derived data:
 *                        App\Service\Search\BlockSearchIndex writes it when a
 *                        block is saved, shown or hidden, and rebuilds it
 *                        whole whenever it is out of date.
 *   search_index_state   one row per derived index: the fingerprint of the
 *                        rules it was built with (extractor version, website
 *                        languages, registered block types). A missing or
 *                        different fingerprint makes the next search rebuild
 *                        the index, so an existing site that updates is
 *                        searchable by its block text from its first search
 *                        on, without this migration reading a single block.
 *
 * Both foreign keys CASCADE: a deleted block, a deleted page and a deleted
 * owner (whose content page goes with it) take their rows along, so a delete
 * never needs the index's help. Language is a plain code with no foreign key:
 * a language that is removed or switched off changes the fingerprint, and
 * the rebuild drops its rows.
 *
 * Nothing here is a source of truth. Emptying both tables is always safe.
 * Idempotent; forward-only (db/migrations/CLAUDE.md).
 */
final class CreateTheSearchBlockIndex extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pages') || !$this->hasTable('page_sections')) {
            return;
        }

        if (!$this->hasTable('search_block_texts')) {
            $this->table('search_block_texts', ['id' => true])
                ->addColumn('page_section_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('page_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('section_type', 'string', ['limit' => 50, 'null' => false])
                ->addColumn('language_code', 'string', ['limit' => 12, 'null' => false])
                ->addColumn('heading_text', 'text', ['null' => false])
                ->addColumn('body_text', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['page_section_id', 'language_code'], ['unique' => true, 'name' => 'uq_search_block_texts_section_language'])
                // Every read: the blocks of a set of pages in one language.
                ->addIndex(['page_id', 'language_code'], ['name' => 'idx_search_block_texts_page_language'])
                ->addForeignKey('page_section_id', 'page_sections', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_search_block_texts_section',
                ])
                ->addForeignKey('page_id', 'pages', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_search_block_texts_page',
                ])
                ->create();
        }

        if (!$this->hasTable('search_index_state')) {
            $this->table('search_index_state', ['id' => false, 'primary_key' => ['index_name']])
                ->addColumn('index_name', 'string', ['limit' => 40, 'null' => false])
                ->addColumn('fingerprint', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('built_at', 'datetime', ['null' => true])
                ->create();
        }
    }
}
