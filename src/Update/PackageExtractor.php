<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Unpacks a VERIFIED release package into a staging directory, entry by
 * entry, and never anywhere else.
 *
 * ZipArchive::extractTo() is deliberately not used: it trusts the names in
 * the archive. Here every entry name passes RelativePath's whitelist before
 * a byte is written, so `../index.php`, `/etc/passwd`, `C:\…` or a name with
 * a NUL byte cannot even be spelled (ZIP slip). On top of that:
 *
 *   - symbolic links are refused outright (a link inside staging pointing
 *     at the live tree would turn "write into staging" into "write into the
 *     site"); so is any entry that is not a plain file or directory;
 *   - two entries that are the same file on a case-insensitive file system
 *     (Windows, macOS) are refused as a whole package;
 *   - the number of entries and the total unpacked size are capped, so a
 *     crafted archive cannot fill the disk (a "zip bomb");
 *   - each file is streamed out and its size checked against the archive's
 *     own directory.
 *
 * The package was hashed against the signed manifest before this runs
 * (ReleasePackage::verify()), so what is unpacked is what the release
 * builder packed. Checking that it is a complete, consistent release is the
 * next step's job (PackageValidator).
 *
 * Extraction can be spread over several requests: extract() stops when its
 * time budget is spent and returns where to continue.
 */
final class PackageExtractor
{
    public const MAX_ENTRIES = 50000;

    public const MAX_UNPACKED_BYTES = 1073741824;

    private const S_IFMT = 0170000;
    private const S_IFREG = 0100000;
    private const S_IFDIR = 0040000;

    public function __construct(
        private readonly string $packagePath,
        private readonly string $stagingPath
    ) {
    }

    /**
     * Reads the whole archive directory and refuses the package if any entry
     * is unacceptable — BEFORE the first file is written.
     *
     * @throws UpdateException
     */
    public function inspect(): int
    {
        $zip = $this->open();

        try {
            $count = $zip->numFiles;

            if ($count < 1 || $count > self::MAX_ENTRIES) {
                throw new UpdateException('update.error.package_corrupt', [], 'Archive has ' . $count . ' entries');
            }

            $total = 0;
            $seen = [];

            for ($index = 0; $index < $count; $index++) {
                [$name, $isDirectory, $size] = $this->entry($zip, $index);

                $total += $size;
                if ($total > self::MAX_UNPACKED_BYTES) {
                    throw new UpdateException('update.error.package_too_large', ['size' => $total], 'Unpacked size exceeds the limit');
                }

                $folded = strtolower($name) . ($isDirectory ? '/' : '');
                if (isset($seen[$folded])) {
                    throw new UpdateException('update.error.package_unsafe', ['path' => $name], 'Duplicate entry (case-insensitive): ' . $name);
                }
                $seen[$folded] = true;
            }

            return $count;
        } finally {
            $zip->close();
        }
    }

    /**
     * Unpacks entries from $fromIndex on until done or out of time.
     *
     * @return int|null the index to continue from, or null when finished
     *
     * @throws UpdateException
     */
    public function extract(int $fromIndex, float $budgetSeconds): ?int
    {
        $zip = $this->open();
        $started = microtime(true);

        try {
            if (!is_dir($this->stagingPath) && !@mkdir($this->stagingPath, 0775, true) && !is_dir($this->stagingPath)) {
                throw new UpdateException('update.error.storage_not_writable', ['path' => $this->stagingPath], 'Cannot create staging');
            }

            for ($index = $fromIndex; $index < $zip->numFiles; $index++) {
                if ($index > $fromIndex && (microtime(true) - $started) >= $budgetSeconds) {
                    return $index;
                }

                [$name, $isDirectory, $size] = $this->entry($zip, $index);
                $target = $this->stagingPath . '/' . $name;

                if ($isDirectory) {
                    $this->makeDirectory($target);
                    continue;
                }

                $this->makeDirectory(dirname($target));
                $this->writeEntry($zip, $index, $name, $target, $size);
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private function open(): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new UpdateException('update.error.zip_missing', [], 'ext-zip is not available');
        }

        $zip = new \ZipArchive();
        $result = $zip->open($this->packagePath, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS);

        if ($result !== true) {
            throw new UpdateException('update.error.package_corrupt', [], 'ZipArchive::open() failed with code ' . (is_int($result) ? $result : '?'));
        }

        return $zip;
    }

    /**
     * @return array{0: string, 1: bool, 2: int} safe relative name, is directory, unpacked size
     */
    private function entry(\ZipArchive $zip, int $index): array
    {
        $stat = $zip->statIndex($index);

        if ($stat === false) {
            throw new UpdateException('update.error.package_corrupt', [], 'Unreadable entry ' . $index);
        }

        $name = (string) $stat['name'];
        $isDirectory = str_ends_with($name, '/');
        $path = $isDirectory ? substr($name, 0, -1) : $name;

        if (!RelativePath::isSafe($path)) {
            throw new UpdateException('update.error.package_unsafe', ['path' => RelativePath::printable($name)], 'Unsafe entry name: ' . RelativePath::printable($name));
        }

        $operatingSystem = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes) && $operatingSystem === \ZipArchive::OPSYS_UNIX) {
            $type = ($attributes >> 16) & self::S_IFMT;

            if ($type !== 0 && $type !== ($isDirectory ? self::S_IFDIR : self::S_IFREG)) {
                throw new UpdateException('update.error.package_unsafe', ['path' => $path], 'Not a plain file or directory (link?): ' . $path);
            }
        }

        return [$path, $isDirectory, (int) $stat['size']];
    }

    private function writeEntry(\ZipArchive $zip, int $index, string $name, string $target, int $size): void
    {
        $source = $zip->getStreamIndex($index);
        if ($source === false) {
            throw new UpdateException('update.error.package_corrupt', [], 'Cannot read entry ' . $name);
        }

        $output = @fopen($target, 'wb');
        if ($output === false) {
            fclose($source);
            throw new UpdateException('update.error.storage_not_writable', ['path' => $this->stagingPath], 'Cannot write ' . $name);
        }

        $written = stream_copy_to_stream($source, $output, $size + 1);
        fclose($source);
        fclose($output);

        if ($written !== $size) {
            @unlink($target);
            throw new UpdateException('update.error.package_corrupt', [], sprintf('Entry %s unpacked to %s bytes, expected %d', $name, var_export($written, true), $size));
        }
    }

    private function makeDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new UpdateException('update.error.package_unsafe', ['path' => basename($directory)], 'Staging contains a link: ' . $directory);
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => $this->stagingPath], 'Cannot create ' . $directory);
        }
    }
}
