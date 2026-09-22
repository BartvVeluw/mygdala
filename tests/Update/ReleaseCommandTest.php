<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * scripts/release.php as a program. No release ships it
 * (App\Update\Ownership), but a development checkout serves its scripts/
 * folder like any other. On Apache .htaccess refuses that folder; for every
 * server that reads no .htaccess the script refuses a web request itself,
 * which is why the web half of this test uses PHP's own built-in server.
 *
 * Before the guard, a visit died on the undefined $argv with PHP's fatal
 * error on screen: the file's path and a stack trace.
 */
final class ReleaseCommandTest extends TestCase
{
    private const REFUSAL = 'This script can only be run from the command line.';

    private ?BuiltInServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string} exit code, stdout and stderr together
     */
    private static function runCommand(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/release.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    public function testItStillRunsFromTheCommandLine(): void
    {
        // Past the guard and the autoloader, it reads its arguments and
        // answers as the command-line tool it is.
        [$status, $output] = self::runCommand([]);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('release: usage: php scripts/release.php keygen|build|verify', $output);

        [$status, $output] = self::runCommand(['verify', '--public-key=not-a-key']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('release: --public-key must be a base64 Ed25519 public key', $output);
        $this->assertStringNotContainsString(self::REFUSAL, $output);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function webRequests(): iterable
    {
        yield 'a visit' => ['GET', '/scripts/release.php'];
        yield 'a command in the query string' => ['GET', '/scripts/release.php?keygen+--out=/tmp'];
        yield 'a form post' => ['POST', '/scripts/release.php'];
    }

    /** @dataProvider webRequests */
    public function testAWebRequestIsRefusedAndSaysNothingAboutTheServer(string $method, string $path): void
    {
        $this->server = BuiltInServer::start();
        if ($this->server === null || !$this->server->answers()) {
            $this->markTestSkipped('PHP\'s built-in server could not be started here');
        }

        $response = $this->server->request($method, $path);

        $this->assertSame(403, $response['status']);
        $this->assertSame(self::REFUSAL, $response['body']);

        foreach ([dirname(__DIR__, 2), 'release.php', 'Stack trace', 'Fatal error', 'Warning', 'argv'] as $leak) {
            $this->assertStringNotContainsString($leak, $response['headers'] . $response['body'], $leak);
        }
    }
}
