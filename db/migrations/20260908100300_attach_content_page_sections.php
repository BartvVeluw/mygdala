<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Turns each migrated information page into a real page-builder page, so it
 * renders through pagina.php + App\Service\SectionRegistry exactly the way
 * a brand-new CMS page does — with visually identical output to what
 * informatiepagina.php produced before this feature.
 *
 * informatiepagina.php rendered every published page as a fixed two-part
 * template: a `.page-hero` block (breadcrumb "Home / <title>", the literal
 * eyebrow "Informatie"/"Information", and the page title as <h1>), followed
 * by the rich-text body in a `.container--narrow` column. Those two parts
 * map one-to-one onto two existing/new section types:
 *
 *   Page Hero  (page_heroes, partials/section-page-hero.php)
 *   Rich Text  (rich_text_sections, partials/section-rich-text.php)
 *
 * so this migration attaches exactly those two, in that order, to each
 * content page's single 'main' zone. A dynamic page has one zone because it
 * has no fixed, non-page-builder content to keep sections from crossing —
 * unlike the six system pages (see App\Service\AdminPageRegistry::ZONES).
 *
 * The Page Hero row is seeded with the strings informatiepagina.php
 * hardcoded, so the rendered hero is the same markup with the same text; the
 * only difference is that all of it is now editable, and the eyebrow/
 * breadcrumb/lead can be changed or the whole hero removed per page.
 * English values are left empty, which PageHeroContent resolves back to the
 * Dutch value — the same output informatiepagina.php gave (its <h1> and
 * breadcrumb had no data-en attributes at all).
 *
 * Idempotent: a page that already has any page_sections row is skipped
 * entirely, so re-running migrations never double-attaches, and a fresh
 * install lands on exactly the same result as an upgraded database.
 */
final class AttachContentPageSections extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $pages = $this->fetchAll('SELECT id, content_key FROM pages WHERE is_system = 0 ORDER BY sort_order ASC, id ASC');

        foreach ($pages as $page) {
            $pageId = (int) $page['id'];
            $contentKey = (string) $page['content_key'];

            $attached = $this->query('SELECT id FROM page_sections WHERE page_id = ?', [$pageId]);
            if ($attached->fetch() !== false) {
                continue;
            }

            $richText = $this->query(
                'SELECT id FROM rich_text_sections WHERE page_slug = ? AND section_key = ?',
                [$contentKey, 'content']
            )->fetch();

            if ($richText === false) {
                // Nothing to migrate for this page (no body content row) —
                // leave it with no sections rather than inventing any.
                continue;
            }

            $hero = $this->query('SELECT id FROM page_heroes WHERE page_slug = ?', [$contentKey])->fetch();
            if ($hero === false) {
                $this->execute(
                    'INSERT INTO page_heroes
                        (page_slug, eyebrow_nl, eyebrow_en, title_nl, title_en, lead_nl, lead_en,
                         breadcrumb_label_nl, breadcrumb_label_en, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, (SELECT title FROM pages WHERE id = ?), NULL, NULL, NULL,
                             (SELECT title FROM pages WHERE id = ?), NULL, 1, ?, ?)',
                    [$contentKey, 'Informatie', 'Information', $pageId, $pageId, $now, $now]
                );
                $hero = $this->query('SELECT id FROM page_heroes WHERE page_slug = ?', [$contentKey])->fetch();
            }

            $this->attach($pageId, $contentKey, 'page_hero', null, (int) $hero['id'], 0, $now);
            $this->attach($pageId, $contentKey, 'rich_text', 'content', (int) $richText['id'], 1, $now);
        }
    }

    public function down(): void
    {
        // Detaches only what up() attached: the page_hero/rich_text sections
        // of non-system pages. The content rows themselves (page_heroes,
        // rich_text_sections) are left alone — they are dropped by their own
        // migrations' down().
        $this->execute(
            "DELETE ps FROM page_sections ps
                JOIN pages p ON p.id = ps.page_id
             WHERE p.is_system = 0 AND ps.section_type IN ('page_hero', 'rich_text')"
        );
    }

    private function attach(
        int $pageId,
        string $contentKey,
        string $sectionType,
        ?string $sectionKey,
        int $sectionId,
        int $sortOrder,
        string $now
    ): void {
        $existing = $this->query(
            'SELECT id FROM page_sections WHERE section_type = ? AND section_id = ?',
            [$sectionType, $sectionId]
        );
        if ($existing->fetch() !== false) {
            return;
        }

        $this->execute(
            'INSERT INTO page_sections
                (page_id, page_slug, section_type, section_key, section_id, zone_key, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$pageId, $contentKey, $sectionType, $sectionKey, $sectionId, 'main', $sortOrder, $now, $now]
        );
    }
}
