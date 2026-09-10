<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Everything a brand-new installation of this CMS gets, and nothing else.
 *
 * Every migration before this one either creates schema or replays a piece
 * of Van Veluw Laserdesign's history. Until now those two jobs were the same
 * job, so a fresh database came up with that site's Diensten, Portfolio,
 * Over mij and Contact pages, its three Dutch legal pages, its menu and its
 * footer. `InstallState` now lets each of those historical seeds recognise a
 * database with no history and stay out of it, which leaves this migration
 * as the single place that answers "what does a new site start with?".
 *
 * The answer is deliberately short:
 *
 *   Core          the Homepage. `/` must always render something
 *                 (App\Service\PageContent::isSiteRoot()), which is also
 *                 what makes it non-deletable, and it carries the Homepage
 *                 Hero — the one block that exists only there and cannot be
 *                 removed.
 *   Shop module   the Shop page, carrying the product grid. That block is
 *                 application-critical: the page's protection follows it
 *                 (PageContent::isProtected()), so the storefront cannot be
 *                 unpublished or deleted out from under the webshop.
 *   Menu          one link per page that exists. Nothing else.
 *
 * Diensten, Portfolio, Over mij, Contact and the legal pages are NOT here.
 * They are ordinary content pages, and an editor makes them when the site
 * needs them — from a page template if they want the usual shape of one
 * (PAGE-TEMPLATES.md). Which legal pages a business owes its customers is a
 * business question, not something a CMS can seed on its behalf.
 *
 * On an existing installation this migration does nothing at all: its first
 * line asks InstallState, which answers "this database has history" for
 * every database that predates the marker. See INSTALL-BOOTSTRAP.md.
 *
 * Why the Shop page is created even when MODULE_SHOP_ENABLED is off: a
 * system page (is_system = 1, with a route_path) cannot be created from the
 * admin — PageRepository::create() hard-codes is_system = 0 and route_path =
 * NULL — so a Shop page skipped at install time could never be recovered by
 * turning the module back on. A row for a disabled module costs nothing:
 * ModuleGuard already answers 404 on /shop.php, the sitemap already leaves
 * it out (PageContent::isServedByAnEnabledModule()), and the menu link below
 * resolves through the Shop module's own route, so it disappears with the
 * module. Deciding schema content from an environment variable that may be
 * flipped afterwards is the thing to avoid here, not the spare row.
 */
final class BootstrapAGenericFreshInstall extends AbstractMigration
{
    /** The homepage's immutable storage key, as every earlier migration writes it. */
    private const HOME_KEY = 'index';

    /** The storefront's immutable storage key. */
    private const SHOP_KEY = 'shop';

    /**
     * A fixed block (product grid, shop collections) has no content row of
     * its own, so its page_sections attachment stores 0 — the convention
     * every existing attachment of a fixed block already uses.
     */
    private const NO_CONTENT_ROW = 0;

    public function up(): void
    {
        if (!InstallState::isFreshInstall($this)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $homeId = $this->createPage(self::HOME_KEY, 'Homepage', '/', 10, $now);
        if ($homeId !== null) {
            $this->attachHomepageHero($homeId, $now);
        }

        $shopId = $this->createPage(self::SHOP_KEY, 'Shop', '/shop.php', 20, $now);
        if ($shopId !== null) {
            $this->attachSection($shopId, self::SHOP_KEY, 'product_grid', null, self::NO_CONTENT_ROW, 0, $now);
        }

        $this->seedMenu($homeId, $shopId, $now);
    }

    /**
     * Forward-only, like every migration here. Rolling this one back would
     * delete the site root, and a rollback is never the right way to get rid
     * of a homepage.
     */
    public function down(): void
    {
    }

    /**
     * @return int|null the new page's id, or null when it already existed
     */
    private function createPage(string $contentKey, string $title, string $routePath, int $sortOrder, string $now): ?int
    {
        $existing = $this->fetchRow('SELECT id FROM pages WHERE content_key = ' . $this->quote($contentKey));
        if ($existing !== false && $existing !== null) {
            return null;
        }

        // No meta_title and no meta_description: the generic SEO fallback
        // ("<Title> — <site name>", the site-wide description) is exactly
        // right for a page whose owner has not written anything yet, and
        // inventing copy on their behalf is what this cleanup removes.
        $this->execute(
            'INSERT INTO pages
                (content_key, slug, title, status, meta_title, meta_title_en,
                 meta_description, meta_description_en, is_system, route_path,
                 sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, 1, ?, ?, ?, ?)',
            [$contentKey, $contentKey, $title, 'published', $routePath, $sortOrder, $now, $now]
        );

        $row = $this->fetchRow('SELECT id FROM pages WHERE content_key = ' . $this->quote($contentKey));

        return ($row === false || $row === null) ? null : (int) $row['id'];
    }

    /**
     * The homepage hero, with placeholder copy in the same voice every block
     * uses for a freshly added instance ("Nieuwe sectie — pas deze titel
     * aan"): obviously editable, about nothing in particular, and not a
     * claim about a business this CMS knows nothing about.
     *
     * Its primary button points at the site root. The block always renders
     * that button, so it needs a destination, and `/` is the one URL every
     * installation is guaranteed to answer.
     */
    private function attachHomepageHero(int $pageId, string $now): void
    {
        $existing = $this->fetchRow('SELECT id FROM homepage_hero WHERE page_slug = ' . $this->quote(self::HOME_KEY));

        if ($existing === false || $existing === null) {
            $this->execute(
                'INSERT INTO homepage_hero
                    (page_slug, eyebrow_nl, eyebrow_en, title_nl, title_en,
                     title_highlight_nl, title_highlight_en, lead_nl, lead_en,
                     primary_label_nl, primary_label_en, primary_url,
                     secondary_label_nl, secondary_label_en, secondary_url,
                     image_path, image_alt_nl, image_alt_en,
                     badge_title_nl, badge_title_en, badge_text_nl, badge_text_en,
                     media_type, video_path, layout, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
                [
                    self::HOME_KEY,
                    'Welkom', 'Welcome',
                    'Nieuwe website — pas deze titel aan', 'New website — edit this title',
                    '', '',
                    'Vertel hier in een paar zinnen wat je doet. Pas deze tekst aan in de paginabouwer van de homepage.',
                    'Say in a few sentences what you do. Edit this text in the homepage page builder.',
                    'Meer informatie', 'Learn more', '/',
                    '', '', '',
                    '', '', '',
                    '', '', '', '',
                    'image', '', 'media_right',
                    $now, $now,
                ]
            );
        }

        $hero = $this->fetchRow('SELECT id FROM homepage_hero WHERE page_slug = ' . $this->quote(self::HOME_KEY));
        if ($hero === false || $hero === null) {
            return;
        }

        $this->attachSection($pageId, self::HOME_KEY, 'homepage_hero', null, (int) $hero['id'], 0, $now);
    }

    private function attachSection(
        int $pageId,
        string $pageSlug,
        string $sectionType,
        ?string $sectionKey,
        int $sectionId,
        int $sortOrder,
        string $now
    ): void {
        $existing = $this->fetchRow(
            'SELECT id FROM page_sections WHERE section_type = ' . $this->quote($sectionType)
            . ' AND section_id = ' . $sectionId
        );
        if ($existing !== false && $existing !== null) {
            return;
        }

        $this->execute(
            'INSERT INTO page_sections
                (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$pageId, $pageSlug, $sectionType, $sectionKey, $sectionId, $sortOrder, $now, $now]
        );
    }

    /**
     * One menu entry per page that exists, as a route link — the same link
     * type the six-item menu used before this cleanup. A route link to the
     * Shop resolves through the Shop module's own route table, so it stops
     * rendering by itself on a CMS-only install (App\Service\RouteRegistry).
     */
    private function seedMenu(?int $homeId, ?int $shopId, string $now): void
    {
        $existing = $this->fetchRow('SELECT COUNT(*) AS c FROM nav_items');
        if ((int) $existing['c'] > 0) {
            return;
        }

        $items = [];
        if ($homeId !== null) {
            $items[] = ['Home', 'Home', 'home'];
        }
        if ($shopId !== null) {
            $items[] = ['Shop', 'Shop', 'shop'];
        }

        foreach ($items as $position => [$labelNl, $labelEn, $route]) {
            $this->execute(
                'INSERT INTO nav_items
                    (label_nl, label_en, link_type, target_route, open_in_new_tab, parent_id, sort_order, is_visible, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, NULL, ?, 1, ?, ?)',
                [$labelNl, $labelEn, 'route', $route, $position, $now, $now]
            );
        }
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
