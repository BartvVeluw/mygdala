<?php

declare(strict_types=1);

namespace App\Service\Secrets;

use Dotenv\Dotenv;

/**
 * The application key that encrypts what an administrator stores in the
 * CMS as a secret (SecretStore). It is never in the database, so a copy of
 * the database alone — a backup, a dump, a site started from one — cannot
 * read a single secret.
 *
 * WHERE IT COMES FROM, in this order and nowhere else:
 *
 *   1. APP_KEY in .env   `base64:` followed by 32 random bytes in base64. A
 *                        deployment that sets it decides. A value of any
 *                        other shape is refused, never quietly replaced by
 *                        the file below: what one key sealed, another cannot
 *                        open.
 *   2. the key file      <private storage>/secrets/app.key. Private storage is
 *                        one directory ABOVE the project root, outside the
 *                        webroot, where App\Service\InvoiceStorage keeps the
 *                        invoices; SECRETS_STORAGE_PATH (an ABSOLUTE path; a
 *                        relative one would depend on which script runs) points
 *                        it elsewhere. Same format as APP_KEY, so the line can
 *                        be moved into .env as it is. The folder also gets an
 *                        .htaccess that denies every request, for an
 *                        installation whose "one directory above" is still
 *                        served (a project in a sub-folder of a document root).
 *   3. none              nothing can be stored or read.
 *
 * MADE ONCE, AND ONLY WHEN A SECRET IS SAVED (loadOrCreate()). A page view,
 * a webhook or a checkout only reads (load()), so reading never writes.
 * Making it is atomic: an exclusive lock, a temporary file with mode 0600
 * written in full and synced to disk, then a rename into place. A second administrator saving at
 * the same moment waits for the lock and then reads the key the first one
 * made. When private storage cannot be written the save fails: there is no
 * fallback that keeps a secret unencrypted, and none that puts the key in the
 * database.
 *
 * AN EXISTING FILE IS NEVER REPLACED. One that cannot be read or has the
 * wrong shape is an error, not a reason to make a new key, which would throw
 * away every secret the old one sealed.
 *
 * WHAT IT DOES WITH THE KEY: seal() and open(), libsodium's
 * XChaCha20-Poly1305 (authenticated encryption) under a subkey derived for
 * this one purpose, with a fresh random 24-byte nonce per seal and the slot
 * name plus this key's id as additional data. A sealed value opens only under
 * the same key AND in the same slot: a test key's ciphertext copied into the
 * live slot is refused, and so is one changed byte. The raw key never leaves
 * this object.
 */
final class MasterKey
{
    public const ENVIRONMENT_VARIABLE = 'APP_KEY';
    public const PATH_VARIABLE = 'SECRETS_STORAGE_PATH';

    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_FILE = 'file';

    private const PREFIX = 'base64:';
    private const FILE_NAME = 'app.key';
    private const SEAL_VERSION = 'v1';

    /** libsodium's KDF context: exactly eight bytes, naming this one use of the key. */
    private const KDF_CONTEXT = 'mygsecrt';

    private static bool $envLoaded = false;

    private function __construct(
        #[\SensitiveParameter] private readonly string $bytes,
        public readonly string $source,
    ) {
    }

    /**
     * The key when there is one, without ever making one.
     *
     * @param array<string, string>|null $environment a test's own variables; null reads .env and $_ENV
     *
     * @throws SecretStoreException KEY_INVALID when APP_KEY or the file is not a key
     */
    public static function load(?array $environment = null): ?self
    {
        $configured = trim(self::variable(self::ENVIRONMENT_VARIABLE, $environment));
        if ($configured !== '') {
            $bytes = self::decode($configured);
            if ($bytes === null) {
                throw new SecretStoreException(SecretStoreException::KEY_INVALID, 'APP_KEY is set but is not "base64:" followed by 32 bytes');
            }

            return new self($bytes, self::SOURCE_ENVIRONMENT);
        }

        $path = self::filePath($environment);
        if (!file_exists($path)) {
            return null;
        }

        return self::fromFile($path);
    }

    /**
     * The key, making the key file first when there is neither APP_KEY nor a
     * file. Only a save of a secret calls this.
     *
     * @param array<string, string>|null $environment
     *
     * @throws SecretStoreException KEY_INVALID, or KEY_NOT_CREATED when storage cannot be written
     */
    public static function loadOrCreate(?array $environment = null): self
    {
        $existing = self::load($environment);
        if ($existing !== null) {
            return $existing;
        }

        $directory = self::directory($environment);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new SecretStoreException(SecretStoreException::KEY_NOT_CREATED, 'the secrets directory could not be created');
        }
        @chmod($directory, 0700);

        // A second line behind "outside the webroot": should this folder be
        // served after all, the web server refuses everything in it.
        if (!file_exists($directory . '/.htaccess')) {
            @file_put_contents($directory . '/.htaccess', "Require all denied\n");
        }

        $lockPath = $directory . '/.' . self::FILE_NAME . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new SecretStoreException(SecretStoreException::KEY_NOT_CREATED, 'the secrets directory could not be locked');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new SecretStoreException(SecretStoreException::KEY_NOT_CREATED, 'the secrets directory could not be locked');
        }
        @chmod($lockPath, 0600);

        try {
            $path = self::filePath($environment);

            // Somebody else may have made it while this request waited.
            if (file_exists($path)) {
                return self::fromFile($path);
            }

            $temporary = $directory . '/.' . self::FILE_NAME . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $handle = @fopen($temporary, 'x');
            if ($handle === false) {
                throw new SecretStoreException(SecretStoreException::KEY_NOT_CREATED, 'the key file could not be written');
            }

            // Restricted before a single byte of the key is in it.
            @chmod($temporary, 0600);
            $written = fwrite($handle, self::PREFIX . base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)) . "\n");
            $flushed = fflush($handle) && fsync($handle);
            fclose($handle);

            if ($written === false || $flushed === false || !@rename($temporary, $path)) {
                @unlink($temporary);
                throw new SecretStoreException(SecretStoreException::KEY_NOT_CREATED, 'the key file could not be written');
            }

            return self::fromFile($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The directory the key file is in: SECRETS_STORAGE_PATH/secrets, or
     * private storage one directory above the project root.
     *
     * @param array<string, string>|null $environment
     *
     * @throws SecretStoreException KEY_INVALID for a relative SECRETS_STORAGE_PATH
     */
    public static function directory(?array $environment = null): string
    {
        $configured = trim(self::variable(self::PATH_VARIABLE, $environment));

        if ($configured !== '' && preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $configured) !== 1) {
            throw new SecretStoreException(SecretStoreException::KEY_INVALID, 'SECRETS_STORAGE_PATH must be an absolute path');
        }

        $base = $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__, 4) . '/storage';

        return $base . '/secrets';
    }

    /** @param array<string, string>|null $environment */
    public static function filePath(?array $environment = null): string
    {
        return self::directory($environment) . '/' . self::FILE_NAME;
    }

    /**
     * A short, stable name for this key that reveals nothing of it: stored
     * next to every value it sealed, so a value sealed by another key is
     * recognised as such instead of failing to decrypt.
     */
    public function id(): string
    {
        return substr(bin2hex(sodium_crypto_generichash('mygdala-key-id', $this->bytes, 16)), 0, 16);
    }

    /** $plaintext sealed for $slot: "v1:" and base64 of nonce + ciphertext. */
    public function seal(string $slot, #[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $this->additionalData($slot), $nonce, $this->subkey());

        return self::SEAL_VERSION . ':' . base64_encode($nonce . $ciphertext);
    }

    /**
     * @throws SecretStoreException UNREADABLE when $sealed was not sealed by this key for this slot, or was changed
     */
    public function open(string $slot, string $sealed): string
    {
        $prefix = self::SEAL_VERSION . ':';
        $raw = str_starts_with($sealed, $prefix) ? base64_decode(substr($sealed, strlen($prefix)), true) : false;
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if (!is_string($raw) || strlen($raw) <= $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new SecretStoreException(SecretStoreException::UNREADABLE, 'the stored value is not a sealed secret');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nonceLength),
            $this->additionalData($slot),
            substr($raw, 0, $nonceLength),
            $this->subkey()
        );

        if ($plaintext === false) {
            throw new SecretStoreException(SecretStoreException::UNREADABLE, 'the stored value does not open with this key in this slot');
        }

        return $plaintext;
    }

    /** Keeps the key out of var_dump(), print_r() and every other dump. */
    public function __debugInfo(): array
    {
        return ['source' => $this->source, 'id' => $this->id()];
    }

    private function subkey(): string
    {
        return sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 1, self::KDF_CONTEXT, $this->bytes);
    }

    private function additionalData(string $slot): string
    {
        return 'mygdala-secret:' . self::SEAL_VERSION . ':' . $slot . ':' . $this->id();
    }

    /**
     * @throws SecretStoreException KEY_INVALID
     */
    private static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);
        $bytes = is_string($contents) ? self::decode(trim($contents)) : null;

        if ($bytes === null) {
            throw new SecretStoreException(SecretStoreException::KEY_INVALID, 'the key file cannot be read or is not a key');
        }

        return new self($bytes, self::SOURCE_FILE);
    }

    /** The 32 key bytes of "base64:…", or null for anything else. */
    private static function decode(#[\SensitiveParameter] string $value): ?string
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $bytes = base64_decode(substr($value, strlen(self::PREFIX)), true);

        return is_string($bytes) && strlen($bytes) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES ? $bytes : null;
    }

    /** @param array<string, string>|null $environment */
    private static function variable(string $name, ?array $environment): string
    {
        if ($environment !== null) {
            return (string) ($environment[$name] ?? '');
        }

        if (!self::$envLoaded) {
            self::$envLoaded = true;
            $root = dirname(__DIR__, 3);
            if (file_exists($root . '/.env')) {
                Dotenv::createImmutable($root)->load();
            }
        }

        return (string) ($_ENV[$name] ?? '');
    }
}
