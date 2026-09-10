<?php

namespace App\Repository;

/**
 * All product_images SQL lives here. One product can have many rows; exactly
 * one of them is is_primary (enforced in application code, not a DB
 * constraint — every write path here goes through this class so that stays
 * true). sort_order controls admin display order and has no other meaning.
 */
class ProductImageRepository extends Repository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByProductId(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, image_path, sort_order, is_primary
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, image_path, sort_order, is_primary
             FROM product_images
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findPrimary(int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, image_path, sort_order, is_primary
             FROM product_images
             WHERE product_id = :product_id AND is_primary = 1
             LIMIT 1'
        );
        $stmt->execute(['product_id' => $productId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new image for a product. It becomes primary when it's the
     * product's first image, or when $forcePrimary is set explicitly.
     */
    public function create(int $productId, string $imagePath, bool $forcePrimary = false): int
    {
        $nextSortOrder = $this->nextSortOrder($productId);
        $isPrimary = $forcePrimary || $nextSortOrder === 0;

        if ($isPrimary) {
            $this->clearPrimary($productId);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO product_images (product_id, image_path, sort_order, is_primary, created_at, updated_at)
             VALUES (:product_id, :image_path, :sort_order, :is_primary, NOW(), NOW())'
        );
        $stmt->execute([
            'product_id' => $productId,
            'image_path' => $imagePath,
            'sort_order' => $nextSortOrder,
            'is_primary' => $isPrimary ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM product_images WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Sets $imageId as the sole primary image for $productId. Returns false
     * (no-op) if $imageId doesn't belong to $productId.
     */
    public function setPrimary(int $productId, int $imageId): bool
    {
        $image = $this->findById($imageId);

        if ($image === null || (int) $image['product_id'] !== $productId) {
            return false;
        }

        $this->clearPrimary($productId);

        $stmt = $this->db->prepare('UPDATE product_images SET is_primary = 1, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $imageId]);

        return true;
    }

    /**
     * Called after deleting an image: if the product has images left but
     * none of them is primary (i.e. the deleted one was), promotes the
     * first-in-order remaining image so a product with photos never ends up
     * without a primary one.
     */
    public function promoteFallbackPrimaryIfNeeded(int $productId): void
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM product_images WHERE product_id = :product_id AND is_primary = 1 LIMIT 1'
        );
        $stmt->execute(['product_id' => $productId]);

        if ($stmt->fetch() !== false) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM product_images WHERE product_id = :product_id ORDER BY sort_order ASC, id ASC LIMIT 1'
        );
        $stmt->execute(['product_id' => $productId]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $this->setPrimary($productId, (int) $row['id']);
        }
    }

    /**
     * Swaps sort_order with the previous/next image (in current display
     * order) for the same product. A no-op at either end of the list.
     */
    public function moveImage(int $productId, int $imageId, string $direction): void
    {
        $images = $this->findByProductId($productId);

        $index = null;
        foreach ($images as $i => $image) {
            if ((int) $image['id'] === $imageId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($images)) {
            return;
        }

        $a = $images[$index];
        $b = $images[$swapWith];

        $this->updateSortOrder((int) $a['id'], (int) $b['sort_order']);
        $this->updateSortOrder((int) $b['id'], (int) $a['sort_order']);
    }

    private function updateSortOrder(int $id, int $sortOrder): void
    {
        $stmt = $this->db->prepare('UPDATE product_images SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    }

    private function clearPrimary(int $productId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_images SET is_primary = 0, updated_at = NOW() WHERE product_id = :product_id'
        );
        $stmt->execute(['product_id' => $productId]);
    }

    private function nextSortOrder(int $productId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM product_images WHERE product_id = :product_id'
        );
        $stmt->execute(['product_id' => $productId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
