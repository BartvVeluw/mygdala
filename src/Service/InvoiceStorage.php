<?php

namespace App\Service;

use Dotenv\Dotenv;

/**
 * Stores generated invoice PDFs genuinely outside the public webroot — same
 * reasoning and layout as App\Service\ContactAttachmentStorage (see that
 * class for why "outside the project root" rather than ".htaccess deny"):
 * default is one directory *above* the project root (a true sibling the web
 * server never serves from), overridable via INVOICE_STORAGE_PATH (.env)
 * for hosting where that default isn't writable. Local Docker gets this via
 * the same `contact_attachments` volume mounted at /var/www/storage
 * (docker-compose.yml) — invoices/ is just a sibling folder inside it.
 *
 * Unlike ContactAttachmentStorage, the "filename" here (a relative path like
 * "2026/INV2026-000001.pdf") is entirely server-generated (the invoice
 * number this project itself formats — see InvoiceService), never derived
 * from user input, so a deterministic name is safe and useful (lets an
 * admin find "the 2026 invoices" on disk). Path traversal is still rejected
 * defensively in path().
 */
class InvoiceStorage
{
    private static bool $envLoaded = false;

    private string $storageDir;

    public function __construct()
    {
        self::loadEnv();

        $configured = trim((string) ($_ENV['INVOICE_STORAGE_PATH'] ?? ''));
        $base = $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__, 3) . '/storage';

        $this->storageDir = $base . '/invoices/';
    }

    /**
     * Writes $contents to $relativePath (e.g. "2026/INV2026-000001.pdf"),
     * creating any missing subdirectory. Overwrites if the file already
     * exists (used by regenerate-if-missing, which reproduces the exact
     * same bytes from the same frozen snapshot).
     *
     * @throws \RuntimeException if the file can't be written
     */
    public function write(string $relativePath, string $contents): void
    {
        $path = $this->path($relativePath);
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Invoice storage directory could not be created.');
        }

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Invoice PDF could not be written to storage.');
        }

        chmod($path, 0644);
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->path($relativePath));
    }

    public function read(string $relativePath): string
    {
        $contents = file_get_contents($this->path($relativePath));
        if ($contents === false) {
            throw new \RuntimeException('Invoice PDF could not be read from storage.');
        }

        return $contents;
    }

    /**
     * Absolute filesystem path for a stored invoice. Rejects any
     * "..' path-traversal segment (the relative path is always our own
     * generated "<year>/<invoice_number>.pdf", never user input, but this
     * is a cheap defensive floor regardless).
     */
    public function path(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $segments = array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== '' && $s !== '.');

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException('Invalid invoice path.');
            }
        }

        return $this->storageDir . implode('/', $segments);
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
