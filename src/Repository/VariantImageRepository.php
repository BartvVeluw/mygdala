<?php

namespace App\Repository;

/**
 * All variant_images SQL lives here. A variant can have many rows; there is
 * no is_primary flag — the first row in sort_order order IS the variant's
 * default/primary image (same "first by sort_order" rule used for the
 * default variant itself, see MAIN.MD). image_name/alt_text are editable
 * metadata only and never affect the stored file.
 */
class VariantImageRepository extends Repository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByVariantId(int $variantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, variant_id, image_path, image_name, alt_text, sort_order
             FROM variant_images
             WHERE variant_id = :variant_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['variant_id' => $variantId]);

        return $stmt->fetchAll();
    }

    /**
     * Bulk variant of findByVariantId(), grouped by variant_id — avoids N+1
     * queries when loading images for every variant of a product at once.
     *
     * @param array<int, int> $variantIds
     * @return array<int, array<int, array<string, mixed>>> keyed by variant_id
     */
    public function findByVariantIds(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, variant_id, image_path, image_name, alt_text, sort_order
             FROM variant_images
             WHERE variant_id IN ({$placeholders})
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute($variantIds);

        $byVariant = [];
        foreach ($stmt->fetchAll() as $row) {
            $byVariant[(int) $row['variant_id']][] = $row;
        }

        return $byVariant;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, variant_id, image_path, image_name, alt_text, sort_order
             FROM variant_images
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new image at the end of the variant's current order.
     */
    public function create(int $variantId, string $imagePath, ?string $imageName, ?string $altText): int
    {
        $nextSortOrder = $this->nextSortOrder($variantId);

        $stmt = $this->db->prepare(
            'INSERT INTO variant_images (variant_id, image_path, image_name, alt_text, sort_order, created_at, updated_at)
             VALUES (:variant_id, :image_path, :image_name, :alt_text, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'variant_id' => $variantId,
            'image_path' => $imagePath,
            'image_name' => $imageName,
            'alt_text' => $altText,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateMeta(int $id, ?string $imageName, ?string $altText): void
    {
        $stmt = $this->db->prepare(
            'UPDATE variant_images SET image_name = :image_name, alt_text = :alt_text, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['image_name' => $imageName, 'alt_text' => $altText, 'id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM variant_images WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Persists a full new display order for a variant's images (drag-and-drop
     * reordering). Only ids that actually belong to $variantId are honored —
     * anything else in $orderedImageIds is silently ignored. Images belonging
     * to the variant but missing from $orderedImageIds keep their relative
     * order and are placed after the given ones, so an incomplete list can
     * never drop an image out of the gallery.
     *
     * @param array<int, int> $orderedImageIds
     */
    public function reorder(int $variantId, array $orderedImageIds): void
    {
        $existing = $this->findByVariantId($variantId);
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

        $stmt = $this->db->prepare('UPDATE variant_images SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        foreach ($ordered as $sortOrder => $id) {
            $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
        }
    }

    private function nextSortOrder(int $variantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM variant_images WHERE variant_id = :variant_id'
        );
        $stmt->execute(['variant_id' => $variantId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
