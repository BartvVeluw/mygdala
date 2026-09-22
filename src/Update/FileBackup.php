<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Copies of exactly the Core files an update is about to replace or delete —
 * and nothing else. Uploads, .env and the rest of the installation are never
 * touched by an update, so they are not duplicated either
 * (docs/updates/ARCHITECTURE.md, "Back-up").
 *
 *     backups/<id>/files/<path>      the old content of each such file
 *     backups/<id>/release.json      the installed release's record
 *     backups/<id>/backup.json       what this backup is (manifest())
 *
 * The copies are what FileApplier::rollback() puts back when an update has to
 * be undone, and what a support engineer copies back by hand if even that
 * fails (docs/updates/RECOVERY.md). Each copy is hashed against the installed
 * release.json as it is made, so a backup is known to be the release, not
 * whatever happened to be on disk.
 *
 * Resumable like every long step: copy() stops at its time budget and returns
 * where to continue.
 */
final class FileBackup
{
    public const FILES = 'files';
    public const MANIFEST = 'backup.json';

    public function __construct(
        private readonly string $root,
        private readonly string $directory
    ) {
    }

    /**
     * @param list<string> $paths
     *
     * @return int|null index to continue from, or null when every file is copied
     *
     * @throws UpdateException
     */
    public function copy(array $paths, ReleaseDescriptor $installed, int $from, float $budgetSeconds): ?int
    {
        $started = microtime(true);
        $count = count($paths);

        if ($from === 0) {
            $this->copyFile($this->root . '/' . Ownership::RELEASE_MANIFEST, $this->directory . '/' . Ownership::RELEASE_MANIFEST);
        }

        for ($index = $from; $index < $count; $index++) {
            if ($index > $from && (microtime(true) - $started) >= $budgetSeconds) {
                return $index;
            }

            $path = RelativePath::assertSafe($paths[$index]);
            $target = $this->directory . '/' . self::FILES . '/' . $path;
            $this->copyFile($this->root . '/' . $path, $target);

            $expected = $installed->files[$path] ?? null;
            if ($expected !== null && !hash_equals($expected, (string) hash_file('sha256', $target))) {
                throw new UpdateException('update.error.backup_inconsistent', ['table' => $path], 'Backed-up copy of ' . $path . ' is not the installed release\'s');
            }
        }

        return null;
    }

    /**
     * Records what this backup is. Written last: a backup without it is an
     * unfinished one.
     *
     * @param array<string, mixed> $details
     */
    public function writeManifest(array $details): void
    {
        UpdateStateStore::writeAtomically(
            $this->directory . '/' . self::MANIFEST,
            json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }

    public function backedUpPath(string $path): string
    {
        return $this->directory . '/' . self::FILES . '/' . RelativePath::assertSafe($path);
    }

    public function releaseManifestPath(): string
    {
        return $this->directory . '/' . Ownership::RELEASE_MANIFEST;
    }

    private function copyFile(string $source, string $target): void
    {
        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => $directory], 'Cannot create ' . $directory);
        }

        if (!@copy($source, $target)) {
            throw new UpdateException('update.error.backup_failed', ['path' => basename($source)], 'Cannot copy ' . $source);
        }
    }
}
