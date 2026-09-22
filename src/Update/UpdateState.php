<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Where an update stands, persisted between requests (UpdateStateStore).
 *
 * The server's state is the truth. The browser only asks "run the next
 * step"; which step that is, for which update and towards which version, is
 * read from here. A closed tab or a refresh therefore loses nothing: the
 * Updates screen reads this file and says where the update stopped.
 *
 * TWO FIELDS, NOT ONE:
 *
 *   status   the lifecycle: idle, running, completed, failed, rolled_back,
 *            recovery_required. Only `running` has a next step.
 *   step     the step to run next while running, or the step it ended on.
 *
 * The step order is STEPS (and ROLLBACK_STEPS once a live change has to be
 * undone). docs/updates/ARCHITECTURE.md maps them onto the words an
 * administrator sees ("Downloaden", "Back-up maken", …).
 *
 * THE FORMAT OUTLIVES THE CODE THAT WROTE IT. Every step after `apply` runs
 * on the NEW release's code, reading a state the OLD release's code wrote.
 * FORMAT is that contract: a release declares in its manifest which formats
 * its updater continues (`updater_protocol`), and Preflight refuses a
 * release that could not finish the update this installation would start.
 * Add fields freely; renaming or removing one is a new FORMAT.
 */
final class UpdateState
{
    public const FORMAT = 1;

    /** The state formats this code can continue. */
    public const SUPPORTED_PROTOCOLS = [1];

    public const IDLE = 'idle';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const ROLLED_BACK = 'rolled_back';
    public const RECOVERY_REQUIRED = 'recovery_required';

    /**
     * The forward steps, in order. Everything before `maintenance` leaves the
     * live site alone; everything from `apply` on changes it.
     */
    public const STEPS = [
        'download',
        'verify',
        'extract',
        'preflight',
        'maintenance',
        'backup_database',
        'backup_files',
        'apply',
        'migrate',
        'health',
        'finish',
    ];

    /** The way back, once a live change has to be undone. */
    public const ROLLBACK_STEPS = [
        'rollback_database',
        'rollback_files',
    ];

    /** Steps after which the site has been changed. */
    public const LIVE_STEPS = ['apply', 'migrate', 'health', 'finish'];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private array $data
    ) {
    }

    public static function idle(): self
    {
        return new self(['format' => self::FORMAT, 'status' => self::IDLE]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $data['status'] = in_array($data['status'] ?? null, [
            self::IDLE, self::RUNNING, self::COMPLETED, self::FAILED, self::ROLLED_BACK, self::RECOVERY_REQUIRED,
        ], true) ? $data['status'] : self::RECOVERY_REQUIRED;

        return new self($data);
    }

    /**
     * A new update towards $manifest's release, starting at the first step.
     */
    public static function start(ReleaseManifest $manifest, string $fromVersion, string $root, string $startedBy): self
    {
        $now = self::now();

        return new self([
            'format' => self::FORMAT,
            'update_id' => gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)),
            'status' => self::RUNNING,
            'step' => self::STEPS[0],
            'from_version' => $fromVersion,
            'to_version' => $manifest->version,
            'manifest' => $manifest->toArray(),
            'installation_root' => $root,
            'started_by' => $startedBy,
            'started_at' => $now,
            'updated_at' => $now,
            'heartbeat_at' => $now,
            'files_changed' => false,
            'database_changed' => false,
            'checks' => [],
            'cursor' => [],
            'message' => null,
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function status(): string
    {
        return (string) $this->data['status'];
    }

    public function isRunning(): bool
    {
        return $this->status() === self::RUNNING;
    }

    /** Finished, one way or another: a new update may start. */
    public function isSettled(): bool
    {
        return in_array($this->status(), [self::IDLE, self::COMPLETED, self::FAILED, self::ROLLED_BACK], true);
    }

    public function updateId(): string
    {
        return (string) ($this->data['update_id'] ?? '');
    }

    public function step(): string
    {
        return (string) ($this->data['step'] ?? '');
    }

    public function fromVersion(): string
    {
        return (string) ($this->data['from_version'] ?? '');
    }

    public function toVersion(): string
    {
        return (string) ($this->data['to_version'] ?? '');
    }

    public function installationRoot(): string
    {
        return (string) ($this->data['installation_root'] ?? '');
    }

    public function manifest(): ReleaseManifest
    {
        return ReleaseManifest::fromStoredArray((array) ($this->data['manifest'] ?? []));
    }

    public function filesChanged(): bool
    {
        return ($this->data['files_changed'] ?? false) === true;
    }

    public function databaseChanged(): bool
    {
        return ($this->data['database_changed'] ?? false) === true;
    }

    public function isRollingBack(): bool
    {
        return in_array($this->step(), self::ROLLBACK_STEPS, true);
    }

    /** Seconds since a step last reported in; how long it has been quiet. */
    public function secondsSinceHeartbeat(): int
    {
        $heartbeat = strtotime((string) ($this->data['heartbeat_at'] ?? ''));

        return $heartbeat === false ? PHP_INT_MAX : max(0, time() - $heartbeat);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        $copy = clone $this;
        $copy->data[$key] = $value;

        return $copy;
    }

    /** @return array<string, mixed> */
    public function cursor(): array
    {
        return is_array($this->data['cursor'] ?? null) ? $this->data['cursor'] : [];
    }

    /** @param array<string, mixed> $cursor */
    public function withCursor(array $cursor): self
    {
        return $this->with('cursor', $cursor)->touched();
    }

    /** On to $step, with a fresh cursor. */
    public function advanceTo(string $step): self
    {
        return $this->with('step', $step)->with('cursor', [])->touched();
    }

    /**
     * @param array<string, string|int> $params
     */
    public function withMessage(string $key, array $params = [], string $detail = ''): self
    {
        return $this->with('message', ['key' => $key, 'params' => $params, 'detail' => $detail]);
    }

    /** @return array{key: string, params: array<string, string|int>, detail: string}|null */
    public function message(): ?array
    {
        $message = $this->data['message'] ?? null;

        if (!is_array($message) || !is_string($message['key'] ?? null)) {
            return null;
        }

        return [
            'key' => $message['key'],
            'params' => is_array($message['params'] ?? null) ? $message['params'] : [],
            'detail' => (string) ($message['detail'] ?? ''),
        ];
    }

    /** Ends the update in $status, remembering which step it ended on. */
    public function settle(string $status): self
    {
        $settled = $this->with('status', $status)->with('finished_at', self::now())->touched();

        return $settled->with('cursor', []);
    }

    public function touched(): self
    {
        $copy = clone $this;
        $copy->data['updated_at'] = self::now();
        $copy->data['heartbeat_at'] = $copy->data['updated_at'];

        return $copy;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
