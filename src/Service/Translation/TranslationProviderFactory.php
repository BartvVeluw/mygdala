<?php

declare(strict_types=1);

namespace App\Service\Translation;

use Dotenv\Dotenv;

/**
 * Which translation provider this deployment runs.
 *
 * A closed map, for the same reason as App\Module\ModuleRegistry and
 * App\Service\Language\LanguageRegistry: TRANSLATION_PROVIDER is a string
 * from a configuration file, and the only thing it may do is hit a key of
 * this list or miss it. Missing is missing — it never becomes a class name.
 *
 * A second provider later is one class plus one line here. No editor, no
 * endpoint and no template changes, which is the entire promise of the
 * TranslationProvider interface.
 *
 * THE DEFAULT IS "NO PROVIDER". A deployment that has said nothing gets
 * NullTranslationProvider: automatic translation is an optional extra, not a
 * dependency, and a fresh Mygdala installation must boot without credentials
 * for a paid service. Naming a provider without giving it a key lands in the
 * same place, because a provider that cannot authenticate reports
 * isConfigured() === false and callers treat that identically.
 */
final class TranslationProviderFactory
{
    /** @var array<string, class-string<TranslationProvider>> */
    private const MAP = [
        DeepLProvider::KEY => DeepLProvider::class,
    ];

    private static ?TranslationProvider $instance = null;
    private static bool $envLoaded = false;

    /**
     * The provider for this request, built once.
     *
     * Never throws and never performs a network call: the CMS constructs this
     * on screens that may never translate anything.
     */
    public static function provider(): TranslationProvider
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $requested = strtolower(trim(self::env('TRANSLATION_PROVIDER')));

        $class = self::MAP[$requested] ?? null;
        if ($class === null) {
            if ($requested !== '' && $requested !== 'none') {
                error_log('[TranslationProviderFactory] Unknown TRANSLATION_PROVIDER "' . $requested . '"; automatic translation is off.');
            }

            return self::$instance = new NullTranslationProvider();
        }

        $provider = new $class();

        // A named provider without credentials is not an error worth stopping
        // for — it is the normal state between "somebody wrote the line" and
        // "somebody pasted the key". The CMS simply offers no translate
        // buttons until it is complete.
        return self::$instance = $provider;
    }

    /**
     * Is automatic translation actually available right now? The one question
     * every screen and endpoint asks before offering a translate button.
     */
    public static function isAvailable(): bool
    {
        return self::provider()->isConfigured();
    }

    /** @return string[] the provider keys this build knows about */
    public static function registeredKeys(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Test seam: run with this provider instead of whatever the environment
     * names. Pass null to go back to reading configuration. Always reset it
     * in tearDown() — the instance is static and outlives one test.
     */
    public static function overrideForTests(?TranslationProvider $provider): void
    {
        self::$instance = $provider;
    }

    private static function env(string $name): string
    {
        self::loadEnv();

        if (isset($_ENV[$name])) {
            return (string) $_ENV[$name];
        }

        $value = getenv($name);

        return $value === false ? '' : (string) $value;
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }

        self::$envLoaded = true;

        $root = dirname(__DIR__, 3);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }
    }
}
