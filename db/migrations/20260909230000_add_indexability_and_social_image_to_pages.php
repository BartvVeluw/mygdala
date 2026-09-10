<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The two SEO fields a CMS page did not have yet: whether search engines may
 * index it, and its own social sharing image.
 *
 * `pages` already carried meta_title/meta_title_en/meta_description/
 * meta_description_en (db/migrations/20260908100000_create_pages_table.php).
 * Products and collections carried those four PLUS og_image_path
 * (20260908180000), so a page was the only content type in this project that
 * could not override the share preview — and no content type at all could be
 * kept out of the index short of unpublishing it, which also takes the page
 * offline. SEO Foundation V1 closes both gaps with the SAME vocabulary
 * rather than a second one: the column is called og_image_path here too, and
 * App\Service\PageSeo reads it exactly the way App\Service\ProductSeo reads
 * the product's.
 *
 * noindex is a NOT NULL TINYINT defaulting to 0 — every existing page stays
 * indexable, which is what they all are today, and a NULL can never turn
 * into an accidental "maybe". The public default is decided by
 * App\Service\SeoDefaults, not here; this column only says "this one page
 * opts out".
 *
 * og_image_path is nullable with no default, because NULL is what triggers
 * the fallback chain (the site-wide Standaard deel-afbeelding). Nothing is
 * backfilled: storing a copy of the global image on every page is exactly
 * the drifting duplicate the fallback exists to avoid.
 *
 * MySQL 5.7 compatible: two plain columns, one ALTER TABLE.
 */
final class AddIndexabilityAndSocialImageToPages extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pages')
            ->addColumn('og_image_path', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'meta_description_en',
            ])
            ->addColumn('noindex', 'boolean', [
                'null' => false,
                'default' => 0,
                'after' => 'og_image_path',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('pages')
            ->removeColumn('og_image_path')
            ->removeColumn('noindex')
            ->update();
    }
}
