<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Is what came out of the package a complete, consistent Mygdala release —
 * the one the signed manifest announced?
 *
 *   - release.json is there and valid, and names exactly the files that are
 *     there: none missing, none extra, every SHA-256 right;
 *   - its version, VERSION and the manifest agree, and so do the
 *     requirements and the migration count the manifest promised;
 *   - no path belongs to the installation (Ownership) — a package that
 *     tries to ship a .env or an upload folder is refused as a whole, not
 *     filtered;
 *   - the files the NEXT steps of this very update depend on are present:
 *     after `apply`, the new release's own updater finishes the job, so a
 *     release without one could never complete, let alone roll back.
 *
 * Returns the release's descriptor, which the planner then compares with
 * the installed one.
 */
final class PackageValidator
{
    /**
     * Without these the new release could not finish the update it is part
     * of: bootstrap, the migrations, and the updater's own step endpoint.
     */
    public const REQUIRED_PATHS = [
        'VERSION',
        'phinx.php',
        'vendor/autoload.php',
        'src/Update/Updater.php',
        'src/Update/MaintenanceGuard.php',
        'admin/updates.php',
        'api/admin/update-step.php',
    ];

    public function __construct(
        private readonly string $stagingPath
    ) {
    }

    /**
     * @throws UpdateException update.error.package_invalid with the reason
     */
    public function validate(ReleaseManifest $manifest): ReleaseDescriptor
    {
        $descriptorPath = $this->stagingPath . '/' . Ownership::RELEASE_MANIFEST;
        if (!is_file($descriptorPath)) {
            throw self::invalid('release.json is missing');
        }

        $descriptor = ReleaseDescriptor::fromJson((string) file_get_contents($descriptorPath));

        if ($descriptor->version !== $manifest->version) {
            throw self::invalid(sprintf('release.json says %s, the manifest %s', $descriptor->version, $manifest->version));
        }

        $version = is_file($this->stagingPath . '/VERSION') ? trim((string) file_get_contents($this->stagingPath . '/VERSION')) : '';
        if ($version !== $manifest->version) {
            throw self::invalid(sprintf('VERSION says "%s", the manifest %s', $version, $manifest->version));
        }

        foreach ([
            'updater protocol' => [$descriptor->updaterProtocol, $manifest->updaterProtocol],
            'minimum PHP' => [$descriptor->minimumPhp, $manifest->minimumPhp],
            'minimum MySQL' => [$descriptor->minimumMysql, $manifest->minimumMysql],
            'minimum MariaDB' => [$descriptor->minimumMariadb, $manifest->minimumMariadb],
            'migration count' => [$descriptor->migrationCount, $manifest->migrationCount],
            'latest migration' => [$descriptor->latestMigration, $manifest->latestMigration],
        ] as $what => [$inPackage, $inManifest]) {
            if ($inPackage !== $inManifest) {
                throw self::invalid(sprintf('%s differs: package %s, manifest %s', $what, var_export($inPackage, true), var_export($inManifest, true)));
            }
        }

        foreach (self::REQUIRED_PATHS as $required) {
            if (!isset($descriptor->files[$required])) {
                throw self::invalid('the release lacks ' . $required);
            }
        }

        $onDisk = $this->filesInStaging();
        unset($onDisk[Ownership::RELEASE_MANIFEST]);

        $extra = array_diff_key($onDisk, $descriptor->files);
        if ($extra !== []) {
            throw self::invalid('files not listed in release.json: ' . implode(', ', array_slice(array_keys($extra), 0, 5)));
        }

        foreach ($descriptor->files as $path => $hash) {
            if (!isset($onDisk[$path])) {
                throw self::invalid('listed but missing: ' . $path);
            }

            if (!hash_equals($hash, (string) hash_file('sha256', $this->stagingPath . '/' . $path))) {
                throw self::invalid('SHA-256 differs for ' . $path);
            }
        }

        $migrations = 0;
        foreach (array_keys($descriptor->files) as $path) {
            if (preg_match('#^db/migrations/\d{14}_[A-Za-z0-9_]+\.php$#', $path) === 1) {
                $migrations++;
            }
        }
        if ($migrations !== $descriptor->migrationCount) {
            throw self::invalid(sprintf('%d migration files, release.json says %d', $migrations, $descriptor->migrationCount));
        }

        return $descriptor;
    }

    /**
     * Every file under staging, as relative path => true. A link anywhere is
     * a refusal: the extractor never makes one, so one here was planted.
     *
     * @return array<string, true>
     */
    private function filesInStaging(): array
    {
        $files = [];
        $root = str_replace('\\', '/', $this->stagingPath);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stagingPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen($root)), '/');

            if ($item->isLink()) {
                throw self::invalid('a link in the package: ' . $relative);
            }

            if ($item->isFile()) {
                $files[$relative] = true;
            }
        }

        return $files;
    }

    private static function invalid(string $detail): UpdateException
    {
        return new UpdateException('update.error.package_invalid', [], $detail);
    }
}
