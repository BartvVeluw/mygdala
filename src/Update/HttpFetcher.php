<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The updater's only way onto the network: plain HTTP GETs with hard limits.
 *
 * Same transport as the project's other outgoing clients
 * (App\Service\Shipping\PostNl\PostNlRateFetcher, TurnstileVerifier): PHP's
 * stream wrapper under allow_url_fopen, no ext-curl, verified TLS. What this
 * class adds is what a DOWNLOADER needs and an API call does not:
 *
 *   - redirects are followed by hand, at most MAX_REDIRECTS, and every hop
 *     is checked against UpdateConfig::isAcceptableUrl() — a release host
 *     may bounce to a CDN, but never down to plain HTTP or to file://;
 *   - a byte ceiling on every response, enforced while reading, so a hostile
 *     or broken server cannot fill the disk or the memory limit;
 *   - a download streams into a ".part" file (PackageDownload) that only
 *     becomes the real file once it is complete, so a half-downloaded package
 *     can never be taken for a whole one — and it goes on from where an
 *     earlier request stopped, with an HTTP Range request, but only when the
 *     answer proves it is the continuation of exactly those bytes (resume()).
 *
 * The User-Agent names the product and version and nothing else. The
 * project's HttpUserAgent adds the site's own URL as a contact; an update
 * check must not tell the release host which sites exist (no telemetry,
 * docs/updates/ARCHITECTURE.md).
 */
final class HttpFetcher
{
    private const MAX_REDIRECTS = 5;

    private const CHUNK = 65536;

    public function __construct(
        private readonly int $timeoutSeconds = 30
    ) {
    }

    /**
     * @throws UpdateException
     */
    public function get(string $url, int $maxBytes): string
    {
        [, , $stream] = $this->open($url, [], null);

        try {
            $body = '';
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK);
                if ($chunk === false) {
                    throw $this->failure('update.error.download_failed', $url, 'read error');
                }
                $body .= $chunk;

                if (strlen($body) > $maxBytes) {
                    throw $this->failure('update.error.download_too_large', $url, 'more than ' . $maxBytes . ' bytes');
                }
            }

            $this->assertNotTimedOut($stream, $url);

            return $body;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Continues $download from $url until it is complete or $deadline (a
     * microtime()) has passed. What arrived stays in the partial file and its
     * cursor, for the next request.
     *
     * RANGES, STRICTLY. From byte N on it asks `Range: bytes=N-` (with
     * If-Range when the file had a validator) and appends only an answer that
     * proves it continues exactly there: 206 with `Content-Range: bytes N-M/T`,
     * T being the size in the signed manifest. Anything else is no
     * continuation:
     *
     *   200                the source ignored the range, or the file changed:
     *                      that body IS the whole file, so it starts over at 0
     *   206 not at N, no   asked once more, without a range, from byte 0
     *   or a bad Content-
     *   Range, or 416
     *   T or the length    not the package the manifest promised: refused
     *   ≠ the manifest
     *
     * A source that got a range wrong once is never asked for one again: each
     * of its requests starts at 0, and PackageDownload ends the update after
     * MAX_RESTARTS of those. Nothing live has changed during a download, so
     * starting over is always safe; trusting a wrong offset never is.
     *
     * Every wait is bounded by $deadline: connecting, the headers, each read.
     * A request that received nothing at all is a failure, not progress.
     *
     * @throws UpdateException
     */
    public function resume(string $url, PackageDownload $download, float $deadline): void
    {
        if ($download->isComplete()) {
            return;
        }

        if ($download->offset() > 0 && $download->rangesSupported() === false) {
            $download->startOver('range_unsupported');
        }

        [$stream, $end] = $this->openContinuation($url, $download, $deadline);

        try {
            while ($download->offset() < $end) {
                $left = $deadline - microtime(true);
                if ($left <= 0 && $download->received() > 0) {
                    // The budget is used; the next request goes on from here.
                    break;
                }

                stream_set_timeout($stream, max(1, (int) ceil($left)));
                $chunk = fread($stream, min(self::CHUNK, $end - $download->offset()));

                if ($chunk === false) {
                    throw $this->failure('update.error.download_failed', $url, 'read error after ' . $download->offset() . ' bytes');
                }

                if ($chunk === '') {
                    // The connection closed early, or stalled past the budget:
                    // keep what arrived, the next request asks for the rest.
                    if (feof($stream) || (stream_get_meta_data($stream)['timed_out'] ?? false) || microtime(true) >= $deadline) {
                        break;
                    }
                    continue;
                }

                $download->append($chunk);
            }

            if ($download->isComplete()) {
                // A byte past the size in the manifest means this is not the package.
                stream_set_timeout($stream, 1);
                $extra = fread($stream, 1);
                if (is_string($extra) && $extra !== '') {
                    throw $this->failure('update.error.download_too_large', $url, 'more than ' . $download->size() . ' bytes');
                }
            }
        } finally {
            fclose($stream);
        }

        if ($download->received() === 0) {
            throw $this->failure('update.error.download_failed', $url, 'no data received at byte ' . $download->offset());
        }
    }

    /**
     * Opens the request that continues $download, and says up to which byte
     * (exclusive) its body may be appended.
     *
     * @return array{0: resource, 1: int}
     */
    private function openContinuation(string $url, PackageDownload $download, float $deadline): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $offset = $download->offset();
            $headers = [];
            if ($offset > 0) {
                $headers[] = 'Range: bytes=' . $offset . '-';
                if ($download->validator() !== null) {
                    $headers[] = 'If-Range: ' . $download->validator();
                }
            }

            [$status, $response, $stream] = $this->open($url, $headers, $deadline, $offset > 0);
            $validator = self::validatorOf($response);
            $length = self::contentLength($response);
            $changed = self::changedSince($download->validator(), $response);

            if ($status === 200) {
                if ($length !== null && $length !== $download->size()) {
                    fclose($stream);
                    throw $length > $download->size()
                        ? $this->failure('update.error.download_too_large', $url, $length . ' bytes, the manifest says ' . $download->size())
                        : new UpdateException('update.error.package_size_mismatch', ['expected' => $download->size(), 'actual' => $length], 'The source serves ' . $length . ' bytes');
                }

                if ($offset > 0) {
                    // The whole file again: either it changed since the first
                    // bytes (If-Range did its job) or ranges are not honoured.
                    if (!$changed) {
                        $download->rememberRanges(false);
                    }
                    $download->startOver($changed ? 'source_changed' : 'range_ignored');
                }

                $download->rememberValidator($validator);

                return [$stream, $download->size()];
            }

            $range = $status === 206 ? self::contentRange($response) : null;

            if ($range !== null && $range[2] !== $download->size()) {
                fclose($stream);
                throw new UpdateException('update.error.package_size_mismatch', ['expected' => $download->size(), 'actual' => $range[2]], 'Content-Range says the file is ' . $range[2] . ' bytes');
            }

            $continues = $offset > 0
                && $range !== null
                && $range[0] === $offset
                && $range[1] >= $range[0]
                && $range[1] < $range[2]
                && ($length === null || $length === $range[1] - $range[0] + 1)
                && !str_starts_with(strtolower($response['content-type'] ?? ''), 'multipart/');

            if ($continues && !$changed) {
                $download->rememberRanges(true);
                if ($download->validator() === null) {
                    $download->rememberValidator($validator);
                }

                return [$stream, $range[1] + 1];
            }

            fclose($stream);

            if (!$continues) {
                $download->rememberRanges(false);
            }
            $download->startOver($continues ? 'source_changed' : 'range_invalid');
        }

        throw $this->failure('update.error.download_failed', $url, 'no usable answer, even without a range');
    }

    /**
     * Opens $url, following acceptable redirects, and returns the final
     * answer: its status, its headers (lower-case names) and the body stream.
     * Only a 2xx comes back — and a 416 when a range was asked for, which
     * resume() handles. With a $deadline, every hop waits only until then
     * (at least a second).
     *
     * @param list<string> $headers extra request headers
     *
     * @return array{0: int, 1: array<string, string>, 2: resource}
     */
    private function open(string $url, array $headers, ?float $deadline, bool $ranged = false): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $timeout = $deadline === null ? $this->timeoutSeconds : self::secondsUntil($deadline, $this->timeoutSeconds);

            if (!UpdateConfig::isAcceptableUrl($url)) {
                throw new UpdateException(
                    'update.error.url_not_allowed',
                    ['url' => UpdateConfig::describeUrl($url)],
                    'Refusing to fetch ' . UpdateConfig::describeUrl($url)
                );
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeout,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'protocol_version' => 1.1,
                    'header' => implode("\r\n", [
                        'User-Agent: ' . self::userAgent(),
                        'Accept: application/json, application/zip, */*',
                        'Connection: close',
                        ...$headers,
                    ]),
                ],
                'ssl' => self::tlsOptions(),
            ]);

            $stream = @fopen($url, 'rb', false, $context);

            if ($stream === false) {
                $error = error_get_last();
                throw $this->failure('update.error.download_failed', $url, (string) ($error['message'] ?? 'connection failed'));
            }

            stream_set_timeout($stream, $timeout);

            $lines = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            [$status, $response] = self::finalResponse(is_array($lines) ? $lines : []);

            if (($status >= 200 && $status < 300) || ($ranged && $status === 416)) {
                return [$status, $response, $stream];
            }

            fclose($stream);

            if (in_array($status, [301, 302, 303, 307, 308], true) && ($response['location'] ?? '') !== '') {
                $url = self::resolve($url, $response['location']);
                continue;
            }

            throw new UpdateException(
                'update.error.download_status',
                ['status' => $status, 'url' => UpdateConfig::describeUrl($url)],
                'HTTP ' . $status . ' from ' . UpdateConfig::describeUrl($url)
            );
        }

        throw $this->failure('update.error.download_failed', $url, 'too many redirects');
    }

    /**
     * The status and headers of the LAST response in the header list. With
     * follow_location off there is only one, but a 100 Continue can precede it.
     *
     * @param list<string> $lines
     *
     * @return array{0: int, 1: array<string, string>}
     */
    private static function finalResponse(array $lines): array
    {
        $status = 0;
        $headers = [];

        foreach ($lines as $line) {
            $line = (string) $line;

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $headers = [];
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        return [$status, $headers];
    }

    /**
     * `Content-Range: bytes START-END/TOTAL` with a known total; null for
     * anything else (`*` as the total, several ranges, garbage).
     *
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function contentRange(array $headers): ?array
    {
        if (preg_match('#^bytes\s+(\d{1,18})-(\d{1,18})/(\d{1,18})$#i', trim($headers['content-range'] ?? ''), $match) !== 1) {
            return null;
        }

        return [(int) $match[1], (int) $match[2], (int) $match[3]];
    }

    /** @param array<string, string> $headers */
    private static function contentLength(array $headers): ?int
    {
        $length = trim($headers['content-length'] ?? '');

        return $length !== '' && strlen($length) <= 18 && ctype_digit($length) ? (int) $length : null;
    }

    /**
     * What identifies the file's version for If-Range: a strong ETag, else
     * Last-Modified. A weak ETag cannot be used with If-Range.
     *
     * @param array<string, string> $headers
     */
    private static function validatorOf(array $headers): ?string
    {
        $etag = trim($headers['etag'] ?? '');
        if ($etag !== '' && !str_starts_with($etag, 'W/')) {
            return $etag;
        }

        $modified = trim($headers['last-modified'] ?? '');

        return $modified !== '' ? $modified : null;
    }

    /**
     * Does the answer say it is another version of the file than the one the
     * bytes so far came from? An ETag is compared with an ETag, a date with
     * Last-Modified; an answer without that kind of validator says nothing
     * (the server already had If-Range to go by). The ETag comparison is the
     * weak one: Apache sends only a weak ETag for a file changed less than a
     * second ago, and another tag is another file either way.
     *
     * @param array<string, string> $headers
     */
    private static function changedSince(?string $stored, array $headers): bool
    {
        if ($stored === null) {
            return false;
        }

        if (str_starts_with($stored, '"')) {
            $etag = trim($headers['etag'] ?? '');
            $tag = str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;

            return $tag !== '' && $tag !== $stored;
        }

        $modified = trim($headers['last-modified'] ?? '');

        return $modified !== '' && $modified !== $stored;
    }

    /** Whole seconds until $deadline, at least one and at most $cap. */
    private static function secondsUntil(float $deadline, int $cap): int
    {
        return max(1, min($cap, (int) ceil($deadline - microtime(true))));
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') . $location;
    }

    /** @return array<string, mixed> */
    private static function tlsOptions(): array
    {
        $options = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];

        // A shared host with an outdated system CA store is common enough
        // that Composer ships its own bundle; use it when it is there.
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $bundle = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
            $options[is_dir($bundle) ? 'capath' : 'cafile'] = $bundle;
        }

        return $options;
    }

    private static function userAgent(): string
    {
        try {
            return 'Mygdala-Updater/' . AppVersion::current();
        } catch (\RuntimeException) {
            return 'Mygdala-Updater';
        }
    }

    /** @param resource $stream */
    private function assertNotTimedOut($stream, string $url): void
    {
        if ((stream_get_meta_data($stream)['timed_out'] ?? false) === true) {
            throw $this->failure('update.error.download_failed', $url, 'timed out');
        }
    }

    private function failure(string $key, string $url, string $detail): UpdateException
    {
        return new UpdateException($key, ['url' => UpdateConfig::describeUrl($url)], UpdateConfig::describeUrl($url) . ': ' . self::withoutQueries($detail));
    }

    /**
     * PHP's own warning text quotes the full URL it failed on, query string
     * and all — which is where a download token would be. Nothing with a
     * query string reaches a log, the state or the screen.
     */
    public static function withoutQueries(string $text): string
    {
        return preg_replace('#(https?://[^\s?"\')]+)\?[^\s"\')]*#i', '$1?…', $text) ?? $text;
    }
}
