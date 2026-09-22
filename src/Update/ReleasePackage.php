<?php

declare(strict_types=1);

namespace App\Update;

/**
 * A downloaded release package: the ZIP on disk plus the signed manifest
 * that promised what it would be.
 *
 * verify() is the gate between "a file arrived" and "a file may be opened".
 * Nothing reads a byte out of the archive before the size and the SHA-256 in
 * the manifest match — so a truncated download, a mirror serving the wrong
 * build, or a package swapped after the manifest was signed all stop here,
 * before the updater has changed anything (docs/updates/RECOVERY.md,
 * "Download of verificatie mislukt").
 *
 * Extraction is PackageExtractor's, validation of what came out is
 * PackageValidator's: three steps with three different failure modes, kept
 * apart so each can refuse on its own terms.
 */
final class ReleasePackage
{
    public function __construct(
        public readonly string $path,
        public readonly ReleaseManifest $manifest
    ) {
    }

    /**
     * @throws UpdateException
     */
    public function verify(): void
    {
        clearstatcache(true, $this->path);

        if (!is_file($this->path)) {
            throw new UpdateException('update.error.package_missing', [], 'No package at ' . basename($this->path));
        }

        $size = (int) filesize($this->path);

        if ($size !== $this->manifest->size) {
            throw new UpdateException(
                'update.error.package_size_mismatch',
                ['expected' => $this->manifest->size, 'actual' => $size],
                sprintf('Package is %d bytes, the manifest says %d', $size, $this->manifest->size)
            );
        }

        $hash = (string) hash_file('sha256', $this->path);

        if (!hash_equals($this->manifest->sha256, $hash)) {
            throw new UpdateException(
                'update.error.package_hash_mismatch',
                [],
                sprintf('Package SHA-256 is %s, the manifest says %s', $hash, $this->manifest->sha256)
            );
        }
    }
}
