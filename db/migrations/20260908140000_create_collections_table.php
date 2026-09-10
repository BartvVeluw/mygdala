<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shop collections: a manually curated, CMS-managed grouping of catalog
 * products with its own public page at /collecties/{slug}.
 *
 * See db/migrations/*_create_collection_products_table.php for the
 * many-to-many relation to `products` — this table only defines the
 * collections themselves. Products stay fully independent entities: a
 * product may belong to zero, one or many collections, and deleting a
 * collection never touches a product (only the pivot rows go).
 *
 * Field conventions are deliberately copied from `products` rather than
 * from `portfolio_categories`, because a collection is a shop entity that
 * renders its own public page: `name`/`name_en` + `description`/
 * `description_en` (nullable `_en` with NL fallback, like every bilingual
 * pair in this project), `image_path` (relative, web-servable — same shape
 * products/CMS sections store) and `is_active` as the publish switch.
 *
 * `slug` is the public URL segment under /collecties/ and is unique. Unlike
 * a product slug it IS editable afterwards (see
 * api/admin/update-collection.php) — collections are new, so no existing
 * URL can break, and the admin needs a way to fix a typo'd slug. It is
 * namespaced under /collecties/, so it can never collide with a root-level
 * application route or a CMS page slug and needs no ReservedRoutes check.
 *
 * `sort_order` drives both the CMS overview and the Collections section on
 * /shop (drag-and-drop in admin/collections.php).
 */
final class CreateCollectionsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('collections', ['id' => true])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('name_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('slug', 'string', ['limit' => 170])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('description_en', 'text', ['null' => true])
            ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['is_active', 'sort_order'])
            ->create();
    }

    public function down(): void
    {
        $this->table('collections')->drop()->save();
    }
}
