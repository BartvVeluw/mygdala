<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Global, CMS-managed main site navigation — replaces
 * partials/nav-config.php (a hardcoded PHP array) as the single source of
 * truth for both the desktop and mobile menu (see partials/header.php).
 * A "page" builder concept already exists (page_sections) but is scoped to
 * content *within* the 6 fixed public pages, not sitewide chrome — this is
 * a deliberate sibling table, not an extension of that system (see
 * docs/CMS_CONTENT_AUDIT.md and MAIN.MD, "Global Navigation + Footer").
 *
 * link_type is a plain validated string (not a MySQL ENUM — no other table
 * in this project uses one, e.g. page_sections.section_type), checked
 * against App\Service\LinkResolver::LINK_TYPES at every write:
 *   - 'page'     -> target_page_id points at information_pages.id. Storing
 *                   the id (not the current slug/URL) means renaming a
 *                   page's slug later never breaks a nav item pointing at
 *                   it (see App\Service\LinkResolver).
 *   - 'route'    -> target_route is a key into App\Service\RouteRegistry,
 *                   the small fixed list of real application routes
 *                   (home/shop/diensten/portfolio/over-mij/contact/cart/
 *                   checkout/...). An admin never types a raw path.
 *   - 'external' -> external_url, validated server-side at save time.
 *   - 'none'     -> a dropdown-only heading with no destination of its own
 *                   (e.g. a top-level "Portfolio" that exists purely to
 *                   hold child links).
 *
 * parent_id is a nullable self-reference, RESTRICT on delete so a parent
 * with children can never be deleted out from under them by a stray FK
 * cascade — api/admin/delete-nav-item.php checks for children first and
 * gives a friendly error; this FK is the defense-in-depth backstop, same
 * convention as portfolio_item_categories's category FK. The frontend only
 * ever renders 2 levels (see App\Service\NavigationService); nothing here
 * stops a 3rd level at the database level, so
 * NavigationRepository::create()/update() additionally reject a parent_id
 * that itself already has a parent — enforced in the app, not the schema,
 * matching this project's convention of validating type-like columns in
 * PHP rather than the database (see SectionRegistry::TYPES).
 *
 * target_page_id SET NULL on delete: if a linked information page is ever
 * deleted, the nav item survives but its link becomes unresolved
 * (LinkResolver treats link_type=page with a null/missing target as hidden)
 * rather than the whole nav item disappearing or the delete being blocked.
 *
 * Backfill: the 6 existing partials/nav-config.php entries, in their
 * current order, each as a top-level (parent_id NULL) route link — this
 * project's public pages are fixed application routes (/index.php,
 * /shop.php, ...), not information_pages, so 'route' is the correct link
 * type for all of them (see App\Service\RouteRegistry). Matched by a
 * unique-per-run guard (COUNT check) so this migration is idempotent on a
 * database that already has nav_items rows (e.g. a repeated fresh-install
 * run in development).
 */
final class CreateNavItemsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('nav_items', ['id' => true]);
        $table
            ->addColumn('label_nl', 'string', ['limit' => 100])
            ->addColumn('label_en', 'string', ['limit' => 100])
            ->addColumn('link_type', 'string', ['limit' => 20])
            ->addColumn('target_page_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('target_route', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('external_url', 'string', ['limit' => 2048, 'null' => true])
            ->addColumn('open_in_new_tab', 'boolean', ['default' => false])
            ->addColumn('parent_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_visible', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['parent_id', 'sort_order'])
            ->addForeignKey('target_page_id', 'information_pages', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('parent_id', 'nav_items', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->create();

        $existing = $this->fetchRow('SELECT COUNT(*) AS c FROM nav_items');
        if ((int) $existing['c'] > 0) {
            // Idempotent re-run guard (matches CreatePageSectionsTable's
            // convention) — never duplicate the seeded menu.
            return;
        }

        if (InstallState::isFreshInstall($this)) {
            // The six menu entries below are this site's own menu, and four
            // of them point at pages a generic install does not have. Its
            // menu is seeded by the fresh-install bootstrap migration
            // instead — see src/Install/InstallState.php.
            return;
        }

        $now = date('Y-m-d H:i:s');

        // [label_nl, label_en, target_route]
        $items = [
            ['Home', 'Home', 'home'],
            ['Diensten', 'Services', 'diensten'],
            ['Portfolio', 'Portfolio', 'portfolio'],
            ['Shop', 'Shop', 'shop'],
            ['Over mij', 'About', 'over-mij'],
            ['Contact', 'Contact', 'contact'],
        ];

        $insert = $this->getAdapter()->getConnection()->prepare(
            'INSERT INTO nav_items
                (label_nl, label_en, link_type, target_route, open_in_new_tab, parent_id, sort_order, is_visible, created_at, updated_at)
             VALUES
                (:label_nl, :label_en, \'route\', :target_route, 0, NULL, :sort_order, 1, :now, :now)'
        );

        foreach ($items as $position => [$labelNl, $labelEn, $route]) {
            $insert->execute([
                'label_nl' => $labelNl,
                'label_en' => $labelEn,
                'target_route' => $route,
                'sort_order' => $position,
                'now' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->table('nav_items')->drop()->save();
    }
}
