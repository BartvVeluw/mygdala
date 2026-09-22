<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Update\ReleaseSignature;

/**
 * Test support: a release feed in a temporary directory, signed with a key
 * made for the test — manifest.json, manifest.json.sig and whatever package
 * files the test puts next to them. Serve it with SandboxServer.
 *
 * The key pair never leaves the test process. Trusting it is a matter of
 * handing publicKey() to UpdateConfig::overrideForTests() (in process) or
 * MYGDALA_UPDATE_PUBLIC_KEY (for a sandbox installation's .env), exactly as a
 * distribution with its own feed would.
 */
final class FeedFixture
{
    /** @var array{public: string, secret: string} */
    private array $keys;

    public readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir() . '/mygdala-feed-' . bin2hex(random_bytes(5));
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
        $this->keys = ReleaseSignature::generateKeyPair();
    }

    public function publicKey(): string
    {
        return base64_encode($this->keys['public']);
    }

    public function secretKey(): string
    {
        return $this->keys['secret'];
    }

    /**
     * Writes a manifest for $packageFile (already in the feed directory) and
     * signs it. $changes overrides any field, after the hash and size are
     * computed — which is how a test publishes a manifest that lies.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed> the manifest as written
     */
    public function publish(string $version, string $packageFile, array $changes = []): array
    {
        $path = $this->directory . '/' . $packageFile;

        $manifest = array_replace([
            'manifest_version' => 1,
            'product' => 'mygdala',
            'version' => $version,
            'build_id' => $version . '+test',
            'released_at' => '2026-10-01T12:00:00Z',
            'package_url' => $packageFile,
            'sha256' => is_file($path) ? hash_file('sha256', $path) : str_repeat('0', 64),
            'size' => is_file($path) ? filesize($path) : 1,
            'minimum_php' => '8.1',
            'minimum_mysql' => '5.7',
            'minimum_mariadb' => '10.3',
            'required_extensions' => ['pdo_mysql'],
            'minimum_source_version' => '0.0.0',
            'updater_protocol' => 1,
            'migrations' => ['count' => 0, 'latest' => ''],
            'notes' => 'Testrelease ' . $version,
        ], $changes);

        $this->writeSigned((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /** Writes manifest bytes exactly as given, with a valid signature over them. */
    public function writeSigned(string $bytes): void
    {
        file_put_contents($this->directory . '/manifest.json', $bytes);
        file_put_contents($this->directory . '/manifest.json.sig', ReleaseSignature::sign($bytes, $this->keys['secret']));
    }

    /** Writes manifest bytes and their signature into another directory (a sandbox's feed). */
    public function signInto(string $directory, string $bytes): void
    {
        file_put_contents($directory . '/manifest.json', $bytes);
        file_put_contents($directory . '/manifest.json.sig', ReleaseSignature::sign($bytes, $this->keys['secret']));
    }

    public function remove(): void
    {
        self::removeDirectory($this->directory);
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @chmod($item->getPathname(), 0777);
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
