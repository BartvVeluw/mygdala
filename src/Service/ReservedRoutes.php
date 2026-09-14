<?php

declare(strict_types=1);

namespace App\Service;

use App\Module\ModuleRegistry;

/**
 * The single source of truth for which bare (extensionless, single-segment)
 * URL words a CMS page may never claim as its slug — because a
 * real application route already owns that word, or would be indistinguishable
 * from a top-level project directory that .htaccess must keep off-limits.
 *
 * Used by App\Service\PageService::validateSlug()/generateSlug() (the
 * actual enforcement point: server-side slug validation, called from
 * api/admin/create-page.php and update-page.php) — see those files.
 *
 * Note that the system pages' own slugs are reserved as root-level PHP files
 * too — index, diensten, over-mij and contact in the list below, shop and
 * portfolio by the module that serves them — so a new CMS page can never
 * claim one of them either.
 *
 * .htaccess's own generic CMS-page RewriteRule additionally guards
 * itself with `-d`/`.php -f` filesystem checks, which independently protect
 * every real root-level PHP file and top-level directory without needing
 * this list kept in sync there too (see .htaccess's docblock). This list
 * exists for two things that check can't do on its own: (1) rejecting a
 * clashing slug at save time with a clear admin-facing error, instead of the
 * admin only discovering the collision later by visiting the URL, and
 * (2) covering the one reserved name (`storage`) that has no matching file
 * or directory actually present at the project root (see
 * src/Service/ContactAttachmentStorage.php — it deliberately lives one
 * directory ABOVE the project root, so there is nothing here for `-d` to
 * catch).
 *
 * Derived by inspecting the project root (2026-09-06, revised 2026-09-08
 * when informatiepagina.php was replaced by pagina.php, and 2026-09-09 when
 * the modules took ownership of their own): every *.php file there (basename,
 * no extension) plus every real top-level directory. Update the list below if
 * a genuinely new top-level CORE route or directory is ever added.
 *
 * A MODULE'S names come from the module (App\Module\ModuleDefinition::reservedSlugs()),
 * and are read from EVERY REGISTERED module — enabled or not. That is
 * deliberate and is the one place the enabled/disabled distinction does not
 * apply: switching the Shop off does not delete shop.php, product.php or
 * cart.php, and a CMS page that claimed one of those slugs would be
 * permanently unreachable behind the file that still shadows it. Reserving a
 * name costs nothing; handing out a slug that can never resolve costs the
 * owner a page.
 */
class ReservedRoutes
{
    private const CORE_RESERVED = [
        // Root-level frontend/application PHP files (basename, no extension).
        'index',
        'diensten',
        'contact',
        'over-mij',
        'cookiebeleid',
        'herroeping',
        'pagina',
        'phinx',
        // Apache's ErrorDocument target (404.php). Not a page anyone browses
        // to, but a real root-level file, so a CMS page named "404" would sit
        // permanently behind it.
        '404',
        // Top-level project directories (never a public page slug).
        'admin',
        'api',
        'assets',
        'db',
        'docker',
        'docs',
        'partials',
        'scripts',
        'src',
        'tests',
        'vendor',
        // Not a real directory at the project root (see docblock above), but
        // still a name that must never resolve to an information page.
        'storage',
    ];

    /** @var list<string>|null Core's names plus every module's, built once */
    private static ?array $reserved = null;

    /** Forgets the merged list; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$reserved = null;
    }

    /** @return list<string> */
    public static function all(): array
    {
        if (self::$reserved !== null) {
            return self::$reserved;
        }

        $reserved = self::CORE_RESERVED;

        foreach (ModuleRegistry::all() as $module) {
            foreach ($module->reservedSlugs() as $slug) {
                $reserved[] = $slug;
            }
        }

        return self::$reserved = array_values(array_unique($reserved));
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::all(), true);
    }
}
