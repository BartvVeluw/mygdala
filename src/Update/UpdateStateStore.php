<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The updater's working directory (UpdateConfig::storagePath()) and the two
 * guarantees it gives: the state file is never half-written, and only one
 * update step runs at a time.
 *
 *     <storage>/state.json        the current update (UpdateState)
 *     <storage>/update.lock       held while a step runs (flock)
 *     <storage>/logs/<id>.log     one log per update (UpdateLog)
 *     <storage>/work/<id>/        download, staging, plan, journal
 *     <storage>/backups/<id>/     files and database dump; kept after the update
 *
 * ATOMIC WRITES. state.json is written to a temporary file and renamed over
 * the old one, so a request killed mid-write leaves the previous state, not
 * a truncated one.
 *
 * THE LOCK. A step takes an exclusive, NON-blocking flock on update.lock and
 * holds it until the step returns. A second step for the same installation —
 * a double click, a second tab, a retry while the first is still working —
 * is refused at once instead of running alongside. flock() is released by
 * the operating system when a process dies, so a lock can never go stale on
 * its own. What CAN go stale is the state: a step that was killed leaves
 * `running` with an old heartbeat, and the Updates screen reports that as an
 * interrupted update that may be continued (docs/updates/RECOVERY.md).
 *
 * ONE INSTALLATION. The state records the site root it belongs to. A storage
 * directory shared by mistake between two installations is refused rather
 * than letting one continue the other's update.
 */
final class UpdateStateStore
{
    public function __construct(
        private readonly string $storagePath,
        private readonly string $root
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(UpdateConfig::storagePath(), UpdateConfig::projectRoot());
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    /**
     * @throws UpdateException when the state belongs to another installation
     *                         or cannot be read
     */
    public function load(): UpdateState
    {
        $path = $this->storagePath . '/state.json';

        if (!is_file($path)) {
            return UpdateState::idle();
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            throw new UpdateException('update.error.state_unreadable', [], 'state.json is not valid JSON');
        }

        if (($data['format'] ?? null) !== UpdateState::FORMAT) {
            throw new UpdateException('update.error.state_format', ['format' => (string) ($data['format'] ?? '?')], 'Unknown state format');
        }

        $state = UpdateState::fromArray($data);

        if ($state->installationRoot() !== '' && !self::sameDirectory($state->installationRoot(), $this->root)) {
            throw new UpdateException(
                'update.error.state_other_installation',
                ['path' => $state->installationRoot()],
                'state.json belongs to ' . $state->installationRoot()
            );
        }

        return $state;
    }

    public function save(UpdateState $state): void
    {
        $this->ensureDirectory($this->storagePath);
        self::writeAtomically(
            $this->storagePath . '/state.json',
            json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }

    /**
     * Runs $work while holding the update lock.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws UpdateException update.error.busy when another step holds it
     */
    public function withLock(callable $work): mixed
    {
        $this->ensureDirectory($this->storagePath);
        $handle = @fopen($this->storagePath . '/update.lock', 'c');

        if ($handle === false) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => $this->storagePath], 'Cannot open update.lock');
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new UpdateException('update.error.busy', [], 'update.lock is held by another request');
            }

            try {
                return $work();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** Is a step running right now? (Somebody holds the lock.) */
    public function isLocked(): bool
    {
        $path = $this->storagePath . '/update.lock';

        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return false;
        }

        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    public function workDirectory(string $updateId): string
    {
        return $this->storagePath . '/work/' . self::safeId($updateId);
    }

    public function backupDirectory(string $updateId): string
    {
        return $this->storagePath . '/backups/' . self::safeId($updateId);
    }

    public function logPath(string $updateId): string
    {
        return $this->storagePath . '/logs/' . self::safeId($updateId) . '.log';
    }

    public function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => $directory], 'Cannot create ' . $directory);
        }

        // Defence in depth for a storage path that someone put inside the
        // web root after all: Apache then refuses to serve any of it.
        if ($directory === $this->storagePath && !is_file($directory . '/.htaccess')) {
            @file_put_contents($directory . '/.htaccess', "Require all denied\n");
        }
    }

    /** Writes $contents to $path through a temporary file and a rename. */
    public static function writeAtomically(string $path, string $contents): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
            @unlink($temporary);
            throw new UpdateException('update.error.storage_not_writable', ['path' => dirname($path)], 'Cannot write ' . $temporary);
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new UpdateException('update.error.storage_not_writable', ['path' => dirname($path)], 'Cannot rename onto ' . $path);
        }
    }

    /** Update ids are generated here (UpdateState::start); still, never trust one into a path. */
    private static function safeId(string $updateId): string
    {
        if (preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $updateId) !== 1) {
            throw new UpdateException('update.error.state_unreadable', [], 'Malformed update id');
        }

        return $updateId;
    }

    private static function sameDirectory(string $a, string $b): bool
    {
        $normalize = static fn (string $path): string => rtrim(str_replace('\\', '/', (string) (realpath($path) ?: $path)), '/');

        return $normalize($a) === $normalize($b);
    }
}
