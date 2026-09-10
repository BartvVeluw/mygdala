<?php

declare(strict_types=1);

namespace App\Module;

use Dotenv\Dotenv;

/**
 * Which first-party modules the deployment WANTS enabled, read from the
 * environment — one variable per module, MODULE_<KEY>_ENABLED:
 *
 *   MODULE_SHOP_ENABLED=false
 *   MODULE_PERSONALIZATION_ENABLED=false
 *
 * Why the environment first: this is deployment configuration, exactly like
 * DB_* and APP_URL. It is one line in the .env the hosting account already
 * has, it needs no database, no migration and no asset rebuild, it cannot be
 * changed by anyone who is merely signed into the CMS, and a test or a
 * container can set it without touching a file the development site shares.
 * Same mechanism, same file and same Dotenv loader as App\Database and
 * App\Service\AppUrl — see .env.example.
 *
 * THE PRECEDENCE CHAIN, and it lives only here:
 *
 *   1. MODULE_<KEY>_ENABLED in the environment, when it is set and not empty
 *   2. the preference stored in the CMS (App\Module\ModuleSettings)
 *   3. the module's own default (ModuleDefinition::enabledByDefault())
 *
 * Step 2 is what the Setup Wizard writes, and it exists because somebody
 * setting up a brand-new site through the CMS cannot reach the server's
 * environment file — asking them to is exactly the manual .env editing that
 * step removes. It is deliberately BEHIND the variable, so nothing about an
 * existing deployment changes: a hosting account that pins its modules in
 * .env still cannot have them changed from the CMS, and the php_cms test
 * container's MODULE_SHOP_ENABLED=false still decides. See MODULES.md.
 *
 * DEFAULT: THE MODULE'S OWN, and for every module that predates that hook it
 * is ENABLED. No variable, no stored preference, a missing .env or an
 * unreadable one all mean "on" for those, so the Van Veluw Laserdesign
 * deployment keeps every module without configuring anything, and a
 * configuration mistake can never silently take the shop off the air. Only an
 * explicit off value — whichever of the two sources it comes from — disables
 * one. A module that is optional in the "most sites do not run this" sense
 * says so itself and starts off; the Blog does (BLOG.md).
 *
 * WANTED is not the same as ACTIVE: a module whose dependency is disabled is
 * itself disabled however this is configured. ModuleRegistry::enabled() makes
 * that decision; this class only reports what was asked for.
 */
final class ModuleConfig
{
    /** Values that mean "off". Everything else, including nonsense, means on. */
    private const DISABLED_VALUES = ['0', 'false', 'off', 'no', 'disabled'];

    /** Whether the deployment asked for this module. */
    public static function wants(string $moduleKey): bool
    {
        $configured = self::environmentValue($moduleKey);

        if ($configured !== null) {
            return $configured;
        }

        return ModuleSettings::stored($moduleKey) ?? self::defaultFor($moduleKey);
    }

    /**
     * Step 3: what a module gets when nobody has said anything about it.
     *
     * The module itself answers (ModuleDefinition::enabledByDefault()), which
     * is why this class still knows nothing about what any module does. An
     * unregistered key is enabled, exactly as before — it can only be read
     * back through ModuleRegistry, which drops it anyway.
     */
    private static function defaultFor(string $moduleKey): bool
    {
        return ModuleRegistry::definition($moduleKey)?->enabledByDefault() ?? true;
    }

    /**
     * Whether the ENVIRONMENT decides this module, and what it decided —
     * null when it is silent and the stored preference gets its turn. The
     * wizard asks this to know whether it may offer the choice at all.
     */
    public static function environmentValue(string $moduleKey): ?bool
    {
        self::loadEnv();

        $raw = $_ENV[self::variableName($moduleKey)] ?? null;

        if (!is_string($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));

        if ($value === '') {
            return null;
        }

        return !in_array($value, self::DISABLED_VALUES, true);
    }

    /** Whether the environment pins this module, leaving the CMS no say. */
    public static function isPinnedByEnvironment(string $moduleKey): bool
    {
        return self::environmentValue($moduleKey) !== null;
    }

    /** The environment variable that switches this module, e.g. MODULE_SHOP_ENABLED. */
    public static function variableName(string $moduleKey): string
    {
        return 'MODULE_' . strtoupper(str_replace('-', '_', $moduleKey)) . '_ENABLED';
    }

    private static function loadEnv(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $loaded = true;

        $root = dirname(__DIR__, 2);
        if (!file_exists($root . '/.env')) {
            return;
        }

        try {
            // createImmutable() never overwrites a variable that is already
            // set, so a container's own MODULE_* environment wins over .env —
            // which is how docker-compose.yml's CMS-only service works.
            Dotenv::createImmutable($root)->load();
        } catch (\Throwable $e) {
            // An unreadable .env must not decide which modules run: leave
            // $_ENV as it is, so every module keeps its "enabled" default.
            error_log('[ModuleConfig] could not read .env: ' . $e->getMessage());
        }
    }
}
