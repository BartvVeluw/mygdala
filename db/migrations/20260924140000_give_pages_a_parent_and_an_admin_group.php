<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pagina's 2.0 (docs/pages/NESTING.md): a page can sit under another page,
 * and a page tree can be filed under "Service & juridisch" in the CMS.
 *
 *   pages.parent_id    NULL = a root page; otherwise the page it sits under.
 *                      Structural, so the same in every language: only the
 *                      slugs are per language (page_translations.slug), and a
 *                      page's public path is its ancestors' slugs followed by
 *                      its own (App\Service\PagePath).
 *   pages.admin_group  'website' | 'service' — which list of the Pages
 *                      overview the page's TREE is filed under. Only a root
 *                      page's value decides; every page below it follows its
 *                      root (App\Service\PageAdminGroups). Nothing public
 *                      reads it: not the router, not SEO, not the sitemap.
 *
 * THE KEY IS A NULLABLE SELF-REFERENCE, ON DELETE RESTRICT, the shape
 * 20260907210000 gave nav_items.parent_id and for the same reason: a page with
 * pages under it cannot disappear from under them. App\Service\PageService
 * refuses that delete with a message first; the key is the guarantee behind
 * it, so a script or a forged request cannot leave a child pointing at
 * nothing, and nothing ever cascades a whole subtree away. Unsigned, because
 * Phinx made pages.id unsigned (db/migrations/CLAUDE.md). The index
 * (parent_id, sort_order) is what the key needs anyway, and it is the order
 * siblings are listed in.
 *
 * EVERY EXISTING PAGE BECOMES A ROOT PAGE IN THE WEBSITE GROUP: parent_id NULL
 * and admin_group 'website', both written by MySQL as part of ADD COLUMN. No
 * page id, slug, status or sort_order changes, so no URL changes: a root
 * page's path is /<its slug>, exactly what it was. Nothing is classified from
 * a title or a slug ("privacy" in the slug does not make a page a service
 * page); an editor files a page under Service & juridisch by hand.
 *
 * Schema only, so there is no fresh-install guard (db/migrations/CLAUDE.md),
 * and each step checks before it acts, so a second run, or one that stopped
 * halfway, ends with exactly one column, one index and one key.
 */
final class GivePagesAParentAndAnAdminGroup extends AbstractMigration
{
    private const FOREIGN_KEY = 'fk_pages_parent';

    public function up(): void
    {
        if (!$this->hasTable('pages')) {
            return;
        }

        $table = $this->table('pages');

        if (!$table->hasColumn('parent_id')) {
            $table
                ->addColumn('parent_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'id',
                    'comment' => 'The page this page sits under; NULL = a root page. See App\Service\PagePath',
                ])
                ->update();
        }

        if (!$this->table('pages')->hasColumn('admin_group')) {
            $this->table('pages')
                ->addColumn('admin_group', 'string', [
                    'limit' => 20,
                    'null' => false,
                    'default' => 'website',
                    'after' => 'parent_id',
                    'comment' => 'website | service; only a root page decides, see App\Service\PageAdminGroups',
                ])
                ->update();
        }

        if (!$this->table('pages')->hasIndex(['parent_id', 'sort_order'])) {
            $this->table('pages')->addIndex(['parent_id', 'sort_order'], ['name' => 'idx_pages_parent_order'])->update();
        }

        if (!$this->table('pages')->hasForeignKey('parent_id')) {
            $this->table('pages')
                ->addForeignKey('parent_id', 'pages', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => self::FOREIGN_KEY,
                ])
                ->update();
        }
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md): nothing to undo on purpose.
    }
}
