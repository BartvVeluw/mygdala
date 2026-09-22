<?php

declare(strict_types=1);

namespace App\Update;

/**
 * release.json: what a release IS, carried inside the release itself.
 *
 * The release builder writes it into every package (docs/updates/RELEASES.md),
 * and after an update it sits in the site root as the installed release's
 * record. It is the one list of files a release owns, with their SHA-256:
 *
 *   - before an update, the updater hashes the installed files against it
 *     to find Core files somebody changed by hand (LocalChanges);
 *   - the new package's copy says what to add and replace, and every path
 *     in the OLD copy that the new one no longer names is deleted
 *     (UpdatePlanner) — so an obsolete PHP file does not stay on a server
 *     forever;
 *   - after an update, the health check hashes the tree against the new copy.
 *
 * A git checkout has no release.json: it is not a release installation, and
 * the Updates screen says so instead of guessing which files are Core.
 *
 * The file does not list itself. Swapping it in is the last write of an
 * update (FileApplier), which makes it the commit point: whichever
 * release.json is in the root is the release that is installed.
 */
final class ReleaseDescriptor
{
    public const FORMAT = 1;

    public const PRODUCT = 'mygdala';

    /**
     * @param array<string, string> $files       path => sha256, sorted by path
     * @param list<string>          $extensions  PHP extensions the release needs
     */
    public function __construct(
        public readonly string $version,
        public readonly string $buildId,
        public readonly string $releasedAt,
        public readonly string $minimumPhp,
        public readonly string $minimumMysql,
        public readonly string $minimumMariadb,
        public readonly array $extensions,
        public readonly int $migrationCount,
        public readonly string $latestMigration,
        public readonly int $updaterProtocol,
        public readonly array $files
    ) {
    }

    /** The installed release, or null for a checkout that is not one. */
    public static function installed(?string $root = null): ?self
    {
        $path = ($root ?? UpdateConfig::projectRoot()) . '/' . Ownership::RELEASE_MANIFEST;

        if (!is_file($path)) {
            return null;
        }

        return self::fromJson((string) file_get_contents($path));
    }

    /**
     * @throws UpdateException when the document is not a valid release.json
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalid('not JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw self::invalid('not an object');
        }

        if (($data['format'] ?? null) !== self::FORMAT) {
            throw self::invalid('unsupported format ' . json_encode($data['format'] ?? null));
        }

        if (($data['product'] ?? null) !== self::PRODUCT) {
            throw self::invalid('not a Mygdala release');
        }

        $version = self::string($data, 'version');
        if (!SemVer::isValid($version)) {
            throw self::invalid('version "' . $version . '" is not MAJOR.MINOR.PATCH');
        }

        $requirements = is_array($data['requirements'] ?? null) ? $data['requirements'] : [];
        $migrations = is_array($data['migrations'] ?? null) ? $data['migrations'] : [];
        $updater = is_array($data['updater'] ?? null) ? $data['updater'] : [];

        $files = $data['files'] ?? null;
        if (!is_array($files) || $files === []) {
            throw self::invalid('no file list');
        }

        $checked = [];
        foreach ($files as $path => $hash) {
            $path = (string) $path;

            if (!RelativePath::isSafe($path)) {
                throw self::invalid('unsafe path ' . RelativePath::printable($path));
            }

            if (!Ownership::isShipped($path) || $path === Ownership::RELEASE_MANIFEST) {
                throw self::invalid('lists a path a release may not own: ' . $path);
            }

            if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw self::invalid('bad SHA-256 for ' . $path);
            }

            $checked[$path] = $hash;
        }
        ksort($checked, SORT_STRING);

        $extensions = [];
        foreach ((array) ($requirements['extensions'] ?? []) as $extension) {
            if (is_string($extension) && preg_match('/^[a-z0-9_]+$/', $extension) === 1) {
                $extensions[] = $extension;
            }
        }

        $latest = (string) ($migrations['latest'] ?? '');
        if ($latest !== '' && preg_match('/^\d{14}$/', $latest) !== 1) {
            throw self::invalid('migrations.latest is not a migration version');
        }

        return new self(
            $version,
            self::string($data, 'build_id'),
            self::string($data, 'released_at'),
            self::versionString($requirements['php'] ?? ''),
            self::versionString($requirements['mysql'] ?? ''),
            self::versionString($requirements['mariadb'] ?? ''),
            $extensions,
            (int) ($migrations['count'] ?? 0),
            $latest,
            (int) ($updater['protocol'] ?? 0),
            $checked
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'product' => self::PRODUCT,
            'version' => $this->version,
            'build_id' => $this->buildId,
            'released_at' => $this->releasedAt,
            'requirements' => [
                'php' => $this->minimumPhp,
                'mysql' => $this->minimumMysql,
                'mariadb' => $this->minimumMariadb,
                'extensions' => $this->extensions,
            ],
            'migrations' => [
                'count' => $this->migrationCount,
                'latest' => $this->latestMigration,
            ],
            'updater' => ['protocol' => $this->updaterProtocol],
            'files' => $this->files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public function semver(): SemVer
    {
        return SemVer::parse($this->version);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? mb_substr(trim($value), 0, 200) : '';
    }

    private static function versionString(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^\d+(\.\d+){0,2}$/', $value) === 1 ? $value : '';
    }

    private static function invalid(string $detail): UpdateException
    {
        return new UpdateException('update.error.release_descriptor_invalid', [], 'release.json: ' . $detail);
    }
}
