<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shop Product & Ordering 2.0, systeempagina's: the Shop and the Portfolio
 * each get their page in Pagina's on EVERY installation, whether the module
 * is on or off (App\Service\ModuleSystemPages, docs/pages/NESTING.md,
 * MODULES.md "Systeempagina's van modules").
 *
 *   pages.module_default   1 on a module's page that THIS migration made:
 *                          while it has no block of its own, the module shows
 *                          its own overview on its address, exactly as before
 *                          the page existed (the built-in Portfolio overview,
 *                          the Shop's 'builtin' listing), and nothing public
 *                          lists the page itself. 0 for every other page,
 *                          including a module page an installation already had.
 *
 * FOR EACH OF shop AND portfolio, by content key and nothing else:
 *
 *   - a page with that content key exists (an installation seeded with it,
 *     or an early fresh install): it IS the system page. Nothing about it is
 *     written — not its title, slug, status, address, blocks or SEO.
 *   - none exists, and nothing else claims its word: a page is created —
 *     content key and slug the word, `is_system` 1 (its own template),
 *     `route_path` /shop.php or /portfolio, published, top level in the
 *     website group, the word as its title in the default language, no slug
 *     per language (a route-bound page has none), module_default 1.
 *   - none exists but another page already holds the word as its slug (in
 *     `pages.slug` or as a slug in any language): NOTHING is created or
 *     renamed. That can only be a page made outside the CMS, which has
 *     always refused these words. The migration says so in its output, and
 *     Pagina's names the conflict (ModuleSystemPages::conflicts()), so the
 *     owner decides. A migration never renames or deletes an owner's page.
 *
 * Replaying it finds the pages by content key and creates nothing twice.
 * Switching a module on or off later touches nothing: the page is simply
 * there, the same record, with its settings.
 */
final class GiveTheShopAndPortfolioTheirSystemPages extends AbstractMigration
{
    /** @var array<string, array{route_path: string, title: string}> content key => the page */
    private const PAGES = [
        'shop' => ['route_path' => '/shop.php', 'title' => 'Shop'],
        'portfolio' => ['route_path' => '/portfolio', 'title' => 'Portfolio'],
    ];

    public function up(): void
    {
        $pages = $this->table('pages');
        if (!$pages->hasColumn('module_default')) {
            $pages->addColumn('module_default', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'route_path',
                'comment' => "1 = a module's system page created for it: shows the module's own overview while it has no block",
            ])->update();
        }

        if (!$this->hasTable('page_translations') || !$this->hasTable('site_languages')) {
            return;
        }

        $default = $this->fetchRow('SELECT code FROM site_languages WHERE is_default = 1 LIMIT 1');
        $defaultLanguage = is_array($default) && isset($default['code']) ? (string) $default['code'] : 'nl';

        foreach (self::PAGES as $contentKey => $page) {
            if ($this->fetchRow('SELECT id FROM pages WHERE content_key = ' . $this->getAdapter()->getConnection()->quote($contentKey)) !== false) {
                continue;
            }

            $quoted = $this->getAdapter()->getConnection()->quote($contentKey);
            $claimed = $this->fetchRow('SELECT id FROM pages WHERE slug = ' . $quoted)
                ?: $this->fetchRow('SELECT page_id AS id FROM page_translations WHERE slug = ' . $quoted);
            if ($claimed !== false) {
                $this->output->writeln(sprintf(
                    ' <comment>Page #%d already uses "%s" as its address: the %s system page is NOT created. Pagina\'s names the conflict.</comment>',
                    (int) $claimed['id'],
                    $contentKey,
                    $contentKey
                ));
                continue;
            }

            $sortOrder = (int) ($this->fetchRow('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next FROM pages WHERE parent_id IS NULL')['next'] ?? 1);
            $now = date('Y-m-d H:i:s');

            $this->table('pages')->insert([
                'parent_id' => null,
                'admin_group' => 'website',
                'content_key' => $contentKey,
                'slug' => $contentKey,
                'status' => 'published',
                'show_breadcrumb' => 1,
                'is_system' => 1,
                'route_path' => $page['route_path'],
                'module_default' => 1,
                'sort_order' => $sortOrder,
                'noindex' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();

            $id = (int) $this->fetchRow('SELECT id FROM pages WHERE content_key = ' . $quoted)['id'];

            $this->table('page_translations')->insert([
                'page_id' => $id,
                'language_code' => $defaultLanguage,
                'title' => $page['title'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
