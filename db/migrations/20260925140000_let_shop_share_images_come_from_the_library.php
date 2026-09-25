<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Media Library 2.0 (MEDIA.md, "Wat er is aangesloten"): the share image of a
 * product and of a collection is chosen from the Media Library, the way a
 * page's and a blog post's already is.
 *
 *   products.og_media_id      the library item; NULL = none, or an image
 *   collections.og_media_id   uploaded before this step that still lives on
 *                             the Shop's own path (og_image_path)
 *
 * og_image_path STAYS and is written along with the chosen item's path, the
 * pattern of pages.og_media_id (20260910150000): App\Service\ProductSeo and
 * App\Service\CollectionContent keep reading the column they always read, so a
 * product or collection that never changes its share image renders exactly as
 * before.
 *
 * NOTHING IS MIGRATED: an existing share image keeps its own file and
 * og_media_id NULL until an editor chooses a library image or removes it.
 *
 * ON DELETE RESTRICT, like every other key onto media; App\Service\ShopMediaUsage
 * reports the use first. Schema only (db/migrations/CLAUDE.md), each step
 * checked before it acts.
 */
final class LetShopShareImagesComeFromTheLibrary extends AbstractMigration
{
    private const TABLES = [
        'products' => 'fk_products_og_media',
        'collections' => 'fk_collections_og_media',
    ];

    public function up(): void
    {
        if (!$this->hasTable('media')) {
            return;
        }

        foreach (self::TABLES as $tableName => $foreignKey) {
            if (!$this->hasTable($tableName)) {
                continue;
            }

            if (!$this->table($tableName)->hasColumn('og_media_id')) {
                $this->table($tableName)
                    ->addColumn('og_media_id', 'integer', [
                        'signed' => false,
                        'null' => true,
                        'default' => null,
                        'after' => 'og_image_path',
                        'comment' => 'Share image from the Media Library; og_image_path is written along with it',
                    ])
                    ->update();
            }

            if (!$this->table($tableName)->hasIndex(['og_media_id'])) {
                $this->table($tableName)->addIndex(['og_media_id'], ['name' => 'idx_' . $tableName . '_og_media'])->update();
            }

            if (!$this->table($tableName)->hasForeignKey('og_media_id')) {
                $this->table($tableName)
                    ->addForeignKey('og_media_id', 'media', 'id', [
                        'delete' => 'RESTRICT',
                        'update' => 'CASCADE',
                        'constraint' => $foreignKey,
                    ])
                    ->update();
            }
        }
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md): nothing to undo on purpose.
    }
}
