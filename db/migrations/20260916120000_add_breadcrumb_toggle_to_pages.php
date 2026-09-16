<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a CMS page shows its breadcrumb — one column on the page itself.
 *
 * WHY ON `pages` AND NOT ON `page_heroes`. The breadcrumb used to be printed
 * by the Paginakop block, so a page without that block, or with it hidden, had
 * no trail either. It is the page's own navigation, not the header's
 * decoration, so the choice belongs where the page's other whole-page
 * settings already live (`status`, `noindex`): one explicit column, the same
 * shape 20260909230000 gave `noindex`. There is no per-page key/value store in
 * this project and this migration deliberately does not invent one — a
 * settings bag would hide a real, queryable choice behind a string.
 *
 * NOT NULL DEFAULT 1, so MySQL writes "on" into every existing row as part of
 * ADD COLUMN: every page that shows a breadcrumb today keeps showing one, and
 * a NULL can never become an accidental "maybe". Nothing else about any page
 * changes.
 *
 * ONLY PAGES HAVE IT. The application's own fixed routes — the cart, the
 * checkout, a product, a collection, the 404 — have no `pages` row at all, and
 * their trail is part of the route rather than something an administrator
 * switches off. HEADER-FOOTER.md records that contract.
 *
 * Nothing is removed here. `page_heroes.breadcrumb_label_nl` and
 * `breadcrumb_label_en` stop being read and stop being overwritten, but they
 * keep every value they hold; a destructive cleanup is a later, separate
 * decision.
 *
 * Schema only, so there is no fresh-install guard: a new installation and an
 * upgraded one end on the same table (db/migrations/CLAUDE.md). The check
 * before the ALTER makes a second run, or one that stopped halfway, end with
 * exactly one column.
 */
final class AddBreadcrumbToggleToPages extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pages') || $this->table('pages')->hasColumn('show_breadcrumb')) {
            return;
        }

        $this->table('pages')
            ->addColumn('show_breadcrumb', 'boolean', [
                'null' => false,
                'default' => 1,
                'after' => 'status',
                'comment' => 'Whether this page prints its breadcrumb; see App\Service\Breadcrumbs\PageBreadcrumb',
            ])
            ->update();
    }

    public function down(): void
    {
        if ($this->hasTable('pages') && $this->table('pages')->hasColumn('show_breadcrumb')) {
            $this->table('pages')->removeColumn('show_breadcrumb')->update();
        }
    }
}
