<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * The unified CMS page model: ONE row per publicly reachable, CMS-managed
 * page, replacing the two unrelated systems this project had until now (see
 * MAIN.MD, "Dynamische CMS-pagina's"):
 *
 *   1. Six "template pages" (index/shop/diensten/portfolio/over-mij/contact)
 *      whose only identity was a hardcoded string page_slug in
 *      App\Service\AdminPageRegistry, with their <title>/meta description
 *      hardcoded in each .php file — no Title/Slug/Status/SEO fields the
 *      site owner could ever edit.
 *   2. Three `information_pages` rows (Verzenden & retourneren, Algemene
 *      voorwaarden, Privacyverklaring) which DID have Title/Slug/published/
 *      meta fields, but could only ever hold one rich-text blob and could
 *      not use the page builder at all.
 *
 * Both become rows here, and from now on a page's content — for either kind
 * — is exclusively page_sections + App\Service\SectionRegistry.
 *
 * Columns
 * -------
 * `content_key` is the page's IMMUTABLE storage key: it is what
 * page_sections.page_slug and every section content table's own `page_slug`
 * column (page_heroes, cta_bands, feature_grids, faq_sections, stat_strips,
 * step_list_sections, text_image_splits, marquee_sections,
 * rich_text_sections) contain for this page. It is assigned once, at
 * creation, and never changes — which is exactly why renaming a page's
 * public slug afterwards cannot break, move or orphan a single section row.
 * The alternative (re-keying all nine section tables to reference pages.id)
 * would have meant rewriting every section table, repository, Content class
 * and admin editor in the project for no functional gain over an immutable
 * key. `page_sections` additionally gets a real `page_id` foreign key (see
 * 20260908100200_add_page_id_to_page_sections.php) so the page -> sections
 * relation is a genuine relational one, with cascade delete.
 *
 * `slug` is the public URL slug and IS mutable (for non-system pages) —
 * /<slug> resolves through .htaccess to pagina.php. Navigation and footer
 * links store pages.id, never a slug, so a rename follows through everywhere
 * automatically (App\Service\LinkResolver).
 *
 * `status` is 'draft' or 'published' — validated in PHP
 * (App\Service\PageContent::STATUSES), not a MySQL ENUM, matching this
 * project's existing convention for page_sections.section_type and
 * nav_items.link_type.
 *
 * `is_system` marks the six pages that are NOT ordinary content pages: each
 * is rendered by its own root-level PHP file because it contains bespoke,
 * non-page-builder content the CMS deliberately does not manage (the product
 * grid, the portfolio gallery + filter bar + lightbox, the quote form, the
 * services/material carousel, the homepage's featured-projects teaser — see
 * App\Service\AdminPageRegistry). They are therefore non-deletable, and
 * their slug/status are locked (an admin cannot rename /shop.php to
 * something else, or set the homepage to Draft, and break the shop). Their
 * Title, SEO title and meta description ARE fully editable, exactly like a
 * new page's. Every other page — including the three former information
 * pages — is a normal, freely editable, freely deletable dynamic page.
 *
 * `route_path` is the fixed public path of a system page ('/' for the
 * homepage, '/shop.php', ...), and NULL for a dynamic page (whose path is
 * '/' . slug). It is the single source of truth for a page's public URL and
 * canonical URL — see App\Service\PageContent::publicUrl()/canonicalPath().
 *
 * SEO fields are stored NL + EN because every public-facing text in this
 * project is bilingual (the six template pages already had
 * `<title data-nl=... data-en=...>` and
 * `<meta name="description" data-nl-content=... data-en-content=...>`);
 * storing only one language would have silently dropped the English
 * <title>/description those pages have today. An empty *_en falls back to
 * the NL value at render time, same convention as PageHeroContent.
 *
 * Backfill
 * --------
 * The six system pages are seeded with the EXACT title/meta strings their
 * templates hardcode today (copied verbatim — no SEO text is invented, per
 * the "do not make up SEO metadata" requirement), so the rendered <head> is
 * byte-identical the moment this migration runs. The information pages are
 * copied across with their own stored title/slug/meta and
 * is_published -> status. Matched and guarded by content_key, so re-running
 * migrations never creates duplicates, and a fresh install (where
 * information_pages was just seeded by its own migration) converges on
 * exactly the same nine rows as an upgraded database.
 */
final class CreatePagesTable extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pages')) {
            $this->table('pages', ['id' => true])
                ->addColumn('content_key', 'string', ['limit' => 100])
                ->addColumn('slug', 'string', ['limit' => 170])
                ->addColumn('title', 'string', ['limit' => 200])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'draft'])
                ->addColumn('meta_title', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('meta_title_en', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('meta_description', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('meta_description_en', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('is_system', 'boolean', ['default' => false])
                ->addColumn('route_path', 'string', ['limit' => 190, 'null' => true])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['content_key'], ['unique' => true])
                ->addIndex(['slug'], ['unique' => true])
                ->addIndex(['status'])
                ->create();
        }

        if (InstallState::isFreshInstall($this)) {
            // Diensten, Portfolio, Over mij and Contact are Van Veluw
            // Laserdesign's pages, and the SEO copy below is that site's
            // own. A generic install gets its Homepage — and, when the Shop
            // module owns a storefront, its Shop page — from the
            // fresh-install bootstrap migration, and creates everything else
            // itself, from a page template if it likes.
            // See src/Install/InstallState.php and INSTALL-BOOTSTRAP.md.
            return;
        }

        $now = date('Y-m-d H:i:s');

        // The six system pages, with the exact <title>/description strings
        // currently hardcoded in index.php, shop.php, diensten.php,
        // portfolio.php, over-mij.php and contact.php. Ampersands are stored
        // as plain text (&) — the templates escape on output, where they
        // become &amp; again exactly as in the current markup.
        // [content_key, title, route_path, meta_title, meta_title_en, meta_description, meta_description_en]
        $systemPages = [
            [
                'index',
                'Homepage',
                '/',
                'Van Veluw Laserdesign | Lasergraveren op hout & metaal in Nijmegen',
                'Van Veluw Laserdesign | Laser Engraving in Wood & Metal, Nijmegen',
                'Persoonlijke lasergravure op hout, metaal, glas en acryl. Maatwerk voor cadeaus, bedrijven en bijzondere gelegenheden — ontworpen en gemaakt in Nijmegen.',
                'Personal laser engraving on wood, metal, glass and acrylic. Custom work for gifts, businesses and special occasions — designed and made in Nijmegen.',
            ],
            [
                'shop',
                'Shop',
                '/shop.php',
                'Shop | Gegraveerde producten — Van Veluw Laserdesign',
                'Shop | Engraved products — Van Veluw Laserdesign',
                'Bestel gegraveerde producten uit hout, metaal en acryl — klaar om te verzenden of af te halen in Nijmegen.',
                'Order engraved products in wood, metal and acrylic — ready to ship or pick up in Nijmegen.',
            ],
            [
                'diensten',
                'Diensten',
                '/diensten.php',
                'Diensten | Hout, metaal, acryl & glas graveren — Van Veluw Laserdesign',
                'Services | Wood, metal, acrylic & glass engraving — Van Veluw Laserdesign',
                'Lasergravure op hout, metaal, acryl en glas. Van naam of logo tot compleet maatwerk, voor particulieren en bedrijven — vanuit Nijmegen.',
                'Laser engraving on wood, metal, acrylic and glass. From a name or logo to full custom work, for individuals and businesses — based in Nijmegen.',
            ],
            [
                'portfolio',
                'Portfolio',
                '/portfolio.php',
                'Portfolio | Lasergravure voorbeelden — Van Veluw Laserdesign',
                'Portfolio | Laser engraving examples — Van Veluw Laserdesign',
                'Een portfolio van eerder werk: gravures op hout en metaal, van persoonlijke cadeaus tot zakelijke opdrachten — gemaakt in Nijmegen.',
                'A portfolio of past work: engravings on wood and metal, from personal gifts to business commissions — made in Nijmegen.',
            ],
            [
                'over-mij',
                'Over mij',
                '/over-mij.php',
                'Over mij | Van Veluw Laserdesign — Nijmegen',
                'About | Van Veluw Laserdesign — Nijmegen',
                'Achter Van Veluw Laserdesign staat iemand met een liefde voor ontwerpen, ambacht en lasergravure. Lees het verhaal achter het werk.',
                'Behind Van Veluw Laserdesign is someone with a love for design, craft and laser engraving. Read the story behind the work.',
            ],
            [
                'contact',
                'Contact',
                '/contact.php',
                'Contact & offerte aanvragen | Van Veluw Laserdesign',
                'Contact & request a quote | Van Veluw Laserdesign',
                'Vraag een vrijblijvende offerte aan voor lasergravure op hout, metaal, acryl of glas. Ik reageer persoonlijk op elke aanvraag.',
                'Request a free, no-obligation quote for laser engraving on wood, metal, acrylic or glass. I personally reply to every request.',
            ],
        ];

        $sortOrder = 0;
        foreach ($systemPages as [$contentKey, $title, $routePath, $metaTitle, $metaTitleEn, $metaDescription, $metaDescriptionEn]) {
            $sortOrder += 10;

            if ($this->pageExists($contentKey)) {
                continue;
            }

            $this->execute(
                'INSERT INTO pages
                    (content_key, slug, title, status, meta_title, meta_title_en,
                     meta_description, meta_description_en, is_system, route_path,
                     sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
                [
                    $contentKey,
                    $contentKey,
                    $title,
                    'published',
                    $metaTitle,
                    $metaTitleEn,
                    $metaDescription,
                    $metaDescriptionEn,
                    $routePath,
                    $sortOrder,
                    $now,
                    $now,
                ]
            );
        }

        // The former information pages become ordinary dynamic content pages.
        // Their body (content_html) is migrated to the page builder by
        // 20260908100100_create_rich_text_sections_table.php and
        // 20260908100300_attach_content_page_sections.php.
        if (!$this->hasTable('information_pages')) {
            return;
        }

        $rows = $this->fetchAll('SELECT * FROM information_pages ORDER BY sort_order ASC, id ASC');
        $siteName = $this->siteName();

        foreach ($rows as $row) {
            $contentKey = (string) $row['slug'];

            if ($this->pageExists($contentKey)) {
                continue;
            }

            $sortOrder += 10;

            $this->execute(
                'INSERT INTO pages
                    (content_key, slug, title, status, meta_title, meta_title_en,
                     meta_description, meta_description_en, is_system, route_path,
                     sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, 0, NULL, ?, ?, ?)',
                [
                    $contentKey,
                    (string) $row['slug'],
                    (string) $row['title'],
                    ((int) $row['is_published'] === 1) ? 'published' : 'draft',
                    $this->migratedMetaTitle($row, $siteName),
                    $row['meta_description'],
                    $sortOrder,
                    $row['created_at'] ?? $now,
                    $row['updated_at'] ?? $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $this->table('pages')->drop()->save();
    }

    private function pageExists(string $contentKey): bool
    {
        $stmt = $this->query('SELECT id FROM pages WHERE content_key = ?', [$contentKey]);

        return $stmt->fetch() !== false;
    }

    /**
     * `pages.meta_title` holds the COMPLETE <title> text and is rendered
     * verbatim when set (see App\Service\PageContent::seoTitle()); an empty
     * value falls back to "<Title> — <site name>". informatiepagina.php
     * instead treated information_pages.meta_title as a bare heading and
     * always appended " — Van Veluw Laserdesign" itself, so a straight copy
     * would silently drop that suffix from three live <title> tags.
     *
     * Translating the old value into the new one, without changing a single
     * rendered character:
     *   - empty, or identical to the title (all three seeded pages) -> NULL,
     *     because the new fallback produces the exact same string.
     *   - a genuinely custom override -> that override plus the suffix
     *     informatiepagina.php used to add.
     */
    private function migratedMetaTitle(array $row, string $siteName): ?string
    {
        $metaTitle = trim((string) ($row['meta_title'] ?? ''));
        $title = trim((string) $row['title']);

        if ($metaTitle === '' || $metaTitle === $title) {
            return null;
        }

        return $metaTitle . ' — ' . $siteName;
    }

    private function siteName(): string
    {
        if (!$this->hasTable('site_settings')) {
            return 'Van Veluw Laserdesign';
        }

        $row = $this->query('SELECT setting_value FROM site_settings WHERE setting_key = ?', ['site_name'])->fetch();
        $value = $row === false ? '' : trim((string) $row['setting_value']);

        return $value !== '' ? $value : 'Van Veluw Laserdesign';
    }
}
