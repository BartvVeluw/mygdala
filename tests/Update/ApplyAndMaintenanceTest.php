<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\FileApplier;
use App\Update\FileBackup;
use App\Update\LocalChanges;
use App\Update\MaintenanceGuard;
use App\Update\MaintenanceMode;
use App\Update\ReleaseDescriptor;
use App\Update\UpdatePlanner;
use App\Update\UpdateStateStore;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * Applying a plan to a tree and taking it back (FileApplier, FileBackup), and
 * the maintenance gate in front of the site (MaintenanceGuard). The trees
 * here are small synthetic releases; the upgrade tests do the same with real
 * Mygdala releases over HTTP.
 */
final class ApplyAndMaintenanceTest extends TestCase
{
    private string $base = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mygdala-apply-' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/site';
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        FeedFixture::removeDirectory($this->base);
    }

    /** @param array<string, string> $files */
    private static function write(string $directory, array $files): void
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname($directory . '/' . $path), 0777, true);
            file_put_contents($directory . '/' . $path, $content);
        }
    }

    /** @param array<string, string> $files */
    private static function release(string $version, array $files): ReleaseDescriptor
    {
        return new ReleaseDescriptor($version, $version, '2026-10-01T00:00:00Z', '8.1', '5.7', '10.3', [], 0, '', 1, array_map(
            static fn (string $content): string => hash('sha256', $content),
            $files
        ));
    }

    /**
     * An installed 0.1.0 and a staged 0.2.0 that changes, adds and removes
     * files, plus installation data that must come through untouched.
     *
     * @return array{0: FileApplier, 1: \App\Update\UpdatePlan, 2: ReleaseDescriptor, 3: ReleaseDescriptor}
     */
    private function scenario(): array
    {
        $old = ['VERSION' => "0.1.0\n", 'index.php' => 'old index', 'src/Keep.php' => 'keep', 'src/Legacy/Gone.php' => 'gone', 'assets/css/core.css' => 'old css'];
        $new = ['VERSION' => "0.2.0\n", 'index.php' => 'new index', 'src/Keep.php' => 'keep', 'src/Fresh/New.php' => 'new', 'assets/css/core.css' => 'new css'];

        $installed = self::release('0.1.0', $old);
        $target = self::release('0.2.0', $new);

        self::write($this->root, $old + ['.env' => 'SECRET=1', 'assets/media/photo.jpg' => 'jpeg', 'release.json' => $installed->toJson()]);
        self::write($this->base . '/staging', $new + ['release.json' => $target->toJson()]);

        $plan = (new UpdatePlanner($this->root))->plan($installed, $target);
        $backup = new FileBackup($this->root, $this->base . '/backup');
        $this->assertNull($backup->copy($plan->pathsToBackUp(), $installed, 0, 60));

        $applier = new FileApplier($this->root, $this->base . '/staging', $backup, $this->base . '/journal', 'test');

        return [$applier, $plan, $installed, $target];
    }

    /**
     * An installed 0.1.0 and a staged 0.2.0 with $bulk new public scripts on
     * top: a changed template and stylesheet, a retired script, and runtime
     * code (src/, admin/) that changes, appears and disappears.
     *
     * @return array{0: FileApplier, 1: \App\Update\UpdatePlan, 2: ReleaseDescriptor, 3: ReleaseDescriptor}
     */
    private function bulkScenario(int $bulk): array
    {
        $old = ['VERSION' => "0.1.0\n", 'index.php' => 'old index', 'src/Keep.php' => 'keep', 'src/Legacy/Gone.php' => 'gone',
            'admin/updates.php' => 'old screen', 'assets/css/core.css' => 'old css', 'assets/js/retired.js' => 'retired'];
        $new = ['VERSION' => "0.2.0\n", 'index.php' => 'new index', 'src/Keep.php' => 'keep v2', 'src/Fresh/New.php' => 'new',
            'admin/updates.php' => 'new screen', 'assets/css/core.css' => 'new css'];
        for ($i = 0; $i < $bulk; $i++) {
            $new[sprintf('assets/js/bulk-%02d.js', $i)] = 'bulk ' . $i;
        }

        $installed = self::release('0.1.0', $old);
        $target = self::release('0.2.0', $new);

        self::write($this->root, $old + ['.env' => 'SECRET=1', 'release.json' => $installed->toJson()]);
        self::write($this->base . '/staging', $new + ['release.json' => $target->toJson()]);

        $plan = (new UpdatePlanner($this->root))->plan($installed, $target);
        file_put_contents($this->base . '/plan.json', $plan->toJson());
        $backup = new FileBackup($this->root, $this->base . '/backup');
        $this->assertNull($backup->copy($plan->pathsToBackUp(), $installed, 0, 60));

        return [$this->applier(), $plan, $installed, $target];
    }

    private function applier(): FileApplier
    {
        return new FileApplier($this->root, $this->base . '/staging', new FileBackup($this->root, $this->base . '/backup'), $this->base . '/journal', 'test');
    }

    /**
     * The apply step, request after request, the way the Updater drives it.
     *
     * @return list<list<int>> the operations each request ran
     */
    private static function applyInRequests(FileApplier $applier, \App\Update\UpdatePlan $plan, int $batch = FileApplier::BATCH, int $from = 0): array
    {
        $requests = [];
        $next = $from;
        do {
            $ran = [];
            $applier->onEachOperation(static function (int $number) use (&$ran): void {
                $ran[] = $number;
            });
            $next = $applier->apply($plan, [], $next, 60.0, $batch);
            $requests[] = $ran;
        } while ($next !== null);
        $applier->onEachOperation(null);

        return $requests;
    }

    public function testApplyingAPlanTurnsTheTreeIntoTheNewReleaseAndLeavesInstallationDataAlone(): void
    {
        [$applier, $plan, , $target] = $this->scenario();

        self::applyInRequests($applier, $plan);

        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
        $this->assertFileDoesNotExist($this->root . '/src/Legacy/Gone.php');
        $this->assertDirectoryDoesNotExist($this->root . '/src/Legacy', 'a folder only the removed file used goes too');
        $this->assertSame('0.2.0', ReleaseDescriptor::installed($this->root)?->version);
        $this->assertStringEqualsFile($this->root . '/.env', 'SECRET=1');
        $this->assertStringEqualsFile($this->root . '/assets/media/photo.jpg', 'jpeg');
        $this->assertSame([], glob($this->root . '/{,*/,*/*/}*.tmp', GLOB_BRACE) ?: [], 'no temporary file is left behind');
    }

    public function testReleaseJsonIsTheLastThingWritten(): void
    {
        [$applier, $plan] = $this->scenario();
        $operations = FileApplier::operations($plan);

        $this->assertSame(['commit', 'release.json'], end($operations));
        $this->assertSame(['write', 'VERSION'], $operations[count($operations) - 2]);
    }

    public function testTheFilesThisRequestIsMadeOfAreWrittenAfterEverythingElse(): void
    {
        [, $plan] = $this->scenario();
        $writes = array_column(array_filter(FileApplier::operations($plan, ['index.php']), fn ($o) => $o[0] === 'write'), 1);

        $this->assertSame('index.php', $writes[count($writes) - 2], 'index.php is critical here, so only VERSION follows it');
    }

    public function testWhatTheOpenScreensLoadChangesOnlyInTheCodeSwitchAtTheEnd(): void
    {
        [, $plan] = $this->bulkScenario(5);
        $operations = FileApplier::operations($plan);
        $switch = FileApplier::switchPoint($operations);

        foreach (array_slice($operations, 0, $switch) as [$action, $path]) {
            $this->assertFalse(FileApplier::isRuntime($path), $action . ' ' . $path . ' comes before the switch');
        }
        foreach (array_slice($operations, $switch) as [$action, $path]) {
            $this->assertTrue(FileApplier::isRuntime($path) || in_array($action, ['rmdir', 'commit'], true) || $path === 'VERSION', $action . ' ' . $path . ' is in the switch');
        }

        $this->assertSame(['write', 'admin/updates.php'], $operations[$switch]);
        $this->assertTrue(FileApplier::isRuntime('.htaccess'), 'the rules the open screens are served under change with them');
        $this->assertTrue(FileApplier::isRuntime('assets/fonts/personalization/.htaccess'));
        $this->assertFalse(FileApplier::isRuntime('assets/css/core.css'));
        $this->assertContains(['delete', 'assets/js/retired.js'], array_slice($operations, 0, $switch), 'a retired public script goes in the batches');
        $this->assertContains(['delete', 'src/Legacy/Gone.php'], array_slice($operations, $switch), 'retired code goes in the switch');
    }

    public function testEachRequestRunsABoundedBatchAndTheCodeSwitchGetsARequestOfItsOwn(): void
    {
        [$applier, $plan, , $target] = $this->bulkScenario(30);
        $operations = FileApplier::operations($plan);
        $switch = FileApplier::switchPoint($operations);
        $this->assertSame(33, $switch, '32 writes and one delete before the switch');

        $requests = self::applyInRequests($applier, $plan, 12);

        $this->assertSame([range(0, 11), range(12, 23), range(24, 32), range(33, count($operations) - 1)], $requests);
        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
    }

    public function testAnApplyLeftAfterThirtyPercentGoesOnExactlyFromItsCursor(): void
    {
        [$applier, $plan, $installed, $target] = $this->bulkScenario(30);
        $operations = FileApplier::operations($plan);

        $next = $applier->apply($plan, [], 0, 60.0, 12);
        $this->assertSame(12, $next);
        $this->assertSame(30, intdiv(100 * $next, count($operations)));

        // Between the requests: the first twelve are the new release, the rest the old one.
        foreach ($operations as $number => [$action, $path]) {
            if ($action !== 'write' || $path === 'VERSION') {
                continue;
            }
            $expected = $number < $next ? $target->files[$path] : ($installed->files[$path] ?? null);
            $this->assertSame($expected, is_file($this->root . '/' . $path) ? hash_file('sha256', $this->root . '/' . $path) : null, $path);
        }
        $this->assertSame('0.1.0', ReleaseDescriptor::installed($this->root)?->version, 'release.json is still the old one');
        $this->assertStringEqualsFile($this->root . '/admin/updates.php', 'old screen', 'the open screens are still the old release');

        // The next request is a new object, as in a new PHP process.
        $requests = self::applyInRequests($this->applier(), $plan, 12, $next);

        $this->assertSame(12, $requests[0][0], 'it picks up at the cursor');
        $this->assertSame(range(12, count($operations) - 1), array_merge(...$requests), 'nothing before the cursor runs twice');
        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
    }

    public function testAJournalAheadOfItsCursorIsSkippedNotRedone(): void
    {
        [$applier, $plan, , $target] = $this->bulkScenario(30);
        $applier->apply($plan, [], 0, 60.0, 12);

        // The request journaled twelve operations but died before the cursor
        // was saved past the fifth.
        $requests = self::applyInRequests($this->applier(), $plan, 12, 5);

        $this->assertSame(12, $requests[0][0], 'operations 5 to 11 are in the journal and do not run again');
        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
    }

    public function testAJournalBehindItsCursorOrFromAnotherPlanIsRefused(): void
    {
        [$applier, $plan] = $this->bulkScenario(30);
        $applier->apply($plan, [], 0, 60.0, 12);

        try {
            $this->applier()->apply($plan, [], 20, 60.0, 12);
            $this->fail('a cursor past the journal must be refused');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.apply_failed', $e->messageKey);
        }

        file_put_contents($this->base . '/journal', "12 write assets/js/somewhere-else.js\n", FILE_APPEND);
        try {
            $this->applier()->apply($plan, [], 12, 60.0, 12);
            $this->fail('a journal that numbers other operations must be refused');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.apply_failed', $e->messageKey);
        }
    }

    public function testAJournalLineCutOffByAKilledRequestIsDropped(): void
    {
        [$applier, $plan, , $target] = $this->bulkScenario(30);
        $applier->apply($plan, [], 0, 60.0, 12);
        file_put_contents($this->base . '/journal', '12 wri', FILE_APPEND);

        self::applyInRequests($this->applier(), $plan, 12, 12);

        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
        $this->assertStringNotContainsString('12 wri12', (string) file_get_contents($this->base . '/journal'));
    }

    /**
     * Runs one request's apply in a process of its own (tests/Support/
     * apply-in-child.php).
     *
     * @return resource
     */
    private function childApply(int $from, int $killAfter)
    {
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__) . '/Support/apply-in-child.php', dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->root, $this->base . '/staging', $this->base . '/backup', $this->base . '/journal', $this->base . '/plan.json',
            (string) $from, (string) $killAfter,
        ], [1 => ['file', $this->base . '/child.out', 'a'], 2 => ['file', $this->base . '/child.out', 'a']], $pipes);
        $this->assertIsResource($process);

        return $process;
    }

    public function testARequestKilledInTheMiddleOfABatchIsFinishedFromItsJournal(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('needs ext-posix');
        }

        [, $plan, , $target] = $this->bulkScenario(30);
        $operations = FileApplier::operations($plan);

        // The first request of the step starts at 0 and is killed for real
        // after its eighth operation — the cursor is still 0.
        proc_close($this->childApply(0, 7));
        $this->assertStringNotContainsString('finished', (string) file_get_contents($this->base . '/child.out'), 'the child was killed');
        $journal = file($this->base . '/journal', FILE_IGNORE_NEW_LINES);
        $this->assertCount(8, $journal);
        $this->assertSame('0.1.0', ReleaseDescriptor::installed($this->root)?->version);

        $requests = self::applyInRequests($this->applier(), $plan, 12, 0);

        $this->assertSame(8, $requests[0][0], 'the journaled operations are not redone');
        $this->assertSame(range(8, count($operations) - 1), array_merge(...$requests));
        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
    }

    public function testARequestKilledBetweenAWriteAndItsJournalLineRedoesOnlyThatWrite(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('needs ext-posix');
        }

        [$applier, $plan, , $target] = $this->bulkScenario(30);
        $operations = FileApplier::operations($plan);
        $applier->apply($plan, [], 0, 60.0, 12);
        [, $path] = $operations[12];

        // Hold the journal: the child writes operation 12, then waits for the
        // journal lock — and is killed there, its write done and unrecorded.
        $lock = fopen($this->base . '/journal', 'c');
        flock($lock, LOCK_EX);
        $process = $this->childApply(12, -1);

        $deadline = microtime(true) + 20;
        while (@hash_file('sha256', $this->root . '/' . $path) !== $target->files[$path] && microtime(true) < $deadline) {
            usleep(5000);
        }
        $this->assertSame($target->files[$path], @hash_file('sha256', $this->root . '/' . $path), 'the child wrote operation 12');

        posix_kill((int) proc_get_status($process)['pid'], 9);
        proc_close($process);
        flock($lock, LOCK_UN);
        $this->assertStringNotContainsString('finished', (string) file_get_contents($this->base . '/child.out'));
        fclose($lock);

        $this->assertCount(12, file($this->base . '/journal', FILE_IGNORE_NEW_LINES), 'operation 12 never reached the journal');

        $requests = self::applyInRequests($this->applier(), $plan, 12, 12);

        $this->assertSame(12, $requests[0][0], 'the unrecorded write runs again — the same bytes, harmlessly');
        $this->assertSame(1, count(array_filter(file($this->base . '/journal', FILE_IGNORE_NEW_LINES) ?: [], static fn (string $line): bool => str_starts_with($line, '12 '))));
        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
        $this->assertSame([], glob($this->root . '/{,*/,*/*/}*.tmp', GLOB_BRACE) ?: []);
    }

    public function testAnInterruptedApplyIsFinishedWhereItStopped(): void
    {
        [$applier, $plan, , $target] = $this->scenario();

        $applier->onEachOperation(static function (int $number): void {
            if ($number === 1) {
                throw new \RuntimeException('simulated: the request was killed');
            }
        });

        try {
            $applier->apply($plan);
            $this->fail('the simulated kill must stop the apply');
        } catch (\RuntimeException) {
        }

        $this->assertSame('0.1.0', ReleaseDescriptor::installed($this->root)?->version, 'half an apply is not a new release');

        $applier->onEachOperation(null);
        self::applyInRequests($applier, $plan);

        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
    }

    public function testARolledBackApplyIsTheOldReleaseAgainByteForByte(): void
    {
        [$applier, $plan, $installed] = $this->scenario();

        self::applyInRequests($applier, $plan);
        $applier->rollback($plan);

        $this->assertTrue(LocalChanges::detect($this->root, $installed)->isClean());
        $this->assertFileDoesNotExist($this->root . '/src/Fresh/New.php');
        $this->assertDirectoryDoesNotExist($this->root . '/src/Fresh');
        $this->assertSame('0.1.0', ReleaseDescriptor::installed($this->root)?->version);
        $this->assertStringEqualsFile($this->root . '/.env', 'SECRET=1');
    }

    public function testAnApplyThatFailsAfterSeveralBatchesIsRolledBackCompletely(): void
    {
        [$applier, $plan, $installed] = $this->bulkScenario(30);
        $operations = FileApplier::operations($plan);
        $this->assertSame(12, $applier->apply($plan, [], 0, 60.0, 12));
        $this->assertSame(24, $this->applier()->apply($plan, [], 12, 60.0, 12));

        // The staged copy of a later file no longer matches the release.
        [, $broken] = $operations[28];
        file_put_contents($this->base . '/staging/' . $broken, 'damaged after the check');
        // … and a killed request once left a temporary copy next to a target.
        file_put_contents($this->root . '/index.php.mygdala-test.tmp', '<?php // half a copy');

        try {
            $this->applier()->apply($plan, [], 24, 60.0, 12);
            $this->fail('a damaged staged file must stop the apply');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.apply_failed', $e->messageKey);
        }

        $this->applier()->rollback($plan);

        $this->assertTrue(LocalChanges::detect($this->root, $installed)->isClean());
        $this->assertSame('0.1.0', ReleaseDescriptor::installed($this->root)?->version);
        $this->assertSame([], glob($this->root . '/assets/js/bulk-*') ?: [], 'every added file is gone');
        $this->assertStringEqualsFile($this->root . '/assets/js/retired.js', 'retired', 'the retired script is back');
        $this->assertSame([], glob($this->root . '/{,*/,*/*/}*.tmp', GLOB_BRACE) ?: [], 'no temporary copy is left');
        $this->assertStringEqualsFile($this->root . '/.env', 'SECRET=1');
    }

    public function testARollbackThatDiesHalfwayLeavesNoJournalAnApplyCouldGoForwardWith(): void
    {
        [$applier, $plan, $installed] = $this->bulkScenario(30);
        $this->assertSame(12, $applier->apply($plan, [], 0, 60.0, 12));

        // The rollback dies part of the way, at a file it cannot put back.
        rename($this->base . '/backup/files/index.php', $this->base . '/index.php.away');
        try {
            $this->applier()->rollback($plan);
            $this->fail('a missing backup file must stop the rollback');
        } catch (UpdateException) {
        }

        $this->assertFileDoesNotExist($this->base . '/journal', 'the journal went before the first file came back');
        try {
            $this->applier()->apply($plan, [], 12, 60.0, 12);
            $this->fail('an apply may not go on over half-restored files');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.apply_failed', $e->messageKey);
        }

        // Only the way back remains, and it can be run again to the end.
        rename($this->base . '/index.php.away', $this->base . '/backup/files/index.php');
        $this->applier()->rollback($plan);
        $this->assertTrue(LocalChanges::detect($this->root, $installed)->isClean());
    }

    public function testAnApplyThatFailsHalfwayCanBeRolledBackFromWhereverItStopped(): void
    {
        [$applier, $plan, $installed] = $this->scenario();

        // A directory where a file has to go: the rename fails, for root too.
        mkdir($this->root . '/src/Fresh/New.php', 0777, true);

        try {
            self::applyInRequests($applier, $plan);
            $this->fail('writing onto a directory must fail');
        } catch (UpdateException $e) {
            $this->assertSame('update.error.apply_failed', $e->messageKey);
        }

        rmdir($this->root . '/src/Fresh/New.php');
        $applier->rollback($plan);

        $this->assertTrue(LocalChanges::detect($this->root, $installed)->isClean());
        $this->assertSame('0.1.0', ReleaseDescriptor::installed($this->root)?->version);
    }
    public function testABackupCopyThatIsNotTheInstalledReleaseIsRefused(): void
    {
        [, $plan, $installed] = $this->scenario();
        file_put_contents($this->root . '/index.php', 'edited after the check');

        $this->expectException(UpdateException::class);
        (new FileBackup($this->root, $this->base . '/backup2'))->copy($plan->pathsToBackUp(), $installed, 0, 60);
    }

    // --- maintenance -----------------------------------------------------

    public function testWithoutAFlagEveryRequestPasses(): void
    {
        $this->assertSame(MaintenanceGuard::PASS, MaintenanceGuard::decide(null, $this->root, $this->root . '/index.php', '/', ''));
    }

    public function testDuringMaintenanceTheSiteIsClosedButTheUpdaterIsNot(): void
    {
        self::write($this->root, [
            'index.php' => '', 'admin/updates.php' => '', 'admin/login.php' => '', 'admin/pages.php' => '',
            'api/admin/updates-step.php' => '', 'api/admin/updates-abort.php' => '', 'api/admin/update-page.php' => '', 'api/checkout.php' => '',
        ]);
        (new MaintenanceMode($this->root))->enable('20261001-120000-abcdef', 'the-token');
        $flag = (new MaintenanceMode($this->root))->read();

        $decide = fn (string $script, string $uri, string $token = ''): string => MaintenanceGuard::decide($flag, $this->root, $this->root . '/' . $script, $uri, $token);

        $this->assertSame(MaintenanceGuard::PAGE_UNAVAILABLE, $decide('index.php', '/'));
        $this->assertSame(MaintenanceGuard::PAGE_UNAVAILABLE, $decide('index.php', '/en/shop'));
        $this->assertSame(MaintenanceGuard::API_UNAVAILABLE, $decide('api/checkout.php', '/api/checkout.php'));
        $this->assertSame(MaintenanceGuard::API_UNAVAILABLE, $decide('api/admin/update-page.php', '/api/admin/update-page.php'));
        $this->assertSame(MaintenanceGuard::REDIRECT, $decide('admin/pages.php', '/admin/pages.php'));

        $this->assertSame(MaintenanceGuard::PASS, $decide('admin/updates.php', '/admin/updates.php'));
        $this->assertSame(MaintenanceGuard::PASS, $decide('admin/login.php', '/admin/login.php'));
        $this->assertSame(MaintenanceGuard::PASS, $decide('api/admin/updates-step.php', '/api/admin/updates-step.php'));
        $this->assertSame(MaintenanceGuard::PASS, $decide('api/admin/updates-abort.php', '/api/admin/updates-abort.php'));

        // The URL cannot talk the guard into an exemption: only the script that runs counts.
        $this->assertNotSame(MaintenanceGuard::PASS, $decide('index.php', '/admin/updates.php'));
        $this->assertNotSame(MaintenanceGuard::PASS, $decide('index.php', '/api/admin/updates-step.php/../../index.php'));

        $this->assertSame(MaintenanceGuard::PASS, $decide('index.php', '/', 'the-token'), 'the health check passes with the token');
        $this->assertSame(MaintenanceGuard::PAGE_UNAVAILABLE, $decide('index.php', '/', 'a-guess'));
    }

    public function testDuringMaintenanceTheCronScriptsWaitButTheToolsRun(): void
    {
        self::write($this->root, [
            'scripts/prune-analytics.php' => '', 'scripts/sync-postnl-rates.php' => '', 'scripts/generate_admin_hash.php' => '',
            'vendor/bin/phinx' => '', 'vendor/bin/phpunit' => '',
        ]);

        $this->assertTrue(MaintenanceGuard::cliMayRun(null, $this->root, $this->root . '/scripts/prune-analytics.php'));

        $flag = ['update_id' => 'x'];
        $this->assertFalse(MaintenanceGuard::cliMayRun($flag, $this->root, $this->root . '/scripts/prune-analytics.php'));
        $this->assertFalse(MaintenanceGuard::cliMayRun($flag, $this->root, $this->root . '/scripts/sync-postnl-rates.php'));
        $this->assertTrue(MaintenanceGuard::cliMayRun($flag, $this->root, $this->root . '/scripts/generate_admin_hash.php'));
        $this->assertTrue(MaintenanceGuard::cliMayRun($flag, $this->root, $this->root . '/vendor/bin/phinx'));
        $this->assertTrue(MaintenanceGuard::cliMayRun($flag, $this->root, $this->root . '/vendor/bin/phpunit'));
    }

    public function testTheUpdaterShutsItsOwnFolderButNeverOneTheSiteLivesIn(): void
    {
        // Made by hand inside the web root before the updater first ran.
        mkdir($this->root . '/updater-private');
        (new UpdateStateStore($this->root . '/updater-private', $this->root))->ensureDirectory($this->root . '/updater-private');
        $this->assertStringEqualsFile($this->root . '/updater-private/.htaccess', "Require all denied\n");

        // Pointed at the folder the site sits in: a .htaccess there would close the site.
        (new UpdateStateStore($this->base, $this->root))->ensureDirectory($this->base);
        $this->assertFileDoesNotExist($this->base . '/.htaccess');
    }

    public function testAnUnreadableFlagStillClosesTheSite(): void
    {
        file_put_contents($this->root . '/.maintenance', 'not json');
        file_put_contents($this->root . '/index.php', '');

        $flag = (new MaintenanceMode($this->root))->read();

        $this->assertSame([], $flag);
        $this->assertSame(MaintenanceGuard::PAGE_UNAVAILABLE, MaintenanceGuard::decide($flag, $this->root, $this->root . '/index.php', '/', 'anything'));
    }

    public function testTheFlagNeverHoldsTheHealthTokenItself(): void
    {
        (new MaintenanceMode($this->root))->enable('20261001-120000-abcdef', 'secret-token');

        $this->assertStringNotContainsString('secret-token', (string) file_get_contents($this->root . '/.maintenance'));
    }

    public function testEveryEntryPointGoesThroughTheGuard(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertContains('src/Update/maintenance-guard.php', $composer['autoload']['files'] ?? []);
    }

    public function testTheInstalledAutoloaderRunsTheGuard(): void
    {
        $files = (string) @file_get_contents(dirname(__DIR__, 2) . '/vendor/composer/autoload_files.php');

        $this->assertStringContainsString(
            'src/Update/maintenance-guard.php',
            $files,
            'vendor/ predates the maintenance guard: run `composer dump-autoload` (or composer install) in this checkout'
        );
    }
}
