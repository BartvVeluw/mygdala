<?php

declare(strict_types=1);

namespace App\Update;

/**
 * THE ownership contract: which files a Mygdala release owns, which files
 * belong to the installation, and which belong to neither because they only
 * exist in the development repository. docs/updates/ARCHITECTURE.md is the
 * prose version of this class; when the two disagree, this class wins.
 *
 * Three answers, and every relative path gets exactly one:
 *
 *   INSTALLATION  .env, uploads, private storage, the maintenance flag and
 *                 the host's own configuration. Never in a release package,
 *                 never replaced, never deleted, never hashed. A package that
 *                 lists one of these paths is refused as a whole
 *                 (PackageValidator), so this list is also the updater's
 *                 promise to every site owner.
 *   DEVELOPMENT   what only the repository needs: tests, Docker, the agent
 *                 setup, prose documentation. The release builder leaves it
 *                 out, and the updater never touches such a file if one
 *                 happens to be on a server (a git checkout, an old upload).
 *   RELEASE       everything else the repository tracks: the application
 *                 code, its assets, its migrations and the bundled vendor/.
 *
 * Both the release builder (App\Update\Build\ReleaseBuilder) and the updater
 * ask this class, so "what ships" and "what may be overwritten" can never be
 * two lists that drift apart. There is no per-file exception anywhere else:
 * a new upload directory is one line in INSTALLATION_PREFIXES, here.
 *
 * Deliberately NOT App\Install\FreshSiteCopyPolicy. That policy answers a
 * different question — what a NEW site copied from this one should start
 * with — and it leaves vendor/ out, which a release must bundle because a
 * shared host has no Composer.
 */
final class Ownership
{
    public const INSTALLATION = 'installation';
    public const DEVELOPMENT = 'development';
    public const RELEASE = 'release';

    /**
     * Whole directories that belong to the installation. Every uploader in
     * src/Service writes under one of these (see the audit in
     * docs/updates/ARCHITECTURE.md); assets/images/ is here as a whole
     * because installations from before the Media Library keep their own
     * images directly in it.
     */
    private const INSTALLATION_PREFIXES = [
        'storage/',
        'assets/media/',
        'assets/images/',
        'assets/videos/',
        'assets/fonts/personalization/',
    ];

    /**
     * Single files that belong to the installation, wherever the site root
     * is. `.maintenance` is the updater's own flag (MaintenanceMode);
     * `.user.ini` and `php.ini` are how a shared host is configured.
     */
    private const INSTALLATION_FILES = [
        '.env',
        '.maintenance',
        '.user.ini',
        'php.ini',
    ];

    /**
     * File names that belong to the installation in ANY directory: PHP on a
     * shared host writes its `error_log` next to the script that failed.
     */
    private const INSTALLATION_BASENAMES = [
        'error_log',
    ];

    /**
     * The only release files inside an installation directory: the sample
     * image the block library previews with, and the MIME rules the
     * engraving-font folder needs to serve uploads correctly.
     */
    private const RELEASE_EXCEPTIONS = [
        'assets/images/block-preview/',
        'assets/fonts/personalization/.htaccess',
    ];

    private const DEVELOPMENT_PREFIXES = [
        '.git/',
        '.github/',
        '.claude/',
        'tests/',
        'docker/',
        'docs/',
        'dist/',
        'node_modules/',
    ];

    private const DEVELOPMENT_FILES = [
        '.gitignore',
        '.gitattributes',
        'docker-compose.yml',
        'phpunit.xml',
        '.phpunit.result.cache',
        // Builds a test database with the MySQL root account; meaningless
        // and unwelcome on a live host.
        'scripts/test-db.php',
        // Copies this repository into a new site; a development tool.
        'scripts/create_fresh_site_copy.php',
        // The release builder's command line. The builder class itself ships
        // (it is ordinary autoloaded code); the command that runs it is for
        // whoever makes releases.
        'scripts/release.php',
    ];

    /** The installed release manifest: written by the build, owned by the release. */
    public const RELEASE_MANIFEST = 'release.json';

    public static function classify(string $path): string
    {
        foreach (self::RELEASE_EXCEPTIONS as $exception) {
            if (self::matches($path, $exception)) {
                return self::RELEASE;
            }
        }

        if (self::isInstallationPath($path)) {
            return self::INSTALLATION;
        }

        if (self::isDevelopmentPath($path)) {
            return self::DEVELOPMENT;
        }

        return self::RELEASE;
    }

    public static function isInstallationOwned(string $path): bool
    {
        return self::classify($path) === self::INSTALLATION;
    }

    /** Would the release builder put this repository file in a package? */
    public static function isShipped(string $path): bool
    {
        return self::classify($path) === self::RELEASE;
    }

    /**
     * The installation directories, for the screens and the plan that say
     * what an update leaves alone.
     *
     * @return list<string>
     */
    public static function installationDirectories(): array
    {
        return array_map(static fn (string $prefix): string => rtrim($prefix, '/'), self::INSTALLATION_PREFIXES);
    }

    /** @return list<string> */
    public static function installationFiles(): array
    {
        return self::INSTALLATION_FILES;
    }

    private static function isInstallationPath(string $path): bool
    {
        foreach (self::INSTALLATION_PREFIXES as $prefix) {
            if (self::matches($path, $prefix)) {
                return true;
            }
        }

        if (in_array($path, self::INSTALLATION_FILES, true)) {
            return true;
        }

        // .env.local, .env.production and friends: every root file whose
        // name starts with .env holds a secret, except the documented
        // example that ships with every release.
        if (!str_contains($path, '/') && str_starts_with($path, '.env') && $path !== '.env.example') {
            return true;
        }

        return in_array(basename($path), self::INSTALLATION_BASENAMES, true);
    }

    private static function isDevelopmentPath(string $path): bool
    {
        foreach (self::DEVELOPMENT_PREFIXES as $prefix) {
            if (self::matches($path, $prefix)) {
                return true;
            }
        }

        if (in_array($path, self::DEVELOPMENT_FILES, true)) {
            return true;
        }

        // Prose documentation, the agent notes in src/*/CLAUDE.md included.
        // Only outside vendor/: a package's LICENSE.md has to travel with it.
        return str_ends_with(strtolower($path), '.md') && !str_starts_with($path, 'vendor/');
    }

    /** A prefix ending in "/" matches the directory and everything in it; anything else matches exactly. */
    private static function matches(string $path, string $rule): bool
    {
        if (str_ends_with($rule, '/')) {
            return str_starts_with($path, $rule) || $path === rtrim($rule, '/');
        }

        return $path === $rule;
    }
}
