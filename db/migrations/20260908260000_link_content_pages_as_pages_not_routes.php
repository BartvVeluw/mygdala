<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Diensten, Portfolio, Over mij and Contact are ordinary CMS content pages,
 * not application routes — so the menu and the footer must link to them the
 * way they link to every other content page: by `pages.id`
 * (`link_type = 'page'`), not by a key in App\Service\RouteRegistry.
 *
 * Why this matters beyond tidiness. A `page` link is a real reference: it
 * disappears by itself when the page is unpublished, follows the page when
 * it is renamed, and — via App\Service\PageService::references() — blocks
 * deleting a page that is still in the menu. A `route` link is none of those
 * things: it is a hardcoded path that keeps pointing at a URL whether or not
 * anything still answers there. As long as these four were linked as routes,
 * "this page is an ordinary content page you may unpublish or delete" would
 * have been a trap: the CMS would happily delete the page and leave the menu
 * item behind, pointing at a dead URL.
 *
 * The four keys are removed from RouteRegistry in the same change, so the
 * admin cannot recreate such a link afterwards. What stays a route there is
 * what genuinely is one: the site root, the shop, the cart, the checkout and
 * the two legal pages that have no `pages` row at all.
 *
 * No public URL changes: PageContent::publicUrl() returns these pages'
 * fixed route_path ('/diensten.php', ...), which is exactly what
 * RouteRegistry returned for them.
 *
 * Idempotent and safe on a fresh install: only rows that still say
 * `link_type = 'route'` with one of these four keys are touched, and only
 * when the matching page actually exists. A row whose page is missing is
 * left exactly as it was rather than being silently pointed somewhere else.
 *
 * MySQL/Vimexx: plain UPDATEs, no CTEs, no window functions.
 */
final class LinkContentPagesAsPagesNotRoutes extends AbstractMigration
{
    /** route key => the pages.content_key it always meant. */
    private const CONVERTED = [
        'diensten' => 'diensten',
        'portfolio' => 'portfolio',
        'over-mij' => 'over-mij',
        'contact' => 'contact',
    ];

    public function up(): void
    {
        foreach (self::CONVERTED as $routeKey => $contentKey) {
            $page = $this->query('SELECT id FROM pages WHERE content_key = ?', [$contentKey])->fetch();

            if ($page === false) {
                // No such page in this environment — leave the route link
                // alone rather than break it.
                continue;
            }

            $pageId = (int) $page['id'];

            foreach (['nav_items', 'footer_links'] as $table) {
                $this->execute(
                    "UPDATE {$table}
                        SET link_type = 'page', target_page_id = ?, target_route = NULL, updated_at = NOW()
                      WHERE link_type = 'route' AND target_route = ?",
                    [$pageId, $routeKey]
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::CONVERTED as $routeKey => $contentKey) {
            $page = $this->query('SELECT id FROM pages WHERE content_key = ?', [$contentKey])->fetch();

            if ($page === false) {
                continue;
            }

            foreach (['nav_items', 'footer_links'] as $table) {
                $this->execute(
                    "UPDATE {$table}
                        SET link_type = 'route', target_route = ?, target_page_id = NULL, updated_at = NOW()
                      WHERE link_type = 'page' AND target_page_id = ?",
                    [$routeKey, (int) $page['id']]
                );
            }
        }
    }
}
