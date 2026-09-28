<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * PROJECTEN 2.0: a gallery on portfolio items shows ALL visible projects, the
 * projects of ONE category, or projects picked by hand, in an order of its
 * own (CONTENT-BLOCKS.md, "Projecten 2.0"; App\Service\ItemGalleryContent).
 * The Projecten block and the gallery block share their rows, so both get it.
 *
 * item_galleries:
 *
 *   portfolio_scope        its meaning changes: 'all' (as before), 'category',
 *                          'manual' — App\Service\ItemGalleryContent::SCOPES.
 *                          'featured' goes: every featured gallery becomes a
 *                          manual choice here (below). The homepage flag it
 *                          read is dropped by the next migration
 *                          (20260928210000).
 *   portfolio_category_id  the category for 'category'. A foreign key with ON
 *                          DELETE SET NULL, exactly like collection_id: a
 *                          deleted category leaves the block pointing at
 *                          nothing, so it shows nothing and its editor asks
 *                          for another one, while every other setting stays.
 *   item_sort              'source' (the start: the Portfolio's own order, as
 *                          every gallery had it), 'newest', 'oldest',
 *                          'title_asc', 'title_desc', 'random' —
 *                          App\Service\ItemGalleryContent::SORTS. For a manual
 *                          choice only 'source' (its own order) and 'random'.
 *
 * item_gallery_portfolio_items — the manual choice, a relation like
 * portfolio_item_categories rather than a list of ids in a column:
 * item_gallery_id → the block's row, portfolio_item_id → the project,
 * sort_order. The composite primary key makes a project impossible to pick
 * twice; both keys CASCADE, so a deleted project leaves every choice it was
 * in (and never comes back under a re-used id) and a deleted block takes its
 * choice along.
 *
 * WHAT A FEATURED GALLERY BECOMES: a manual choice of exactly the projects it
 * showed, in exactly that order. The flag had exactly one reader, a gallery
 * whose portfolio_scope was 'featured' — on an existing installation the
 * homepage's project teaser (20260908290000), and any Projecten block an
 * editor set to it. Every item with the flag goes in, ordered as the old query
 * ordered them (featured_sort_order, sort_order, id) — a hidden one too,
 * because a hidden featured item came back on the homepage the moment it was
 * made visible again, and a hidden item in a manual choice does the same. So
 * the page shows the same cards in the same order after this migration, and
 * the choice is edited from then on in the block itself instead of per item.
 * Only the catalogue the old source read (the first portfolio_galleries row)
 * is looked at, as that source did.
 *
 * Idempotent, no fresh-install guard (db/migrations/CLAUDE.md): the conversion
 * only touches galleries still on 'featured' and only while the flag exists,
 * and a gallery that already has a choice keeps it (a re-run). Every other
 * gallery gets 'source' and no category, which is what it showed: nothing on
 * any site changes here.
 */
final class GivePortfolioGalleriesACategoryAChoiceAndAnOrder extends AbstractMigration
{
    public function up(): void
    {
        $galleries = $this->table('item_galleries');

        if (!$galleries->hasColumn('portfolio_category_id')) {
            $galleries
                ->addColumn('portfolio_category_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'portfolio_scope',
                    'comment' => "portfolio_scope = 'category': which category's projects",
                ])
                ->addForeignKey('portfolio_category_id', 'portfolio_categories', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_item_galleries_portfolio_category',
                ])
                ->update();
        }

        if (!$this->table('item_galleries')->hasColumn('item_sort')) {
            $this->table('item_galleries')
                ->addColumn('item_sort', 'string', [
                    'limit' => 20,
                    'null' => false,
                    'default' => 'source',
                    'after' => 'max_items',
                    'comment' => 'App\Service\ItemGalleryContent::SORTS',
                ])
                ->update();
        }

        if (!$this->hasTable('item_gallery_portfolio_items')) {
            $this->table('item_gallery_portfolio_items', ['id' => false, 'primary_key' => ['item_gallery_id', 'portfolio_item_id']])
                ->addColumn('item_gallery_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('portfolio_item_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addIndex(['portfolio_item_id'], ['name' => 'idx_item_gallery_portfolio_items_item'])
                ->addForeignKey('item_gallery_id', 'item_galleries', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_item_gallery_portfolio_items_gallery',
                ])
                ->addForeignKey('portfolio_item_id', 'portfolio_gallery_items', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_item_gallery_portfolio_items_item',
                ])
                ->create();
        }

        if ($this->table('portfolio_gallery_items')->hasColumn('is_featured')) {
            $this->turnFeaturedGalleriesIntoAChoice();
        }
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md).
    }

    private function turnFeaturedGalleriesIntoAChoice(): void
    {
        $galleries = $this->fetchAll("SELECT id FROM item_galleries WHERE portfolio_scope = 'featured' ORDER BY id");
        if ($galleries === []) {
            return;
        }

        $catalogue = $this->fetchRow('SELECT id FROM portfolio_galleries ORDER BY id ASC LIMIT 1');
        $featured = $catalogue === false ? [] : $this->fetchAll(
            'SELECT id FROM portfolio_gallery_items
             WHERE portfolio_gallery_id = ' . (int) $catalogue['id'] . ' AND is_featured = 1
             ORDER BY featured_sort_order ASC, sort_order ASC, id ASC'
        );

        $pdo = $this->getAdapter()->getConnection();
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO item_gallery_portfolio_items (item_gallery_id, portfolio_item_id, sort_order, created_at)
             VALUES (:gallery_id, :item_id, :sort_order, NOW())'
        );
        $switch = $pdo->prepare(
            "UPDATE item_galleries SET portfolio_scope = 'manual', item_sort = 'source', updated_at = NOW() WHERE id = :id"
        );

        foreach ($galleries as $gallery) {
            $galleryId = (int) $gallery['id'];
            $hasChoice = $this->fetchRow('SELECT 1 AS present FROM item_gallery_portfolio_items WHERE item_gallery_id = ' . $galleryId . ' LIMIT 1');

            if ($hasChoice === false) {
                foreach ($featured as $position => $item) {
                    $insert->execute(['gallery_id' => $galleryId, 'item_id' => (int) $item['id'], 'sort_order' => $position]);
                }
            }

            $switch->execute(['id' => $galleryId]);
        }
    }
}
