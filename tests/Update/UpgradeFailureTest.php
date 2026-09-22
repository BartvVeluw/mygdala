<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\FileApplier;
use App\Update\UpdatePlan;
use PHPUnit\Framework\TestCase;
use Tests\Support\UpdaterSandbox;

/**
 * What happens when an update goes wrong, end to end, on throwaway
 * installations of real releases (Tests\Support\UpdaterSandbox). Each case
 * is a line of the failure model in docs/updates/RECOVERY.md:
 *
 *   C   a corrupt package              refused before anything changes
 *   D   a package with the wrong hash  refused before anything changes
 *   E   PHP or MySQL too old           the update cannot even start
 *   F   a file PHP may not replace     the pre-check refuses, nothing changes
 *   G   a migration that fails         database and files restored, site open
 *       an apply that fails after      files restored in that request, no
 *       several batches                migration ran, site open
 *       … and the rollback fails too   recovery_required, site stays closed,
 *                                      until the owner resolves it
 *       … aborted before any change    flag off, work and backup thrown away
 */
final class UpgradeFailureTest extends TestCase
{
    private ?UpdaterSandbox $sandbox = null;

    private const VOLATILE = ['phinx_migration_log', 'admin_users', 'page_views', 'analytics_visitor_salts'];

    protected function setUp(): void
    {
        if (!UpdaterSandbox::available()) {
            $this->markTestSkipped('needs the MySQL root account (DB_ROOT_PASSWORD), ext-zip and ext-sodium');
        }
    }

    protected function tearDown(): void
    {
        $this->sandbox?->destroy();
    }

    private function install(string $name): UpdaterSandbox
    {
        $this->sandbox = UpdaterSandbox::install($name);
        $this->sandbox->login();

        return $this->sandbox;
    }

    /**
     * Runs an update against whatever is published and returns the answers.
     *
     * @return list<array<string, mixed>>
     */
    private function attempt(UpdaterSandbox $site, ?callable $afterStep = null): array
    {
        $site->check();
        $start = $site->startUpdate();
        $this->assertSame(303, $start['status']);

        return $site->runSteps($afterStep);
    }

    private function assertNothingChanged(UpdaterSandbox $site, array $treeBefore): void
    {
        $this->assertSame($treeBefore, $site->tree(), 'not a byte of the site changed');
        $this->assertFileDoesNotExist($site->root() . '/.maintenance');
        $this->assertSame(200, $site->get('/')['status']);
        $this->assertSame([], glob($site->storage() . '/backups/*') ?: [], 'no backup was started');
    }

    public function testScenarioCACorruptPackageIsRefusedBeforeAnythingChanges(): void
    {
        $site = $this->install('corrupt');
        $before = $site->tree();
        $package = (string) file_get_contents(UpdaterSandbox::release('B') . '/mygdala-0.2.0.zip');
        // Signed and hashed as it is — so only opening it can tell.
        $site->publish('B', [], substr($package, 0, (int) (strlen($package) / 2)));

        $answers = $this->attempt($site);

        $this->assertSame('failed', end($answers)['status']);
        $this->assertSame('extract', $site->state()['failed_step']);
        $this->assertSame('update.error.package_corrupt', $site->state()['message']['key']);
        $this->assertNothingChanged($site, $before);
        $this->assertStringContainsString('Het updatepakket is beschadigd', $site->updatesPage());
    }

    public function testScenarioDAPackageThatIsNotTheOneTheManifestNamesIsRefused(): void
    {
        $site = $this->install('wrong-sha');
        $before = $site->tree();
        $site->publish('B', ['sha256' => str_repeat('0', 64)]);

        $answers = $this->attempt($site);

        $this->assertSame('failed', end($answers)['status']);
        $this->assertSame('verify', $site->state()['failed_step']);
        $this->assertSame('update.error.package_hash_mismatch', $site->state()['message']['key']);
        $this->assertNothingChanged($site, $before);
    }

    public function testScenarioETooOldPhpOrDatabaseStopsTheUpdateBeforeItStarts(): void
    {
        $site = $this->install('requirements');
        $before = $site->tree();

        foreach ([
            ['minimum_php' => '99.0'],
            ['minimum_mysql' => '99.0', 'minimum_mariadb' => '99.0'],
        ] as $impossible) {
            $site->publish('B', $impossible);
            $site->check();
            $page = $site->updatesPage();
            $this->assertMatchesRegularExpression('/Deze release vraagt (PHP 99\.0|minimaal 99\.0)/', $page);
            $this->assertMatchesRegularExpression('/<button type="submit" class="admin-btn" disabled>Update installeren/', $page);

            $start = $site->startUpdate();
            $this->assertSame(303, $start['status']);
            $this->assertStringContainsString('Niet aan alle vereisten is voldaan.', $site->updatesPage());
            $this->assertSame([], $site->state(), 'no update was started');
        }

        $this->assertNothingChanged($site, $before);
    }

    public function testScenarioFAFileThePhpUserMayNotReplaceIsCaughtByThePreCheck(): void
    {
        if (UpdaterSandbox::asWebUser() === []) {
            $this->markTestSkipped('needs to run as root to serve the sandbox as www-data');
        }

        $site = $this->install('unwritable');
        // The folder of a file 0.2.0 replaces now belongs to root: www-data
        // can neither write into it nor rename a new file over the old one.
        chown($site->root() . '/src/Service', 0);
        chmod($site->root() . '/src/Service', 0755);
        $before = $site->tree();
        $site->publish('B');

        $answers = $this->attempt($site);

        $this->assertSame('failed', end($answers)['status']);
        $checks = array_column((array) $site->state()['checks'], null, 'name');
        $this->assertSame('error', $checks['writable']['status']);
        $this->assertStringContainsString('src/Service/HttpUserAgent.php', (string) $checks['writable']['params']['paths']);
        $this->assertNothingChanged($site, $before);
    }

    public function testScenarioGAFailingMigrationRestoresDatabaseAndFiles(): void
    {
        $site = $this->install('migration-fails');
        mkdir($site->root() . '/assets/media', 0777, true);
        file_put_contents($site->root() . '/assets/media/keep.jpg', 'owner photo');
        $site->handToWebUser();
        $treeBefore = $site->tree();
        $contentBefore = $site->databaseSnapshot(self::VOLATILE);
        $logBefore = $site->pdo()->query('SELECT version FROM phinx_migration_log ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);
        $site->publish('BAD');

        $answers = $this->attempt($site);
        $ran = array_column($answers, 'ran');

        $this->assertSame('rolled_back', end($answers)['status'], json_encode($answers, JSON_PRETTY_PRINT) . $site->serverLog());
        $this->assertContains('migrate', $ran);
        $this->assertContains('rollback_database', $ran);
        $this->assertContains('rollback_files', $ran);
        $this->assertNotContains('finish', $ran);
        $this->assertSame('update.error.migration_failed', $site->state()['message']['key']);

        // Files: exactly the old release again, installation data untouched.
        $this->assertSame($treeBefore, $site->tree());
        $this->assertSame("0.1.0\n", file_get_contents($site->root() . '/VERSION'));

        // Database: the half-created table is gone, the migration log is as it was, content unchanged.
        $this->assertFalse($site->hasTable('updater_e2e_half'));
        $this->assertSame($logBefore, $site->pdo()->query('SELECT version FROM phinx_migration_log ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame($contentBefore, $site->databaseSnapshot(self::VOLATILE));

        // The site is open again, and the owner is told what happened.
        $this->assertFileDoesNotExist($site->root() . '/.maintenance');
        $this->assertSame(200, $site->get('/')['status']);
        $page = $site->updatesPage();
        $this->assertStringContainsString('is mislukt en volledig teruggedraaid', $page);
        $this->assertStringContainsString('De databasewijziging 20990101000000 (UpdaterE2eMarker) is mislukt.', $page);
        $this->assertStringContainsString('data-current-version>0.1.0<', $page);
    }

    public function testAnApplyThatFailsAfterSeveralBatchesIsRolledBackAndNeverMigrates(): void
    {
        $site = $this->install('apply-fails');
        $treeBefore = $site->tree();
        $contentBefore = $site->databaseSnapshot(self::VOLATILE);
        $site->publish('B');

        // After the first batch, the staged copy of a file the third batch
        // has to write stops matching the release.
        $damaged = null;
        $answers = $this->attempt($site, function (array $answer) use ($site, &$damaged): ?bool {
            $state = $site->state();
            if ($damaged === null && $answer['ran'] === 'apply' && (int) ($state['cursor']['next'] ?? 0) === FileApplier::BATCH) {
                $work = $site->storage() . '/work/' . $state['update_id'];
                $plan = UpdatePlan::fromJson((string) file_get_contents($work . '/plan.json'));
                [, $damaged] = FileApplier::operations($plan, (array) $state['cursor']['critical'])[2 * FileApplier::BATCH + 10];
                file_put_contents($work . '/staging/' . $damaged, 'damaged after the check');
            }

            return null;
        });
        $ran = array_column($answers, 'ran');

        $this->assertNotNull($damaged);
        $this->assertSame('rolled_back', end($answers)['status'], json_encode($answers, JSON_PRETTY_PRINT) . $site->serverLog());
        $this->assertSame(3, count(array_keys($ran, 'apply', true)), 'two batches went through, the third failed');
        $this->assertNotContains('migrate', $ran, 'no migration runs after a failed apply');
        $this->assertSame('update.error.apply_failed', $site->state()['message']['key']);
        $this->assertSame($damaged, $site->state()['message']['params']['path']);
        $this->assertSame(['rollback_files', 'apply'], [$site->state()['step'], $site->state()['failed_step']], 'the way back was saved as its own step before it was taken');

        // Every file is the old release again, byte for byte, and nothing is left over.
        $this->assertSame($treeBefore, $site->tree());
        $this->assertSame("0.1.0\n", file_get_contents($site->root() . '/VERSION'));
        $this->assertFalse($site->hasTable('updater_e2e_marker'));
        $this->assertSame($contentBefore, $site->databaseSnapshot(self::VOLATILE));

        $this->assertFileDoesNotExist($site->root() . '/.maintenance');
        $this->assertSame(200, $site->get('/')['status']);
        $this->assertStringContainsString('is mislukt en volledig teruggedraaid', $site->updatesPage());
    }

    public function testAFailedRollbackKeepsTheSiteClosedUntilTheOwnerResolvesIt(): void
    {
        $site = $this->install('recovery');
        $site->publish('BAD');

        // Once the migration has failed and the rollback is next, damage the
        // database backup: the restore must refuse it rather than replay it.
        $answers = $this->attempt($site, function (array $answer) use ($site): ?bool {
            if (($answer['step'] ?? '') === 'rollback_database' && $answer['ran'] === 'migrate') {
                $dump = $site->storage() . '/backups/' . $site->state()['update_id'] . '/database.sql.gz';
                file_put_contents($dump, 'damaged', FILE_APPEND);
            }

            return null;
        });

        $this->assertSame('recovery_required', end($answers)['status'], json_encode($answers, JSON_PRETTY_PRINT));
        $this->assertSame('update.error.backup_damaged', $site->state()['message']['key']);

        // Visitors see the maintenance page, not a half-updated site.
        $this->assertFileExists($site->root() . '/.maintenance');
        $this->assertSame(503, $site->get('/')['status']);

        // The owner sees exactly what happened and where the backup is.
        $page = $site->updatesPage();
        $this->assertStringContainsString('Herstel nodig', $page);
        $this->assertStringContainsString('ook het automatisch terugzetten is niet gelukt', $page);
        $this->assertStringContainsString($site->storage() . '/backups/' . $site->state()['update_id'], $page);
        $this->assertStringContainsString('De back-up van de database is beschadigd', $page);

        // No new update can start in this state.
        $site->check();
        $site->startUpdate();
        $this->assertSame('recovery_required', $site->state()['status']);

        // After recovering by hand, the owner lifts maintenance from the screen.
        $resolve = $site->post('/api/admin/updates-resolve.php', ['update_id' => $site->state()['update_id']]);
        $this->assertSame(303, $resolve['status']);
        $this->assertSame('failed', $site->state()['status']);
        $this->assertFileDoesNotExist($site->root() . '/.maintenance');
    }

    public function testAnUpdateAbortedBeforeAnyChangeLeavesNothingBehind(): void
    {
        $site = $this->install('abort');
        $before = $site->tree();
        $site->publish('B');
        $site->check();
        $site->startUpdate();

        $site->runSteps(static fn (array $answer): ?bool => $answer['ran'] === 'backup_database' ? false : null);
        $this->assertFileExists($site->root() . '/.maintenance');

        $abort = $site->post('/api/admin/updates-abort.php', ['update_id' => $site->state()['update_id']]);
        $this->assertSame(303, $abort['status']);
        $this->assertSame('failed', $site->state()['status']);
        $this->assertSame('update.message.aborted', $site->state()['message']['key']);
        $this->assertNothingChanged($site, $before);
        $this->assertDirectoryDoesNotExist($site->storage() . '/work/' . $site->state()['update_id']);
    }
}
