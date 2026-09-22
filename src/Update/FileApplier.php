<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Carries out an UpdatePlan on the live tree, and undoes it.
 *
 * HOW A FILE IS REPLACED. The new content is first copied next to its
 * target, in the SAME directory (`<name>.mygdala-<id>.tmp`), and then renamed
 * over it. A rename within one directory is atomic on every file system a
 * shared host uses, so a visitor — or the next request of this update — sees
 * either the old file or the new one, never half of one. Staging may live on
 * another file system (it does in the Docker setup); that is why the copy
 * happens next to the target and not in staging.
 *
 * WHAT IS NOT ATOMIC, AND WHY THAT IS SAFE ENOUGH. A whole release cannot be
 * swapped in one rename on shared hosting: there is no symlinked
 * "current" directory to flip, and the site root is the document root. So
 * for the seconds `apply` takes, some files are new and some are old. Three
 * things make that window harmless (docs/updates/ARCHITECTURE.md,
 * "Atomiciteit"):
 *
 *   1. the site is in maintenance — no visitor request runs code in it;
 *   2. the whole apply happens in ONE request, with every class this request
 *      needs loaded before the first file moves, and the files this very
 *      request is made of written LAST (critical files, below);
 *   3. every step is journaled before it is taken, the old content is in
 *      the backup, and release.json — the record of which release is
 *      installed — is written last of all. An apply that is interrupted is
 *      finished on "Doorgaan"; one that fails is rolled back from the backup.
 *
 * Every written file is dropped from OPcache, so the next request compiles
 * the new code instead of running cached old bytecode.
 */
final class FileApplier
{
    public const JOURNAL = 'apply.journal';

    /** Test seam: called after every completed operation, with its number. */
    private ?\Closure $afterOperation = null;

    public function __construct(
        private readonly string $root,
        private readonly string $stagingPath,
        private readonly FileBackup $backup,
        private readonly string $journalPath,
        private readonly string $tempSuffix
    ) {
    }

    public function onEachOperation(?\Closure $hook): void
    {
        $this->afterOperation = $hook;
    }

    /**
     * The operations the plan comes down to, in the order they run. The
     * order is a pure function of the plan, so a resumed apply numbers them
     * exactly as the interrupted one did.
     *
     * @param list<string> $critical files to write last (this request's own code)
     *
     * @return list<array{0: string, 1: string}> [action, path]
     */
    public static function operations(UpdatePlan $plan, array $critical = []): array
    {
        $writes = [...array_keys($plan->add), ...array_keys($plan->replace)];
        sort($writes, SORT_STRING);

        $late = [];
        $early = [];
        foreach ($writes as $path) {
            if ($path === AppVersion::VERSION_FILE) {
                continue;
            }
            $isCritical = in_array($path, $critical, true)
                || str_starts_with($path, 'src/Update/')
                || str_starts_with($path, 'vendor/composer/')
                || $path === 'vendor/autoload.php';

            if ($isCritical) {
                $late[] = $path;
            } else {
                $early[] = $path;
            }
        }

        $operations = [];
        foreach ([...$early, ...$late] as $path) {
            $operations[] = ['write', $path];
        }
        foreach ($plan->delete as $path) {
            $operations[] = ['delete', $path];
        }
        foreach ($plan->emptiedDirectories as $directory) {
            $operations[] = ['rmdir', $directory];
        }
        if (isset($plan->add[AppVersion::VERSION_FILE]) || isset($plan->replace[AppVersion::VERSION_FILE])) {
            $operations[] = ['write', AppVersion::VERSION_FILE];
        }
        $operations[] = ['commit', Ownership::RELEASE_MANIFEST];

        return $operations;
    }

    /**
     * Applies every operation the journal does not already record as done.
     *
     * @param list<string> $critical
     *
     * @throws UpdateException on the first operation that fails; nothing is
     *                         rolled back here — that is rollback()'s job
     */
    public function apply(UpdatePlan $plan, array $critical = []): void
    {
        $done = $this->completed();

        foreach (self::operations($plan, $critical) as $number => [$action, $path]) {
            if (isset($done[$number])) {
                continue;
            }

            match ($action) {
                'write' => $this->install($this->stagingPath . '/' . $path, $path, $plan->add[$path] ?? $plan->replace[$path] ?? null),
                'delete' => $this->remove($path),
                'rmdir' => $this->removeDirectoryIfEmpty($path),
                'commit' => $this->install($this->stagingPath . '/' . Ownership::RELEASE_MANIFEST, Ownership::RELEASE_MANIFEST, null),
            };

            $this->record($number);

            if ($this->afterOperation !== null) {
                ($this->afterOperation)($number);
            }
        }
    }

    /**
     * Puts every file the plan touched back as the backup has it, removes
     * every file it added, and restores release.json last. Safe to run
     * again, and safe to run on a plan that was only partly applied.
     *
     * @throws UpdateException when a file cannot be put back
     */
    public function rollback(UpdatePlan $plan): void
    {
        foreach (array_reverse($plan->delete) as $path) {
            $this->install($this->backup->backedUpPath($path), $path, null);
        }

        foreach (array_keys($plan->replace) as $path) {
            $this->install($this->backup->backedUpPath($path), $path, null);
        }

        foreach (array_keys($plan->add) as $path) {
            $absolute = $this->root . '/' . $path;
            if (is_file($absolute) && !@unlink($absolute)) {
                throw new UpdateException('update.error.rollback_failed', ['path' => $path], 'Cannot remove added file ' . $path);
            }
            self::invalidate($absolute);
        }

        // Folders the apply created for added files, deepest first.
        $created = [];
        foreach (array_keys($plan->add) as $path) {
            foreach (RelativePath::parents($path) as $parent) {
                $created[$parent] = substr_count($parent, '/');
            }
        }
        arsort($created);
        foreach (array_keys($created) as $directory) {
            $this->removeDirectoryIfEmpty($directory);
        }

        $this->install($this->backup->releaseManifestPath(), Ownership::RELEASE_MANIFEST, null);
        @unlink($this->journalPath);
    }

    /** Copies $source next to $path under a temporary name, then renames it into place. */
    private function install(string $source, string $path, ?string $expectedHash): void
    {
        $target = $this->root . '/' . RelativePath::assertSafe($path);
        $directory = dirname($target);

        if (!is_file($source)) {
            throw new UpdateException('update.error.apply_failed', ['path' => $path], 'Source missing for ' . $path);
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UpdateException('update.error.apply_failed', ['path' => $path], 'Cannot create the folder for ' . $path);
        }

        $temporary = $target . '.mygdala-' . $this->tempSuffix . '.tmp';

        if (!@copy($source, $temporary)) {
            @unlink($temporary);
            throw new UpdateException('update.error.apply_failed', ['path' => $path], 'Cannot write next to ' . $path);
        }

        if ($expectedHash !== null && !hash_equals($expectedHash, (string) hash_file('sha256', $temporary))) {
            @unlink($temporary);
            throw new UpdateException('update.error.apply_failed', ['path' => $path], 'Copied content of ' . $path . ' does not match the release');
        }

        if (is_file($target)) {
            @chmod($temporary, fileperms($target) & 0777);
        }

        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new UpdateException('update.error.apply_failed', ['path' => $path], 'Cannot move ' . $path . ' into place');
        }

        self::invalidate($target);
    }

    private function remove(string $path): void
    {
        $target = $this->root . '/' . RelativePath::assertSafe($path);

        if (is_file($target) && !@unlink($target)) {
            throw new UpdateException('update.error.apply_failed', ['path' => $path], 'Cannot delete ' . $path);
        }

        self::invalidate($target);
    }

    private function removeDirectoryIfEmpty(string $directory): void
    {
        $absolute = $this->root . '/' . RelativePath::assertSafe($directory);

        if (is_dir($absolute) && (scandir($absolute) ?: []) === ['.', '..']) {
            @rmdir($absolute);
        }
    }

    /** @return array<int, true> */
    private function completed(): array
    {
        if (!is_file($this->journalPath)) {
            return [];
        }

        $done = [];
        foreach (file($this->journalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (ctype_digit($line)) {
                $done[(int) $line] = true;
            }
        }

        return $done;
    }

    private function record(int $number): void
    {
        if (@file_put_contents($this->journalPath, $number . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => dirname($this->journalPath)], 'Cannot write the apply journal');
        }
    }

    private static function invalidate(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
