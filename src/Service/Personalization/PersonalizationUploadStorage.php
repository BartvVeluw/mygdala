<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use Dotenv\Dotenv;

/**
 * Stores validated customer personalization uploads genuinely OUTSIDE the
 * public webroot, exactly like App\Service\ContactAttachmentStorage does for
 * contact-request attachments — and for the same reason: an `.htaccess` deny
 * rule stops protecting anything the moment `AllowOverride`/mod_rewrite is
 * not honoured, and both this project's local document root and the Vimexx
 * deploy convention make the project root the site root. The default is
 * therefore a sibling of the project root, never a folder inside it, and can
 * be pointed anywhere writable with PERSONALIZATION_UPLOADS_PATH (.env).
 *
 * Two files are written per upload, both named from the upload's random
 * token — never from anything the customer supplied:
 *
 *   <token>.orig.<ext>     the untouched, validated original. This is what
 *                          the owner needs for production, and it is only
 *                          ever readable through the authenticated admin
 *                          endpoint (api/admin/order-personalization-file.php).
 *   <token>.preview.<ext>  a downscaled, GD RE-ENCODED copy. This is the only
 *                          file ever served to a browser (through
 *                          api/personalization-image.php, by token). Because
 *                          it is regenerated pixel data it cannot carry EXIF,
 *                          an embedded payload, or anything but an image.
 *
 * Nothing here ever accepts a path: the caller supplies a token and an
 * extension, and every read/delete resolves through path(), which rebuilds
 * the filename from the token itself. A traversal component therefore has
 * nothing to attach to.
 */
class PersonalizationUploadStorage
{
    private static bool $envLoaded = false;

    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        if ($storageDir !== null) {
            $this->storageDir = rtrim($storageDir, '/\\') . '/';
        } else {
            self::loadEnv();

            $configured = trim((string) ($_ENV['PERSONALIZATION_UPLOADS_PATH'] ?? ''));
            $base = $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__, 4) . '/storage';

            $this->storageDir = $base . '/personalization-uploads/';
        }

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    public function directory(): string
    {
        return $this->storageDir;
    }

    /**
     * The stored filename for one of an upload's files. Built from the token,
     * so it is by construction a plain name inside storageDir.
     *
     * 'composed' is the third kind of file this directory holds: the PNG the
     * browser composed of one personalization VIEW, exactly as the customer
     * saw it (see App\Service\Personalization\PersonalizationPreviewComposer).
     * It lives here rather than in its own folder because it needs exactly the
     * same protection as a customer upload — outside the webroot, reachable
     * only through an authenticated endpoint.
     *
     * @param 'original'|'preview'|'composed' $variant
     */
    public static function filenameFor(string $token, string $variant, string $extension): string
    {
        $infix = match ($variant) {
            'preview' => '.preview.',
            'composed' => '.composed.',
            default => '.orig.',
        };

        return $token . $infix . $extension;
    }

    /**
     * Writes one already-validated blob (a composed preview PNG) under the
     * token's own name. Deliberately separate from store(), which keeps its
     * move_uploaded_file() guarantee for the customer's own file.
     *
     * @throws \RuntimeException
     */
    public function storeComposed(string $token, string $bytes): string
    {
        $filename = self::filenameFor($token, 'composed', 'png');

        if (file_put_contents($this->storageDir . $filename, $bytes) === false) {
            throw new \RuntimeException('Het voorbeeld kon niet worden opgeslagen.');
        }

        chmod($this->storageDir . $filename, 0644);

        return $filename;
    }

    /**
     * Absolute filesystem path for a stored file. basename() strips any
     * directory component a corrupted database row could ever contain, so
     * this can only resolve to a file directly inside storageDir.
     */
    public function path(string $storedFilename): string
    {
        return $this->storageDir . basename($storedFilename);
    }

    /**
     * Moves an already-validated PHP upload into permanent storage and writes
     * the browser-facing preview beside it.
     *
     * @param string $tmpPath       the still-in-tmp uploaded file
     * @param string $previewBytes  the re-encoded preview produced by PersonalizationUploadValidator
     * @return array{stored_filename: string, preview_filename: string}
     * @throws \RuntimeException if either file cannot be written
     */
    public function store(string $token, string $tmpPath, string $extension, string $previewBytes): array
    {
        $storedFilename = self::filenameFor($token, 'original', $extension);
        $previewFilename = self::filenameFor($token, 'preview', $extension);

        $destination = $this->storageDir . $storedFilename;

        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new \RuntimeException('De afbeelding kon niet worden opgeslagen.');
        }

        chmod($destination, 0644);

        if (file_put_contents($this->storageDir . $previewFilename, $previewBytes) === false) {
            @unlink($destination);
            throw new \RuntimeException('De afbeelding kon niet worden opgeslagen.');
        }

        chmod($this->storageDir . $previewFilename, 0644);

        return ['stored_filename' => $storedFilename, 'preview_filename' => $previewFilename];
    }

    /**
     * Test/seed helper: the same as store() for a file that is not a PHP
     * upload. Deliberately separate so the production path keeps its
     * move_uploaded_file() guarantee and can never be handed an arbitrary
     * server-side path by a request.
     *
     * @return array{stored_filename: string, preview_filename: string}
     */
    public function storeBytes(string $token, string $originalBytes, string $extension, string $previewBytes): array
    {
        $storedFilename = self::filenameFor($token, 'original', $extension);
        $previewFilename = self::filenameFor($token, 'preview', $extension);

        if (file_put_contents($this->storageDir . $storedFilename, $originalBytes) === false
            || file_put_contents($this->storageDir . $previewFilename, $previewBytes) === false) {
            throw new \RuntimeException('De afbeelding kon niet worden opgeslagen.');
        }

        return ['stored_filename' => $storedFilename, 'preview_filename' => $previewFilename];
    }

    public function delete(?string $storedFilename): void
    {
        if ($storedFilename === null || $storedFilename === '') {
            return;
        }

        $path = $this->path($storedFilename);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        $root = dirname(__DIR__, 3);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
