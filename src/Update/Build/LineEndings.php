<?php

declare(strict_types=1);

namespace App\Update\Build;

/**
 * The line-ending contract of a release: which repository files are text and
 * ship with LF only, and which are binary and ship byte for byte.
 * .gitattributes is the same contract for Git — every checkout and every
 * `git archive` — and Tests\Update\LineEndingsTest keeps the two lists equal,
 * so a new file type is one line in each.
 *
 * Why the builder applies it too, when Git already does: a release must not
 * depend on where it was built. Its source can be a Windows working copy
 * (core.autocrlf), an archive from a Git that did not read these attributes,
 * or a file an editor saved with CRLF; built from any of them, a release has
 * the bytes of the repository's own blobs. v0.1.0 was built from a
 * `git archive` under core.autocrlf=true, before this contract existed, and
 * shipped every text file with CRLF.
 *
 * Only the repository's own files. vendor/ is Composer's output and keeps its
 * upstream bytes: dompdf ships font metrics with CRLF and fonts that are
 * binary, and neither is this repository's to rewrite.
 */
final class LineEndings
{
    public const TEXT = 'text';
    public const BINARY = 'binary';
    public const VENDOR = 'vendor';

    /** Text by extension: `*.php text eol=lf` in .gitattributes. */
    public const TEXT_EXTENSIONS = [
        'php', 'js', 'css', 'json', 'lock', 'md', 'txt', 'xml', 'yml', 'yaml',
        'svg', 'ini', 'conf', 'sh', 'py', 'example',
    ];

    /** Text by file name, in any directory: `.htaccess text eol=lf`. */
    public const TEXT_NAMES = ['.htaccess', '.gitignore', '.gitattributes', '.gitkeep', 'VERSION', 'Dockerfile'];

    /** Binary by extension: `*.png binary`. */
    public const BINARY_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico',
        'woff', 'woff2', 'ttf', 'otf', 'pdf',
    ];

    /**
     * TEXT, BINARY or VENDOR; null for a file type the contract does not
     * know, which the builder refuses rather than guess about. Matching is
     * case-sensitive, as Git's is on Linux: `LOGO.PNG` is not a `*.png`.
     */
    public static function classify(string $path): ?string
    {
        if (str_starts_with($path, 'vendor/')) {
            return self::VENDOR;
        }

        $name = basename($path);
        if (in_array($name, self::TEXT_NAMES, true)) {
            return self::TEXT;
        }

        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }

        $extension = substr($name, $dot + 1);
        if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
            return self::TEXT;
        }

        return in_array($extension, self::BINARY_EXTENSIONS, true) ? self::BINARY : null;
    }

    /**
     * A text file's release bytes: every CRLF becomes LF, as Git's own
     * normalization does. Null when a carriage return is left that ends no
     * line — a stray byte Git would keep and a release will not guess about.
     */
    public static function canonicalText(string $bytes): ?string
    {
        $text = str_replace("\r\n", "\n", $bytes);

        return str_contains($text, "\r") ? null : $text;
    }
}
