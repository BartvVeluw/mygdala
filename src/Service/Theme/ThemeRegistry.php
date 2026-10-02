<?php

declare(strict_types=1);

namespace App\Service\Theme;

use App\Service\AppEnvironment;

/**
 * The Global Themes this installation knows, and which one the website uses.
 *
 * A CLOSED list in code, the same mentality as App\Service\AdminTheme and
 * App\Module\ModuleRegistry: no directory scan, no reflection, no class
 * discovery, no theme defined by a database row or a request. A theme exists
 * because definitions() below names it.
 *
 * ## legacy
 *
 * `legacy` is the permanent backward-compatible fallback: a real registered
 * definition WITHOUT a stylesheet, so a site on it renders from core.css and
 * its own settings alone — byte for byte what it rendered before Global
 * Themes existed. A missing, empty or unknown stored key always resolves to
 * it, now and later. It is not "the default design" under another name: a
 * first-party default gets a key of its own once it exists.
 *
 * ## What the stored key may do
 *
 * The active key is `theme_settings.active_theme`
 * (ThemeSettings::activeThemeKey()). It is only ever compared with the keys
 * below. It never becomes a path, a class name, an include, a CSS selector
 * or an attribute in the page; a key that is not in the list resolves to
 * legacy and stays stored as it is — a theme that is missing for a while
 * (an extension not deployed yet) comes back by itself, and repairing the
 * row would destroy what the owner chose.
 *
 * ## The first-party themes
 *
 * `legacy` and `minimal` (THEMING.md, "First-party themes"). Registering a
 * theme never activates it: only the stored key does, and only the theme
 * picker on Vormgeving writes that key (api/admin/save-active-theme.php,
 * after find() said yes, through ThemeSettings::saveActiveThemeKey()), so a
 * site without one stays on legacy.
 *
 * ## What it deliberately is not
 *
 * Not a writer: the registry only answers what exists. No CRUD, no
 * repository. A theme never writes palettes, fonts or button styles; see
 * THEMING.md, "Global Theme".
 */
final class ThemeRegistry
{
    /** The key every absent, empty or unknown stored value resolves to. */
    public const FALLBACK_KEY = 'legacy';

    /** @var array<string, ThemeDefinition>|null */
    private static ?array $definitions = null;

    /**
     * Every theme, keyed and in the order they are declared.
     *
     * @return array<string, ThemeDefinition>
     * @throws \LogicException when the list itself is broken (see index())
     */
    public static function all(): array
    {
        return self::$definitions ??= self::index(self::definitions());
    }

    public static function find(string $key): ?ThemeDefinition
    {
        return self::all()[$key] ?? null;
    }

    /**
     * `legacy`, built on its own: needs no database, no file and no other
     * definition, so it is there even when the list or the storage is not.
     */
    public static function fallback(): ThemeDefinition
    {
        return new ThemeDefinition(self::FALLBACK_KEY, 'Klassiek');
    }

    /**
     * The theme the website uses: the stored key when it names a theme in
     * the list, legacy for anything else (absent, empty, unknown, removed).
     *
     * Not caught here, on purpose: the reading of the setting. ThemeSettings
     * owns that and already has the site's rule for an unreachable database
     * (log, render the shipped appearance). Anything else it throws is a
     * real fault and must surface, not pass for "no theme chosen".
     *
     * Caught here: a broken list (a duplicate key, an invalid definition) —
     * a programmer error this class owns. Outside production that throws, so
     * development and the tests see it; in production it is logged and the
     * public site renders on legacy rather than not at all.
     */
    public static function active(): ThemeDefinition
    {
        $key = ThemeSettings::activeThemeKey();

        try {
            $definitions = self::all();
        } catch (\LogicException $e) {
            error_log('[ThemeRegistry] the theme list is invalid, rendering legacy: ' . $e->getMessage());
            if (!AppEnvironment::isProduction()) {
                throw $e;
            }

            return self::fallback();
        }

        return $definitions[$key] ?? self::fallback();
    }

    /**
     * Keys a list of definitions, refusing a duplicate key. all() builds the
     * registry through this; public so the guard can be tested without a
     * second production list.
     *
     * @param list<ThemeDefinition> $definitions
     * @return array<string, ThemeDefinition>
     * @throws \LogicException on a duplicate key or a list without legacy
     */
    public static function index(array $definitions): array
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->key])) {
                throw new \LogicException('Duplicate theme key: ' . $definition->key);
            }
            $indexed[$definition->key] = $definition;
        }

        if (!isset($indexed[self::FALLBACK_KEY])) {
            throw new \LogicException('The theme list has no "' . self::FALLBACK_KEY . '" theme.');
        }

        return $indexed;
    }

    /**
     * THE list. A new theme is a line here and, for a first-party theme, a
     * stylesheet under assets/css/themes/.
     *
     * @return list<ThemeDefinition>
     */
    private static function definitions(): array
    {
        return [
            self::fallback(),
            new ThemeDefinition('minimal', 'Minimal', 'assets/css/themes/minimal.css'),
        ];
    }
}
