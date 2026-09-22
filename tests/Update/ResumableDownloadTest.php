<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Service\AppEnvironment;
use App\Update\HttpFetcher;
use App\Update\PackageDownload;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;
use Tests\Support\SandboxServer;

/**
 * The download step over several requests (PackageDownload,
 * HttpFetcher::resume()), against a real web server that honours ranges,
 * ignores them, answers them wrongly, drops the connection, stalls, or
 * changes the file in between (tests/Support/range-feed-router.php).
 *
 * Each call to request() is what ONE request of the download step does: open
 * the partial file from the cursor the update state kept, download until the
 * budget is used, close. The cursor it returns is what the next request gets
 * — never anything a browser sent.
 */
final class ResumableDownloadTest extends TestCase
{
    /** Three checkpoints and a bit. */
    private const SIZE = 3 * PackageDownload::CHECKPOINT_BYTES + 12345;

    private static ?SandboxServer $server = null;
    private static string $feed = '';

    private string $work = '';

    public static function setUpBeforeClass(): void
    {
        self::$feed = sys_get_temp_dir() . '/mygdala-range-feed-' . bin2hex(random_bytes(4));
        mkdir(self::$feed, 0777, true);
        self::$server = SandboxServer::start(self::$feed, [], dirname(__DIR__) . '/Support/range-feed-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        FeedFixture::removeDirectory(self::$feed);
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            $this->markTestSkipped('could not start a local web server for the feed');
        }

        AppEnvironment::overrideForTests('testing');
        file_put_contents(self::$feed . '/package.zip', random_bytes(self::SIZE));
        @unlink(self::$feed . '/requests.log');
        @unlink(self::$feed . '/package-requests.count');
        $this->mode([]);

        $this->work = sys_get_temp_dir() . '/mygdala-download-' . bin2hex(random_bytes(4));
        mkdir($this->work);
    }

    protected function tearDown(): void
    {
        AppEnvironment::overrideForTests(null);
        FeedFixture::removeDirectory($this->work);
    }

    /** @param array<string, mixed> $mode see range-feed-router.php */
    private function mode(array $mode): void
    {
        file_put_contents(self::$feed . '/mode.json', json_encode($mode));
    }

    /** @return list<array{range: ?string, if_range: ?string}> what the server was asked, in order */
    private function requests(): array
    {
        $lines = file(self::$feed . '/requests.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(static fn (string $line): array => (array) json_decode($line, true), $lines);
    }

    /**
     * One request of the download step.
     *
     * @param array<string, mixed> $cursor
     *
     * @return array{0: array<string, mixed>, 1: PackageDownload}
     */
    private function request(array $cursor, float $seconds = 5.0, int $size = self::SIZE): array
    {
        $download = PackageDownload::open($this->work . '/package.zip', $size, $cursor);

        try {
            (new HttpFetcher(5))->resume(self::$server->url('/package.zip'), $download, microtime(true) + $seconds);
        } finally {
            $cursor = $download->close();
        }

        return [$cursor, $download];
    }

    private function part(): string
    {
        return $this->work . '/package.zip' . PackageDownload::SUFFIX;
    }

    /** @return list<string> the log keys of what happened in that request */
    private static function events(PackageDownload $download): array
    {
        return array_map(static fn (array $event): string => $event[0] . (isset($event[1]['reason']) ? ':' . $event[1]['reason'] : ''), $download->events());
    }

    private function assertFinishedAsTheServedFile(PackageDownload $download): void
    {
        $this->assertTrue($download->isComplete());
        $download->finish();
        $this->assertFileEquals(self::$feed . '/package.zip', $this->work . '/package.zip');
        $this->assertFileDoesNotExist($this->part());
    }

    public function testAnUninterruptedDownloadIsOneRequest(): void
    {
        [, $download] = $this->request([]);

        $this->assertFinishedAsTheServedFile($download);
        $this->assertSame([['range' => null, 'if_range' => null]], $this->requests());
    }

    public function testADroppedConnectionIsContinuedWithARangeFromTheSavedOffset(): void
    {
        $cut = (int) (self::SIZE * 0.4);
        $this->mode(['drop_after' => $cut, 'drop_times' => 1]);

        [$cursor, $first] = $this->request([]);
        $this->assertFalse($first->isComplete());
        $this->assertSame($cut, $cursor['bytes'], 'the cursor holds exactly what arrived');
        $this->assertSame($cut, filesize($this->part()));
        $this->assertSame(hash('sha256', substr((string) file_get_contents(self::$feed . '/package.zip'), 0, $cut)), $cursor['sha256']);

        [$cursor, $second] = $this->request($cursor);

        $asked = $this->requests()[1];
        $this->assertSame('bytes=' . $cut . '-', $asked['range']);
        $this->assertNotNull($asked['if_range'], 'the resume is conditional on the same file');
        $this->assertTrue($cursor['ranges']);
        $this->assertSame(['update.log.download_resumed'], self::events($second));
        $this->assertSame(self::SIZE - $cut, $second->received(), 'only the rest was downloaded');
        $this->assertFinishedAsTheServedFile($second);
    }

    public function testASourceThatIgnoresRangesStartsOverFromByteZeroAndNeverAppendsItsAnswer(): void
    {
        $cut = (int) (self::SIZE * 0.4);
        $this->mode(['ranges' => 'ignore', 'drop_after' => $cut, 'drop_times' => 1]);

        [$cursor] = $this->request([]);
        [$cursor, $second] = $this->request($cursor);

        $this->assertSame('bytes=' . $cut . '-', $this->requests()[1]['range'], 'it was asked for a range');
        $this->assertSame(['update.log.download_resumed', 'update.log.download_restarted:range_ignored'], self::events($second));
        $this->assertFalse($cursor['ranges'], 'and is never asked for one again');
        $this->assertSame(1, $cursor['restarts']);
        $this->assertSame(self::SIZE, $second->received(), 'the whole file, from byte 0');
        $this->assertFinishedAsTheServedFile($second);
    }

    public function testASourceWithoutRangesThatNeverFinishesEndsTheDownloadAfterThreeRestarts(): void
    {
        $this->mode(['ranges' => 'ignore', 'drop_after' => PackageDownload::CHECKPOINT_BYTES]);

        [$cursor] = $this->request([]);
        for ($restart = 1; $restart <= PackageDownload::MAX_RESTARTS; $restart++) {
            [$cursor, $download] = $this->request($cursor);
            $this->assertSame($restart, $cursor['restarts']);
            $this->assertSame(PackageDownload::CHECKPOINT_BYTES, $download->offset(), 'each attempt starts at byte 0 again');
        }

        try {
            $this->request($cursor);
            $this->fail('a download that keeps starting over must end');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.download_unstable', $e->messageKey);
        }

        $ranges = array_column($this->requests(), 'range');
        $this->assertSame([null, 'bytes=' . PackageDownload::CHECKPOINT_BYTES . '-', null, null], $ranges, 'after one ignored range, no range is asked again');
    }

    /** @return iterable<string, array{0: string}> */
    public static function answersThatAreNoContinuation(): iterable
    {
        yield 'a range that starts elsewhere' => ['wrong-start'];
        yield 'a 206 without Content-Range' => ['no-content-range'];
    }

    /**
     * @dataProvider answersThatAreNoContinuation
     */
    public function testAPartialAnswerThatIsNotTheContinuationIsNeverAppended(string $ranges): void
    {
        $cut = (int) (self::SIZE * 0.4);
        $this->mode(['ranges' => $ranges, 'drop_after' => $cut, 'drop_times' => 1]);

        [$cursor] = $this->request([]);
        [$cursor, $second] = $this->request($cursor);

        $this->assertSame(['update.log.download_resumed', 'update.log.download_restarted:range_invalid'], self::events($second));
        $this->assertSame(['bytes=' . $cut . '-', null], array_column(array_slice($this->requests(), 1), 'range'), 'asked again, without a range');
        $this->assertFalse($cursor['ranges']);
        $this->assertFinishedAsTheServedFile($second);
    }

    public function testAContentRangeForAFileOfAnotherSizeIsRefused(): void
    {
        $this->mode(['ranges' => 'wrong-total', 'drop_after' => 1000, 'drop_times' => 1]);

        [$cursor] = $this->request([]);

        try {
            $this->request($cursor);
            $this->fail('a source serving a file of another size must be refused');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.package_size_mismatch', $e->messageKey);
        }
    }

    /** @return iterable<string, array{0: string, 1: ?string}> the new build's ETag, and the validator kept from it */
    public static function newBuilds(): iterable
    {
        yield 'with a strong ETag' => ['"build-2"', '"build-2"'];
        // What Apache sends for a file changed less than a second ago.
        yield 'with only a weak ETag' => ['W/"build-2"', null];
    }

    /**
     * @dataProvider newBuilds
     */
    public function testAFileChangedOnTheSourceBetweenTwoRequestsStartsOverInsteadOfMixing(string $newEtag, ?string $kept): void
    {
        $this->mode(['etag' => '"build-1"', 'drop_after' => 1000000, 'drop_times' => 1]);
        [$cursor] = $this->request([]);
        $this->assertSame('"build-1"', $cursor['validator']);

        // A new build is published under the same URL.
        file_put_contents(self::$feed . '/package.zip', random_bytes(self::SIZE));
        $this->mode(['etag' => $newEtag]);

        [$cursor, $second] = $this->request($cursor);

        $this->assertSame('"build-1"', $this->requests()[1]['if_range']);
        $this->assertSame(['update.log.download_resumed', 'update.log.download_restarted:source_changed'], self::events($second));
        $this->assertNull($cursor['ranges'] ?? null, 'a changed file says nothing against ranges');
        $this->assertSame($kept, $cursor['validator'], 'a weak ETag cannot be used for If-Range');
        $this->assertFinishedAsTheServedFile($second);
    }

    public function testADamagedPartialFileStartsOverRatherThanBeingContinued(): void
    {
        $this->mode(['drop_after' => (int) (self::SIZE * 0.5), 'drop_times' => 1]);
        [$cursor] = $this->request([]);

        // Same length, one byte different: only the checkpoint's hash can tell.
        $handle = fopen($this->part(), 'r+b');
        fseek($handle, 1000);
        $byte = fread($handle, 1);
        fseek($handle, 1000);
        fwrite($handle, chr(ord($byte) ^ 0xff));
        fclose($handle);

        [, $second] = $this->request($cursor);

        $this->assertSame(['update.log.download_restarted:partial_damaged'], self::events($second));
        $this->assertNull($this->requests()[1]['range'], 'nothing is resumed from damaged bytes');
        $this->assertFinishedAsTheServedFile($second);
    }

    public function testAPartialFileShorterThanItsCheckpointStartsOver(): void
    {
        $this->mode(['drop_after' => (int) (self::SIZE * 0.5), 'drop_times' => 1]);
        [$cursor] = $this->request([]);

        $handle = fopen($this->part(), 'r+b');
        ftruncate($handle, 1000);
        fclose($handle);

        [, $second] = $this->request($cursor);

        $this->assertSame(['update.log.download_restarted:partial_short'], self::events($second));
        $this->assertFinishedAsTheServedFile($second);
    }

    public function testAKilledRequestKeepsItsLastCheckpointAndLosesOnlyWhatCameAfter(): void
    {
        $this->mode(['drop_after' => (int) (2.5 * PackageDownload::CHECKPOINT_BYTES), 'drop_times' => 1]);

        // The request dies before it can close(): what survives is what the
        // checkpoints saved in the update state along the way.
        $saved = [];
        $download = PackageDownload::open($this->work . '/package.zip', self::SIZE, []);
        $download->onCheckpoint(static function (array $cursor) use (&$saved): void {
            $saved = $cursor;
        });
        (new HttpFetcher(5))->resume(self::$server->url('/package.zip'), $download, microtime(true) + 5);
        unset($download);

        // A checkpoint falls on the first read after every CHECKPOINT_BYTES.
        $this->assertGreaterThanOrEqual(2 * PackageDownload::CHECKPOINT_BYTES, $saved['bytes']);
        $this->assertLessThan((int) (2.5 * PackageDownload::CHECKPOINT_BYTES), $saved['bytes']);
        $this->assertSame((int) (2.5 * PackageDownload::CHECKPOINT_BYTES), filesize($this->part()), 'more bytes on disk than the checkpoint vouches for');

        [, $second] = $this->request($saved);

        $this->assertSame('bytes=' . $saved['bytes'] . '-', $this->requests()[1]['range'], 'resumed at the checkpoint; the unvouched tail was cut');
        $this->assertFinishedAsTheServedFile($second);
    }

    public function testEveryRequestStopsAtItsBudgetAndTheNextGoesOn(): void
    {
        $this->mode(['rate' => 1000000]);

        $started = microtime(true);
        [$cursor, $first] = $this->request([], 1.0);
        $took = microtime(true) - $started;

        $this->assertFalse($first->isComplete());
        $this->assertGreaterThan(0, $first->received());
        $this->assertLessThan(2.5, $took, 'a request ends at its budget, not when the download ends');

        $this->mode([]);
        [, $second] = $this->request($cursor);
        $this->assertFinishedAsTheServedFile($second);
    }

    public function testARequestThatReceivesNothingIsAFailureNotProgress(): void
    {
        $this->mode(['stall_after' => 0]);

        $started = microtime(true);
        try {
            $this->request([], 1.0);
            $this->fail('a stalled source must fail the request');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.download_failed', $e->messageKey);
        }

        $this->assertLessThan(4.0, microtime(true) - $started, 'the wait is bounded by the budget, not the 30-second stall');
    }

    public function testThePackageNeverGrowsPastTheSizeInTheManifest(): void
    {
        try {
            $this->request([], 5.0, self::SIZE - 10);
            $this->fail('a larger file than the manifest names must be refused');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.download_too_large', $e->messageKey);
        }

        $this->assertFileDoesNotExist($this->work . '/package.zip');
        $this->assertLessThanOrEqual(self::SIZE - 10, (int) @filesize($this->part()));
    }
}
