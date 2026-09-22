<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The maintenance flag: `.maintenance` in the site root.
 *
 * While it exists, MaintenanceGuard answers every request except the
 * updater's own with a maintenance page, so no visitor places an order or
 * submits a form against a database that is being backed up or migrated.
 *
 * WHY A FILE IN THE ROOT. The guard runs on every request, before .env is
 * loaded and before any database connection, so the question "is the site in
 * maintenance?" must cost one stat() of a path it can compute without
 * configuration. The same name WordPress uses, deliberately: a support
 * engineer with only FTP knows what to delete (docs/updates/RECOVERY.md), and
 * .htaccess keeps the file itself from being served. It is installation
 * data (Ownership) and never part of a release.
 *
 *     {"format": 1, "update_id": "…", "since": "…", "health": "<sha256>"}
 *
 * `health` is the SHA-256 of a random token held only in the update state:
 * a request carrying that token passes the guard, which is how the health
 * check requests the site from inside the maintenance window
 * (HealthCheck). The token itself is never on disk in the web root.
 *
 * A crash cannot make the flag permanent in the sense that matters: the
 * Updates screen and the login stay reachable while it is up, and they show
 * why it is there and what may be done about it.
 */
final class MaintenanceMode
{
    public const FILE = '.maintenance';

    public function __construct(
        private readonly string $root
    ) {
    }

    public function enable(string $updateId, string $healthToken): void
    {
        UpdateStateStore::writeAtomically($this->path(), json_encode([
            'format' => 1,
            'update_id' => $updateId,
            'since' => UpdateState::now(),
            'health' => hash('sha256', $healthToken),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    public function disable(): void
    {
        if (is_file($this->path()) && !@unlink($this->path()) && is_file($this->path())) {
            throw new UpdateException('update.error.maintenance_stuck', [], 'Cannot remove ' . self::FILE);
        }
    }

    public function isActive(): bool
    {
        return is_file($this->path());
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        if (!$this->isActive()) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($this->path()), true);

        return is_array($data) ? $data : [];
    }

    private function path(): string
    {
        return $this->root . '/' . self::FILE;
    }
}
