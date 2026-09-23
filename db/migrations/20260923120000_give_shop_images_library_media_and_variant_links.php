<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Puts the Shop's pictures on the Media Library and gives a product ONE pool
 * of pictures that its variants choose from (MODULES.md, "Shop"; MEDIA.md).
 *
 * WHAT CHANGES
 *
 *   - product_images.media_id and collections.media_id: a reference to a
 *     `media` row, ON DELETE RESTRICT like every other media reference. The
 *     old image_path stays as the fallback MEDIA.md describes and is written
 *     along with the chosen item's path, so every existing reader of it keeps
 *     working unchanged.
 *   - product_variant_images: which of the product's own pictures a variant
 *     shows, in the variant's own order. A link, not a copy: removing a
 *     variant removes its links and nothing else, and one picture can be
 *     shown by several variants.
 *
 * WHY. A variant used to own a separate set of uploaded files (variant_images),
 * and the product page showed only those as soon as a product had variants,
 * so adding a variant made the product's own pictures disappear. Now a
 * picture always belongs to the product, and assigning it to a variant never
 * moves it.
 *
 * THE BACKFILL keeps every gallery a visitor sees today. Each variant_images
 * row becomes a picture of the variant's product (reusing a product picture
 * with the same path, so nothing is doubled) plus a link in the variant's own
 * order. No file is moved, copied or deleted. variant_images itself is left
 * exactly as it is — forward-only — and nothing reads it any more.
 *
 * Schema first, data after, and the data step only in the run that created
 * the link table: a second run finds the table and adds nothing
 * (db/migrations/CLAUDE.md).
 */
final class GiveShopImagesLibraryMediaAndVariantLinks extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('media')) {
            return;
        }

        if ($this->hasTable('product_images') && !$this->table('product_images')->hasColumn('media_id')) {
            $this->table('product_images')
                ->addColumn('media_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'image_path',
                    'comment' => 'media.id; image_path is the fallback and is written along with it',
                ])
                ->addIndex(['product_id', 'media_id'], ['unique' => true, 'name' => 'uq_product_images_product_media'])
                ->addForeignKey('media_id', 'media', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_product_images_media',
                ])
                ->update();
        }

        if ($this->hasTable('collections') && !$this->table('collections')->hasColumn('media_id')) {
            $this->table('collections')
                ->addColumn('media_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'image_path',
                    'comment' => 'media.id; image_path is the fallback and is written along with it',
                ])
                ->addForeignKey('media_id', 'media', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_collections_media',
                ])
                ->update();
        }

        if (
            $this->hasTable('product_variant_images')
            || !$this->hasTable('product_variants')
            || !$this->hasTable('product_images')
        ) {
            return;
        }

        $this->table('product_variant_images', ['id' => true])
            ->addColumn('variant_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('product_image_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['variant_id', 'product_image_id'], ['unique' => true, 'name' => 'uq_product_variant_images_pair'])
            ->addIndex(['product_image_id'], ['name' => 'idx_product_variant_images_image'])
            ->addForeignKey('variant_id', 'product_variants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_product_variant_images_variant',
            ])
            ->addForeignKey('product_image_id', 'product_images', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_product_variant_images_image',
            ])
            ->create();

        if ($this->hasTable('variant_images')) {
            $this->backfillVariantLinks();
        }
    }

    /**
     * Every old variant picture becomes a product picture plus a link, in the
     * variant's own order. A product that had no picture of its own gets the
     * first one as its primary, and its products.image_path only when that
     * was still empty, so no card changes.
     */
    private function backfillVariantLinks(): void
    {
        $rows = $this->fetchAll(
            "SELECT vi.variant_id, vi.image_path, v.product_id
             FROM variant_images vi
             INNER JOIN product_variants v ON v.id = vi.variant_id
             WHERE vi.image_path IS NOT NULL AND vi.image_path <> '' AND v.product_id IS NOT NULL
             ORDER BY v.product_id ASC, v.sort_order ASC, v.id ASC, vi.sort_order ASC, vi.id ASC"
        );

        $now = date('Y-m-d H:i:s');
        $linkOrder = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $variantId = (int) $row['variant_id'];
            $path = (string) $row['image_path'];

            $imageId = $this->productImageId($productId, $path, $now);

            $exists = $this->fetchRow(
                'SELECT 1 FROM product_variant_images WHERE variant_id = ' . $variantId . ' AND product_image_id = ' . $imageId
            );
            if ($exists !== false) {
                continue;
            }

            $sortOrder = $linkOrder[$variantId] ?? 0;
            $linkOrder[$variantId] = $sortOrder + 1;

            $this->execute(
                'INSERT INTO product_variant_images (variant_id, product_image_id, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$variantId, $imageId, $sortOrder, $now, $now]
            );
        }
    }

    /** The product's picture with this path, created at the end of its order when there is none. */
    private function productImageId(int $productId, string $path, string $now): int
    {
        $existing = $this->query(
            'SELECT id FROM product_images WHERE product_id = ? AND image_path = ? ORDER BY id ASC LIMIT 1',
            [$productId, $path]
        )->fetch();

        if ($existing !== false) {
            return (int) $existing['id'];
        }

        $next = $this->query(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort, COUNT(*) AS total FROM product_images WHERE product_id = ?',
            [$productId]
        )->fetch();
        $isPrimary = (int) $next['total'] === 0;

        $this->execute(
            'INSERT INTO product_images (product_id, image_path, sort_order, is_primary, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$productId, $path, (int) $next['next_sort'], $isPrimary ? 1 : 0, $now, $now]
        );

        $id = (int) $this->getAdapter()->getConnection()->lastInsertId();

        if ($isPrimary) {
            $this->execute(
                "UPDATE products SET image_path = ? WHERE id = ? AND (image_path IS NULL OR image_path = '')",
                [$path, $productId]
            );
        }

        return $id;
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
