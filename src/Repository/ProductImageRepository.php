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
 *
 * GENERAL OR VARIANT-ONLY. A row with variant_only = 1 is still one of the
 * product's pictures, but only a variant that links to it shows it: it is
 * never in the general gallery and never the primary picture. So every
 * reader of "the product's pictures" here (findByProductId(), findPrimary(),
 * primaryForProducts()) returns general pictures only; what manages the
 * whole pool (the product editor, ProductGallery, ProductDeletionService)
 * asks findPoolByProductId(). The order and is_primary only mean something
 * among the general pictures: applyOrder() writes them and clears
 * variant_only, markVariantOnly() sets it and clears is_primary, so a
 * variant-only row is never primary whatever a caller sends.
 */
class ProductImageRepository extends Repository
{
    private const COLUMNS = 'pi.id, pi.product_id, pi.media_id,
             COALESCE(m.path, pi.image_path) AS image_path,
             COALESCE(m.thumbnail_path, m.path, pi.image_path) AS thumbnail_path,
             NULLIF(m.alt_text, \'\') AS alt_text,
             m.display_name, m.width, m.height,
             pi.sort_order, pi.is_primary, pi.variant_only';

    /**
     * The product's general pictures, in gallery order: what its gallery,
     * its share image and its search result show. A variant-only picture is
     * not among them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByProductId(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM product_images pi
             LEFT JOIN media m ON m.id = pi.media_id
             WHERE pi.product_id = :product_id AND pi.variant_only = 0
             ORDER BY pi.sort_order ASC, pi.id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll();
    }

    /**
     * The product's whole pool: the general pictures in gallery order, then
     * the variant-only ones. For what manages the pool, never for a visitor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPoolByProductId(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM product_images pi
             LEFT JOIN media m ON m.id = pi.media_id
             WHERE pi.product_id = :product_id
             ORDER BY pi.variant_only ASC, pi.sort_order ASC, pi.id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll();
    }

    public function findPrimary(int $productId): ?array
    {
        return $this->findByProductId($productId)[0] ?? null;
    }

    /**
     * The primary picture of each of these products in one query (the first
     * general row in sort_order, as findPrimary() picks it). A product
     * without general pictures is absent.
     *
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>> product id => picture
     */
    public function primaryForProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . "
             FROM product_images pi
             LEFT JOIN media m ON m.id = pi.media_id
             WHERE pi.product_id IN ({$placeholders}) AND pi.variant_only = 0
             ORDER BY pi.product_id ASC, pi.sort_order ASC, pi.id ASC"
        );
        $stmt->execute($ids);

        $primary = [];
        foreach ($stmt->fetchAll() as $row) {
            $primary[(int) $row['product_id']] ??= $row;
        }

        return $primary;
    }

    /**
     * Appends a picture uploaded outside the library (the seeded catalogue,
     * tests). It becomes primary when it is the product's first general one.
     */
    public function create(int $productId, string $imagePath): int
    {
        return $this->insert($productId, $imagePath, null, false);
    }

    /**
     * Appends a Media Library picture at the end of the product's order: a
     * general one, or a variant-only one (which is never primary).
     */
    public function addFromMedia(int $productId, int $mediaId, string $path, bool $variantOnly = false): int
    {
        return $this->insert($productId, $path, $mediaId, $variantOnly);
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
     * Stores $orderedIds as the product's general pictures in their order:
     * position = sort_order, the first one is the primary picture, and each
     * of them is general (variant_only = 0) from now on. Ids of another
     * product are ignored by the WHERE clause.
     *
     * @param list<int> $orderedIds
     */
    public function applyOrder(int $productId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_images
             SET sort_order = :sort_order, is_primary = :is_primary, variant_only = 0, updated_at = NOW()
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

    /**
     * Makes these pictures of the product variant-only: out of the general
     * gallery and never primary, placed after the general ones in sort_order
     * (a position no gallery reads). Their variant links stay as they are.
     * Ids of another product are ignored by the WHERE clause.
     *
     * @param list<int> $ids
     */
    public function markVariantOnly(int $productId, array $ids, int $firstSortOrder = 0): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_images
             SET variant_only = 1, is_primary = 0, sort_order = :sort_order, updated_at = NOW()
             WHERE id = :id AND product_id = :product_id'
        );

        foreach (array_values($ids) as $position => $id) {
            $stmt->execute([
                'sort_order' => $firstSortOrder + $position,
                'id' => (int) $id,
                'product_id' => $productId,
            ]);
        }
    }

    private function insert(int $productId, string $imagePath, ?int $mediaId, bool $variantOnly): int
    {
        $next = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order,
                    COALESCE(SUM(variant_only = 0), 0) AS general
             FROM product_images WHERE product_id = :product_id'
        );
        $next->execute(['product_id' => $productId]);
        $row = $next->fetch();

        $stmt = $this->db->prepare(
            'INSERT INTO product_images (product_id, image_path, media_id, sort_order, is_primary, variant_only, created_at, updated_at)
             VALUES (:product_id, :image_path, :media_id, :sort_order, :is_primary, :variant_only, NOW(), NOW())'
        );
        $stmt->execute([
            'product_id' => $productId,
            'image_path' => $imagePath,
            'media_id' => $mediaId,
            'sort_order' => (int) $row['next_sort_order'],
            'is_primary' => !$variantOnly && (int) $row['general'] === 0 ? 1 : 0,
            'variant_only' => $variantOnly ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }
}
