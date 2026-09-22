<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Works out the UpdatePlan from two release.json files and the disk.
 *
 * The rule, in one sentence: the new release's file list is the complete
 * truth about what a release owns, so every path it names is added or
 * replaced, and every path the INSTALLED release named that the new one no
 * longer does is deleted. Nothing outside those two lists is ever touched.
 *
 * It relies on LocalChanges having run first: a replaced file's current
 * content is known to be the installed release's, so comparing the two
 * release.json hashes is enough, and only a path new to the release has to
 * be hashed on disk (to tell "already there, identical" from a conflict).
 */
final class UpdatePlanner
{
    public function __construct(
        private readonly string $root
    ) {
    }

    public function plan(ReleaseDescriptor $installed, ReleaseDescriptor $target): UpdatePlan
    {
        $add = [];
        $replace = [];
        $conflicts = [];
        $unchanged = 0;

        foreach ($target->files as $path => $hash) {
            self::assertReleaseOwned($path);

            if (isset($installed->files[$path])) {
                if ($installed->files[$path] === $hash) {
                    $unchanged++;
                } else {
                    $replace[$path] = $hash;
                }
                continue;
            }

            $absolute = $this->root . '/' . $path;

            if (is_dir($absolute)) {
                $conflicts[] = $path;
            } elseif (!is_file($absolute)) {
                $add[$path] = $hash;
            } elseif (hash_equals($hash, (string) hash_file('sha256', $absolute))) {
                // Already there with exactly this content (an earlier manual
                // upload, say): nothing to write, and the new release.json
                // adopts it.
                $unchanged++;
            } else {
                $conflicts[] = $path;
            }
        }

        $delete = [];
        foreach (array_keys($installed->files) as $path) {
            self::assertReleaseOwned($path);

            if (!isset($target->files[$path]) && is_file($this->root . '/' . $path)) {
                $delete[] = $path;
            }
        }

        return new UpdatePlan(
            $add,
            $replace,
            $delete,
            $conflicts,
            $this->preserved($installed, $target),
            $unchanged,
            self::emptiedDirectories($delete, $target)
        );
    }

    /**
     * Directories that might be left empty by the deletes and hold nothing
     * of the new release. FileApplier removes each only if it really is
     * empty, deepest first — an error_log or an upload in it keeps it.
     *
     * @param list<string> $delete
     *
     * @return list<string>
     */
    private static function emptiedDirectories(array $delete, ReleaseDescriptor $target): array
    {
        $stillUsed = [];
        foreach (array_keys($target->files) as $path) {
            foreach (RelativePath::parents($path) as $parent) {
                $stillUsed[$parent] = true;
            }
        }

        $candidates = [];
        foreach ($delete as $path) {
            foreach (RelativePath::parents($path) as $parent) {
                if (!isset($stillUsed[$parent]) && !Ownership::isInstallationOwned($parent . '/x')) {
                    $candidates[$parent] = substr_count($parent, '/');
                }
            }
        }

        arsort($candidates);

        return array_keys($candidates);
    }

    /**
     * What stays exactly as it is, for the screen: the installation's own
     * directories and files that exist here, plus anything on disk under a
     * release directory that no release lists (host logs, stray uploads).
     *
     * @return array<string, int> label => number of files
     */
    private function preserved(ReleaseDescriptor $installed, ReleaseDescriptor $target): array
    {
        $preserve = [];

        foreach (Ownership::installationFiles() as $file) {
            if (is_file($this->root . '/' . $file)) {
                $preserve[$file] = 1;
            }
        }

        foreach (Ownership::installationDirectories() as $directory) {
            if (is_dir($this->root . '/' . $directory)) {
                $preserve[$directory . '/'] = self::countFiles($this->root . '/' . $directory, 100000);
            }
        }

        $owned = $installed->files + $target->files;
        $unlisted = 0;
        foreach (['admin', 'api', 'src', 'partials', 'db', 'assets/css', 'assets/js'] as $directory) {
            if (!is_dir($this->root . '/' . $directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $this->root))), '/');
                if ($file->isFile() && !isset($owned[$relative])) {
                    $unlisted++;
                }
            }
        }

        if ($unlisted > 0) {
            $preserve['unlisted'] = $unlisted;
        }

        return $preserve;
    }

    private static function countFiles(string $directory, int $limit): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && ++$count >= $limit) {
                break;
            }
        }

        return $count;
    }

    private static function assertReleaseOwned(string $path): void
    {
        RelativePath::assertSafe($path);

        if (!Ownership::isShipped($path)) {
            throw new UpdateException('update.error.package_invalid', [], 'A release may not own ' . $path);
        }
    }
}
