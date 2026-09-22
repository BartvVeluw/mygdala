<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The one central gate every web request passes while `.maintenance` exists.
 *
 * WHERE IT RUNS. This project has no shared bootstrap — every entry point
 * requires vendor/autoload.php itself — so the gate hangs on the only thing
 * they all share: Composer's autoload "files" list (composer.json), which
 * runs src/Update/maintenance-guard.php inside that require. It therefore
 * runs before a page reads .env, opens the database or starts a session.
 * The file does a single is_file(); only when the flag exists is this class
 * loaded at all.
 *
 * WHAT PASSES:
 *   - the command line (phinx, scripts, the test suite);
 *   - the updater itself and what it needs to be reached: the Updates
 *     screen, its step and resolve endpoints, login and logout (EXEMPT);
 *   - a request carrying the current update's health token (HealthCheck).
 *
 * WHAT DOES NOT:
 *   - an admin screen is sent to the Updates screen (302), which then shows
 *     what is going on, or the login first;
 *   - an admin API gets a plain 503;
 *   - everything else — the public site — gets a small, self-contained 503
 *     page that needs no database, no theme and no language setting, because
 *     none of those can be trusted halfway through an update.
 *
 * An unreadable flag counts as a flag: when in doubt the site stays closed.
 */
final class MaintenanceGuard
{
    /** Scripts that stay reachable during maintenance, relative to the site root. */
    public const EXEMPT = [
        'admin/login.php',
        'admin/logout.php',
        'admin/updates.php',
        'api/admin/updates-step.php',
        'api/admin/updates-resolve.php',
    ];

    public const HEALTH_HEADER = 'X-Mygdala-Health';

    public const RETRY_AFTER_SECONDS = 120;

    public const PASS = 'pass';
    public const REDIRECT = 'redirect';
    public const API_UNAVAILABLE = 'api';
    public const PAGE_UNAVAILABLE = 'page';

    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        $root = dirname(__DIR__, 2);
        $flag = (new MaintenanceMode($root))->read();

        $decision = self::decide(
            $flag,
            $root,
            (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            (string) ($_SERVER['HTTP_X_MYGDALA_HEALTH'] ?? '')
        );

        if ($decision === self::PASS) {
            return;
        }

        header('Cache-Control: no-store');
        header('Retry-After: ' . self::RETRY_AFTER_SECONDS);

        if ($decision === self::REDIRECT) {
            header('Location: /admin/updates.php', true, 302);
            exit;
        }

        http_response_code(503);

        if ($decision === self::API_UNAVAILABLE) {
            header('Content-Type: text/plain; charset=utf-8');
            exit('Service unavailable: an update is being installed.');
        }

        header('Content-Type: text/html; charset=utf-8');
        echo self::page();
        exit;
    }

    /**
     * The decision alone, as a pure function of the request, so it can be
     * tested without a web server.
     *
     * @param array<string, mixed>|null $flag MaintenanceMode::read()
     */
    public static function decide(?array $flag, string $root, string $scriptFilename, string $requestUri, string $healthToken): string
    {
        if ($flag === null) {
            return self::PASS;
        }

        $script = self::relativeScript($root, $scriptFilename);
        if ($script !== null && in_array($script, self::EXEMPT, true)) {
            return self::PASS;
        }

        $expected = is_string($flag['health'] ?? null) ? $flag['health'] : '';
        if ($expected !== '' && $healthToken !== '' && hash_equals($expected, hash('sha256', $healthToken))) {
            return self::PASS;
        }

        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');

        if (str_starts_with($path, '/api/')) {
            return self::API_UNAVAILABLE;
        }

        if (str_starts_with($path, '/admin/') || $path === '/admin') {
            return self::REDIRECT;
        }

        return self::PAGE_UNAVAILABLE;
    }

    /**
     * The script PHP is running, relative to the site root — from the
     * resolved file path rather than the URL, so no PATH_INFO or encoding
     * trick can make /index.php look like an exempt endpoint.
     */
    private static function relativeScript(string $root, string $scriptFilename): ?string
    {
        $script = realpath($scriptFilename);
        $base = realpath($root);

        if ($script === false || $base === false) {
            return null;
        }

        $script = str_replace('\\', '/', $script);
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';

        return str_starts_with($script, $base) ? substr($script, strlen($base)) : null;
    }

    /**
     * Visitor text, in both languages this CMS ships a catalogue for, because
     * which language this visitor wanted is exactly what cannot be looked up
     * right now.
     */
    public static function page(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="nl">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>Onderhoud · Maintenance</title>
            <style>
              :root { color-scheme: light dark; }
              body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 16px/1.5 system-ui, sans-serif; background: #f6f6f4; color: #1d1d1b; }
              main { max-width: 32rem; padding: 2rem 1.5rem; }
              h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
              p { margin: 0 0 1.25rem; }
              p[lang="en"] { color: #5b5b57; }
              @media (prefers-color-scheme: dark) { body { background: #1b1b1a; color: #ededea; } p[lang="en"] { color: #a9a9a4; } }
            </style>
            </head>
            <body>
            <main>
              <h1>Deze website wordt bijgewerkt</h1>
              <p>Over een paar minuten is alles weer beschikbaar. Probeer het dan opnieuw.</p>
              <p lang="en">This website is being updated. Please try again in a few minutes.</p>
            </main>
            </body>
            </html>
            HTML;
    }
}
