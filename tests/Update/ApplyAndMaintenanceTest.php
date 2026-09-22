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

    public function testApplyingAPlanTurnsTheTreeIntoTheNewReleaseAndLeavesInstallationDataAlone(): void
    {
        [$applier, $plan, , $target] = $this->scenario();

        $applier->apply($plan);

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
        $applier->apply($plan);

        $this->assertTrue(LocalChanges::detect($this->root, $target)->isClean());
    }

    public function testARolledBackApplyIsTheOldReleaseAgainByteForByte(): void
    {
        [$applier, $plan, $installed] = $this->scenario();

        $applier->apply($plan);
        $applier->rollback($plan);

        $this->assertTrue(LocalChanges::detect($this->root, $installed)->isClean());
        $this->assertFileDoesNotExist($this->root . '/src/Fresh/New.php');
        $this->assertDirectoryDoesNotExist($this->root . '/src/Fresh');
        $this->assertSame('0.1.0', ReleaseDescriptor::installed($this->root)?->version);
        $this->assertStringEqualsFile($this->root . '/.env', 'SECRET=1');
    }

    public function testAnApplyThatFailsHalfwayCanBeRolledBackFromWhereverItStopped(): void
    {
        [$applier, $plan, $installed] = $this->scenario();

        // A directory where a file has to go: the rename fails, for root too.
        mkdir($this->root . '/src/Fresh/New.php', 0777, true);

        try {
            $applier->apply($plan);
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
            'api/admin/updates-step.php' => '', 'api/admin/update-page.php' => '', 'api/checkout.php' => '',
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

        // The URL cannot talk the guard into an exemption: only the script that runs counts.
        $this->assertNotSame(MaintenanceGuard::PASS, $decide('index.php', '/admin/updates.php'));
        $this->assertNotSame(MaintenanceGuard::PASS, $decide('index.php', '/api/admin/updates-step.php/../../index.php'));

        $this->assertSame(MaintenanceGuard::PASS, $decide('index.php', '/', 'the-token'), 'the health check passes with the token');
        $this->assertSame(MaintenanceGuard::PAGE_UNAVAILABLE, $decide('index.php', '/', 'a-guess'));
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
