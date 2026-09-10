<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Editable SEO metadata for shop products and shop collections.
 *
 * The column names, types and widths are copied verbatim from the CMS page
 * model (db/migrations/20260908100000_create_pages_table.php: meta_title,
 * meta_title_en, meta_description, meta_description_en) and from
 * db/migrations/20260906030000_add_og_image_path_to_site_settings.php
 * (og_image_path) — this is deliberately the SAME SEO vocabulary the project
 * already uses, not a second, parallel one. App\Service\PageContent reads
 * those four columns for a page; App\Service\ProductSeo and
 * App\Service\CollectionContent read exactly the same four (plus the social
 * image) for a product/collection.
 *
 * Every column is NULLABLE with no default, because NULL/'' is what triggers
 * the fallback chain (product name + site title convention, plain-text
 * product description, the product's own photo, the global og_image_path
 * Site Setting). Storing a copy of the product's own name/description here
 * would be exactly the drifting duplicate the fallbacks exist to avoid, so
 * nothing is backfilled: every existing product and collection keeps
 * behaving as it does today until the owner types something.
 *
 * MySQL 5.7 compatible: plain nullable VARCHAR columns, no defaults on TEXT,
 * no generated/JSON columns, one ALTER TABLE per table.
 */
final class AddSeoFieldsToProductsAndCollections extends AbstractMigration
{
    public function up(): void
    {
        $this->table('products')
            ->addColumn('meta_title', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'description_en',
            ])
            ->addColumn('meta_title_en', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'meta_title',
            ])
            ->addColumn('meta_description', 'string', [
                'limit' => 500,
                'null' => true,
                'after' => 'meta_title_en',
            ])
            ->addColumn('meta_description_en', 'string', [
                'limit' => 500,
                'null' => true,
                'after' => 'meta_description',
            ])
            ->addColumn('og_image_path', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'meta_description_en',
            ])
            ->update();

        $this->table('collections')
            ->addColumn('meta_title', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'description_en',
            ])
            ->addColumn('meta_title_en', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'meta_title',
            ])
            ->addColumn('meta_description', 'string', [
                'limit' => 500,
                'null' => true,
                'after' => 'meta_title_en',
            ])
            ->addColumn('meta_description_en', 'string', [
                'limit' => 500,
                'null' => true,
                'after' => 'meta_description',
            ])
            ->addColumn('og_image_path', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'meta_description_en',
            ])
            ->update();
    }

    public function down(): void
    {
        foreach (['products', 'collections'] as $table) {
            $this->table($table)
                ->removeColumn('meta_title')
                ->removeColumn('meta_title_en')
                ->removeColumn('meta_description')
                ->removeColumn('meta_description_en')
                ->removeColumn('og_image_path')
                ->update();
        }
    }
}
