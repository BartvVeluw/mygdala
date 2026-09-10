<?php

namespace App\Repository;

/**
 * All portfolio_item_images SQL lives here — the additional gallery photos
 * for a Portfolio item's optional project detail page (see
 * db/migrations/20260906060000_create_portfolio_item_images_table.php).
 * Same shape/conventions as VariantImageRepository: no is_primary flag, the
 * first row in sort_order order is simply the first image in the gallery.
 * This repository only stores the resulting image_path; the actual upload/
 * validation/delete is App\Service\SectionImageUploader's job, called from
 * the API layer.
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

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, portfolio_item_id, image_path, thumbnail_path, alt_nl, alt_en, sort_order
             FROM portfolio_item_images
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new image at the end of the item's current order.
     */
    public function create(int $portfolioItemId, string $imagePath, ?string $altNl, ?string $altEn, ?string $thumbnailPath = null): int
    {
        $nextSortOrder = $this->nextSortOrder($portfolioItemId);

        $stmt = $this->db->prepare(
            'INSERT INTO portfolio_item_images (portfolio_item_id, image_path, thumbnail_path, alt_nl, alt_en, sort_order, created_at, updated_at)
             VALUES (:portfolio_item_id, :image_path, :thumbnail_path, :alt_nl, :alt_en, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'portfolio_item_id' => $portfolioItemId,
            'image_path' => $imagePath,
            'thumbnail_path' => $thumbnailPath,
            'alt_nl' => $altNl,
            'alt_en' => $altEn,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateMeta(int $id, ?string $altNl, ?string $altEn): void
    {
        $stmt = $this->db->prepare(
            'UPDATE portfolio_item_images SET alt_nl = :alt_nl, alt_en = :alt_en, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['alt_nl' => $altNl, 'alt_en' => $altEn, 'id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM portfolio_item_images WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Persists a full new display order for an item's additional photos
     * (drag-and-drop reordering, admin/portfolio-item.php). Only ids that
     * actually belong to $portfolioItemId are honored — same
     * "never drop an image out of the gallery" guarantee as
     * VariantImageRepository::reorder().
     *
     * @param array<int, int> $orderedImageIds
     */
    public function reorder(int $portfolioItemId, array $orderedImageIds): void
    {
        $existing = $this->findByPortfolioItemId($portfolioItemId);
        $existingIds = array_map(static fn (array $img): int => (int) $img['id'], $existing);

        $ordered = [];
        foreach ($orderedImageIds as $id) {
            $id = (int) $id;
            if (in_array($id, $existingIds, true) && !in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }
        foreach ($existingIds as $id) {
            if (!in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }

        $stmt = $this->db->prepare('UPDATE portfolio_item_images SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        foreach ($ordered as $sortOrder => $id) {
            $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
        }
    }

    private function nextSortOrder(int $portfolioItemId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM portfolio_item_images WHERE portfolio_item_id = :portfolio_item_id'
        );
        $stmt->execute(['portfolio_item_id' => $portfolioItemId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
