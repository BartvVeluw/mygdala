<?php

declare(strict_types=1);

namespace App\Update;

/**
 * One compact log per update: <storage>/logs/<update id>.log, one JSON
 * object per line.
 *
 *     {"at":"2026-10-01T12:00:03Z","step":"verify","event":"done","message":"update.log.verified","params":{…}}
 *
 * What goes in: the update id, source and target, every step's start and
 * end, the package hash, the plan's counts, every migration Phinx ran with
 * its output, and every failure with its technical detail. What never goes
 * in: a secret. There is none in the updater's hands to begin with — the
 * manifest URL is reduced to host and path (UpdateConfig::describeUrl), the
 * health token is a random value that dies with the update — and details
 * are cut to a sane length so a runaway error cannot fill the disk.
 *
 * JSON lines rather than prose so the Updates screen can show the last
 * update's steps in the administrator's own CMS language (the message is a
 * catalog key), and support can still read the file as text.
 */
final class UpdateLog
{
    private const MAX_DETAIL = 8000;

    public function __construct(
        private readonly string $path
    ) {
    }

    /**
     * @param array<string, string|int> $params
     */
    public function write(string $step, string $event, string $messageKey = '', array $params = [], string $detail = ''): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $line = json_encode(array_filter([
            'at' => UpdateState::now(),
            'step' => $step,
            'event' => $event,
            'message' => $messageKey,
            'params' => $params,
            'detail' => mb_substr($detail, 0, self::MAX_DETAIL),
        ], static fn (mixed $value): bool => $value !== '' && $value !== []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The last $limit entries, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(int $limit = 200): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $entries = [];

        foreach (array_slice($lines, -$limit) as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
