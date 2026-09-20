<?php

declare(strict_types=1);

namespace App\Module;

/**
 * THE list of first-party modules this application has, and the one place
 * that answers "is this module active right now".
 *
 * Registration is EXPLICIT and closed, for exactly the reasons
 * App\Service\Blocks\BlockDefinitions is: no directory scanning, no reflection
 * over class names, no Composer plugins, no class name from a database row or
 * a request. A module key can only ever hit or miss a key of MAP below, and a
 * miss is a miss — it never becomes a class name.
 *
 * This is a MODULAR MONOLITH, not a plug-in system. One repository, one
 * deployable application, a handful of first-party modules whose code is
 * always present. Disabling one means it contributes nothing at runtime; it
 * does not uninstall anything, and it never touches the database (see
 * MODULES.md).
 *
 * WANTED vs ACTIVE. App\Module\ModuleConfig says what the deployment asked
 * for (MODULE_<KEY>_ENABLED in .env, then the stored preference, then the
 * module's own ModuleDefinition::enabledByDefault() — on for every module
 * except the Blog and the Portfolio). enabled() below turns that
 * into what actually runs by also applying dependencies(): Personalisatie
 * depends on the Shop, so a configuration that asks for Personalisatie while
 * the Shop is off gets Personalisatie off as well, with one line in the error
 * log. Failing safely beats refusing to boot on a site whose CMS pages have
 * nothing to do with either module.
 */
final class ModuleRegistry
{
    /** @var array<string, class-string<ModuleDefinition>> */
    private const MAP = [
        'shop' => ShopModule::class,
        'personalization' => PersonalizationModule::class,
        'blog' => BlogModule::class,
        'portfolio' => PortfolioModule::class,
    ];

    /** @var array<string, ModuleDefinition> */
    private static array $instances = [];

    /** @var array<string, ModuleDefinition>|null resolved once per request */
    private static ?array $enabled = null;

    /** @var array<string, bool>|null test seam; see overrideForTests() */
    private static ?array $override = null;

    /** @return list<string> every registered key, in registration order */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::MAP);
    }

    /**
     * Every registered module, enabled or not, in registration order. Used by
     * the few things that must know a module EXISTS even while it is off —
     * App\Service\ReservedRoutes (its PHP files are still on disk) and
     * App\Service\AdminPermissions (its permission names are still stored on
     * user accounts).
     *
     * @return array<string, ModuleDefinition>
     */
    public static function all(): array
    {
        $modules = [];
        foreach (array_keys(self::MAP) as $key) {
            $modules[$key] = self::definition($key);
        }

        return $modules;
    }

    /**
     * The modules that actually contribute behaviour this request, in
     * registration order.
     *
     * @return array<string, ModuleDefinition>
     */
    public static function enabled(): array
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        $wanted = [];
        foreach (array_keys(self::MAP) as $key) {
            $wanted[$key] = self::$override !== null
                ? (self::$override[$key] ?? false)
                : ModuleConfig::wants($key);
        }

        // Resolve dependencies to a fixed point: a module drops out when any
        // module it needs has dropped out, which may in turn drop another.
        $changed = true;
        while ($changed) {
            $changed = false;

            foreach (array_keys(self::MAP) as $key) {
                if (!$wanted[$key]) {
                    continue;
                }

                foreach (self::definition($key)->dependencies() as $dependency) {
                    if (self::has($dependency) && $wanted[$dependency]) {
                        continue;
                    }

                    $wanted[$key] = false;
                    $changed = true;

                    if (self::$override === null) {
                        error_log(sprintf(
                            '[ModuleRegistry] module "%s" is disabled because it depends on "%s", which is not'
                                . ' enabled (see %s)',
                            $key,
                            $dependency,
                            ModuleConfig::variableName($dependency)
                        ));
                    }

                    break;
                }
            }
        }

        $enabled = [];
        foreach (array_keys(self::MAP) as $key) {
            if ($wanted[$key]) {
                $enabled[$key] = self::definition($key);
            }
        }

        return self::$enabled = $enabled;
    }

    public static function isEnabled(string $key): bool
    {
        return array_key_exists($key, self::enabled());
    }

    /**
     * One module's definition, whether or not it is enabled. Definitions are
     * stateless, so one instance per key per request is enough.
     */
    public static function definition(string $key): ?ModuleDefinition
    {
        if (!array_key_exists($key, self::MAP)) {
            return null;
        }

        if (!isset(self::$instances[$key])) {
            $class = self::MAP[$key];
            self::$instances[$key] = new $class();
        }

        return self::$instances[$key];
    }

    /**
     * Everything the enabled modules contribute through one list-shaped hook,
     * merged in registration order. The hook name is a method on
     * ModuleDefinition; a caller in Core names it literally, never from a
     * request.
     *
     * @return list<mixed>
     */
    public static function collect(string $hook): array
    {
        $collected = [];

        foreach (self::enabled() as $module) {
            foreach ($module->{$hook}() as $contribution) {
                $collected[] = $contribution;
            }
        }

        return $collected;
    }

    /**
     * As collect(), for a hook returning a keyed map (routes, block
     * definitions, gallery sources). A later module cannot overwrite an
     * earlier one's key: the first registration wins and the clash is logged,
     * so a duplicate key is visible instead of silently shadowing something.
     *
     * @return array<string, mixed>
     */
    public static function collectMap(string $hook): array
    {
        $collected = [];

        foreach (self::enabled() as $key => $module) {
            foreach ($module->{$hook}() as $name => $contribution) {
                if (array_key_exists($name, $collected)) {
                    error_log(sprintf(
                        '[ModuleRegistry] module "%s" tried to contribute "%s" to %s(), which is already taken',
                        $key,
                        (string) $name,
                        $hook
                    ));

                    continue;
                }

                $collected[$name] = $contribution;
            }
        }

        return $collected;
    }

    /**
     * Which module owns a contribution key, whether or not it is enabled —
     * how Core tells "this block belongs to a module that is switched off"
     * apart from "this block type does not exist at all".
     *
     * @param string $hook a keyed hook, e.g. 'blockDefinitions'
     */
    public static function ownerOf(string $hook, string $name): ?string
    {
        foreach (self::all() as $key => $module) {
            if (array_key_exists($name, $module->{$hook}())) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The module owning a fixed public path (a `pages` row's `route_path`,
     * e.g. "/shop.php"), when that module is currently SWITCHED OFF — and
     * null otherwise, including for a Core path and an unclaimed one.
     *
     * This is what lets Core answer "does this page's URL still resolve"
     * without knowing what a shop is. A CMS page served from a module's own
     * template stays ordinary CMS data — it is not deleted, not unpublished
     * and still editable — but its URL 404s while the module is off
     * (App\Module\ModuleGuard), so nothing may advertise it.
     */
    public static function disabledModuleForRoutePath(string $path): ?string
    {
        $path = '/' . ltrim(trim($path), '/');

        if ($path === '/') {
            return null;
        }

        foreach (self::all() as $key => $module) {
            if (self::isEnabled($key)) {
                continue;
            }

            foreach ($module->routes() as $route) {
                if ((string) $route['url'] === $path) {
                    return $key;
                }
            }

            // A CMS page served from the module's own template that is no menu
            // route (/portfolio.php): ModuleDefinition::publicPaths().
            if (in_array($path, $module->publicPaths(), true)) {
                return $key;
            }
        }

        return null;
    }

    /** The admin-facing name of a module, for a message about it. */
    public static function label(string $key): string
    {
        return self::definition($key)?->label() ?? $key;
    }

    /**
     * Test seam: pin the enabled set instead of reading the environment.
     * Pass null to go back to the configured behaviour. Tests must reset it
     * in tearDown() — everything that caches a module contribution
     * (App\Service\AdminNavigation, PageAssets, ...) is reset with it.
     *
     * @param array<string, bool>|null $enabled module key => enabled
     */
    public static function overrideForTests(?array $enabled): void
    {
        self::$override = $enabled;
        self::reset();
    }

    /** Forgets the resolved enabled set and every cache derived from it. */
    public static function reset(): void
    {
        self::$enabled = null;

        // The stored half of the configuration is read per request too, so a
        // reset that left it cached would answer the next question with the
        // preference that was just overwritten.
        ModuleSettings::clearCache();

        \App\Service\AdminNavigation::reset();
        \App\Service\AdminPermissions::reset();
        \App\Service\Blocks\BlockDefinitions::reset();
        \App\Service\ItemGallerySources::reset();
        \App\Service\Media\MediaUsageRegistry::reset();
        \App\Service\PageAssets::reset();
        \App\Service\ReservedRoutes::reset();
        \App\Service\RouteRegistry::reset();
        \App\Service\Routing\RouteSegments::reset();
        \App\Service\Routing\RouteTable::reset();
        \App\Service\SectionRegistry::reset();
    }
}
