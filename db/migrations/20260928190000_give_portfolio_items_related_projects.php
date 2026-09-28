<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RELATED PROJECTS on a project page (Portfolio 2.0 follow-up, MODULES.md
 * "Portfolio", App\Service\PortfolioRelatedProjects): below a project, a row
 * of other projects, picked by shared categories, by hand, or both.
 *
 * WHAT AN ITEM GETS, all language-neutral, on portfolio_gallery_items:
 *
 *   related_enabled    0 (the start: every existing project page stays exactly
 *                      as it is until an editor switches it on), 1
 *   related_mode       'automatic' | 'manual' | 'hybrid'
 *   related_max        2, 3, 4, 6, 8 — how many at most (3: the grid's row)
 *   related_sort       'relevance' | 'newest' | 'oldest' | 'title' | 'random'
 *                      — the order of the automatic ones
 *   related_fallback   'available' (show what shares a category) | 'fill'
 *                      (top up with other projects)
 *   related_layout     'compact' | 'normal' | 'large' — the card grid's preset
 *   related_show_text  1 (the card's short text) | 0
 *
 * Each word is one of the closed lists of App\Service\PortfolioRelatedProjects;
 * an unknown stored value reads as that list's default.
 *
 * THE WORDS, per website language, next to the item's other words in
 * portfolio_item_translations: related_title (150, the h2 above the row) and
 * related_lead (500). Both optional; without a title the row is headed by the
 * built-in "Gerelateerde projecten" / "Related projects".
 *
 * THE MANUAL CHOICE is a relation, not a list of ids in a column:
 * portfolio_related_items (portfolio_item_id → the project whose page it is,
 * related_item_id → the project shown, sort_order). The composite primary key
 * makes a project impossible to pick twice; both keys CASCADE, so a deleted
 * project leaves every list it was on, and a deleted page's list goes with it.
 * No project can be its own related project (the editor and the reader both
 * refuse it; a CHECK would not be enforced by every MySQL this runs on).
 *
 * No snapshot: which projects show is worked out per request from live rows.
 *
 * Schema only, idempotent, no fresh-install guard (db/migrations/CLAUDE.md).
 * Every existing item gets the defaults, related_enabled = 0 among them, so
 * nothing on any site changes.
 */
final class GivePortfolioItemsRelatedProjects extends AbstractMigration
{
    public function up(): void
    {
        $this->addSettingColumns();
        $this->addWordColumns();
        $this->createRelationTable();
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md).
    }

    private function addSettingColumns(): void
    {
        $columns = [
            'related_enabled' => ['boolean', ['null' => false, 'default' => false, 'comment' => 'Related projects shown below the project page']],
            'related_mode' => ['string', ['limit' => 20, 'null' => false, 'default' => 'automatic', 'comment' => 'App\Service\PortfolioRelatedProjects::MODES']],
            'related_max' => ['integer', ['signed' => false, 'null' => false, 'default' => 3, 'comment' => 'App\Service\PortfolioRelatedProjects::MAXIMUMS']],
            'related_sort' => ['string', ['limit' => 20, 'null' => false, 'default' => 'relevance', 'comment' => 'App\Service\PortfolioRelatedProjects::SORTS']],
            'related_fallback' => ['string', ['limit' => 20, 'null' => false, 'default' => 'available', 'comment' => 'App\Service\PortfolioRelatedProjects::FALLBACKS']],
            'related_layout' => ['string', ['limit' => 20, 'null' => false, 'default' => 'normal', 'comment' => 'App\Service\PortfolioRelatedProjects::LAYOUTS']],
            'related_show_text' => ['boolean', ['null' => false, 'default' => true, 'comment' => 'The cards show their short text']],
        ];

        $after = 'slug';
        foreach ($columns as $column => [$type, $options]) {
            if (!$this->table('portfolio_gallery_items')->hasColumn($column)) {
                $this->table('portfolio_gallery_items')
                    ->addColumn($column, $type, $options + ['after' => $after])
                    ->update();
            }
            $after = $column;
        }
    }

    private function addWordColumns(): void
    {
        $columns = [
            'related_title' => 150,
            'related_lead' => 500,
        ];

        $after = 'description';
        foreach ($columns as $column => $maxLength) {
            if (!$this->table('portfolio_item_translations')->hasColumn($column)) {
                $this->table('portfolio_item_translations')
                    ->addColumn($column, 'string', [
                        'limit' => $maxLength,
                        'null' => true,
                        'default' => null,
                        'after' => $after,
                        'comment' => 'The words in this language; a language without words has no row',
                    ])
                    ->update();
            }
            $after = $column;
        }
    }

    private function createRelationTable(): void
    {
        if ($this->hasTable('portfolio_related_items')) {
            return;
        }

        $this->table('portfolio_related_items', ['id' => false, 'primary_key' => ['portfolio_item_id', 'related_item_id']])
            ->addColumn('portfolio_item_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'The project whose page shows the row'])
            ->addColumn('related_item_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'The project shown in it'])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addIndex(['related_item_id'], ['name' => 'idx_portfolio_related_items_related'])
            ->addForeignKey('portfolio_item_id', 'portfolio_gallery_items', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_portfolio_related_items_item',
            ])
            ->addForeignKey('related_item_id', 'portfolio_gallery_items', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_portfolio_related_items_related',
            ])
            ->create();
    }
}
