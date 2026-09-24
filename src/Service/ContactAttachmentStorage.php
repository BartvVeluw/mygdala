<?php

namespace App\Service;

use Dotenv\Dotenv;

/**
 * Stores every file a form accepts (App\Service\Forms\FormUploadInspector
 * does the type and size checking, App\Service\Forms\FormSubmissionHandler
 * calls this), and still serves the older contact_requests archive's files,
 * genuinely outside the public webroot — not merely
 * behind an .htaccess deny rule, since that stops protecting anything the
 * moment AllowOverride/mod_rewrite isn't honoured. Both this project's
 * document root (confirmed locally: Apache's DocumentRoot is /var/www/html,
 * i.e. the project root itself — see docker/apache-app.conf) and the
 * Vimexx deploy convention (README: "upload the folder via SSH/FTP", so the
 * project root becomes the site root there too) mean a folder *inside* the
 * project root is always inside the webroot. So the default here is one
 * directory *above* the project root instead — a true sibling the web
 * server never serves from, regardless of .htaccess.
 *
 * The path is also configurable via CONTACT_ATTACHMENTS_PATH (.env), for
 * the (expected-safe-but-not-yet-SSH-verified — deployment hasn't happened
 * yet, see MAIN.MD) case where Vimexx's actual account layout doesn't allow
 * writing one level up: point it at any other writable, non-web-served
 * absolute path without a code change. Local Docker gets the equivalent via
 * a dedicated named volume mounted at /var/www/storage (docker-compose.yml)
 * — also outside /var/www/html, and persisted across container recreation.
 *
 * Mirrors ProductImageUploader's random-filename convention, but never
 * exposes the stored path to the browser — attachments are only readable
 * through the authenticated admin download endpoint
 * (api/admin/contact-request-attachment.php).
 */
class ContactAttachmentStorage
{
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

            $configured = trim((string) ($_ENV['CONTACT_ATTACHMENTS_PATH'] ?? ''));
            $base = $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__, 3) . '/storage';

            $this->storageDir = $base . '/contact-attachments/';
        }

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Moves an already-validated PHP upload tmp file into permanent storage
     * under a random filename. The client-supplied name/extension is never
     * used for the stored filename: the extension is the one
     * App\Service\Forms\FormFileTypes gives the type the bytes turned out to
     * be, and only lower-case letters and digits survive here anyway.
     *
     * @throws \RuntimeException if the file can't be moved
     */
    public function store(string $tmpPath, string $extension): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->storageDir . $filename;

        if (!($this->move)($tmpPath, $destination)) {
            throw new \RuntimeException('Attachment could not be stored.');
        }

        chmod($destination, 0644);

        return $filename;
    }

    /**
     * Absolute filesystem path for a stored attachment. basename() strips
     * any directory traversal component, so this can only ever resolve to
     * a file directly inside storageDir.
     */
    public function path(string $storedFilename): string
    {
        return $this->storageDir . basename($storedFilename);
    }

    /**
     * Removes one stored file. A file that is already gone is not an error:
     * the row that pointed at it is what matters, and it goes either way.
     * Only ever a bare name directly inside the storage directory.
     */
    public function delete(string $storedFilename): void
    {
        if ($storedFilename === '' || basename($storedFilename) !== $storedFilename) {
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

        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
