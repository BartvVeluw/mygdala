<?php

declare(strict_types=1);

namespace App\Service;

use Dotenv\Dotenv;

/**
 * Which environment this deployment is: production, or something that is
 * deliberately not production (a staging copy, a local Docker stack, the
 * test containers).
 *
 * Read from ONE explicit variable, `APP_ENV` in .env, and from nothing else.
 * The hostname is deliberately not consulted: a copy of this site can be
 * served from any domain, a production site can be reached over several, and
 * guessing from the Host header would mean a request could talk the
 * application into believing it is a test server.
 *
 * PRODUCTION IS THE DEFAULT, and that is the safety property that matters.
 * A missing .env, an empty value, an unreadable file or a typo all mean
 * production, so the one thing this class can never do is take a live site
 * out of the search index because a configuration value went missing. Only
 * an explicitly recognised non-production value switches it, and the list of
 * those is closed (see NON_PRODUCTION).
 *
 * Same env-loading pattern as App\Service\AppUrl / App\Database: load once,
 * immutably, so a value already present in the process environment (which is
 * how the Docker containers and the test bootstrap set things) wins over the
 * file.
 */
class AppEnvironment
{
    /**
     * The values that mean "not the live site". Closed on purpose: anything
     * else — including a typo — is production.
     *
     * @var list<string>
     */
    private const NON_PRODUCTION = ['local', 'development', 'dev', 'staging', 'test', 'testing'];

    private static bool $envLoaded = false;

    /** The configured environment name, lowercased. '' when nothing is set. */
    public static function name(): string
    {
        self::loadEnv();

        return strtolower(trim((string) ($_ENV['APP_ENV'] ?? '')));
    }

    /**
     * Is this the live site? True unless APP_ENV explicitly says otherwise.
     */
    public static function isProduction(): bool
    {
        return !in_array(self::name(), self::NON_PRODUCTION, true);
    }

    /**
     * Test seam: pretend APP_ENV holds this value. Pass null to go back to
     * reading the environment. Always reset it in tearDown().
     */
    public static function overrideForTests(?string $name): void
    {
        self::$envLoaded = true;

        if ($name === null) {
            unset($_ENV['APP_ENV']);

            return;
        }

        $_ENV['APP_ENV'] = $name;
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
