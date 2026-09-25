<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Portfolio 2.0: the Portfolio overview answers at the module root,
 * /portfolio, next to its project pages at /portfolio/<slug>
 * (MODULES.md, "Portfolio"; App\Module\PortfolioModule::publicRoutes()).
 *
 *   pages.route_path   '/portfolio.php' -> '/portfolio'
 *
 * for the CMS page an existing site has at /portfolio.php (content key
 * "portfolio"). Everything that addresses that page reads its route_path —
 * its canonical, its hreflang versions, the sitemap, a menu or footer link
 * to it, the Portfolio level of a project page's breadcrumb — so this one
 * value moves them all at once, without a link being rewritten anywhere.
 * The old address keeps answering: portfolio.php sends /portfolio.php to
 * /portfolio with a permanent redirect.
 *
 * ONLY THAT ONE ROW, and only while it still says '/portfolio.php': a page an
 * editor gave another route would be left alone, and a second run finds
 * nothing to do. A fresh installation has no such page, so this changes
 * nothing there. Nothing else is touched: a redirect or a typed link that
 * names /portfolio.php keeps working through that same redirect.
 *
 * Forward-only (db/migrations/CLAUDE.md).
 */
final class ServeThePortfolioOverviewAtItsModuleRoot extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pages') || !$this->table('pages')->hasColumn('route_path')) {
            return;
        }

        $this->execute(
            "UPDATE pages SET route_path = '/portfolio', updated_at = NOW()
             WHERE content_key = 'portfolio' AND route_path = '/portfolio.php'"
        );
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md): nothing to undo on purpose.
    }
}
