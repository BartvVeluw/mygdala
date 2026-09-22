<?php

declare(strict_types=1);

namespace App\Update;

use App\Service\AppEnvironment;
use Dotenv\Dotenv;

/**
 * Where updates come from and where the updater keeps its work — decided per
 * DISTRIBUTION, in the environment, never by a site administrator in a form.
 *
 * A field on a settings screen would let anybody with a CMS login point a
 * site at a different update server, and the updater would then happily
 * install whatever that server signs with a key the site trusts. So the
 * feed and the trust root are deployment configuration, exactly like
 * APP_URL (App\Service\AppUrl) and the database credentials:
 *
 *   MYGDALA_UPDATE_MANIFEST_URL   the release manifest (manifest.json)
 *   MYGDALA_UPDATE_PUBLIC_KEY     the Ed25519 public key releases are signed
 *                                 with; replaces the built-in key
 *                                 (ReleaseKeys) when set
 *   MYGDALA_UPDATE_STORAGE_PATH   downloads, staging, backups, state, logs
 *
 * The project default for the first two is empty until the project's own
 * release hosting is chosen (docs/updates/RELEASES.md): an unconfigured feed
 * is visibly unconfigured on the Updates screen, not quietly pointed at a
 * host nobody decided on.
 *
 * Transport: HTTPS only, except on an installation that says explicitly it
 * is not production (APP_ENV, App\Service\AppEnvironment). Production is the
 * default there, so a missing or mistyped APP_ENV can never downgrade a live
 * site to plain HTTP; the exception exists for the local fixture server the
 * upgrade tests use.
 *
 * Same env-loading pattern as AppEnvironment and AppUrl: load .env once,
 * immutably, so a value in the process environment wins over the file.
 */
final class UpdateConfig
{
    public const MANIFEST_URL_VARIABLE = 'MYGDALA_UPDATE_MANIFEST_URL';
    public const PUBLIC_KEY_VARIABLE = 'MYGDALA_UPDATE_PUBLIC_KEY';
    public const STORAGE_PATH_VARIABLE = 'MYGDALA_UPDATE_STORAGE_PATH';

    /** The project's own feed, filled in once release hosting is chosen. */
    public const DEFAULT_MANIFEST_URL = '';

    private static bool $envLoaded = false;

    /** @var array<string, string>|null */
    private static ?array $overrides = null;

    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** '' when no feed is configured. */
    public static function manifestUrl(): string
    {
        $configured = self::value(self::MANIFEST_URL_VARIABLE);

        return $configured !== '' ? $configured : self::DEFAULT_MANIFEST_URL;
    }

    public static function publicKey(): string
    {
        return self::value(self::PUBLIC_KEY_VARIABLE);
    }

    /**
     * The updater's own directory. Outside the web root by default, next to
     * the other private storage of this installation
     * (App\Service\ContactAttachmentStorage uses the same parent): it holds a
     * full database dump, which is every customer address the site has.
     */
    public static function storagePath(): string
    {
        $configured = self::value(self::STORAGE_PATH_VARIABLE);

        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        return dirname(self::projectRoot()) . '/storage/updates';
    }

    public static function allowsInsecureTransport(): bool
    {
        return !AppEnvironment::isProduction();
    }

    /**
     * Is $url one the updater may fetch? HTTPS always; HTTP only where
     * allowsInsecureTransport() says so. No credentials in the URL — a
     * token belongs in no log line and in no manifest.
     */
    public static function isAcceptableUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        return $scheme === 'https' || ($scheme === 'http' && self::allowsInsecureTransport());
    }

    /**
     * A URL as it may appear on a screen or in the update log: scheme, host
     * and path, never a query string (which is where a download token would
     * be).
     */
    public static function describeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'])) {
            return '(invalid URL)';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower((string) ($parts['scheme'] ?? '')) . '://' . $parts['host'] . $port . ($parts['path'] ?? '/');
    }

    /**
     * Test seam: pretend these variables are set (and every other updater
     * variable is empty). Pass null to go back to the environment.
     *
     * @param array<string, string>|null $values
     */
    public static function overrideForTests(?array $values): void
    {
        self::$overrides = $values;
    }

    private static function value(string $name): string
    {
        if (self::$overrides !== null) {
            return trim((string) (self::$overrides[$name] ?? ''));
        }

        self::loadEnv();

        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        $root = self::projectRoot();
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
