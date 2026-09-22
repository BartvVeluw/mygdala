<?php

declare(strict_types=1);

namespace App\Update\Build;

use App\Update\Ownership;
use App\Update\RelativePath;
use App\Update\UpdateException;

/**
 * The files a release is built from: relative path in the release => file
 * on disk. Only what Ownership says a release owns ever gets in, so a source
 * tree with a .env, uploads, tests or editor leftovers in it still builds a
 * clean release.
 *
 * Two sources are joined, because they come from two places on purpose:
 *
 *   the application   a directory holding one exact revision — normally a
 *                     `git archive` of a tag, so the file set is the
 *                     repository's and not a working copy's. Its line
 *                     endings do not matter: the builder ships every text
 *                     file with LF whatever the source has (LineEndings)
 *   vendor/           a `composer install --no-dev` made for this release,
 *                     since a shared host cannot run Composer itself
 *
 * with() and without() exist for the upgrade tests, which build a "next"
 * release from the current one with a file changed, added and removed.
 */
final class ReleaseSource
{
    /**
     * @param array<string, string> $files relative => absolute
     */
    private function __construct(
        private array $files
    ) {
    }

    /**
     * @param string|null $vendorDirectory a separate vendor/; null takes <root>/vendor
     */
    public static function fromDirectory(string $root, ?string $vendorDirectory = null): self
    {
        if (!is_dir($root)) {
            throw new UpdateException('update.error.package_invalid', [], 'Source directory does not exist: ' . $root);
        }

        $files = [];
        foreach (self::walk($root, ['vendor', '.git', 'node_modules', 'dist', '.claude']) as $relative => $absolute) {
            if ($relative !== Ownership::RELEASE_MANIFEST && Ownership::isShipped($relative)) {
                $files[$relative] = $absolute;
            }
        }

        $vendorDirectory ??= $root . '/vendor';
        if (!is_dir($vendorDirectory)) {
            throw new UpdateException('update.error.package_invalid', [], 'No vendor directory at ' . $vendorDirectory);
        }

        foreach (self::walk($vendorDirectory, []) as $relative => $absolute) {
            $files['vendor/' . $relative] = $absolute;
        }

        ksort($files, SORT_STRING);

        return new self($files);
    }

    /** @return array<string, string> */
    public function files(): array
    {
        return $this->files;
    }

    public function with(string $path, string $absolute): self
    {
        RelativePath::assertSafe($path);
        $copy = clone $this;
        $copy->files[$path] = $absolute;
        ksort($copy->files, SORT_STRING);

        return $copy;
    }

    public function without(string $path): self
    {
        $copy = clone $this;
        unset($copy->files[$path]);

        return $copy;
    }

    /**
     * @param list<string> $skipTopLevel
     *
     * @return \Generator<string, string>
     */
    private static function walk(string $root, array $skipTopLevel): \Generator
    {
        $base = rtrim(str_replace('\\', '/', $root), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $item) use ($base, $skipTopLevel): bool {
                    $relative = ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen($base)), '/');

                    return !$item->isLink() && !in_array($relative, $skipTopLevel, true);
                }
            )
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                yield ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen($base)), '/') => $item->getPathname();
            }
        }
    }
}
