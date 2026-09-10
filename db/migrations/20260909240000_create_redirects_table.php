<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The Redirect Manager's one table: an exact, forward-only map from a public
 * path this site no longer serves to the URL that replaced it. See
 * REDIRECTS.md.
 *
 * EXACT PATHS ONLY. There is no pattern column, no regex, no wildcard and no
 * hostname: a row matches one normalized request path or it matches nothing.
 * That is what makes the runtime lookup a single indexed equality query, and
 * what makes an administrator able to read a row and know exactly which URL
 * it affects.
 *
 * source_path is stored in ONE normalized form (App\Service\Redirects\RedirectPath):
 * leading slash, no duplicate slashes, no trailing slash, percent-decoded, no
 * query string. /foo, /foo/ and /foo// are therefore the same row, exactly as
 * .htaccess's `^([a-z0-9-]+)/?$` already treats them as the same route — three
 * separate rows for one URL is the failure mode this normalisation exists to
 * prevent.
 *
 * COLLATION. source_path is utf8mb4_bin rather than this project's usual
 * utf8mb4_unicode_ci, and that is deliberate: unicode_ci is case-INSENSITIVE,
 * so both the unique index and the runtime lookup would treat /Oude-Pagina and
 * /oude-pagina as the same path. Apache does not — /Oude-Pagina 404s on this
 * site while /oude-pagina resolves — and a redirect table that is more
 * forgiving than the router it protects would fire on URLs that were never
 * this site's. A binary collation makes storage agree with routing.
 *
 * target_type is a plain validated string, not a MySQL ENUM, matching
 * nav_items.link_type and page_sections.section_type. Two values, both
 * checked in PHP at every write (App\Service\Redirects\RedirectTarget):
 *   - 'internal' -> target_value is a site-relative path, optionally with its
 *                   own query string ('/services-new', '/shop.php'). Made
 *                   absolute against APP_URL at redirect time
 *                   (App\Service\AppUrl), never against the request's Host
 *                   header.
 *   - 'external' -> target_value is an absolute http(s) URL. Only an
 *                   authenticated editor can ever create one; nothing reads a
 *                   destination from public request input.
 *
 * There is deliberately no 'page' target type pointing at a pages.id. A CMS
 * page rename already maintains its own redirects (origin 'slug_change', see
 * App\Service\Redirects\SlugChangeRedirects), so the extra indirection would
 * buy nothing but a second link-resolution path to keep in step with
 * App\Service\LinkResolver.
 *
 * status_code is 301 or 302 and nothing else, validated in PHP the same way.
 * 307/308 are not offered: this site has no POST endpoint behind a legacy
 * path, so method-preserving redirects would be a code path with no caller.
 *
 * origin records WHY a row exists — 'manual' (an editor typed it) or
 * 'slug_change' (a published CMS page was renamed). It is shown in the admin
 * so an automatic redirect is never unexplained infrastructure, and it is the
 * one thing that decides whether a rename may update an existing row: a
 * manual row is an editor's decision and is never silently overwritten.
 *
 * NO SEED DATA. A fresh install starts with zero redirects, and this migration
 * adds none for this particular site either — inventing a legacy URL without
 * evidence that it was ever public is how a redirect table starts lying.
 */
final class CreateRedirectsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('redirects', ['id' => true])
            ->addColumn('source_path', 'string', [
                'limit' => 255,
                'collation' => 'utf8mb4_bin',
                'comment' => 'Normalized, site-relative, no query string',
            ])
            ->addColumn('target_type', 'string', ['limit' => 20, 'default' => 'internal'])
            ->addColumn('target_value', 'string', ['limit' => 2048])
            ->addColumn('status_code', 'integer', ['limit' => 11, 'default' => 301])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('origin', 'string', ['limit' => 20, 'default' => 'manual'])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            // The runtime lookup is one equality on this column, and one URL
            // may only ever have one destination.
            ->addIndex(['source_path'], ['unique' => true])
            // Chain collapsing after a rename updates every row pointing at
            // the old path (see SlugChangeRedirects); a prefix index keeps
            // that from scanning the table.
            ->addIndex(['target_value'], ['limit' => 191])
            ->create();
    }

    public function down(): void
    {
        $this->table('redirects')->drop()->save();
    }
}
