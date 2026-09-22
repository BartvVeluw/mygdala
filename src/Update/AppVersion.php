<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The version of Mygdala this code IS. One canonical answer:
 *
 *     AppVersion::current()   // "0.1.0"
 *
 * Read from the VERSION file in the site root, which is committed in the
 * repository and shipped in every release package. Never derived from git:
 * a live server has no .git, and a version that depends on how the files got
 * there is no version. The release builder refuses to build a package whose
 * VERSION disagrees with the version it was asked to build
 * (docs/updates/RELEASES.md), so the file and the package can never tell two
 * stories.
 *
 * SemVer (MAJOR.MINOR.PATCH) leads. The build id and the release date live
 * in release.json (ReleaseDescriptor), which only a release package has:
 * build() and releasedAt() are informative, and empty on a development
 * checkout.
 *
 * The release version and the migration version are independent. A release
 * does not bump the schema by definition, and a migration does not make a
 * release; the health check compares each against its own expectation.
 */
final class AppVersion
{
    public const VERSION_FILE = 'VERSION';

    /** @var array<string, string> per-request cache, by root */
    private static array $cache = [];

    /**
     * @throws \RuntimeException when VERSION is missing or not MAJOR.MINOR.PATCH —
     *                           a broken installation, not a state to guess around
     */
    public static function current(?string $root = null): string
    {
        $root ??= UpdateConfig::projectRoot();

        if (isset(self::$cache[$root])) {
            return self::$cache[$root];
        }

        $path = $root . '/' . self::VERSION_FILE;
        $version = is_file($path) ? trim((string) file_get_contents($path)) : '';

        if (!SemVer::isValid($version)) {
            throw new \RuntimeException(sprintf('%s does not hold a MAJOR.MINOR.PATCH version', $path));
        }

        return self::$cache[$root] = $version;
    }

    public static function semver(?string $root = null): SemVer
    {
        return SemVer::parse(self::current($root));
    }

    /** The build id of the installed release; '' on a development checkout. */
    public static function build(?string $root = null): string
    {
        return self::descriptor($root)?->buildId ?? '';
    }

    /** When the installed release was made (ISO 8601); '' on a development checkout. */
    public static function releasedAt(?string $root = null): string
    {
        return self::descriptor($root)?->releasedAt ?? '';
    }

    /** Forgets what this request read, for code that just replaced VERSION. */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function descriptor(?string $root): ?ReleaseDescriptor
    {
        try {
            return ReleaseDescriptor::installed($root);
        } catch (UpdateException) {
            return null;
        }
    }
}
