<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds homepage-curation fields to portfolio_gallery_items so the homepage
 * "Portfolio-uitlichting" (index.php) can derive its content directly from
 * the existing Portfolio Gallery instead of maintaining a second, duplicated
 * set of items — see App\Service\PortfolioGalleryContent::featuredForHomepage()
 * and MAIN.MD.
 *
 * `is_featured` marks an item for homepage display (independent of the
 * item's own `is_active`/portfolio-page visibility — an inactive item is
 * excluded from the homepage regardless of this flag).
 *
 * `featured_sort_order` is a second, independent ordering column so the
 * curated homepage order can differ from the item's position in the full
 * portfolio grid (`sort_order`) — same "swap with neighbour" convention,
 * scoped to featured items only. NULL while an item isn't featured; assigned
 * (append-to-end) the moment an item is marked featured, cleared when
 * unmarked, so a later re-feature starts fresh at the end rather than
 * resuming a stale position.
 *
 * Purely additive — existing rows default to not-featured, so
 * portfolio.php's own grid and every other reader of this table is
 * unaffected.
 *
 * Backfills is_featured/featured_sort_order on the same 4 items that were
 * hardcoded on index.php's homepage teaser before this feature (matched by
 * image_path, same order) — see App\Service\PortfolioGalleryContent::DEFAULTS
 * for the identical fallback data. Without this, the homepage would still
 * render correctly via the fallback path (empty selection -> defaults), but
 * the admin's "Homepage-uitlichting" list would misleadingly show 0 items
 * while the page displays 4 — this keeps the CMS state and the rendered
 * output in sync from the start, on this and any future environment this
 * migration runs against (including production, once deployed).
 */
final class AddFeaturedToPortfolioGalleryItems extends AbstractMigration
{
    public function up(): void
    {
        $this->table('portfolio_gallery_items')
            ->addColumn('is_featured', 'boolean', ['default' => false])
            ->addColumn('featured_sort_order', 'integer', ['null' => true, 'default' => null])
            ->update();

        $featured = [
            'assets/images/skyline-nijmegen-hout.webp' => 0,
            'assets/images/nec-stadion-wanddecoratie.webp' => 1,
            'assets/images/medaille-heuvelenloop.webp' => 2,
            'assets/images/naambordje-olifant.webp' => 3,
        ];

        foreach ($featured as $imagePath => $featuredSortOrder) {
            $this->query(
                'UPDATE portfolio_gallery_items SET is_featured = 1, featured_sort_order = ? WHERE image_path = ?',
                [$featuredSortOrder, $imagePath]
            );
        }
    }

    public function down(): void
    {
        $this->table('portfolio_gallery_items')
            ->removeColumn('is_featured')
            ->removeColumn('featured_sort_order')
            ->update();
    }
}
