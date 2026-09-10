<?php

declare(strict_types=1);

namespace App\Module;

/**
 * The one guard a file belonging to a module puts at the top of itself.
 *
 * Hiding a menu entry is not a guard. Every module's PHP files stay on disk
 * when the module is switched off, so /shop.php, /api/checkout.php and
 * /admin/products.php are all still physically reachable — and without this
 * they would go on working. One helper rather than an ad-hoc `if` per file,
 * so the three answers stay consistent and are testable in one place.
 *
 * The answers deliberately match what this project already does when
 * something is not available:
 *
 *   public page   404 with the site's own "Pagina niet gevonden" document —
 *                 byte-for-byte what an unknown slug or an unpublished page
 *                 produces (see pagina.php). A visitor cannot tell a disabled
 *                 module from a page that was never there, which is exactly
 *                 the property this project already wanted for drafts.
 *   admin page    the CMS's own 403 "Geen toegang" page, via AdminAuth —
 *                 the convention every admin screen already follows.
 *   API/write     the same plain-text 403 AdminAuth::requirePermissionForApi()
 *                 and Csrf::validate() return, so a rejected request looks the
 *                 same whichever guard rejected it. Nothing is written.
 *
 * ADMIN PAGES AND WRITE ENDPOINTS MOSTLY NEED NO CALL AT ALL. A module owns
 * its permissions (App\Service\AdminPermissions), and a disabled module's
 * permissions are held by nobody — not even a Super Admin. Every
 * requirePermission('products.manage') in the 174 admin endpoints therefore
 * already refuses while the Shop is off, without a second check being added
 * to each file. requireAdmin() below exists for the few admin screens that
 * are guarded by something else.
 *
 * This never weakens a guard: it runs BEFORE login, CSRF and permission
 * checks and only ever refuses. A request that gets past it still faces every
 * check that was already there.
 */
final class ModuleGuard
{
    /**
     * A public route that belongs to $moduleKey. Renders the project's 404
     * document and stops the request when the module is off.
     */
    public static function requirePublicRoute(string $moduleKey): void
    {
        if (ModuleRegistry::isEnabled($moduleKey)) {
            return;
        }

        http_response_code(404);
        require dirname(__DIR__, 2) . '/partials/route-not-found-page.php';
        exit;
    }

    /**
     * An admin screen that belongs to $moduleKey. Uses the CMS's own "no
     * access" page, so a signed-in user sees the sidebar they do have and a
     * way back, rather than a bare error.
     */
    public static function requireAdmin(string $moduleKey): void
    {
        if (ModuleRegistry::isEnabled($moduleKey)) {
            return;
        }

        \App\Service\AdminAuth::requireLogin();

        http_response_code(403);
        require dirname(__DIR__, 2) . '/admin/_forbidden.php';
        exit;
    }

    /**
     * An endpoint that belongs to $moduleKey — public API or admin write.
     * Refuses in plain text before anything is read, validated or stored.
     */
    public static function requireApi(string $moduleKey): void
    {
        if (ModuleRegistry::isEnabled($moduleKey)) {
            return;
        }

        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found: this part of the site is not available.');
    }
}
