<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One page = ONE ordered list of content blocks.
 *
 * Until now a page's blocks were split into several `zone_key` sub-lists
 * (see 20260907160000_create_page_sections_table.php): each of the six pages
 * with its own PHP template interleaves page-builder blocks with content the
 * template hardcoded — the services carousel and portfolio teaser on the
 * homepage, the collection tiles and product grid on Shop, the quicknav and
 * material sections on Diensten, the filter bar and gallery on Portfolio,
 * the quote form on Contact — and a zone was the contiguous run of blocks
 * that could be reordered without displacing that fixed content. The result
 * was a page holding up to three separate lists, exactly what
 * docs/content-blocks/ARCHITECTURE.md rules out.
 *
 * This migration removes the split by promoting that hardcoded content to
 * what it always was in page order: a block. Each piece becomes a normal
 * page_sections row of a new FIXED block type registered in
 * App\Service\SectionRegistry (`services_carousel`, `portfolio_teaser`,
 * `shop_collections`, `product_grid`, `service_quicknav`, `service_details`,
 * `portfolio_gallery`, `contact_form`) — positioned and reorderable like any
 * other block, but not manually addable and not deletable, because its
 * content is still owned by the product catalogue / Portfolio / the Diensten
 * editors / Site-instellingen. With every piece of the page in one list,
 * `zone_key` has nothing left to describe and is dropped.
 *
 * A fixed block has no content row of its own, so `section_id` is 0. That is
 * not a placeholder gap: combined with the existing
 * UNIQUE(section_type, section_id) it is what guarantees a fixed type can
 * exist exactly once across the whole site — the same "max one instance" the
 * registry declares.
 *
 * Render order is preserved EXACTLY. For every page the rows are re-numbered
 * in their current (zone_key, sort_order, id) order, and each fixed block is
 * inserted at the position its template rendered it: directly after the zone
 * it was anchored to (the former AdminPageRegistry::SECTIONS' 'zone_after').
 * Nothing is deleted, no content row is touched, and the public pages render
 * byte-identically the moment this runs.
 *
 * Idempotent: the fixed-block inserts are guarded on
 * (section_type, section_id) already existing, and the whole migration is
 * skipped once `zone_key` is gone. Safe on a fresh install too — a page or
 * block that isn't there is simply skipped.
 *
 * MySQL/Vimexx: plain ALTER TABLE + UPDATE/INSERT, no CTEs, no window
 * functions, no JSON.
 *
 * Note the portfolio lightbox is deliberately NOT a block: it lives outside
 * <main> in portfolio.php and is theme behaviour, not page content. It used
 * to be listed as a fixed admin row, which was misleading.
 */
final class FlattenPageSectionsIntoOneList extends AbstractMigration
{
    /**
     * Per page content_key, the fixed blocks it renders and the zone_key
     * each one came directly after — copied verbatim from the former
     * App\Service\AdminPageRegistry::SECTIONS' 'zone_after' anchors, which
     * is what made the page render in that order until now.
     *
     * @var array<string, list<array{0: string, 1: string}>> content_key => [[section_type, after zone_key], ...]
     */
    private const FIXED_BLOCKS = [
        'index' => [
            ['services_carousel', 'index-a'],
            ['portfolio_teaser', 'index-b'],
        ],
        'shop' => [
            ['shop_collections', 'shop-a'],
            ['product_grid', 'shop-a'],
        ],
        'diensten' => [
            ['service_quicknav', 'diensten-a'],
            ['service_details', 'diensten-a'],
        ],
        'portfolio' => [
            ['portfolio_gallery', 'portfolio-a'],
        ],
        'contact' => [
            ['contact_form', 'contact-a'],
        ],
    ];

    public function up(): void
    {
        $table = $this->table('page_sections');

        if (!$table->hasColumn('zone_key')) {
            // Already flattened.
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($this->fetchAll('SELECT id, content_key FROM pages ORDER BY id') as $page) {
            $pageId = (int) $page['id'];
            $contentKey = (string) $page['content_key'];

            $rows = $this->fetchAll(
                'SELECT id, zone_key FROM page_sections WHERE page_id = ' . $pageId
                . ' ORDER BY zone_key ASC, sort_order ASC, id ASC'
            );

            // The page's blocks in current render order, with each fixed
            // block spliced in right after the last row of the zone it was
            // anchored to. Zones with no rows of their own still place their
            // fixed blocks, in zone order.
            $fixedByZone = [];
            foreach (self::FIXED_BLOCKS[$contentKey] ?? [] as [$sectionType, $afterZone]) {
                $fixedByZone[$afterZone][] = $sectionType;
            }

            $ordered = [];
            $seenZones = [];
            $previousZone = null;

            foreach ($rows as $row) {
                $zone = (string) $row['zone_key'];

                if ($previousZone !== null && $zone !== $previousZone) {
                    foreach ($fixedByZone[$previousZone] ?? [] as $sectionType) {
                        $ordered[] = ['fixed', $sectionType];
                    }
                    $seenZones[$previousZone] = true;
                }

                $ordered[] = ['existing', (int) $row['id']];
                $previousZone = $zone;
            }

            if ($previousZone !== null) {
                foreach ($fixedByZone[$previousZone] ?? [] as $sectionType) {
                    $ordered[] = ['fixed', $sectionType];
                }
                $seenZones[$previousZone] = true;
            }

            // A zone that held no rows at all never got its turn above.
            foreach ($fixedByZone as $zone => $sectionTypes) {
                if (isset($seenZones[$zone])) {
                    continue;
                }
                foreach ($sectionTypes as $sectionType) {
                    $ordered[] = ['fixed', $sectionType];
                }
            }

            foreach ($ordered as $position => [$kind, $value]) {
                if ($kind === 'existing') {
                    $this->execute(
                        'UPDATE page_sections SET sort_order = ?, updated_at = ? WHERE id = ?',
                        [$position, $now, $value]
                    );
                    continue;
                }

                $exists = $this->query(
                    'SELECT id FROM page_sections WHERE section_type = ? AND section_id = 0',
                    [$value]
                )->fetch();

                if ($exists !== false) {
                    $this->execute(
                        'UPDATE page_sections SET sort_order = ?, updated_at = ? WHERE id = ?',
                        [$position, $now, (int) $exists['id']]
                    );
                    continue;
                }

                $this->execute(
                    'INSERT INTO page_sections
                        (page_id, page_slug, section_type, section_key, section_id, zone_key, sort_order, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, NULL, 0, ?, ?, 1, ?, ?)',
                    [$pageId, $contentKey, $value, 'main', $position, $now, $now]
                );
            }
        }

        // The old (page_slug, zone_key, sort_order) index — MySQL names a
        // multi-column index after its first column — is replaced by the one
        // lookup that remains: a page's blocks in order, by page_id.
        $table = $this->table('page_sections');

        if ($table->hasIndexByName('page_slug')) {
            $table->removeIndexByName('page_slug')->update();
        }

        $this->table('page_sections')
            ->removeColumn('zone_key')
            ->addIndex(['page_id', 'sort_order'])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('page_sections');

        if ($table->hasColumn('zone_key')) {
            return;
        }

        $this->table('page_sections')
            ->addColumn('zone_key', 'string', ['limit' => 50, 'default' => 'main', 'after' => 'section_id'])
            ->update();

        // The fixed blocks only exist in the one-list model; the six page
        // templates render that content themselves again once they are gone.
        $this->execute('DELETE FROM page_sections WHERE section_id = 0');
    }
}
