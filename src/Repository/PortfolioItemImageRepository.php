<?php

namespace App\Repository;

/**
 * All portfolio_item_images SQL: the extra photos of an item's OLD project
 * page (see db/migrations/20260906060000_create_portfolio_item_images_table.php).
 *
 * Read-only since a project page became an ordinary CMS page that an item
 * links to (MODULES.md, "Portfolio"). No screen adds, edits, reorders or
 * deletes these photos any more, so nothing here writes, and the rows and
 * their files stay. Two readers remain: portfolio-detail.php, through
 * App\Service\PortfolioGalleryContent::itemForDetailPage(), for an old address
 * that still shows its page; and api/admin/delete-portfolio-item.php, which
 * removes an item's files before the foreign key's ON DELETE CASCADE removes
 * these rows.
 *
 * Same shape/conventions as VariantImageRepository: no is_primary flag, the
 * first row in sort_order order is simply the first image in the gallery.
 */
class PortfolioItemImageRepository extends Repository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByPortfolioItemId(int $portfolioItemId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, portfolio_item_id, image_path, thumbnail_path, alt_nl, alt_en, sort_order
             FROM portfolio_item_images
             WHERE portfolio_item_id = :portfolio_item_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['portfolio_item_id' => $portfolioItemId]);

        return $stmt->fetchAll();
    }
}
