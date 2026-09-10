<?php

declare(strict_types=1);

namespace App\Module;

use App\Repository\ModuleSettingRepository;

/**
 * The STORED answer to "does this installation want module X", for the
 * installations that cannot put it in .env.
 *
 * This is the smallest persistent layer the Setup Wizard needs, and it is
 * deliberately not a second module-state system:
 *
 *   ModuleConfig    the one precedence chain — environment, then this, then
 *                   the "enabled" default. Every caller goes through it.
 *   ModuleSettings  storage and validation for the middle step only.
 *   ModuleRegistry  what is actually ACTIVE, after dependencies.
 *
 * Nothing here knows what a shop is, resolves a dependency, or decides
 * whether a module runs. It stores one of two strings per registered module
 * key and reads them back once per request.
 *
 * A KEY THAT IS NOT REGISTERED IS DROPPED, on the way in and on the way out.
 * The keys come from a request in exactly one place (the wizard), and the
 * only thing such a key may ever do is hit or miss ModuleRegistry's closed
 * map — the same rule the registry itself follows.
 *
 * Values are '1' and '0' rather than the .env vocabulary ("false", "off",
 * "no", ...): this side is written by the application, never typed by a
 * person, so one spelling is enough and a stored value that is neither is
 * treated as absent.
 */
final class ModuleSettings
{
    /** Prefix for a module's stored preference, e.g. `module_shop_enabled`. */
    private const KEY_PREFIX = 'module_';
    private const KEY_SUFFIX = '_enabled';

    /** @var array<string, bool>|null resolved once per request */
    private static ?array $cache = null;

    /** @var array<string, bool>|null test seam; see overrideForTests() */
    private static ?array $override = null;

    /**
     * The stored preference for one module, or null when there is none —
     * which is what makes "no row" mean "let the default decide" rather than
     * "off".
     */
    public static function stored(string $moduleKey): ?bool
    {
        return self::all()[$moduleKey] ?? null;
    }

    /**
     * Every stored preference, keyed by module. Only registered keys appear.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = [];

        try {
            $stored = (new ModuleSettingRepository())->findAll();
        } catch (\Throwable $e) {
            // No table yet, or no database at all. Both mean "nothing was
            // ever chosen here", which leaves ModuleConfig on its default —
            // the same failure direction every other settings layer takes.
            error_log('[ModuleSettings] falling back to no stored preference: ' . $e->getMessage());
        }

        $preferences = [];

        foreach (ModuleRegistry::keys() as $moduleKey) {
            $value = $stored[self::settingKey($moduleKey)] ?? null;

            if ($value === '1') {
                $preferences[$moduleKey] = true;
            } elseif ($value === '0') {
                $preferences[$moduleKey] = false;
            }
        }

        return self::$cache = $preferences;
    }

    /**
     * Stores a preference per module. Unregistered keys are dropped rather
     * than rejected, so a stale checkbox from an older wizard cannot fail a
     * whole save — it simply configures nothing.
     *
     * @param array<string, bool> $preferences module key => wanted
     */
    public static function save(array $preferences): void
    {
        $values = [];

        foreach ($preferences as $moduleKey => $wanted) {
            if (!is_string($moduleKey) || !ModuleRegistry::has($moduleKey)) {
                continue;
            }

            $values[self::settingKey($moduleKey)] = $wanted ? '1' : '0';
        }

        if ($values === []) {
            return;
        }

        (new ModuleSettingRepository())->upsertMany($values);

        self::clearCache();
        ModuleRegistry::reset();
    }

    /**
     * The storage key for a module, e.g. 'shop' => 'module_shop_enabled'.
     * Deliberately the lower-case twin of ModuleConfig::variableName(), so
     * the two halves of the same question are recognisably the same
     * question.
     */
    public static function settingKey(string $moduleKey): string
    {
        return self::KEY_PREFIX . strtolower(str_replace('-', '_', $moduleKey)) . self::KEY_SUFFIX;
    }

    /** Clears the per-request cache; used by save() and by tests. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Test seam: pretend these are the stored preferences, without a
     * database. Pass null to go back to reading storage. Always reset it in
     * tearDown() — the cache is static and outlives one test.
     *
     * @param array<string, bool>|null $preferences
     */
    public static function overrideForTests(?array $preferences): void
    {
        if ($preferences === null) {
            self::$override = null;
            self::$cache = null;
            ModuleRegistry::reset();

            return;
        }

        $clean = [];
        foreach ($preferences as $moduleKey => $wanted) {
            if (is_string($moduleKey) && ModuleRegistry::has($moduleKey)) {
                $clean[$moduleKey] = (bool) $wanted;
            }
        }

        self::$override = $clean;
        self::$cache = null;
        ModuleRegistry::reset();
    }
}
