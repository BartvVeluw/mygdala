<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Test support, never part of the application: PHP's own built-in web server
 * on this checkout, started with an environment of the test's choosing, and a
 * plain HTTP client to talk to it.
 *
 * It exists for the tests that have to see a module switched on and off over
 * real HTTP. Which modules run is read from the environment a server was
 * STARTED with (MODULES.md), so a test cannot flip that for the php_test
 * container; it can start a server of its own with a different
 * MODULE_<KEY>_ENABLED instead. The server inherits this process's
 * environment first, which tests/bootstrap.php has already pointed at the test
 * database, so nothing it serves can reach development.
 *
 * It serves files, not .htaccess rewrites: a test asks for
 * /portfolio-detail.php?slug=… rather than /portfolio/…, exactly as
 * Tests\Service\PagePreviewAccessTest asks for /pagina.php?slug=….
 */
final class BuiltInServer
{
    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        public readonly int $port
    ) {
    }

    /**
     * A running server, or null when one could not be started here — the
     * caller then skips, the same way the HTTP tier does (TESTING.md).
     *
     * @param array<string, string> $environment on top of this process's own
     * @param string|null            $router      a project-relative router script, for a
     *                                            test that needs the ROUTING rather than the
     *                                            files — see tests/Support/dispatcher-router.php
     */
    public static function start(array $environment = [], ?string $router = null): ?self
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return null;
        }

        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $root = dirname(__DIR__, 2);
        $discard = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        $process = proc_open(
            $router === null
                ? [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root]
                : [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/' . ltrim($router, '/')],
            [0 => ['pipe', 'r'], 1 => ['file', $discard, 'w'], 2 => ['file', $discard, 'w']],
            $pipes,
            $root,
            $environment + array_map('strval', getenv())
        );

        if (!is_resource($process)) {
            return null;
        }

        $server = new self($process, $port);

        for ($attempt = 0; $attempt < 50 && !$server->answers(); $attempt++) {
            usleep(100000);
        }

        return $server;
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
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    /**
     * One request, redirects NOT followed — a write endpoint's answer is its
     * redirect. A POST is sent url-encoded, or as multipart the moment a file
     * travels with it, like a browser form.
     *
     * @param array<string, string> $fields
     * @param array<string, \CURLFile> $files
     * @param list<string> $headers extra request headers, such as the
     *        `Accept: application/json` that turns api/form-submit.php's
     *        redirect into the answer its fetch() reads
     * @return array{status: int, location: string, body: string, headers: string}
     */
    public function request(string $method, string $path, ?string $sessionId = null, array $fields = [], array $files = [], array $headers = []): array
    {
        // NEVER hold a session open while asking the server for one. PHP's
        // built-in server is single-threaded and session_start() takes an
        // exclusive lock on the session file, so a test process that still has
        // that same session open makes the request wait for its own lock until
        // curl gives up — a 30-second timeout reported as status 0, with
        // nothing in the server's log to explain it. Anything that reads the
        // signed-in administrator in the test process opens that session
        // (App\Service\Language\AdminTranslator does, through AdminLocale), so
        // closing it here rather than in every test is the only reliable place.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $handle = curl_init('http://127.0.0.1:' . $this->port . $path);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        if ($sessionId !== null) {
            $options[CURLOPT_COOKIE] = AdminTestSession::COOKIE . '=' . $sessionId;
        }

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $files === [] ? http_build_query($fields) : $fields + $files;
        }

        curl_setopt_array($handle, $options);

        $response = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        preg_match('/^Location:\s*(\S+)/mi', substr($response, 0, $headerSize), $location);

        return [
            'status' => $status,
            'location' => (string) ($location[1] ?? ''),
            'body' => substr($response, $headerSize),
            'headers' => substr($response, 0, $headerSize),
        ];
    }

    /**
     * One header of a response from request(), or '' when it has none: what
     * a page says about caching, indexing or framing.
     *
     * @param array{headers: string} $response
     */
    public static function header(array $response, string $name): string
    {
        preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\s*$/mi', $response['headers'], $match);

        return (string) ($match[1] ?? '');
    }
}
