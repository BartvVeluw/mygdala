<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Content Blocks Lifecycle 1.0: a block the editor chose in the block picker
 * but has not saved yet (CONTENT-BLOCKS.md, "De levensloop van een nieuw
 * blok").
 *
 * Every block editor needs a content row before it can open — its words live
 * in block_translations under that row's id, its cards, items and uploads
 * hang from it — so a new block still gets its content row the moment it is
 * chosen. What it no longer gets is its place on the page: the page_sections
 * row (the attachment, the position, what the public page and the block list
 * read) is written by the block's first successful save, inside that save's
 * transaction. Until then the content row is a DRAFT, and this table is the
 * one record that says so:
 *
 *   - a save attaches a row only when it is listed here, so an unattached
 *     content row of any other origin is never put on a page by accident;
 *   - Annuleren removes the row listed here (App\Service\Blocks\ContentBlockDrafts),
 *     and a draft nobody came back to is removed after a while, so nothing
 *     half-made stays behind.
 *
 * `page_id` is a real foreign key with CASCADE: the draft row is bookkeeping,
 * and a page deletion that forgot it must not be refused for it (the page's
 * own delete discards its drafts' content first, PageService::delete()).
 * UNIQUE(section_type, section_id) mirrors page_sections: one content row,
 * one place. Nothing existing changes; the table starts empty.
 */
final class CreateContentBlockDrafts extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('content_block_drafts')) {
            return;
        }

        $this->table('content_block_drafts', ['id' => true])
            ->addColumn('page_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'the block list the draft will join on its first save'])
            ->addColumn('section_type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('section_key', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('section_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['section_type', 'section_id'], ['unique' => true, 'name' => 'uq_content_block_drafts_row'])
            ->addIndex(['created_at'], ['name' => 'idx_content_block_drafts_created'])
            ->addForeignKey('page_id', 'pages', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_content_block_drafts_page'])
            ->create();
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
