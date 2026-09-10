<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Removes the now-fully-migrated `information_pages` table.
 *
 * Everything it held lives somewhere better by the time this runs:
 *   slug/title/meta_title/meta_description/is_published
 *                              -> pages            (20260908100000_...)
 *   content_html               -> rich_text_sections (20260908100100_...)
 *                                 + a page_sections attachment (…100300)
 *   nav_items/footer_links FKs -> pages.id           (…100400)
 *
 * Keeping the table around would leave a second, drifting copy of every
 * page's title/slug/published state next to the unified model — exactly the
 * "existing pages stay as separate legacy definitions" situation this
 * feature exists to remove.
 *
 * Three of its columns were already dead code before this feature:
 * show_in_footer, footer_label and sort_order fed
 * InformationPageContent::publishedForFooter(), which stopped being called
 * when the footer became fully CMS-managed
 * (20260907220000_create_footer_tables.php — footer_columns/footer_links
 * took over, and the migrated "Informatie" column links to the same pages
 * explicitly). They are therefore dropped with nothing to migrate them to;
 * a page's presence in the footer is now a footer_links row.
 *
 * down() recreates the table and copies the data back out of pages +
 * rich_text_sections, so rolling this migration back restores a working
 * information_pages table (the three dead columns come back with their
 * defaults: show_in_footer = 1, footer_label = NULL).
 */
final class DropInformationPagesTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('information_pages')) {
            $this->table('information_pages')->drop()->save();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('information_pages')) {
            return;
        }

        $this->table('information_pages', ['id' => true])
            ->addColumn('slug', 'string', ['limit' => 170])
            ->addColumn('title', 'string', ['limit' => 200])
            ->addColumn('content_html', 'text', ['null' => true])
            ->addColumn('meta_title', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('meta_description', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('is_published', 'boolean', ['default' => true])
            ->addColumn('show_in_footer', 'boolean', ['default' => true])
            ->addColumn('footer_label', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->create();

        $this->execute(
            "INSERT INTO information_pages
                (slug, title, content_html, meta_title, meta_description, is_published,
                 show_in_footer, footer_label, sort_order, created_at, updated_at)
             SELECT p.slug, p.title, rts.content_html, p.meta_title, p.meta_description,
                    CASE WHEN p.status = 'published' THEN 1 ELSE 0 END,
                    1, NULL, p.sort_order, p.created_at, p.updated_at
               FROM pages p
               LEFT JOIN rich_text_sections rts
                 ON rts.page_slug = p.content_key AND rts.section_key = 'content'
              WHERE p.is_system = 0"
        );
    }
}
