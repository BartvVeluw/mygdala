<?php

declare(strict_types=1);

namespace App\Update;

/**
 * A package download that outlives its request: the bytes so far in
 * `<destination>.part`, and in the update state's cursor what the server
 * needs to go on — how many of those bytes are trusted, their SHA-256, the
 * validator of the file they came from, and whether the source has shown it
 * honours HTTP ranges.
 *
 * WHY. A download has to fit the time budget of one request (Updater) and a
 * slow host or a large release does not. So a request downloads until its
 * budget is used and stops; the next one goes on where the cursor says, with
 * a Range request (HttpFetcher::resume()). The browser never supplies an
 * offset: it comes from this cursor and from the file on disk, and the two
 * have to agree.
 *
 * WHAT IS TRUSTED. Only the bytes a checkpoint recorded — they were flushed
 * before the checkpoint was written — and only while the file still hashes to
 * what the checkpoint says. A request killed halfway can leave more bytes than
 * that (they are cut off) or fewer (the download starts over); a file changed
 * on disk between two requests starts over too. Starting over is always safe:
 * nothing of the live site changes during a download. It is also bounded:
 * after MAX_RESTARTS the update stops instead of downloading forever.
 *
 * WHAT IT DOES NOT DO. Check the package's own SHA-256: that is
 * ReleasePackage::verify(), in its own step, over the whole file, whatever
 * route its bytes took. This class only refuses to let the file grow past the
 * size in the signed manifest.
 */
final class PackageDownload
{
    public const SUFFIX = '.part';

    /** Starting over more often than this ends the update (nothing live has changed yet). */
    public const MAX_RESTARTS = 3;

    /** A checkpoint is written to the update state after this many new bytes. */
    public const CHECKPOINT_BYTES = 1048576;

    /** @var resource */
    private $handle;

    private \HashContext $hash;

    private int $bytes = 0;

    private int $sinceCheckpoint = 0;

    private int $received = 0;

    private ?\Closure $onCheckpoint = null;

    /** @var list<array{0: string, 1: array<string, string|int>}> */
    private array $events = [];

    /**
     * @param array<string, mixed> $cursor
     */
    private function __construct(
        private readonly string $destination,
        private readonly int $size,
        private array $cursor
    ) {
    }

    /**
     * The download of a $size-byte package into $destination, continued from
     * $cursor (the update state's; [] for a fresh start).
     *
     * @param array<string, mixed> $cursor
     *
     * @throws UpdateException when the partial file cannot be opened, or the
     *                         download has started over too often
     */
    public static function open(string $destination, int $size, array $cursor): self
    {
        $download = new self($destination, $size, $cursor);
        $download->cursor['requests'] = (int) ($cursor['requests'] ?? 0) + 1;

        $path = $download->partPath();
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => basename($path)], 'Cannot open ' . $path);
        }
        $download->handle = $handle;
        $download->hash = hash_init('sha256');
        $download->continueOrStartOver();

        return $download;
    }

    public function partPath(): string
    {
        return $this->destination . self::SUFFIX;
    }

    /** Bytes on disk that belong to the download: where the next byte goes. */
    public function offset(): int
    {
        return $this->bytes;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** How many more bytes the package may still have. */
    public function remaining(): int
    {
        return $this->size - $this->bytes;
    }

    public function isComplete(): bool
    {
        return $this->bytes === $this->size;
    }

    /** New bytes written during this request. */
    public function received(): int
    {
        return $this->received;
    }

    /** The ETag or Last-Modified of the file the bytes came from, for If-Range. */
    public function validator(): ?string
    {
        $validator = $this->cursor['validator'] ?? null;

        return is_string($validator) && $validator !== '' ? $validator : null;
    }

    public function rememberValidator(?string $validator): void
    {
        $this->cursor['validator'] = $validator;
    }

    /** true once the source answered a range correctly, false once it ignored one, null before that. */
    public function rangesSupported(): ?bool
    {
        $ranges = $this->cursor['ranges'] ?? null;

        return is_bool($ranges) ? $ranges : null;
    }

    public function rememberRanges(bool $supported): void
    {
        $this->cursor['ranges'] = $supported;
    }

    public function onCheckpoint(?\Closure $callback): void
    {
        $this->onCheckpoint = $callback;
    }

    /**
     * Throws the bytes so far away and starts again at byte 0.
     *
     * @throws UpdateException update.error.download_unstable after MAX_RESTARTS
     */
    public function startOver(string $reason): void
    {
        $restarts = (int) ($this->cursor['restarts'] ?? 0) + 1;

        if ($restarts > self::MAX_RESTARTS) {
            throw new UpdateException(
                'update.error.download_unstable',
                ['count' => self::MAX_RESTARTS],
                'The download started over ' . self::MAX_RESTARTS . ' times; stopping at ' . $reason
            );
        }

        ftruncate($this->handle, 0);
        rewind($this->handle);
        $this->hash = hash_init('sha256');
        $this->bytes = 0;
        $this->sinceCheckpoint = 0;
        $this->cursor['restarts'] = $restarts;
        $this->cursor['validator'] = null;
        $this->events[] = ['update.log.download_restarted', ['reason' => $reason]];
        $this->checkpoint();
    }

    /**
     * @throws UpdateException when $bytes would make the package larger than
     *                         the manifest says, or the disk refuses them
     */
    public function append(string $bytes): void
    {
        $length = strlen($bytes);

        if ($length > $this->remaining()) {
            throw new UpdateException('update.error.package_size_mismatch', [
                'expected' => $this->size,
                'actual' => $this->bytes + $length,
            ], 'Refusing to grow the package past ' . $this->size . ' bytes');
        }

        if (fwrite($this->handle, $bytes) !== $length) {
            throw new UpdateException('update.error.disk_full', [], 'Short write to ' . $this->partPath());
        }

        hash_update($this->hash, $bytes);
        $this->bytes += $length;
        $this->received += $length;
        $this->sinceCheckpoint += $length;

        if ($this->sinceCheckpoint >= self::CHECKPOINT_BYTES) {
            $this->checkpoint();
        }
    }

    /**
     * Flushes the file and records how far it is trustworthy; the callback
     * saves that in the update state, so a request killed after this point
     * keeps these bytes.
     */
    public function checkpoint(): void
    {
        fflush($this->handle);
        $this->cursor['bytes'] = $this->bytes;
        $this->cursor['sha256'] = hash_final(hash_copy($this->hash));
        $this->sinceCheckpoint = 0;

        if ($this->onCheckpoint !== null) {
            ($this->onCheckpoint)($this->cursor);
        }
    }

    /**
     * Ends this request's part of the download.
     *
     * @return array<string, mixed> the cursor for the update state
     */
    public function close(): array
    {
        if (is_resource($this->handle)) {
            fflush($this->handle);
            $this->cursor['bytes'] = $this->bytes;
            $this->cursor['sha256'] = hash_final(hash_copy($this->hash));
            fclose($this->handle);
        }

        return $this->cursor;
    }

    /**
     * Turns the complete partial file into $destination.
     *
     * @throws UpdateException
     */
    public function finish(): void
    {
        $this->close();

        if (!$this->isComplete()) {
            throw new UpdateException('update.error.download_failed', ['url' => '—'], 'The download is not complete');
        }

        if (!@rename($this->partPath(), $this->destination)) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => basename($this->destination)], 'Cannot move ' . $this->partPath());
        }
    }

    /** @return list<array{0: string, 1: array<string, string|int>}> what happened, for the update log */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Picks up where the cursor says, if the file on disk still backs it up;
     * otherwise starts over. Bytes beyond the last checkpoint are cut off:
     * they were written by a request that did not live to vouch for them.
     */
    private function continueOrStartOver(): void
    {
        $trusted = (int) ($this->cursor['bytes'] ?? 0);
        $expected = (string) ($this->cursor['sha256'] ?? '');
        $onDisk = (int) (fstat($this->handle)['size'] ?? 0);

        if ($trusted <= 0) {
            ftruncate($this->handle, 0);
            $this->cursor['bytes'] = 0;
            $this->cursor['sha256'] = hash_final(hash_copy($this->hash));

            return;
        }

        if ($trusted > $this->size || $onDisk < $trusted) {
            $this->startOver($onDisk < $trusted ? 'partial_short' : 'partial_too_long');

            return;
        }

        if ($onDisk > $trusted) {
            ftruncate($this->handle, $trusted);
        }

        rewind($this->handle);
        hash_update_stream($this->hash, $this->handle);

        if (!hash_equals($expected, hash_final(hash_copy($this->hash)))) {
            $this->startOver('partial_damaged');

            return;
        }

        fseek($this->handle, $trusted);
        $this->bytes = $trusted;
        $this->events[] = ['update.log.download_resumed', ['bytes' => $trusted, 'size' => $this->size]];
    }
}
