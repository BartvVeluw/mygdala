<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "TOON OP HOMEPAGE" GOES (Portfolio 2.0 follow-up, MODULES.md "Portfolio").
 *
 * The flag was two columns on portfolio_gallery_items, is_featured and its own
 * order featured_sort_order (20260905010000), and exactly one reader: a
 * gallery whose portfolio_scope was 'featured'. The previous migration
 * (20260928200000) turned every such gallery into a manual choice of exactly
 * the projects it showed, in exactly that order, so the flag has no reader
 * left. Nothing else ever filtered on it: a gallery on 'all' never looked at
 * the flag, so no project is hidden anywhere by removing it.
 *
 * Each column is dropped only while it exists, so this can run again.
 *
 * Old migrations stay as they are (db/migrations/CLAUDE.md): a fresh
 * installation creates the columns there and loses them here, and ends on the
 * same schema as an upgraded one.
 */
final class RemoveThePortfolioHomepageFlag extends AbstractMigration
{
    public function up(): void
    {
        if ($this->table('portfolio_gallery_items')->hasColumn('featured_sort_order')) {
            $this->table('portfolio_gallery_items')->removeColumn('featured_sort_order')->update();
        }

        if ($this->table('portfolio_gallery_items')->hasColumn('is_featured')) {
            $this->table('portfolio_gallery_items')->removeColumn('is_featured')->update();
        }
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md).
    }
}
