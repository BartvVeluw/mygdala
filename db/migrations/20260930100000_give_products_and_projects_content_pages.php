<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Product & Portfolio Content Pages 1.0: a product and a portfolio project
 * can carry the same content blocks as a CMS page, through the SAME block
 * engine (CONTENT-BLOCKS.md, "Blokken op een product of project").
 *
 * THE OWNER MODEL. The block engine already only knows one opaque storage
 * key per block list (`page_slug` = `pages.content_key`) and one list table
 * (`page_sections`, FK to `pages`). So an owner that is not a page gets a
 * page row of its own to hold its blocks — its CONTENT PAGE — and every
 * block table, editor, endpoint, translation, media and form usage keeps
 * working as it does for a page:
 *
 *   pages.owner_type        NULL for every ordinary page (every existing row);
 *                           'product' or 'portfolio_project' for a content
 *                           page (App\Service\ContentOwners). Every listing of
 *                           pages leaves such a row out: it is never in the
 *                           page tree, a menu, the sitemap, search or the
 *                           destination picker, and it has no address.
 *   product_content_pages   product    -> its content page, one each way
 *   portfolio_content_pages project    -> its content page, one each way
 *
 * Both link tables hold REAL foreign keys on both sides, RESTRICT: a product
 * or project cannot be deleted while it still has a content page, and a
 * content page cannot be deleted while it is still linked. Deleting goes
 * through App\Service\ContentOwners\ContentPages::deleteFor(), which removes
 * the blocks through SectionRegistry::delete() (their words, child rows and
 * files) first. No polymorphic id without a key: owner_type only says which
 * link table to read.
 *
 * THE PORTFOLIO LAYOUT (Portfolio layout 2.0):
 *
 *   portfolio_gallery_items.project_layout  NULL = follow the Portfolio
 *                           default (site setting portfolio_project_layout,
 *                           default image_left: nothing changes for an
 *                           existing site), else image_left | image_right |
 *                           image_top | free (App\Service\PortfolioProjectLayout).
 *   portfolio_project_infos the Projectinformatie block: the project's own
 *                           picture, title, text and categories, read live
 *                           from the project it is on — this row holds only
 *                           how it is shown, never a copy of project data.
 *
 * NEW TABLES AND TWO NULLABLE COLUMNS, nothing rewritten: every existing
 * page, product and project reads exactly as before. Idempotent.
 */
final class GiveProductsAndProjectsContentPages extends AbstractMigration
{
    public function up(): void
    {
        $pages = $this->table('pages');
        if (!$pages->hasColumn('owner_type')) {
            $pages->addColumn('owner_type', 'string', [
                'limit' => 30,
                'null' => true,
                'default' => null,
                'after' => 'module_default',
                'comment' => 'NULL = an ordinary page; else the content page of a product or project (App\\Service\\ContentOwners)',
            ])->addIndex(['owner_type'], ['name' => 'idx_pages_owner_type'])->update();
        }

        if (!$this->hasTable('product_content_pages')) {
            $this->table('product_content_pages', ['id' => false, 'primary_key' => ['product_id']])
                ->addColumn('product_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('page_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['page_id'], ['unique' => true, 'name' => 'uq_product_content_pages_page'])
                ->addForeignKey('product_id', 'products', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_product_content_pages_product'])
                ->addForeignKey('page_id', 'pages', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_product_content_pages_page'])
                ->create();
        }

        if (!$this->hasTable('portfolio_content_pages')) {
            $this->table('portfolio_content_pages', ['id' => false, 'primary_key' => ['portfolio_item_id']])
                ->addColumn('portfolio_item_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('page_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['page_id'], ['unique' => true, 'name' => 'uq_portfolio_content_pages_page'])
                ->addForeignKey('portfolio_item_id', 'portfolio_gallery_items', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_portfolio_content_pages_item'])
                ->addForeignKey('page_id', 'pages', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_portfolio_content_pages_page'])
                ->create();
        }

        $items = $this->table('portfolio_gallery_items');
        if (!$items->hasColumn('project_layout')) {
            $items->addColumn('project_layout', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
                'comment' => 'NULL = the Portfolio default; App\\Service\\PortfolioProjectLayout::LAYOUTS',
            ])->update();
        }

        if (!$this->hasTable('portfolio_project_infos')) {
            $this->table('portfolio_project_infos', ['id' => true])
                ->addColumn('page_slug', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('section_key', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
                ->addColumn('image_position', 'string', ['limit' => 20, 'null' => false, 'default' => 'left', 'comment' => 'left | right | top'])
                ->addColumn('show_gallery', 'boolean', ['null' => false, 'default' => true, 'comment' => 'the project\'s extra photos below it'])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['page_slug', 'section_key'], ['unique' => true, 'name' => 'uq_portfolio_project_infos_instance'])
                ->create();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
