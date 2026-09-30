<?php

declare(strict_types=1);

namespace App\Service\Theme;

/**
 * Where the Font Library's files live: `assets/fonts/library/`, under a name
 * this class makes up (32 random hex characters plus the verified format).
 *
 * WHY THERE. The fonts are served by the web server itself, as static
 * files, so the site never needs a PHP request (or a Google request) to
 * show text. The folder is INSTALLATION data: App\Update\Ownership lists it,
 * so the self-updater never overwrites, deletes or packages it; `.gitignore`
 * and App\Install\FreshSiteCopyPolicy keep one site's fonts out of git and
 * out of a new site. The only release file in it is its `.htaccess`: the
 * MIME types, `nosniff`, a year of immutable caching, and a refusal of
 * every name that is not a generated font name.
 *
 * WHY A GENERATED NAME. Nothing the browser sent becomes part of a path, a
 * second upload with the same original name cannot overwrite the first, and
 * a replaced variant gets a NEW name — so the year-long cache never serves
 * the old file (there is nothing to invalidate).
 *
 * This class only moves and deletes. Whether a file is a font is decided
 * before, by App\Service\Theme\FontFileInspector.
 */
final class FontStorage
{
    public const PUBLIC_PREFIX = 'assets/fonts/library/';

    /** What every stored name looks like; also what the `.htaccess` serves. */
    public const NAME_PATTERN = '/^[0-9a-f]{32}\.(woff2|woff|ttf|otf)$/';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory !== null
            ? rtrim($directory, '/\\') . '/'
            : dirname(__DIR__, 3) . '/' . self::PUBLIC_PREFIX;
    }

    /**
     * Moves a checked file into the library under a new name and returns
     * that name.
     *
     * @param bool $uploaded true for a PHP upload (move_uploaded_file), false
     *        for a file the caller made itself (tests)
     * @throws \RuntimeException
     */
    public function store(string $sourcePath, string $format, bool $uploaded = true): string
    {
        if (!array_key_exists($format, FontFileInspector::FORMATS)) {
            throw new \RuntimeException('Unknown font format: ' . $format);
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The font library folder cannot be created.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $format;
        $destination = $this->directory . $name;

        // Suppressed on purpose: a failed write (a folder the web user cannot
        // write to, a full disk) must become the clean message of the
        // caller, not a warning with a server path printed before a header.
        $moved = $uploaded ? @move_uploaded_file($sourcePath, $destination) : @copy($sourcePath, $destination);
        if (!$moved) {
            throw new \RuntimeException('The font file could not be stored.');
        }

        @chmod($destination, 0644);

        return $name;
    }

    /**
     * Deletes one stored file. A no-op for anything that is not a generated
     * name, so a corrupted row can never remove anything else.
     */
    public function delete(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return;
        }

        $path = $this->directory . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1 && is_file($this->directory . $name);
    }

    /** The root-relative URL a stylesheet loads the file from. */
    public static function url(string $name): string
    {
        return '/' . self::PUBLIC_PREFIX . $name;
    }
}
