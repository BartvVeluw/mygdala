<?php

declare(strict_types=1);

namespace App\Update;

use App\Repository\DatabaseSchemaRepository;

/**
 * Everything that has to be true before an update may change the site.
 *
 * Two passes, because some answers need the package and some do not:
 *
 *   environment()   before anything is downloaded, from the signed manifest
 *                   alone: is this a release installation, is the release
 *                   newer, may this version jump straight to it, do PHP, the
 *                   database server and the extensions meet its requirements,
 *                   can the updater write its own directory. The Updates
 *                   screen shows these as "Vereisten" next to the release.
 *   installation()  once the package is staged and the plan is known: are
 *                   Core files changed by hand, would a new file overwrite
 *                   one that is not Core, can every file to replace be
 *                   replaced, is the migration log what the installed release
 *                   expects, is there room for backup and apply, can the
 *                   database be backed up completely.
 *
 * Any ERROR stops the update with the reasons listed; a WARNING is shown and
 * logged but does not. Nothing here writes to the site. The rule for choosing
 * between the two: an error is something that WILL make the update fail or
 * lose data; a warning is something the updater cannot measure on this host
 * (free disk space when disk_free_space() is disabled, for instance).
 */
final class Preflight
{
    /** What the updater itself needs, whatever the release asks for. */
    public const UPDATER_EXTENSIONS = ['zip', 'sodium', 'json', 'zlib', 'pdo_mysql'];

    /** How many paths a refusal lists before it says "and N more". */
    public const MAX_LISTED_PATHS = 25;

    public function __construct(
        private readonly string $root,
        private readonly string $storagePath,
        private readonly ?DatabaseSchemaRepository $database = null
    ) {
    }

    /**
     * @return list<PreflightCheck>
     */
    public function environment(ReleaseManifest $manifest): array
    {
        $checks = [];

        $this->installedRelease($checks);
        $current = $this->currentVersion($checks);

        if ($current !== null) {
            $target = $manifest->semver();

            $checks[] = $target->isNewerThan($current)
                ? PreflightCheck::ok('version', 'update.preflight.version_ok', ['current' => $current->toString(), 'target' => $target->toString()])
                : PreflightCheck::error('version', 'update.preflight.version_not_newer', ['current' => $current->toString(), 'target' => $target->toString()]);

            if ($manifest->minimumSourceVersion !== '') {
                $minimum = SemVer::parse($manifest->minimumSourceVersion);
                $checks[] = $current->compare($minimum) >= 0
                    ? PreflightCheck::ok('source_version', 'update.preflight.source_ok', ['minimum' => $minimum->toString()])
                    : PreflightCheck::error('source_version', 'update.preflight.source_too_old', ['current' => $current->toString(), 'minimum' => $minimum->toString()]);
            }
        }

        $checks[] = in_array($manifest->updaterProtocol, UpdateState::SUPPORTED_PROTOCOLS, true)
            ? PreflightCheck::ok('protocol', 'update.preflight.protocol_ok')
            : PreflightCheck::error('protocol', 'update.preflight.protocol_unsupported', ['protocol' => $manifest->updaterProtocol]);

        $checks[] = $this->phpVersion($manifest->minimumPhp);
        $checks[] = $this->databaseServer($manifest->minimumMysql, $manifest->minimumMariadb);
        $checks[] = $this->extensions($manifest->requiredExtensions);
        $checks[] = $this->storage();
        $checks[] = $this->freeSpace('disk_download', $this->storagePath, $manifest->size * 4);

        return $checks;
    }

    /** @param list<PreflightCheck> $checks */
    public static function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->isError()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<PreflightCheck> $checks
     */
    private function installedRelease(array &$checks): ?ReleaseDescriptor
    {
        if (is_dir($this->root . '/.git') || is_file($this->root . '/.git')) {
            $checks[] = PreflightCheck::error('release_install', 'update.preflight.git_checkout');

            return null;
        }

        try {
            $descriptor = ReleaseDescriptor::installed($this->root);
        } catch (UpdateException $e) {
            $checks[] = PreflightCheck::error('release_install', 'update.preflight.release_json_invalid', [], $e->getMessage());

            return null;
        }

        if ($descriptor === null) {
            $checks[] = PreflightCheck::error('release_install', 'update.preflight.not_a_release');

            return null;
        }

        $checks[] = PreflightCheck::ok('release_install', 'update.preflight.release_ok', ['version' => $descriptor->version]);

        return $descriptor;
    }

    /** @param list<PreflightCheck> $checks */
    private function currentVersion(array &$checks): ?SemVer
    {
        try {
            $current = AppVersion::semver($this->root);
        } catch (\RuntimeException $e) {
            $checks[] = PreflightCheck::error('version', 'update.preflight.version_unknown', [], $e->getMessage());

            return null;
        }

        $descriptor = null;
        try {
            $descriptor = ReleaseDescriptor::installed($this->root);
        } catch (UpdateException) {
            // Already reported by installedRelease().
        }

        if ($descriptor !== null && $descriptor->version !== $current->toString()) {
            $checks[] = PreflightCheck::error(
                'version',
                'update.preflight.version_disagrees',
                ['version' => $current->toString(), 'release' => $descriptor->version]
            );

            return null;
        }

        return $current;
    }

    private function phpVersion(string $minimum): PreflightCheck
    {
        if ($minimum === '' || version_compare(PHP_VERSION, $minimum, '>=')) {
            return PreflightCheck::ok('php', 'update.preflight.php_ok', ['version' => PHP_VERSION]);
        }

        return PreflightCheck::error('php', 'update.preflight.php_too_old', ['version' => PHP_VERSION, 'minimum' => $minimum]);
    }

    private function databaseServer(string $minimumMysql, string $minimumMariadb): PreflightCheck
    {
        try {
            $reported = ($this->database ?? new DatabaseSchemaRepository())->serverVersion();
        } catch (\Throwable $e) {
            return PreflightCheck::error('database', 'update.preflight.database_unreachable', [], $e->getMessage());
        }

        $isMariaDb = stripos($reported, 'mariadb') !== false;
        preg_match('/^\d+\.\d+(\.\d+)?/', $reported, $match);
        $version = $match[0] ?? '0';
        $minimum = $isMariaDb ? $minimumMariadb : $minimumMysql;
        $server = ($isMariaDb ? 'MariaDB ' : 'MySQL ') . $version;

        if ($minimum === '' || version_compare($version, $minimum, '>=')) {
            return PreflightCheck::ok('database', 'update.preflight.database_ok', ['server' => $server]);
        }

        return PreflightCheck::error('database', 'update.preflight.database_too_old', ['server' => $server, 'minimum' => $minimum]);
    }

    /** @param list<string> $required */
    private function extensions(array $required): PreflightCheck
    {
        $missing = [];
        foreach (array_unique([...self::UPDATER_EXTENSIONS, ...$required]) as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        if ($missing === []) {
            return PreflightCheck::ok('extensions', 'update.preflight.extensions_ok');
        }

        return PreflightCheck::error('extensions', 'update.preflight.extensions_missing', ['extensions' => implode(', ', $missing)]);
    }

    private function storage(): PreflightCheck
    {
        if (!is_dir($this->storagePath) && !@mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
            return PreflightCheck::error('storage', 'update.preflight.storage_missing', ['path' => $this->storagePath]);
        }

        $probe = $this->storagePath . '/.write-test-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'x') !== 1) {
            return PreflightCheck::error('storage', 'update.preflight.storage_not_writable', ['path' => $this->storagePath]);
        }
        @unlink($probe);

        return PreflightCheck::ok('storage', 'update.preflight.storage_ok');
    }

    private function freeSpace(string $name, string $directory, int $needed): PreflightCheck
    {
        $free = function_exists('disk_free_space') ? @disk_free_space(is_dir($directory) ? $directory : dirname($directory)) : false;

        if ($free === false) {
            return PreflightCheck::warning($name, 'update.preflight.disk_unknown', ['needed' => self::megabytes($needed)]);
        }

        if ($free < $needed) {
            return PreflightCheck::error($name, 'update.preflight.disk_insufficient', [
                'needed' => self::megabytes($needed),
                'free' => self::megabytes((int) $free),
            ]);
        }

        return PreflightCheck::ok($name, 'update.preflight.disk_ok', ['free' => self::megabytes((int) $free)]);
    }

    public static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
}
