<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The two things every tier of this suite needs to agree on: which database
 * it is allowed to touch, and which web server the HTTP tier may talk to.
 *
 * Both deliberately resolve away from development. The database name is
 * forced onto the connection by tests/bootstrap.php before anything can open
 * one; the base URL points at the php_test container, which is wired to that
 * same test database. See TESTING.md.
 */
final class TestEnvironment
{
    /**
     * The database the suite runs against — TEST_DB_DATABASE, or the
     * development database name with "_test" appended.
     */
    public static function databaseName(): string
    {
        $explicit = self::value('TEST_DB_DATABASE');
        if ($explicit !== '') {
            return $explicit;
        }

        $development = self::developmentDatabaseName();

        return $development === '' ? '' : $development . '_test';
    }

    /**
     * The database the SITE uses — never the one the suite may write to.
     * Only tests/bootstrap.php's guard has a use for this.
     */
    public static function developmentDatabaseName(): string
    {
        return self::value('DB_DATABASE');
    }

    /**
     * Where the HTTP tier sends its requests.
     *
     * The default is the php_test service from docker-compose.yml, reachable
     * under that name from inside the Docker network (which is where the
     * suite runs: `docker compose exec php_test php vendor/bin/phpunit`). It serves
     * the same code as the development site but against the test database,
     * so an HTTP test can create a page without an editor ever seeing it.
     */
    public static function baseUrl(): string
    {
        $configured = self::value('TEST_BASE_URL');

        return rtrim($configured === '' ? 'http://php_test' : $configured, '/');
    }

    /**
     * Where the CMS-ONLY tests send their requests: the php_cms container,
     * the same code and the same test database as php_test but started with
     * MODULE_SHOP_ENABLED=false (docker-compose.yml).
     *
     * A separate server rather than a flag the suite sets, because module
     * configuration is read from the environment a web server was STARTED
     * with — an HTTP test cannot change that from the outside, and a config
     * file would be shared with the development site. The in-process side of
     * the same behaviour uses App\Module\ModuleRegistry::overrideForTests().
     */
    public static function cmsOnlyBaseUrl(): string
    {
        $configured = self::value('TEST_CMS_BASE_URL');

        return rtrim($configured === '' ? 'http://php_cms' : $configured, '/');
    }

    /** True when the CMS-only web server answers; those tests skip otherwise. */
    public static function cmsOnlySiteIsReachable(): bool
    {
        static $reachable = null;

        if ($reachable === null) {
            $reachable = self::answers(self::cmsOnlyBaseUrl() . '/index.php');
        }

        return $reachable;
    }

    /** The message a CMS-only test skips with, so they all read the same. */
    public static function cmsOnlyUnreachableMessage(): string
    {
        return 'no CMS-only test web server on ' . self::cmsOnlyBaseUrl()
            . ' — start it with `docker compose --profile test up -d` (see TESTING.md)';
    }

    /**
     * The host the HTTP tier's requests arrive on — what a test asserts
     * must NOT leak into a canonical URL or a sitemap.
     */
    public static function requestHost(): string
    {
        return (string) parse_url(self::baseUrl(), PHP_URL_HOST);
    }

    /** True when the HTTP tier's web server answers; the tier skips itself otherwise. */
    public static function siteIsReachable(): bool
    {
        static $reachable = null;

        if ($reachable === null) {
            $reachable = self::answers(self::baseUrl() . '/index.php');
        }

        return $reachable;
    }

    private static function answers(string $url): bool
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($handle);
        $answers = curl_errno($handle) === 0;
        curl_close($handle);

        return $answers;
    }

    /** The message an HTTP test skips with, so they all read the same. */
    public static function unreachableMessage(): string
    {
        return 'no test web server on ' . self::baseUrl()
            . ' — start it with `docker compose --profile test up -d` (see TESTING.md)';
    }

    private static function value(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }
}
