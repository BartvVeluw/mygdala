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
 *   - a download streams to a ".part" file and only becomes the real file
 *     once it is complete, so a half-downloaded package can never be taken
 *     for a whole one.
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
        $stream = $this->open($url);

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
     * Streams $url into $destination. Refuses to write more than $maxBytes;
     * leaves nothing at $destination unless the whole body arrived.
     *
     * @throws UpdateException
     */
    public function download(string $url, string $destination, int $maxBytes): int
    {
        $partial = $destination . '.part';
        @unlink($partial);

        $stream = $this->open($url);
        $target = @fopen($partial, 'wb');

        if ($target === false) {
            fclose($stream);
            throw new UpdateException('update.error.storage_not_writable', ['path' => basename($partial)], 'Cannot write ' . $partial);
        }

        $written = 0;

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK);
                if ($chunk === false) {
                    throw $this->failure('update.error.download_failed', $url, 'read error after ' . $written . ' bytes');
                }

                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw $this->failure('update.error.download_too_large', $url, 'more than ' . $maxBytes . ' bytes');
                }

                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new UpdateException('update.error.disk_full', [], 'Short write to ' . $partial);
                }
            }

            $this->assertNotTimedOut($stream, $url);
        } catch (\Throwable $e) {
            fclose($target);
            @unlink($partial);
            throw $e;
        } finally {
            fclose($stream);
        }

        fclose($target);

        if (!@rename($partial, $destination)) {
            @unlink($partial);
            throw new UpdateException('update.error.storage_not_writable', ['path' => basename($destination)], 'Cannot move ' . $partial);
        }

        return $written;
    }

    /**
     * Opens $url, following acceptable redirects, and returns the body stream
     * of a 2xx answer.
     *
     * @return resource
     */
    private function open(string $url)
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
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
                    'timeout' => $this->timeoutSeconds,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'protocol_version' => 1.1,
                    'header' => implode("\r\n", [
                        'User-Agent: ' . self::userAgent(),
                        'Accept: application/json, application/zip, */*',
                        'Connection: close',
                    ]),
                ],
                'ssl' => self::tlsOptions(),
            ]);

            $stream = @fopen($url, 'rb', false, $context);

            if ($stream === false) {
                $error = error_get_last();
                throw $this->failure('update.error.download_failed', $url, (string) ($error['message'] ?? 'connection failed'));
            }

            stream_set_timeout($stream, $this->timeoutSeconds);

            $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            $headers = is_array($headers) ? $headers : [];
            [$status, $location] = self::finalStatus($headers);

            if ($status >= 200 && $status < 300) {
                return $stream;
            }

            fclose($stream);

            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                $url = self::resolve($url, $location);
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
     * The status line and Location of the LAST response in the header list.
     * With follow_location off there is only one, but a 100 Continue can
     * precede it.
     *
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string}
     */
    private static function finalStatus(array $headers): array
    {
        $status = 0;
        $location = '';

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $match) === 1) {
                $status = (int) $match[1];
                $location = '';
                continue;
            }

            if (stripos((string) $header, 'Location:') === 0) {
                $location = trim(substr((string) $header, 9));
            }
        }

        return [$status, $location];
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
