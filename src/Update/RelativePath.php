<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The one test every path in a release has to pass before the updater acts
 * on it: a ZIP entry, a line in release.json, a file to back up or delete.
 *
 * A whitelist, not a blacklist. Every file this project ships — its own and
 * every Composer package — is named with ASCII letters, digits and `._-@+~`,
 * so that is the whole alphabet. That one rule already rules out the
 * classic ZIP-slip spellings (`../`, `..\`, `C:`, a leading `/`, a NUL byte,
 * an NTFS stream after `:`), without this class having to enumerate them.
 * On top of it, segment by segment:
 *
 *   - no empty segment (`a//b`), no `.` and no `..`;
 *   - no segment ending in a dot: Windows strips it, so `evil.php.` would
 *     land on `evil.php` there while being a different name here;
 *   - a bounded length, so a path that fits in the package also fits on a
 *     shared host's file system.
 *
 * Case-insensitive duplicates are a PACKAGE property (two entries, one
 * file on Windows or macOS), so PackageValidator checks those, not this.
 */
final class RelativePath
{
    private const SEGMENT = '/^[A-Za-z0-9._@+~-]+$/';

    private const MAX_LENGTH = 300;

    public static function isSafe(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_LENGTH) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }

            if (preg_match(self::SEGMENT, $segment) !== 1 || str_ends_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws UpdateException when the path could point anywhere but inside
     *                         the directory it is meant for
     */
    public static function assertSafe(string $path): string
    {
        if (!self::isSafe($path)) {
            throw new UpdateException(
                'update.error.unsafe_path',
                ['path' => self::printable($path)],
                'Unsafe relative path: ' . self::printable($path)
            );
        }

        return $path;
    }

    /** The path as it may be shown in a message: control bytes made visible. */
    public static function printable(string $path): string
    {
        $visible = preg_replace_callback(
            '/[^\x20-\x7e]/',
            static fn (array $byte): string => sprintf('\\x%02x', ord($byte[0])),
            $path
        );

        return mb_strimwidth((string) $visible, 0, 200, '…');
    }

    /** "a/b/c.php" → ["a", "a/b"]: every directory the file sits in, outermost first. */
    public static function parents(string $path): array
    {
        $parents = [];
        $segments = explode('/', $path);
        array_pop($segments);

        $current = '';
        foreach ($segments as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $parents[] = $current;
        }

        return $parents;
    }
}
