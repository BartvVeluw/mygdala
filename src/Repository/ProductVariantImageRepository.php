<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All product_variant_images SQL lives here: which of its product's own
 * pictures a variant shows, in the variant's own order (MODULES.md, "Shop").
 *
 * A LINK, NOT A PICTURE. The picture is a product_images row of the same
 * product; this table only says "variant 5 shows picture 12 as its second".
 * Deleting a variant or a picture removes its links (ON DELETE CASCADE) and
 * never a picture or a file. A variant with no links shows every picture of
 * its product — that is the reader's rule (api/product.php, shop.js), not a
 * row here.
 *
 * Replaces variant_images, where a variant owned uploaded files of its own.
 * That table is left as it was by 20260923120000 and nothing reads it.
 */
final class ProductVariantImageRepository extends Repository
{
    /**
     * Every listed variant's pictures, in each variant's order, shaped like
     * ProductImageRepository's rows (the picture's own id is `id`).
     *
     * @param list<int> $variantIds
     * @return array<int, list<array<string, mixed>>> keyed by variant id
     */
    public function findByVariantIds(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds), static fn (int $id): bool => $id > 0)));

        if ($variantIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT pvi.variant_id, pi.id, pi.product_id, pi.media_id,
                    COALESCE(m.path, pi.image_path) AS image_path,
                    COALESCE(m.thumbnail_path, m.path, pi.image_path) AS thumbnail_path,
                    NULLIF(m.alt_text, '') AS alt_text,
                    m.display_name, m.width, m.height,
                    pvi.sort_order
             FROM product_variant_images pvi
             INNER JOIN product_images pi ON pi.id = pvi.product_image_id
             INNER JOIN product_variants v ON v.id = pvi.variant_id AND v.product_id = pi.product_id
             LEFT JOIN media m ON m.id = pi.media_id
             WHERE pvi.variant_id IN ({$placeholders})
             ORDER BY pvi.variant_id ASC, pvi.sort_order ASC, pvi.id ASC"
        );
        $stmt->execute($variantIds);

        $byVariant = [];
        foreach ($stmt->fetchAll() as $row) {
            $variantId = (int) $row['variant_id'];
            unset($row['variant_id']);
            $byVariant[$variantId][] = $row;
        }

        return $byVariant;
    }

    /**
     * Makes a variant's selection exactly $productImageIds, in that order.
     * The caller has checked that every id is a picture of the variant's own
     * product (App\Service\ProductGallery); the join in findByVariantIds()
     * would not show a stray one anyway.
     *
     * @param list<int> $productImageIds
     */
    public function replaceForVariant(int $variantId, array $productImageIds): void
    {
        $delete = $this->db->prepare('DELETE FROM product_variant_images WHERE variant_id = ?');
        $delete->execute([$variantId]);

        $insert = $this->db->prepare(
            'INSERT INTO product_variant_images (variant_id, product_image_id, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        );

        foreach (array_values(array_unique(array_map('intval', $productImageIds))) as $position => $imageId) {
            $insert->execute([$variantId, $imageId, $position]);
        }
    }
}
