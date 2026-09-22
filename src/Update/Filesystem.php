<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The one recursive delete the updater has, and the one place that may call
 * it: the updater's OWN directories (work, staging, old backups), always
 * built from UpdateStateStore paths. Never a path from a plan, a manifest or
 * a request — a live Core file is only ever removed by FileApplier, one
 * planned path at a time.
 *
 * Links are unlinked, never followed.
 */
final class Filesystem
{
    public static function remove(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            @unlink($directory);

            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
