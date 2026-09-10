<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multiple photos per product, replacing the single products.image_path
 * column as the source of truth for a product's photos. products.image_path
 * is kept and is now a mirror of the primary product_images row (see
 * syncPrimaryImagePath() in api/admin/_product_image_helpers.php) so every
 * existing read of that column (shop cards, cart, order queries/emails)
 * keeps working unchanged.
 */
final class CreateProductImagesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('product_images');
        $table
            ->addColumn('product_id', 'integer', ['signed' => false])
            ->addColumn('image_path', 'string', ['limit' => 255])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_primary', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['product_id'])
            ->create();

        // Migrate every existing products.image_path into a primary
        // product_images row, so nothing that already had a photo loses it.
        $now = date('Y-m-d H:i:s');
        $rows = $this->fetchAll(
            "SELECT id, image_path FROM products WHERE image_path IS NOT NULL AND image_path != ''"
        );

        $pdo = $this->getAdapter()->getConnection();
        $insert = $pdo->prepare(
            'INSERT INTO product_images (product_id, image_path, sort_order, is_primary, created_at, updated_at)
             VALUES (:product_id, :image_path, 0, 1, :created_at, :updated_at)'
        );

        foreach ($rows as $row) {
            $insert->execute([
                'product_id' => (int) $row['id'],
                'image_path' => (string) $row['image_path'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->table('product_images')->drop()->save();
    }
}
