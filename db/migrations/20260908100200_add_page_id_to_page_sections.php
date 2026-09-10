<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gives page_sections a real foreign key to the new `pages` table, so the
 * page -> its sections relation is relational rather than a string match on
 * page_slug (see 20260908100000_create_pages_table.php for the full
 * rationale, including why the section CONTENT tables deliberately keep
 * using the immutable pages.content_key string instead of being re-keyed).
 *
 * page_slug stays on this table on purpose: it is the owning page's
 * immutable content_key, and it is what every section content table
 * (page_heroes, feature_grids, ...) is keyed by, so
 * App\Service\SectionRegistry::render() still needs it to look content up.
 * It is never the page's public URL slug and never changes when the admin
 * renames a page.
 *
 * ON DELETE CASCADE: deleting a page removes its page_sections attachments
 * automatically. That is a safety net, not the delete path — the actual
 * delete flow (App\Service\PageService::delete()) walks the page's sections
 * and calls SectionRegistry::delete() on each first, so the underlying
 * content rows and any uploaded media are cleaned up too, inside one
 * transaction, instead of being orphaned.
 *
 * The backfill fails loudly if any page_sections row cannot be mapped to a
 * page: silently leaving such a row NULL would make its section vanish from
 * the site with no error, which is exactly the kind of quiet content loss a
 * migration must never cause. In practice every page_slug in this table is
 * one of the six system pages the previous migration seeded.
 */
final class AddPageIdToPageSections extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('page_sections');

        if (!$table->hasColumn('page_id')) {
            $table->addColumn('page_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'id'])
                ->update();
        }

        $this->execute(
            'UPDATE page_sections ps
                JOIN pages p ON p.content_key = ps.page_slug
             SET ps.page_id = p.id
             WHERE ps.page_id IS NULL'
        );

        $orphans = $this->fetchAll('SELECT id, page_slug FROM page_sections WHERE page_id IS NULL');
        if ($orphans !== []) {
            $slugs = implode(', ', array_unique(array_map(static fn (array $r): string => (string) $r['page_slug'], $orphans)));

            throw new \RuntimeException(
                'page_sections rows reference page_slug values with no matching pages.content_key: ' . $slugs
                . '. Create the missing page rows first, then re-run this migration — refusing to leave sections unattached.'
            );
        }

        $table = $this->table('page_sections');
        $table->changeColumn('page_id', 'integer', ['signed' => false, 'null' => false])->update();

        if (!$table->hasForeignKey('page_id')) {
            $this->table('page_sections')
                ->addForeignKey('page_id', 'pages', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('page_sections');

        if ($table->hasForeignKey('page_id')) {
            $table->dropForeignKey('page_id')->update();
        }

        $this->table('page_sections')->removeColumn('page_id')->update();
    }
}
