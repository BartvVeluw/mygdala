<?php

namespace App\Repository;

/**
 * All product_images SQL lives here: a product's own pool of pictures
 * (MODULES.md, "Shop"). The first row in sort_order is the product's primary
 * picture, and is_primary says the same thing for the readers that ask for
 * it — every write path here keeps the two in step (applyOrder()).
 *
 * A picture chosen from the Media Library has a media_id, and its image_path
 * is written along with the item's path so every older reader of the column
 * keeps working. A picture uploaded before the library has only its
 * image_path. Readers get both through one LEFT JOIN: the library's path,
 * alt text and dimensions when there is an item, the stored path otherwise
 * (the precedence MEDIA.md, "Hoe een feature naar media verwijst", describes).
 *
 * Variants never own a row here; they link to rows of their product in
 * product_variant_images (ProductVariantImageRepository).
 */
class ProductImageRepository extends Repository
{
    private const COLUMNS = 'pi.id, pi.product_id, pi.media_id,
             COALESCE(m.path, pi.image_path) AS image_path,
             COALESCE(m.thumbnail_path, m.path, pi.image_path) AS thumbnail_path,
             NULLIF(m.alt_text, \'\') AS alt_text,
             m.display_name, m.width, m.height,
             pi.sort_order, pi.is_primary';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByProductId(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM product_images pi
             LEFT JOIN media m ON m.id = pi.media_id
             WHERE pi.product_id = :product_id
             ORDER BY pi.sort_order ASC, pi.id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll();
    }

    public function findPrimary(int $productId): ?array
    {
        return $this->findByProductId($productId)[0] ?? null;
    }

    /**
     * Appends a picture uploaded outside the library (the seeded catalogue,
     * tests). It becomes primary when it is the product's first.
     */
    public function create(int $productId, string $imagePath): int
    {
        return $this->insert($productId, $imagePath, null);
    }

    /** Appends a Media Library picture at the end of the product's order. */
    public function addFromMedia(int $productId, int $mediaId, string $path): int
    {
        return $this->insert($productId, $path, $mediaId);
    }

    /**
     * Removes every picture of the product that is not in $keepIds. Only the
     * rows: a library item and an older file on disk both stay (the class
     * docblock of App\Service\ProductGallery says why). Variant links to a
     * removed row go with it (ON DELETE CASCADE).
     *
     * @param list<int> $keepIds
     */
    public function deleteExcept(int $productId, array $keepIds): void
    {
        $keepIds = array_values(array_unique(array_map('intval', $keepIds)));

        if ($keepIds === []) {
            $stmt = $this->db->prepare('DELETE FROM product_images WHERE product_id = ?');
            $stmt->execute([$productId]);

            return;
        }

        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM product_images WHERE product_id = ? AND id NOT IN ({$placeholders})"
        );
        $stmt->execute([$productId, ...$keepIds]);
    }

    /**
     * Stores $orderedIds as the product's order: position = sort_order, and
     * the first one is the primary picture. Ids of another product are
     * ignored by the WHERE clause.
     *
     * @param list<int> $orderedIds
     */
    public function applyOrder(int $productId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_images
             SET sort_order = :sort_order, is_primary = :is_primary, updated_at = NOW()
             WHERE id = :id AND product_id = :product_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute([
                'sort_order' => $position,
                'is_primary' => $position === 0 ? 1 : 0,
                'id' => (int) $id,
                'product_id' => $productId,
            ]);
        }
    }

    private function insert(int $productId, string $imagePath, ?int $mediaId): int
    {
        $next = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order, COUNT(*) AS total
             FROM product_images WHERE product_id = :product_id'
        );
        $next->execute(['product_id' => $productId]);
        $row = $next->fetch();

        $stmt = $this->db->prepare(
            'INSERT INTO product_images (product_id, image_path, media_id, sort_order, is_primary, created_at, updated_at)
             VALUES (:product_id, :image_path, :media_id, :sort_order, :is_primary, NOW(), NOW())'
        );
        $stmt->execute([
            'product_id' => $productId,
            'image_path' => $imagePath,
            'media_id' => $mediaId,
            'sort_order' => (int) $row['next_sort_order'],
            'is_primary' => (int) $row['total'] === 0 ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }
}
