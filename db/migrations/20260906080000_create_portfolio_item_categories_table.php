<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Many-to-many relation between portfolio_gallery_items and
 * portfolio_categories (Portfolio-redesign, step 2) — replaces
 * portfolio_gallery_items.categories (a space-separated string column, e.g.
 * "metaal zakelijk") as the source of truth for an item's categories. Same
 * junction-table shape/FK convention as product_variant_values (see
 * db/migrations/20260904160000_create_product_variant_tables.php):
 * composite primary key, no surrogate id, CASCADE on the "owning" side
 * (portfolio item), RESTRICT on the "referenced" side (category) so a
 * category that's still assigned to items can't be silently deleted out
 * from under them — api/admin/delete-portfolio-category.php checks usage
 * first and gives a friendly error, this FK is the defense-in-depth backstop.
 *
 * portfolio_gallery_items.categories itself is deliberately left in place,
 * untouched and unindexed going forward — dropping a column is not
 * additive, and nothing above needs the old data destroyed, only
 * superseded. No code reads or writes it after this migration; it is purely
 * historical.
 *
 * Backfill: every existing item's space-separated categories string is
 * parsed and turned into real (portfolio_item_id, portfolio_category_id)
 * rows against the 3 categories seeded by the previous migration (same
 * slugs: hout/metaal/zakelijk) — so no existing Portfolio item loses a
 * category because of this migration.
 */
final class CreatePortfolioItemCategoriesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('portfolio_item_categories', ['id' => false, 'primary_key' => ['portfolio_item_id', 'portfolio_category_id']])
            ->addColumn('portfolio_item_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('portfolio_category_id', 'integer', ['signed' => false, 'null' => false])
            ->addForeignKey('portfolio_item_id', 'portfolio_gallery_items', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('portfolio_category_id', 'portfolio_categories', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->create();

        $categoryIdBySlug = [];
        foreach ($this->fetchAll('SELECT id, slug FROM portfolio_categories') as $row) {
            $categoryIdBySlug[$row['slug']] = (int) $row['id'];
        }

        $pdo = $this->getAdapter()->getConnection();
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO portfolio_item_categories (portfolio_item_id, portfolio_category_id) VALUES (:item_id, :category_id)'
        );

        foreach ($this->fetchAll('SELECT id, categories FROM portfolio_gallery_items') as $item) {
            $tokens = preg_split('/\s+/', trim((string) $item['categories']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($tokens as $token) {
                if (!isset($categoryIdBySlug[$token])) {
                    // Unknown token (shouldn't happen — the admin only ever
                    // wrote the 3 known keys): skip rather than fail the migration.
                    continue;
                }

                $insert->execute([
                    'item_id' => (int) $item['id'],
                    'category_id' => $categoryIdBySlug[$token],
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->table('portfolio_item_categories')->drop()->save();
    }
}
