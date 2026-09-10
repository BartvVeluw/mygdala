<?php

namespace App\Service;

use Dotenv\Dotenv;

/**
 * Stores a validated contact-request attachment (see
 * App\Service\ContactAttachmentValidator, which does the actual
 * type/size checking) genuinely outside the public webroot — not merely
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

    public function __construct()
    {
        self::loadEnv();

        $configured = trim((string) ($_ENV['CONTACT_ATTACHMENTS_PATH'] ?? ''));
        $base = $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__, 3) . '/storage';

        $this->storageDir = $base . '/contact-attachments/';

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Moves an already-validated PHP upload tmp file into permanent storage
     * under a random filename. The client-supplied name/extension is never
     * used for the stored filename (see ContactAttachmentValidator).
     *
     * @throws \RuntimeException if the file can't be moved
     */
    public function store(string $tmpPath, string $extension): string
    {
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->storageDir . $filename;

        if (!move_uploaded_file($tmpPath, $destination)) {
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

    public function delete(string $storedFilename): void
    {
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
