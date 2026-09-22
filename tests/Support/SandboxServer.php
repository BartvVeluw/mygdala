<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Test support, never part of the application: PHP's built-in web server on
 * ANY directory — a release feed of fixture files, or a throwaway Mygdala
 * installation the upgrade tests built from a release package.
 *
 * Tests\Support\BuiltInServer serves this checkout; the updater tests need
 * to serve something that is deliberately NOT this checkout, with an
 * environment that is deliberately NOT the suite's. So:
 *
 *   - the environment is given, not inherited, when a test asks for that:
 *     a sandbox installation must read its own .env, and Dotenv never
 *     overrides a variable the process already has (DB_DATABASE from the
 *     container would otherwise win over the sandbox's own database);
 *   - several workers (PHP_CLI_SERVER_WORKERS), because the updater's health
 *     check requests the site from inside a request to the same site, and a
 *     single-threaded server would wait for itself forever;
 *   - a cookie jar per server, so a test can log in once and stay logged in.
 */
final class SandboxServer
{
    private string $cookieJar;

    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        public readonly int $port,
        public readonly string $docroot
    ) {
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'sandbox-cookies-');
    }

    /**
     * @param array<string, string> $environment
     * @param bool                   $inherit     add this process's environment underneath
     * @param list<string>           $prefix      runs the server through this (setpriv to www-data)
     */
    public static function start(string $docroot, array $environment = [], ?string $router = null, bool $inherit = true, ?int $port = null, array $prefix = []): ?self
    {
        $port ??= self::freePort();
        if ($port === null) {
            return null;
        }

        $base = $inherit ? array_map('strval', getenv()) : ['PATH' => (string) getenv('PATH')];
        $environment += ['PHP_CLI_SERVER_WORKERS' => '4'];

        $command = [...$prefix, PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot];
        if ($router !== null) {
            $command[] = $router;
        }

        $log = sys_get_temp_dir() . '/sandbox-server-' . $port . '.log';
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $docroot,
            $environment + $base
        );

        if (!is_resource($process)) {
            return null;
        }

        $server = new self($process, $port, $docroot);

        for ($attempt = 0; $attempt < 50 && !$server->answers(); $attempt++) {
            usleep(100000);
        }

        return $server->answers() ? $server : null;
    }

    public static function freePort(): ?int
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return null;
        }

        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function answers(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            // With PHP_CLI_SERVER_WORKERS the server is a parent with forked
            // workers, and the workers do NOT exit with their parent: stop
            // them first, or every test leaves four servers running.
            $parent = (int) (proc_get_status($this->process)['pid'] ?? 0);
            foreach (self::childrenOf($parent) as $child) {
                @posix_kill($child, 15);
            }

            proc_terminate($this->process);
            proc_close($this->process);
        }

        @unlink($this->cookieJar);
        @unlink(sys_get_temp_dir() . '/sandbox-server-' . $this->port . '.log');
    }

    /**
     * One request, redirects not followed, cookies kept.
     *
     * @param array<string, string> $fields
     * @param list<string>          $headers
     *
     * @return array{status: int, location: string, body: string, headers: string}
     */
    public function request(string $method, string $path, array $fields = [], array $headers = [], int $timeout = 120, bool $cookies = true): array
    {
        $handle = curl_init($this->url($path));

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($cookies) {
            $options[CURLOPT_COOKIEJAR] = $this->cookieJar;
            $options[CURLOPT_COOKIEFILE] = $this->cookieJar;
        }

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        }

        curl_setopt_array($handle, $options);
        $raw = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $rawHeaders = substr($raw, 0, $headerSize);
        preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $location);

        return [
            'status' => $status,
            'location' => trim($location[1] ?? ''),
            'body' => substr($raw, $headerSize),
            'headers' => $rawHeaders,
        ];
    }

    /** @return list<int> the processes whose parent is $parent (Linux /proc) */
    private static function childrenOf(int $parent): array
    {
        if ($parent <= 0 || !is_dir('/proc')) {
            return [];
        }

        $children = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
            $fields = explode(' ', (string) @file_get_contents($stat));
            // pid (comm) state ppid …; comm cannot contain a space for php.
            if ((int) ($fields[3] ?? 0) === $parent) {
                $children[] = (int) $fields[0];
            }
        }

        return $children;
    }

    /** The server's own output, for a failure message. */
    public function log(): string
    {
        $path = sys_get_temp_dir() . '/sandbox-server-' . $this->port . '.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
