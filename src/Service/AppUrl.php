<?php

namespace App\Service;

use Dotenv\Dotenv;

/**
 * Single source of truth for the site's public base URL, used to build
 * canonical links, og:url and absolute og:image URLs without repeating a
 * domain across every page.
 *
 * THE PRECEDENCE CHAIN, and there is exactly one:
 *
 *   1. APP_URL in .env            deployment configuration, and the answer
 *                                 this project documents first
 *   2. site_settings.canonical_base_url
 *                                 what the Setup Wizard writes, for an
 *                                 installation whose owner cannot edit .env
 *   3. DEFAULT_BASE_URL           a placeholder that means "nobody has said"
 *
 * Step 2 exists because APP_URL cannot be reached from the CMS on shared
 * hosting, and demanding it is exactly the manual .env editing the Setup
 * Wizard removes. It sits BEHIND the variable on purpose: a deployment that
 * pins APP_URL keeps deciding, and nothing an administrator saves can
 * silently move the canonical domain of a site whose environment names one.
 * {@see source()} says which step answered, so a screen can show the owner
 * where the value comes from instead of pretending it is theirs to change.
 *
 * THE HOST HEADER IS NEVER CONSULTED. A canonical URL that follows the
 * request is not a canonical URL, and a request must not be able to talk the
 * application into naming a domain the owner never configured.
 *
 * Canonical/OG URLs intentionally resolve to the real production domain even
 * when a page happens to be rendered from local Docker: that was this
 * project's convention before this class existed (canonical tags, sitemap.xml
 * and robots.txt were hardcoded to the production domain and tested that way
 * from local Docker too — see MAIN.MD), and canonical/OG tags are supposed to
 * name the real, indexable URL regardless of where the HTML was generated.
 */
class AppUrl
{
    /**
     * The last resort, and deliberately NOT a real company's domain. It used
     * to be this site's own, which meant a brand-new installation of this
     * CMS published canonical tags and og:url values pointing at Van Veluw
     * Laserdesign until somebody noticed. An obviously-local placeholder is
     * visibly wrong instead of quietly wrong.
     *
     * No existing installation reaches it: db/migrations/20260910110000
     * writes this site's current base URL into `canonical_base_url` as a
     * real row, exactly the way 20260909210000 pinned the branding paths
     * before their defaults went generic.
     */
    private const DEFAULT_BASE_URL = 'https://localhost';

    /** The setting that step 2 of the chain reads. */
    public const SETTING_KEY = 'canonical_base_url';

    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_SETTING = 'setting';
    public const SOURCE_FALLBACK = 'fallback';

    private static bool $envLoaded = false;

    /**
     * The configured base URL, scheme + host only, no trailing slash.
     * Walks the chain above and falls through anything missing, blank or not
     * a well-formed http(s) URL — a malformed value can therefore never
     * produce broken or unsafe HTML, it just falls through (logged).
     */
    public static function base(): string
    {
        $environment = self::normalize(self::environmentValue());
        if ($environment !== null) {
            return $environment;
        }

        $setting = self::normalize(self::settingValue());
        if ($setting !== null) {
            return $setting;
        }

        return self::normalize(self::DEFAULT_BASE_URL) ?? self::DEFAULT_BASE_URL;
    }

    /**
     * Which step of the chain answered: 'environment', 'setting' or
     * 'fallback'. What an admin screen shows next to the value, so nobody
     * edits a field that a configured APP_URL is overruling.
     */
    public static function source(): string
    {
        if (self::normalize(self::environmentValue()) !== null) {
            return self::SOURCE_ENVIRONMENT;
        }

        if (self::normalize(self::settingValue()) !== null) {
            return self::SOURCE_SETTING;
        }

        return self::SOURCE_FALLBACK;
    }

    /** Whether anybody has actually named a base URL for this installation. */
    public static function isConfigured(): bool
    {
        return self::source() !== self::SOURCE_FALLBACK;
    }

    /**
     * Whether the environment pins the base URL, leaving the CMS no say.
     * The wizard asks this to know whether to offer a field or a read-only
     * line explaining where the value lives.
     */
    public static function isPinnedByEnvironment(): bool
    {
        return self::source() === self::SOURCE_ENVIRONMENT;
    }

    /**
     * The environment variable that owns step 1 — named rather than spelled
     * out in a template, so the UI and the code cannot drift apart.
     */
    public static function environmentVariableName(): string
    {
        return 'APP_URL';
    }

    /**
     * The single canonical form of a submitted base URL, or null when it is
     * not one. Public because the wizard validates before it stores, and
     * "what counts as a base URL" must have exactly one answer.
     */
    public static function normalizeBase(string $url): ?string
    {
        return self::normalize($url);
    }

    /**
     * Absolute canonical URL for a site-relative path, e.g. canonical('diensten.php')
     * or canonical('/') for the homepage. Leading slashes on $path are ignored.
     */
    public static function canonical(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }

    /**
     * Absolute URL for a site-relative asset path (image, etc). Same rules
     * as canonical() — kept as a separate method for callers to express intent.
     */
    public static function asset(string $path): string
    {
        return self::canonical($path);
    }

    private static function environmentValue(): string
    {
        self::loadEnv();

        return trim((string) ($_ENV[self::environmentVariableName()] ?? ''));
    }

    /**
     * Step 2. Reading a setting means reading the database, and this is one
     * of the few classes that must keep working when there is none —
     * App\Service\SiteSettings already falls back to its defaults rather
     * than throwing, and the default here is the empty string, which simply
     * hands the question to step 3.
     */
    private static function settingValue(): string
    {
        try {
            return trim(SiteSettings::get(self::SETTING_KEY));
        } catch (\Throwable $e) {
            error_log('[AppUrl] could not read ' . self::SETTING_KEY . ': ' . $e->getMessage());

            return '';
        }
    }

    private static function normalize(string $url): ?string
    {
        $trimmed = rtrim(trim($url), '/');

        if ($trimmed === '') {
            return null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            error_log("[AppUrl] Ignoring malformed base URL '{$trimmed}'");

            return null;
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            error_log("[AppUrl] Ignoring base URL '{$trimmed}': only http and https are allowed");

            return null;
        }

        // Everything downstream appends a path to this value, so a query or
        // a fragment in it would corrupt every canonical link built from it.
        // A PATH is allowed: an installation served from a subdirectory has
        // one, and canonical() concatenates correctly either way.
        if (
            parse_url($trimmed, PHP_URL_QUERY) !== null
            || parse_url($trimmed, PHP_URL_FRAGMENT) !== null
        ) {
            error_log("[AppUrl] Ignoring base URL '{$trimmed}': it must not carry a query or a fragment");

            return null;
        }

        return $trimmed;
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
