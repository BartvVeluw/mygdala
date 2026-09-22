<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The release manifest a feed publishes: the newest release, what it needs,
 * and where its package is. docs/updates/RELEASES.md documents the format.
 *
 *     {
 *       "manifest_version": 1,
 *       "product": "mygdala",
 *       "version": "0.2.0",
 *       "build_id": "0.2.0+3f2a1c9",
 *       "released_at": "2026-10-01T12:00:00Z",
 *       "package_url": "mygdala-0.2.0.zip",
 *       "sha256": "…",
 *       "size": 9876543,
 *       "minimum_php": "8.2",
 *       "minimum_mysql": "5.7",
 *       "minimum_mariadb": "10.3",
 *       "required_extensions": ["pdo_mysql", "zip", "sodium", …],
 *       "minimum_source_version": "0.1.0",
 *       "updater_protocol": 1,
 *       "migrations": {"count": 162, "latest": "20261001120000"},
 *       "notes": "…"
 *     }
 *
 * Only ever built from bytes whose signature has already been verified
 * (HttpUpdateSource does that first). Every field the updater acts on is
 * validated here, once, so no later step handles an unchecked value — and the
 * package URL in particular only ever comes from this signed document, never
 * from a request (docs/updates/ARCHITECTURE.md, "Beveiliging").
 *
 * `manifest_version` makes the format versioned: a feed that moves to a
 * format this updater does not know is refused with a message that says so,
 * rather than half-understood.
 */
final class ReleaseManifest
{
    /** The manifest formats this updater reads. */
    public const SUPPORTED_VERSIONS = [1];

    /** The largest package the updater will download, in bytes. */
    public const MAX_PACKAGE_BYTES = 314572800;

    private const MAX_NOTES_LENGTH = 20000;

    /**
     * @param list<string> $requiredExtensions
     */
    private function __construct(
        public readonly int $manifestVersion,
        public readonly string $version,
        public readonly string $buildId,
        public readonly string $releasedAt,
        public readonly string $packageUrl,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $minimumPhp,
        public readonly string $minimumMysql,
        public readonly string $minimumMariadb,
        public readonly array $requiredExtensions,
        public readonly string $minimumSourceVersion,
        public readonly int $updaterProtocol,
        public readonly int $migrationCount,
        public readonly string $latestMigration,
        public readonly string $notes
    ) {
    }

    /**
     * @param string $manifestUrl where the bytes came from; a relative
     *                            package_url is resolved against it
     *
     * @throws UpdateException
     */
    public static function fromJson(string $json, string $manifestUrl): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalid('not JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw self::invalid('not an object');
        }

        $format = $data['manifest_version'] ?? null;
        if (!is_int($format)) {
            throw self::invalid('manifest_version missing');
        }

        if (!in_array($format, self::SUPPORTED_VERSIONS, true)) {
            throw new UpdateException(
                'update.error.manifest_format',
                ['format' => $format],
                'Unsupported manifest_version ' . $format
            );
        }

        if (($data['product'] ?? null) !== ReleaseDescriptor::PRODUCT) {
            throw self::invalid('not a Mygdala manifest');
        }

        $version = self::string($data, 'version');
        if (!SemVer::isValid($version)) {
            throw self::invalid('version "' . $version . '" is not MAJOR.MINOR.PATCH');
        }

        $sha256 = self::string($data, 'sha256');
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw self::invalid('sha256 is not 64 lowercase hex digits');
        }

        $size = $data['size'] ?? null;
        if (!is_int($size) || $size <= 0) {
            throw self::invalid('size is not a positive integer');
        }

        if ($size > self::MAX_PACKAGE_BYTES) {
            throw new UpdateException(
                'update.error.package_too_large',
                ['size' => $size],
                'Package of ' . $size . ' bytes exceeds the limit'
            );
        }

        $releasedAt = self::string($data, 'released_at');
        if ($releasedAt === '' || strtotime($releasedAt) === false) {
            throw self::invalid('released_at is not a date');
        }

        $minimumSource = self::string($data, 'minimum_source_version');
        if ($minimumSource !== '' && !SemVer::isValid($minimumSource)) {
            throw self::invalid('minimum_source_version is not MAJOR.MINOR.PATCH');
        }

        $protocol = $data['updater_protocol'] ?? null;
        if (!is_int($protocol) || $protocol < 1) {
            throw self::invalid('updater_protocol missing');
        }

        $migrations = is_array($data['migrations'] ?? null) ? $data['migrations'] : [];
        $latest = is_string($migrations['latest'] ?? null) ? $migrations['latest'] : '';
        if ($latest !== '' && preg_match('/^\d{14}$/', $latest) !== 1) {
            throw self::invalid('migrations.latest is not a migration version');
        }

        $extensions = [];
        foreach ((array) ($data['required_extensions'] ?? []) as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z0-9_]+$/', $extension) !== 1) {
                throw self::invalid('required_extensions holds something that is not an extension name');
            }
            $extensions[] = $extension;
        }

        $buildId = self::string($data, 'build_id');
        if ($buildId !== '' && preg_match('/^[A-Za-z0-9._+-]{1,100}$/', $buildId) !== 1) {
            throw self::invalid('build_id has characters it may not have');
        }

        $notes = is_string($data['notes'] ?? null) ? (string) $data['notes'] : '';

        return new self(
            $format,
            $version,
            $buildId,
            $releasedAt,
            self::resolvePackageUrl(self::string($data, 'package_url'), $manifestUrl),
            $sha256,
            $size,
            self::requirement($data, 'minimum_php'),
            self::requirement($data, 'minimum_mysql'),
            self::requirement($data, 'minimum_mariadb'),
            $extensions,
            $minimumSource,
            $protocol,
            (int) ($migrations['count'] ?? 0),
            $latest,
            mb_substr($notes, 0, self::MAX_NOTES_LENGTH)
        );
    }

    public function semver(): SemVer
    {
        return SemVer::parse($this->version);
    }

    /** What the Updates screen and the state file keep of a manifest. */
    public function toArray(): array
    {
        return [
            'manifest_version' => $this->manifestVersion,
            'version' => $this->version,
            'build_id' => $this->buildId,
            'released_at' => $this->releasedAt,
            'package_url' => $this->packageUrl,
            'sha256' => $this->sha256,
            'size' => $this->size,
            'minimum_php' => $this->minimumPhp,
            'minimum_mysql' => $this->minimumMysql,
            'minimum_mariadb' => $this->minimumMariadb,
            'required_extensions' => $this->requiredExtensions,
            'minimum_source_version' => $this->minimumSourceVersion,
            'updater_protocol' => $this->updaterProtocol,
            'migrations' => ['count' => $this->migrationCount, 'latest' => $this->latestMigration],
            'notes' => $this->notes,
        ];
    }

    /**
     * Rebuilds a manifest the updater already verified and stored in its own
     * state (UpdateStateStore). The storage directory is private and written
     * only by the updater, so this re-validates the shape but does not ask
     * for the signature a second time.
     *
     * @param array<string, mixed> $data
     */
    public static function fromStoredArray(array $data): self
    {
        $manifestUrl = (string) ($data['package_url'] ?? '');
        $data['product'] = ReleaseDescriptor::PRODUCT;

        return self::fromJson(json_encode($data, JSON_THROW_ON_ERROR), $manifestUrl);
    }

    /**
     * A relative package_url means "next to the manifest", so a feed is one
     * directory that can move hosts without re-signing anything. Anything
     * relative has to be a plain file name or path: no "..", no query games.
     */
    private static function resolvePackageUrl(string $packageUrl, string $manifestUrl): string
    {
        if ($packageUrl === '') {
            throw self::invalid('package_url missing');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $packageUrl) === 1) {
            return $packageUrl;
        }

        if (preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $packageUrl) !== 1 || str_contains($packageUrl, '..')) {
            throw self::invalid('relative package_url must be a plain path');
        }

        $parts = parse_url($manifestUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw self::invalid('cannot resolve a relative package_url without a manifest URL');
        }

        $directory = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . $directory . $packageUrl;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $data */
    private static function requirement(array $data, string $key): string
    {
        $value = self::string($data, $key);

        if ($value !== '' && preg_match('/^\d+(\.\d+){0,2}$/', $value) !== 1) {
            throw self::invalid($key . ' is not a version number');
        }

        return $value;
    }

    private static function invalid(string $detail): UpdateException
    {
        return new UpdateException('update.error.manifest_invalid', [], 'Manifest: ' . $detail);
    }
}
