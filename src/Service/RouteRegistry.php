<?php

namespace App\Service;

use App\Module\ModuleRegistry;

/**
 * The small, fixed list of real APPLICATION routes a nav item or footer link
 * may point at with link_type='route' — an admin picks a key from this list,
 * never types a raw path (see App\Service\LinkResolver). This is this
 * project's public-page equivalent of App\Service\ReservedRoutes (which
 * exists to keep CMS page slugs from colliding with these same routes) —
 * kept as a separate, admin-facing list rather than reusing ReservedRoutes
 * directly because that list also includes internal-only names (admin, api,
 * assets, storage, ...) that must never be offered as a navigation
 * destination.
 *
 * A CMS CONTENT PAGE does not belong here, even when it happens to be served
 * from its own PHP file. Diensten, Portfolio, Over mij and Contact used to
 * be listed, which quietly made them un-deletable in practice: a route link
 * is a hardcoded path, so it keeps pointing at a URL after the page behind
 * it is unpublished or deleted, and PageService::references() cannot see it.
 * They are linked as pages now (link_type='page', see
 * db/migrations/20260908260000_link_content_pages_as_pages_not_routes.php),
 * which makes the menu follow the page automatically and blocks deleting a
 * page that is still linked.
 *
 * What Core guarantees is the site root and the two legal pages that have no
 * `pages` row at all. Everything else here is contributed by an ENABLED
 * module (App\Module\ModuleRegistry): the storefront, the cart and the
 * checkout come from the Shop, so a CMS-only deployment simply cannot offer
 * them as a menu destination — and an existing nav item pointing at one stops
 * resolving (LinkResolver drops a link whose route key no longer exists)
 * rather than sending visitors to a 404.
 *
 * Add a new entry here only for a genuinely new CORE application route —
 * never for a page an administrator could have created, and never for a
 * module's route.
 */
class RouteRegistry
{
    /**
     * `order` decides where an entry appears in the admin's route picker, so
     * a module's routes can sit between Core's without either side knowing
     * about the other. It is stripped from what all() returns.
     */
    private const CORE_ROUTES = [
        'home' => ['url' => '/index.php', 'label_nl' => 'Home', 'label_en' => 'Home', 'order' => 10],
        'cookiebeleid' => ['url' => '/cookiebeleid.php', 'label_nl' => 'Cookiebeleid', 'label_en' => 'Cookie policy', 'order' => 90],
        'herroeping' => ['url' => '/herroeping.php', 'label_nl' => 'Herroepingsrecht', 'label_en' => 'Right of withdrawal', 'order' => 91],
    ];

    /** @var array<string, array{url: string, label_nl: string, label_en: string}>|null */
    private static ?array $routes = null;

    /** Forgets the merged list; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$routes = null;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function url(string $key): ?string
    {
        return self::all()[$key]['url'] ?? null;
    }

    /**
     * @return array<string, array{url: string, label_nl: string, label_en: string}>
     */
    public static function all(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }

        // "+" rather than array_merge(): Core's keys win, so a module can
        // never shadow the site root or a legal page with a route of its own.
        $routes = self::CORE_ROUTES + ModuleRegistry::collectMap('routes');

        uasort($routes, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        foreach ($routes as $key => $route) {
            unset($routes[$key]['order']);
        }

        return self::$routes = $routes;
    }
}
