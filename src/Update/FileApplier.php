<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Carries out an UpdatePlan on the live tree, in batches that each fit one
 * request, and undoes it.
 *
 * HOW A FILE IS REPLACED. The new content is first copied next to its
 * target, in the SAME directory (`<name>.mygdala-<id>.tmp`), and then renamed
 * over it. A rename within one directory is atomic on every file system a
 * shared host uses, so a visitor — or the next request of this update — sees
 * either the old file or the new one, never half of one. Staging may live on
 * another file system (it does in the Docker setup); that is why the copy
 * happens next to the target and not in staging. .htaccess keeps such a
 * temporary file from ever being served.
 *
 * BATCHES AND A CURSOR. The operations are a fixed list (operations(), a pure
 * function of the plan), and apply() runs them from a cursor the Updater keeps
 * in the update state: at most BATCH operations or the request's time budget,
 * then it returns where the next request goes on. Every operation is written
 * to the journal right after it succeeded and before the cursor can move past
 * it, so a request killed halfway leaves a journal that is ahead of the
 * cursor, never behind it. The next request skips what the journal records;
 * an operation that ran but did not live to be journaled simply runs again,
 * which is harmless: a write puts the same release bytes in place, a delete
 * finds nothing to delete.
 *
 * WHAT IS NOT ATOMIC, AND WHY THAT IS SAFE ENOUGH. A whole release cannot be
 * swapped in one rename on shared hosting: there is no symlinked "current"
 * directory to flip, and the site root is the document root. So while the
 * apply runs, some files are new and some are old. What keeps that harmless
 * (docs/updates/ARCHITECTURE.md, "Bestanden toepassen"):
 *
 *   1. the site is in maintenance — a visitor request stops in the guard,
 *      inside vendor/autoload.php, before it runs any code of the page;
 *   2. the batches change only what no request during maintenance can load
 *      or be served: templates, public assets, migrations, scripts, the
 *      libraries the updater does not use. What the updater and the few
 *      screens that stay open do load — src/, admin/, the update endpoints,
 *      Composer's autoloader, every file the updating request itself was
 *      made of, and the .htaccess rules they are served under (isRuntime())
 *      — changes in ONE last request, the code switch, which never stops
 *      halfway for its budget. So the Updates screen, the login and the next
 *      step always run on one consistent release;
 *   3. every step is journaled, the old content is in the backup, and
 *      release.json — the record of which release is installed — is written
 *      last of all. An apply that is interrupted is finished on "Doorgaan";
 *      one that fails is rolled back from the backup.
 *
 * Every written file is dropped from OPcache, so the next request compiles
 * the new code instead of running cached old bytecode.
 */
final class FileApplier
{
    public const JOURNAL = 'apply.journal';

    /** At most this many operations per request, before the code switch. */
    public const BATCH = 200;

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
     * Is $path something a request during maintenance can load, be served
     * or be served UNDER: the updater's own code, the screens that stay open
     * (MaintenanceGuard::EXEMPT) and everything they include, Composer's
     * autoloader, the files the updating request itself was made of
     * ($critical), and every .htaccess — a new rule, or one the host
     * rejects, must not meet the old screens halfway through the apply.
     * Those change together, in the code switch.
     *
     * @param list<string> $critical
     */
    public static function isRuntime(string $path, array $critical = []): bool
    {
        return in_array($path, $critical, true)
            || str_starts_with($path, 'src/')
            || str_starts_with($path, 'admin/')
            || str_starts_with($path, 'api/admin/updates-')
            || str_starts_with($path, 'vendor/composer/')
            || $path === 'vendor/autoload.php'
            || $path === '.htaccess'
            || str_ends_with($path, '/.htaccess');
    }

    /**
     * The operations the plan comes down to, in the order they run. The
     * order is a pure function of the plan and $critical, so a resumed apply
     * numbers them exactly as the interrupted one did:
     *
     *   batches       writes, then deletes, of everything that is not runtime
     *   code switch   the other writes (the request's own files last), their
     *                 deletes, emptied folders, VERSION, and release.json
     *
     * @param list<string> $critical files to write last (this request's own code)
     *
     * @return list<array{0: string, 1: string}> [action, path]
     */
    public static function operations(UpdatePlan $plan, array $critical = []): array
    {
        $writes = [...array_keys($plan->add), ...array_keys($plan->replace)];
        sort($writes, SORT_STRING);

        $early = [];
        $late = [];
        $last = [];
        foreach ($writes as $path) {
            if ($path === AppVersion::VERSION_FILE) {
                continue;
            }

            if (in_array($path, $critical, true)) {
                $last[] = $path;
            } elseif (self::isRuntime($path)) {
                $late[] = $path;
            } else {
                $early[] = $path;
            }
        }

        $operations = [];
        foreach ($early as $path) {
            $operations[] = ['write', $path];
        }
        foreach ($plan->delete as $path) {
            if (!self::isRuntime($path, $critical)) {
                $operations[] = ['delete', $path];
            }
        }
        foreach ([...$late, ...$last] as $path) {
            $operations[] = ['write', $path];
        }
        foreach ($plan->delete as $path) {
            if (self::isRuntime($path, $critical)) {
                $operations[] = ['delete', $path];
            }
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
     * Where the code switch starts in operations(): the first operation that
     * must run in the same request as everything after it.
     *
     * @param list<array{0: string, 1: string}> $operations
     * @param list<string>                      $critical
     */
    public static function switchPoint(array $operations, array $critical = []): int
    {
        foreach ($operations as $number => [$action, $path]) {
            if (!in_array($action, ['write', 'delete'], true) || $path === AppVersion::VERSION_FILE || self::isRuntime($path, $critical)) {
                return $number;
            }
        }

        return count($operations);
    }

    /**
     * Applies the operations from number $from on, skipping every one the
     * journal already records, and stops before the code switch once
     * $budgetSeconds or $batch operations have gone by — or right at the
     * switch, so the switch always starts a request of its own. Inside the
     * switch it never stops.
     *
     * @param list<string> $critical
     *
     * @return int|null the operation the next request goes on with; null when everything is done
     *
     * @throws UpdateException on the first operation that fails, or a journal
     *                         that does not match the plan; nothing is rolled
     *                         back here — that is rollback()'s job
     */
    public function apply(UpdatePlan $plan, array $critical = [], int $from = 0, float $budgetSeconds = 15.0, int $batch = self::BATCH): ?int
    {
        $operations = self::operations($plan, $critical);
        $switch = self::switchPoint($operations, $critical);
        $done = $this->completed($operations);

        // The cursor only moves past what was journaled; a gap means the
        // journal is not the one this cursor was written with.
        for ($number = 0; $number < $from; $number++) {
            if (!isset($done[$number])) {
                throw new UpdateException('update.error.apply_failed', ['path' => Ownership::RELEASE_MANIFEST], 'The apply journal lacks operation ' . $number . ' below the cursor ' . $from);
            }
        }

        $started = microtime(true);
        $ran = 0;

        for ($number = $from, $count = count($operations); $number < $count; $number++) {
            if ($ran > 0 && $number <= $switch
                && ($number === $switch || $ran >= $batch || microtime(true) - $started >= $budgetSeconds)
            ) {
                return $number;
            }

            if (isset($done[$number])) {
                continue;
            }

            [$action, $path] = $operations[$number];

            match ($action) {
                'write' => $this->install($this->stagingPath . '/' . $path, $path, $plan->add[$path] ?? $plan->replace[$path] ?? null),
                'delete' => $this->remove($path),
                'rmdir' => $this->removeDirectoryIfEmpty($path),
                'commit' => $this->install($this->stagingPath . '/' . Ownership::RELEASE_MANIFEST, Ownership::RELEASE_MANIFEST, null),
            };

            $this->record($number, $action, $path);
            $ran++;

            if ($this->afterOperation !== null) {
                ($this->afterOperation)($number);
            }
        }

        return null;
    }

    /**
     * Puts every file the plan touched back as the backup has it, removes
     * every file it added, and restores release.json last. Safe to run
     * again, and safe to run on a plan that was only partly applied — after
     * any number of batches, or a request that died halfway through one.
     *
     * @throws UpdateException when a file cannot be put back
     */
    public function rollback(UpdatePlan $plan): void
    {
        // First: once files start going back, no journal may let an apply
        // skip them as done.
        if (is_file($this->journalPath) && !@unlink($this->journalPath)) {
            throw new UpdateException('update.error.rollback_failed', ['path' => self::JOURNAL], 'Cannot remove the apply journal');
        }

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

        // A request killed between the copy and the rename left its
        // temporary file next to the target.
        foreach ([...array_keys($plan->add), ...array_keys($plan->replace), ...$plan->delete, Ownership::RELEASE_MANIFEST] as $path) {
            @unlink($this->temporaryPath($this->root . '/' . $path));
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
    }

    private function temporaryPath(string $target): string
    {
        return $target . '.mygdala-' . $this->tempSuffix . '.tmp';
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

        $temporary = $this->temporaryPath($target);

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

    /**
     * The operations the journal records as done, by number. Only complete
     * lines count — a line cut off by a killed request is not a record — and
     * every recorded operation must be the one the plan has at that number:
     * a journal that numbers another list is refused, not trusted.
     *
     * @param list<array{0: string, 1: string}> $operations
     *
     * @return array<int, true>
     *
     * @throws UpdateException
     */
    private function completed(array $operations): array
    {
        $contents = is_file($this->journalPath) ? (string) file_get_contents($this->journalPath) : '';

        // A line cut off by a killed request is dropped from the file too,
        // so the next record is not glued onto it.
        $complete = substr($contents, 0, (int) strrpos("\n" . $contents, "\n"));
        if ($complete !== $contents && @file_put_contents($this->journalPath, $complete, LOCK_EX) === false) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => dirname($this->journalPath)], 'Cannot repair the apply journal');
        }

        $lines = explode("\n", $complete);
        array_pop($lines);

        $done = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\d+) ([a-z]+) (\S+)$/', $line, $match) !== 1) {
                throw new UpdateException('update.error.apply_failed', ['path' => Ownership::RELEASE_MANIFEST], 'Unreadable apply journal line: ' . $line);
            }

            $number = (int) $match[1];
            if (($operations[$number] ?? null) !== [$match[2], $match[3]]) {
                throw new UpdateException('update.error.apply_failed', ['path' => $match[3]], 'The apply journal does not match the plan at operation ' . $number);
            }

            $done[$number] = true;
        }

        return $done;
    }

    private function record(int $number, string $action, string $path): void
    {
        if (@file_put_contents($this->journalPath, $number . ' ' . $action . ' ' . $path . "\n", FILE_APPEND | LOCK_EX) === false) {
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
