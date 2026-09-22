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
    public const UPDATER_EXTENSIONS = ['zip', 'sodium', 'json', 'zlib', 'mbstring', 'pdo_mysql'];

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
        $settings = self::opcacheSettings();
        $checks[] = self::opcacheCheck($settings['enabled'], $settings['validates_timestamps'], $settings['revalidate_freq'], $settings['can_invalidate']);

        return $checks;
    }

    /**
     * Will the next request run the NEW files? With OPcache on, a replaced
     * file is only recompiled when the updater may invalidate it
     * (opcache_invalidate(), not blocked by opcache.restrict_api) or when
     * OPcache checks timestamps and the updater waits out revalidate_freq.
     * A host that does neither would run old bytecode next to new files and
     * the health check would not notice, so the update is refused there.
     */
    public static function opcacheCheck(bool $enabled, bool $validatesTimestamps, int $revalidateFrequency, bool $canInvalidate): PreflightCheck
    {
        if (!$enabled || $canInvalidate || ($validatesTimestamps && $revalidateFrequency <= 60)) {
            return PreflightCheck::ok('opcache', 'update.preflight.opcache_ok');
        }

        return PreflightCheck::error('opcache', 'update.preflight.opcache_stale');
    }

    /** How long the screen waits after `apply` before the first request on the new code. */
    public static function opcacheDelayMilliseconds(): int
    {
        $settings = self::opcacheSettings();

        if (!$settings['enabled'] || $settings['can_invalidate']) {
            return 500;
        }

        return min(61000, ($settings['revalidate_freq'] + 1) * 1000);
    }

    /**
     * @return array{enabled: bool, validates_timestamps: bool, revalidate_freq: int, can_invalidate: bool}
     */
    public static function opcacheSettings(): array
    {
        $flag = static fn (string $name): bool => filter_var(ini_get($name), FILTER_VALIDATE_BOOLEAN);
        $enabled = extension_loaded('Zend OPcache') && $flag(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable');

        $restriction = (string) ini_get('opcache.restrict_api');
        $script = str_replace('\\', '/', (string) (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: ''));

        return [
            'enabled' => $enabled,
            'validates_timestamps' => ini_get('opcache.validate_timestamps') === false || $flag('opcache.validate_timestamps'),
            'revalidate_freq' => max(0, (int) ini_get('opcache.revalidate_freq')),
            'can_invalidate' => function_exists('opcache_invalidate')
                && ($restriction === '' || ($script !== '' && str_starts_with($script, str_replace('\\', '/', $restriction)))),
        ];
    }

    /**
     * The second pass, once the package is staged and the plan is known.
     *
     * @param array{pending: list<string>, missing: list<string>} $migrations the INSTALLED release's status
     *
     * @return list<PreflightCheck>
     */
    public function installation(UpdatePlan $plan, LocalChanges $changes, array $migrations, string $stagingPath): array
    {
        $checks = [];

        $checks[] = $changes->modified === []
            ? PreflightCheck::ok('local_changes', 'update.preflight.local_changes_none')
            : PreflightCheck::error('local_changes', 'update.preflight.local_changes', [
                'count' => count($changes->modified),
                'paths' => self::listPaths($changes->modified),
            ]);

        if ($changes->missing !== []) {
            $checks[] = PreflightCheck::error('local_missing', 'update.preflight.local_missing', [
                'count' => count($changes->missing),
                'paths' => self::listPaths($changes->missing),
            ]);
        }

        $checks[] = $plan->conflicts === []
            ? PreflightCheck::ok('conflicts', 'update.preflight.conflicts_none')
            : PreflightCheck::error('conflicts', 'update.preflight.conflicts', [
                'count' => count($plan->conflicts),
                'paths' => self::listPaths($plan->conflicts),
            ]);

        $unwritable = $this->unwritable($plan);
        $checks[] = $unwritable === []
            ? PreflightCheck::ok('writable', 'update.preflight.writable_ok')
            : PreflightCheck::error('writable', 'update.preflight.not_writable', [
                'count' => count($unwritable),
                'paths' => self::listPaths($unwritable),
            ]);

        if ($migrations['pending'] !== []) {
            $checks[] = PreflightCheck::error('migrations', 'update.preflight.migrations_pending', [
                'count' => count($migrations['pending']),
                'versions' => self::listPaths($migrations['pending']),
            ]);
        } elseif ($migrations['missing'] !== []) {
            $checks[] = PreflightCheck::error('migrations', 'update.preflight.migrations_unknown', [
                'count' => count($migrations['missing']),
                'versions' => self::listPaths($migrations['missing']),
            ]);
        } else {
            $checks[] = PreflightCheck::ok('migrations', 'update.preflight.migrations_ok');
        }

        $database = $this->database ?? new DatabaseSchemaRepository();
        $databaseBytes = 0;
        try {
            $objects = $database->programmableObjects();
            $databaseBytes = $database->estimatedSize();
            $checks[] = array_sum($objects) === 0
                ? PreflightCheck::ok('backup', 'update.preflight.backup_ok')
                : PreflightCheck::error('backup', 'update.preflight.backup_unsupported', $objects);
        } catch (\Throwable $e) {
            $checks[] = PreflightCheck::error('backup', 'update.preflight.database_unreachable', [], $e->getMessage());
        }

        $checks[] = is_file($this->root . '/.maintenance')
            ? PreflightCheck::error('maintenance', 'update.preflight.maintenance_active')
            : PreflightCheck::ok('maintenance', 'update.preflight.maintenance_off');

        $backupBytes = $databaseBytes;
        foreach ($plan->pathsToBackUp() as $path) {
            $backupBytes += (int) @filesize($this->root . '/' . $path);
        }
        $checks[] = $this->freeSpace('disk_backup', $this->storagePath, $backupBytes + 50 * 1048576);

        $applyBytes = 0;
        foreach ([...array_keys($plan->add), ...array_keys($plan->replace)] as $path) {
            $applyBytes += (int) @filesize($stagingPath . '/' . $path);
        }
        $checks[] = $this->freeSpace('disk_apply', $this->root, $applyBytes + 20 * 1048576);

        return $checks;
    }

    /**
     * Every file the plan will write or remove, and the directory it lives
     * in, must be writable by PHP. Checked for the directory too: a rename
     * onto a file needs write access to the directory, not only to the file.
     *
     * @return list<string>
     */
    private function unwritable(UpdatePlan $plan): array
    {
        $problems = [];
        $checkedDirectories = [];

        $directoryOk = function (string $directory) use (&$checkedDirectories): bool {
            if (!isset($checkedDirectories[$directory])) {
                $checkedDirectories[$directory] = is_dir($directory) && is_writable($directory);
            }

            return $checkedDirectories[$directory];
        };

        foreach ([...array_keys($plan->replace), ...$plan->delete] as $path) {
            $absolute = $this->root . '/' . $path;

            if (!is_writable($absolute) || !$directoryOk(dirname($absolute))) {
                $problems[] = $path;
            }
        }

        foreach (array_keys($plan->add) as $path) {
            // The nearest directory that already exists is where the new
            // file's folders will be created.
            $directory = dirname($this->root . '/' . $path);
            while (!is_dir($directory) && strlen($directory) > strlen($this->root)) {
                $directory = dirname($directory);
            }

            if (!$directoryOk($directory)) {
                $problems[] = $path;
            }
        }

        if (!$directoryOk($this->root)) {
            $problems[] = Ownership::RELEASE_MANIFEST;
        }

        return $problems;
    }

    /** @param list<string> $paths */
    private static function listPaths(array $paths): string
    {
        $shown = array_slice($paths, 0, self::MAX_LISTED_PATHS);
        $more = count($paths) - count($shown);

        return implode(', ', $shown) . ($more > 0 ? ' (+' . $more . ')' : '');
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
