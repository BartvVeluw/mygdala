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
 *
 * attach() gives the same client for a server this class did not start (the
 * Apache of a throwaway container, ApacheAcceptanceTest).
 */
final class SandboxServer
{
    private string $cookieJar;

    /** @var list<string> the command prefix the server runs under (setpriv to www-data) */
    private array $prefix = [];

    /** @var list<int>|null the server's processes, fixed the first time they are needed */
    private ?array $processes = null;

    /**
     * @param resource|null $process null for a server started elsewhere
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
        $server->prefix = $prefix;

        for ($attempt = 0; $attempt < 50 && !$server->answers(); $attempt++) {
            usleep(100000);
        }

        return $server->answers() ? $server : null;
    }

    /** A client for a web server that is already running on $port. */
    public static function attach(int $port, string $docroot): self
    {
        return new self(null, $port, $docroot);
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
            foreach (array_slice($this->processes(), 1) as $child) {
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
    public function request(string $method, string $path, array $fields = [], array $headers = [], float $timeout = 120, bool $cookies = true): array
    {
        $handle = curl_init($this->url($path));

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
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

    /**
     * The worker process that has $path open right now — the one serving the
     * request that works on it — or null.
     *
     * Another user's open files in /proc need CAP_SYS_PTRACE, which a Docker
     * container's root does not have; so the lookup runs as the server's own
     * user (the same prefix it was started with).
     */
    public function workerHolding(string $path): ?int
    {
        if (!is_resource($this->process)) {
            return null;
        }

        $lookup = <<<'PHP'
            [, $path, $pids] = $argv;
            foreach (explode(',', $pids) as $pid) {
                foreach (glob('/proc/' . $pid . '/fd/*') ?: [] as $descriptor) {
                    if (@readlink($descriptor) === $path) {
                        echo $pid;
                        exit;
                    }
                }
            }
            PHP;

        $process = proc_open([...$this->prefix, PHP_BINARY, '-r', $lookup, '--', $path, implode(',', $this->processes())], [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $pid = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($process);

        return ctype_digit($pid) ? (int) $pid : null;
    }

    /** The server's processes (pid, parent, state), for a failure message. */
    public function processTree(): string
    {
        $lines = [];
        foreach ($this->processes() as $pid) {
            $fields = explode(' ', (string) @file_get_contents('/proc/' . $pid . '/stat'));
            $lines[] = $pid . ' ppid ' . ($fields[3] ?? '-') . ' ' . ($fields[2] ?? 'gone');
        }

        return implode(', ', $lines);
    }

    /**
     * The server process and its workers. Fixed the first time it is asked
     * for: the built-in server's parent serves requests too, and once a test
     * has killed it, its workers belong to init and no longer look like its
     * children.
     *
     * @return list<int>
     */
    private function processes(): array
    {
        if (!is_resource($this->process)) {
            return [];
        }

        if ($this->processes === null) {
            $parent = (int) (proc_get_status($this->process)['pid'] ?? 0);
            $this->processes = [$parent, ...self::childrenOf($parent)];
        }

        return $this->processes;
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
