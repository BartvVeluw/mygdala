<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multiple photos per variant, replacing product_variants.image_path (a
 * single optional override) as the source of truth for a variant's photos.
 * No is_primary flag: the first row in sort_order IS the variant's
 * default/primary image (same rule as "first active variant by sort_order
 * is the default variant" — see MAIN.MD). image_name is editable metadata
 * only; it never renames the physical uploaded file.
 */
final class CreateVariantImagesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('variant_images')
            ->addColumn('variant_id', 'integer', ['signed' => false])
            ->addColumn('image_path', 'string', ['limit' => 255])
            ->addColumn('image_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('alt_text', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('variant_id', 'product_variants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['variant_id'])
            ->create();

        // One-time data migration: every variant's existing single
        // image_path override becomes that variant's first (default)
        // variant_images row, so no variant loses its photo.
        $now = date('Y-m-d H:i:s');
        $rows = $this->fetchAll(
            "SELECT id, image_path FROM product_variants WHERE image_path IS NOT NULL AND image_path != ''"
        );

        $pdo = $this->getAdapter()->getConnection();
        $insert = $pdo->prepare(
            'INSERT INTO variant_images (variant_id, image_path, sort_order, created_at, updated_at)
             VALUES (:variant_id, :image_path, 0, :created_at, :updated_at)'
        );

        foreach ($rows as $row) {
            $insert->execute([
                'variant_id' => (int) $row['id'],
                'image_path' => (string) $row['image_path'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->table('product_variants')->removeColumn('image_path')->update();
    }

    public function down(): void
    {
        $this->table('product_variants')
            ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
            ->update();

        $pdo = $this->getAdapter()->getConnection();
        $rows = $this->fetchAll(
            'SELECT vi.variant_id, vi.image_path
             FROM variant_images vi
             INNER JOIN (
                 SELECT variant_id, MIN(sort_order) AS min_sort
                 FROM variant_images
                 GROUP BY variant_id
             ) first ON first.variant_id = vi.variant_id AND first.min_sort = vi.sort_order'
        );

        $update = $pdo->prepare('UPDATE product_variants SET image_path = :image_path WHERE id = :id');
        foreach ($rows as $row) {
            $update->execute([
                'image_path' => (string) $row['image_path'],
                'id' => (int) $row['variant_id'],
            ]);
        }

        $this->table('variant_images')->drop()->save();
    }
}
