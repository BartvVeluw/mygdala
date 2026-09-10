<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The dynamic page-builder's junction table: page -> section instance ->
 * section content. See docs/CMS_CONTENT_AUDIT.md and MAIN.MD ("Dynamic page
 * builder") for the full rationale; summary below.
 *
 * Every CMS section type up to this migration (page_heroes, cta_bands,
 * feature_grids, faq_sections, stat_strips, step_list_sections,
 * text_image_splits, marquee_sections, homepage_hero) is a fixed, hand-coded
 * slot in a PHP template: its (page_slug[, section_key]) is a hardcoded
 * constant in both the Content class and the template, so an admin cannot
 * add, remove, duplicate or reorder a section without a code change. This
 * table replaces that hardcoded wiring with data: it is the ordered list of
 * "this content row is attached to this page, at this position, visible or
 * not" — the existing per-type tables/repositories/Content classes/admin
 * editors keep owning their own schema, fallback defaults and validation
 * unchanged; this table only points at them by (section_type, section_id).
 *
 * section_type is a key into App\Service\SectionRegistry (never a raw class
 * name — request data is never used to instantiate a class). section_key
 * mirrors the pointed-at row's own section_key for the repeater types
 * (feature_grid/faq/stat_strip/step_list/text_image_split/marquee) and is
 * NULL for the page_slug-only singleton types (page_hero/cta_band/
 * homepage_hero) — see SectionRegistry for which is which. section_id is the
 * pointed-at row's own primary key, so deleting a page_sections row and
 * knowing exactly which content row to also delete never depends on string
 * matching.
 *
 * zone_key groups a page's sections into the contiguous run they may be
 * freely reordered within. Several pages interleave page-builder-managed
 * content with content this feature deliberately does not manage (the
 * services carousel and portfolio teaser on index.php, the product grid on
 * shop.php, the quicknav + material sections on diensten.php, the filter bar
 * + gallery + lightbox on portfolio.php — see App\Service\AdminPageRegistry,
 * which still lists those as fixed anchors). Free drag-and-drop reordering
 * only ever makes sense *within* one such run without visually displacing
 * that anchored content, so admin/pages.php scopes both the UI and the
 * reorder endpoint's validation to one zone_key at a time. A page with no
 * interleaved static content (over-mij.php, contact.php) simply has one zone
 * covering everything.
 *
 * is_active here is a SEPARATE flag from each content row's own is_active
 * column (e.g. feature_grids.is_active) — this is deliberate, not
 * duplication: a section's own dedicated editor can still hide it
 * independently (existing behaviour, fully preserved), and now the page
 * builder can *also* detach-without-deleting a section from a specific page
 * position. Either flag hides the section from the public page; showing it
 * again from the page builder does not silently override a hide set from
 * the section's own editor (see SectionRegistry::render()).
 *
 * UNIQUE(section_type, section_id) means one content row is attached to
 * exactly one page position — it cannot be silently double-attached, and a
 * lookup from "this content row" back to "its page_sections row" is always
 * unambiguous, which is what the delete/reorder/toggle endpoints rely on for
 * CSRF-safe, non-arbitrary mutation.
 *
 * Backfill (below): every section type's content rows already exist (seeded
 * by their own create-table migrations, 2026-09-04 through 2026-09-06). This
 * migration only attaches them, in the exact order/zone their page template
 * renders them in today, so the public site's rendered output — and order —
 * is unchanged the moment this migration runs. Matched by natural key
 * (page_slug[, section_key]) rather than assuming any particular id, so this
 * is safe to run against a fresh install (same seeded rows, same ids in
 * practice, but matched by key regardless) or a database that was migrated
 * incrementally. Idempotent: every insert is guarded by a "does a
 * page_sections row already point at this exact content row" check, so
 * re-running migrations in development (e.g. after `phinx rollback` +
 * `migrate` while iterating) never creates duplicate attachments.
 */
final class CreatePageSectionsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('page_sections', ['id' => true]);
        $table
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_type', 'string', ['limit' => 50])
            ->addColumn('section_key', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('section_id', 'integer', ['signed' => false])
            ->addColumn('zone_key', 'string', ['limit' => 50, 'default' => 'main'])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['section_type', 'section_id'], ['unique' => true])
            ->addIndex(['page_slug', 'zone_key', 'sort_order'])
            ->create();

        $now = date('Y-m-d H:i:s');

        // [table, page_slug, section_key|null, section_type, zone_key, sort_order]
        $plan = [
            ['homepage_hero', 'index', null, 'homepage_hero', 'index-a', 0],
            ['marquee_sections', 'index', 'materialenband', 'marquee', 'index-a', 1],
            ['feature_grids', 'index', 'value-props', 'feature_grid', 'index-a', 2],
            ['step_list_sections', 'index', 'werkwijze', 'step_list', 'index-b', 0],
            ['stat_strips', 'index', 'capability-band', 'stat_strip', 'index-c', 0],
            ['cta_bands', 'index', null, 'cta_band', 'index-c', 1],

            ['page_heroes', 'shop', null, 'page_hero', 'shop-a', 0],
            ['cta_bands', 'shop', null, 'cta_band', 'shop-b', 0],

            ['page_heroes', 'diensten', null, 'page_hero', 'diensten-a', 0],
            ['faq_sections', 'diensten', 'faq', 'faq', 'diensten-b', 0],
            ['cta_bands', 'diensten', null, 'cta_band', 'diensten-b', 1],

            ['page_heroes', 'portfolio', null, 'page_hero', 'portfolio-a', 0],
            ['cta_bands', 'portfolio', null, 'cta_band', 'portfolio-b', 0],

            ['page_heroes', 'over-mij', null, 'page_hero', 'over-mij-a', 0],
            ['text_image_splits', 'over-mij', 'intro', 'text_image_split', 'over-mij-a', 1],
            ['feature_grids', 'over-mij', 'mijn-stijl', 'feature_grid', 'over-mij-a', 2],
            ['text_image_splits', 'over-mij', 'idee-naar-product', 'text_image_split', 'over-mij-a', 3],

            ['page_heroes', 'contact', null, 'page_hero', 'contact-a', 0],
        ];

        foreach ($plan as [$sourceTable, $pageSlug, $sectionKey, $sectionType, $zoneKey, $sortOrder]) {
            if ($sectionKey === null) {
                $stmt = $this->query("SELECT id FROM {$sourceTable} WHERE page_slug = ?", [$pageSlug]);
            } else {
                $stmt = $this->query("SELECT id FROM {$sourceTable} WHERE page_slug = ? AND section_key = ?", [$pageSlug, $sectionKey]);
            }
            $row = $stmt->fetch();

            if ($row === false) {
                // Nothing to attach — a fresh install that hasn't seeded this
                // row yet, or an environment where this content was removed.
                // The page builder's "+ Add section" can attach one later.
                continue;
            }

            $sectionId = (int) $row['id'];

            $existsStmt = $this->query(
                'SELECT id FROM page_sections WHERE section_type = ? AND section_id = ?',
                [$sectionType, $sectionId]
            );
            if ($existsStmt->fetch() !== false) {
                // Already attached (idempotent re-run) — never duplicate.
                continue;
            }

            $this->execute(
                'INSERT INTO page_sections
                    (page_slug, section_type, section_key, section_id, zone_key, sort_order, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                [$pageSlug, $sectionType, $sectionKey, $sectionId, $zoneKey, $sortOrder, $now, $now]
            );
        }
    }

    public function down(): void
    {
        $this->table('page_sections')->drop()->save();
    }
}
