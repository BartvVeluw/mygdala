<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

use Dotenv\Dotenv;

/**
 * The private files of "Afbeelding uploaden" order questions, genuinely
 * OUTSIDE the webroot — the same place and the same reasoning as
 * App\Service\ContactAttachmentStorage and the Personalisatie uploads: the
 * project root is the site root (locally and on Vimexx), and an .htaccess
 * deny stops protecting anything the moment it is not honoured. The default
 * is `storage/order-field-uploads/` one level above the project root (the
 * Docker volume at /var/www/storage), and ORDER_FIELD_UPLOADS_PATH (.env)
 * moves that base (the folder that holds `order-field-uploads/`) anywhere
 * else that is writable and not served, like PERSONALIZATION_UPLOADS_PATH.
 *
 * Two files per picture, both named from a random STORAGE NAME that has
 * nothing to do with the customer's filename or with the browser's token:
 *
 *   <name>.orig.<ext>    the customer's file, untouched (what the workshop
 *                        needs); only ever read by api/admin/order-field-upload.php
 *   <name>.thumb.<ext>   a small copy RE-ENCODED by GD, so it is pixels and
 *                        nothing else (no EXIF, no payload): the order
 *                        screen's thumbnail
 *
 * Nothing here takes a path: every read and delete rebuilds the filename
 * from the storage name (which must be 32 hex characters) and the extension
 * (one of OrderFieldUploadPolicy::FORMATS), so a request or a damaged row
 * has nothing to put a directory into.
 */
class OrderFieldUploadStorage
{
    public const ORIGINAL = 'orig';
    public const THUMBNAIL = 'thumb';

    private static bool $envLoaded = false;

    private string $storageDir;

    /** @var \Closure(string, string): bool */
    private \Closure $move;

    /**
     * @param string|null                           $storageDir a test's own directory; production resolves it below
     * @param (\Closure(string, string): bool)|null $move       move_uploaded_file(); a test hands in its own
     */
    public function __construct(?string $storageDir = null, ?\Closure $move = null)
    {
        $this->move = $move ?? static fn (string $from, string $to): bool => move_uploaded_file($from, $to);

        if ($storageDir !== null) {
            $this->storageDir = rtrim($storageDir, '/\\') . '/';
        } else {
            self::loadEnv();

            $configured = trim((string) ($_ENV['ORDER_FIELD_UPLOADS_PATH'] ?? getenv('ORDER_FIELD_UPLOADS_PATH') ?: ''));
            $base = $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__, 4) . '/storage';
            $this->storageDir = $base . '/order-field-uploads/';
        }

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    public function directory(): string
    {
        return $this->storageDir;
    }

    public static function newStorageName(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The file of a stored picture, or null when the name or the extension is
     * not one this storage could ever have written.
     *
     * @param self::ORIGINAL|self::THUMBNAIL $kind
     */
    public function path(string $storageName, string $extension, string $kind): ?string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $storageName) !== 1
            || !in_array($extension, array_column(OrderFieldUploadPolicy::FORMATS, 0), true)
            || !in_array($kind, [self::ORIGINAL, self::THUMBNAIL], true)) {
            return null;
        }

        return $this->storageDir . $storageName . '.' . $kind . '.' . $extension;
    }

    /**
     * Moves a validated PHP upload into place and writes its thumbnail beside
     * it. Either both files are there afterwards, or neither.
     *
     * @throws \RuntimeException
     */
    public function store(string $storageName, string $extension, string $tmpPath, string $thumbnailBytes): void
    {
        $original = $this->path($storageName, $extension, self::ORIGINAL);
        $thumbnail = $this->path($storageName, $extension, self::THUMBNAIL);
        if ($original === null || $thumbnail === null) {
            throw new \RuntimeException('Invalid storage name.');
        }

        if (!($this->move)($tmpPath, $original)) {
            throw new \RuntimeException('The upload could not be moved into storage.');
        }
        @chmod($original, 0640);

        if (file_put_contents($thumbnail, $thumbnailBytes) === false) {
            @unlink($original);
            throw new \RuntimeException('The thumbnail could not be written.');
        }
        @chmod($thumbnail, 0640);
    }

    /** Removes both files of a picture; a file that is already gone is fine. */
    public function delete(string $storageName, string $extension): void
    {
        foreach ([self::ORIGINAL, self::THUMBNAIL] as $kind) {
            $path = $this->path($storageName, $extension, $kind);
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function exists(string $storageName, string $extension): bool
    {
        $path = $this->path($storageName, $extension, self::ORIGINAL);

        return $path !== null && is_file($path);
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        $root = dirname(__DIR__, 3);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }
    }
}
