<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Update\FileApplier;
use App\Update\LocalChanges;
use App\Update\ReleaseDescriptor;
use App\Update\UpdatePlan;
use PHPUnit\Framework\TestCase;
use Tests\Support\UpdaterSandbox;

/**
 * The upgrade paths that must work, end to end: a throwaway installation of
 * a REAL release (built from this checkout by the release builder), updated
 * only through the Updates screen's own HTTP endpoints, by a logged-in owner,
 * against a signed feed. See Tests\Support\UpdaterSandbox for the setup and
 * docs/updates/ARCHITECTURE.md ("Bewijs") for the whole list.
 *
 *   A   0.1.0 → 0.2.0: a changed PHP file, a new file, a removed file, a
 *       changed stylesheet and .htaccess, hundreds of new files (an apply of
 *       several requests), a migration and the version bump
 *   B   0.1.0 → 0.3.0 in one update, across two migration generations
 *   H   a Core file changed by hand blocks the update
 *   I   an update interrupted inside its download and inside its apply — a
 *       closed tab, a second tab, a request the host kills — is continued
 *       from the state the server kept
 *
 * The failure paths (C–G) are UpgradeFailureTest; the proof on a copy of an
 * existing installation is ExistingInstallAcceptanceTest.
 */
final class UpgradeEndToEndTest extends TestCase
{
    private ?UpdaterSandbox $sandbox = null;

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

    /** @param array<string, string> $env extra lines for the installation's .env */
    private function install(string $name, array $env = []): UpdaterSandbox
    {
        $this->sandbox = UpdaterSandbox::install($name, 'A', null, false, null, ['env' => $env]);
        $this->sandbox->login();

        return $this->sandbox;
    }

    public function testScenarioAUpdatesToTheNextReleaseThroughTheUpdatesScreen(): void
    {
        $site = $this->install('a2b');
        mkdir($site->root() . '/assets/media', 0777, true);
        file_put_contents($site->root() . '/assets/media/e2e-upload.jpg', 'uploaded by the owner');
        $site->handToWebUser();
        $envBefore = (string) file_get_contents($site->root() . '/.env');
        $volatile = ['phinx_migration_log', 'admin_users', 'page_views', 'analytics_visitor_salts'];
        $contentBefore = $site->databaseSnapshot($volatile);

        $site->publish('B');
        $page = $site->updatesPage();
        $this->assertStringContainsString('0.1.0', $page);

        $this->assertSame(303, $site->check()['status']);
        $page = $site->updatesPage();
        $this->assertStringContainsString('data-latest-version>0.2.0<', $page);
        $this->assertStringContainsString('Update beschikbaar', $page);
        $this->assertStringContainsString('Testrelease 0.2.0 (B).<br />', $page, 'the release notes are shown');

        $start = $site->startUpdate();
        $this->assertSame(303, $start['status'], $start['body']);
        $this->assertStringEndsWith('/admin/updates.php', $start['location']);
        $this->assertStringContainsString('data-autorun="1"', $start['page'], 'the screen right after starting runs the steps');
        $this->assertStringContainsString('data-autorun="0"', $site->updatesPage(), '… once: a refresh waits for Doorgaan');

        $seenMaintenance = false;
        $answers = $site->runSteps(function (array $answer) use ($site, &$seenMaintenance): ?bool {
            if ($answer['ran'] === 'maintenance') {
                // The site is closed for visitors, the admin is sent to the
                // Updates screen, and the updater itself still answers.
                $home = $site->get('/');
                $this->assertSame(503, $home['status']);
                $this->assertStringContainsString('Retry-After:', $home['headers']);
                $this->assertStringContainsString('Deze website wordt bijgewerkt', $home['body']);

                $admin = $site->get('/admin/pages.php');
                $this->assertSame(302, $admin['status']);
                $this->assertStringEndsWith('/admin/updates.php', $admin['location']);

                $this->assertSame(503, $site->get('/api/checkout.php')['status']);
                $this->assertSame(200, $site->get('/admin/updates.php')['status']);
                $seenMaintenance = true;
            }

            return null;
        });

        $this->assertTrue($seenMaintenance);
        $final = end($answers);
        $this->assertSame('completed', $final['status'], json_encode($answers, JSON_PRETTY_PRINT) . $site->serverLog());

        $ran = array_column($answers, 'ran');
        $this->assertSame(
            ['download', 'verify', 'extract', 'preflight', 'maintenance', 'backup_database', 'backup_files', 'apply', 'migrate', 'health', 'finish'],
            array_values(array_unique($ran)),
            'every step, in order, and none after another has begun'
        );
        $this->assertGreaterThanOrEqual(4, count(array_keys($ran, 'apply', true)), 'the apply took several requests');

        // The release is the new one, file by file.
        $this->assertSame("0.2.0\n", file_get_contents($site->root() . '/VERSION'));
        $this->assertSame('0.2.0', json_decode((string) file_get_contents($site->root() . '/release.json'), true)['version']);
        $this->assertStringContainsString('Changed in the 0.2.0 test release', (string) file_get_contents($site->root() . '/src/Service/HttpUserAgent.php'));
        $this->assertStringContainsString('0.2.0 test release', (string) file_get_contents($site->root() . '/assets/css/core.css'));
        $this->assertFileExists($site->root() . '/assets/updater-e2e/new.txt');
        $this->assertFileDoesNotExist($site->root() . '/assets/updater-e2e/obsolete.txt');
        $this->assertCount(UpdaterSandbox::BULK, glob($site->root() . '/assets/updater-e2e/bulk/*.txt') ?: []);
        $this->assertStringContainsString('# Changed in the 0.2.0 test release.', (string) file_get_contents($site->root() . '/.htaccess'), '.htaccess is Core and is replaced');

        // The migration ran through Phinx, and nothing else in the database moved.
        $this->assertTrue($site->hasTable('updater_e2e_marker'));
        $this->assertSame(1, (int) $site->pdo()->query("SELECT COUNT(*) FROM phinx_migration_log WHERE version = '20990101000000'")->fetchColumn());
        $this->assertSame($contentBefore, $site->databaseSnapshot([...$volatile, 'updater_e2e_marker']));

        // What belongs to the installation is exactly as it was.
        $this->assertSame($envBefore, file_get_contents($site->root() . '/.env'));
        $this->assertSame('uploaded by the owner', file_get_contents($site->root() . '/assets/media/e2e-upload.jpg'));

        // The site is open again and answers.
        $this->assertFileDoesNotExist($site->root() . '/.maintenance');
        $this->assertSame(200, $site->get('/')['status']);
        $this->assertSame(302, $site->get('/admin/login.php')['status'], 'signed in, the login page sends the owner on');
        $this->assertSame(200, $site->get('/admin/index.php')['status'], 'the rest of the CMS is open again');

        // The backup is there and says what it is.
        $state = $site->state();
        $backup = $site->storage() . '/backups/' . $state['update_id'];
        $this->assertFileExists($backup . '/database.sql.gz');
        $manifest = json_decode((string) file_get_contents($backup . '/backup.json'), true);
        $this->assertSame('0.1.0', $manifest['from_version']);
        $this->assertSame('0.2.0', $manifest['to_version']);
        $this->assertContains('src/Service/HttpUserAgent.php', $manifest['files_replaced']);
        $this->assertContains('assets/updater-e2e/obsolete.txt', $manifest['files_deleted']);
        $this->assertFileExists($backup . '/files/src/Service/HttpUserAgent.php');
        $this->assertFileDoesNotExist($backup . '/files/assets/media/e2e-upload.jpg', 'uploads are not duplicated');
        $note = (string) file_get_contents($backup . '/HERSTEL.txt');
        $this->assertStringContainsString('Van versie 0.1.0 naar 0.2.0', $note);
        $this->assertStringContainsString('assets/updater-e2e/new.txt', $note, 'the files to remove again are named');
        $this->assertStringContainsString($site->root(), $note);

        // The screen says so, and keeps the log.
        $page = $site->updatesPage();
        $this->assertStringContainsString('Mygdala is bijgewerkt van 0.1.0 naar 0.2.0.', $page);
        $this->assertStringContainsString('data-current-version>0.2.0<', $page);
        $site->check();
        $this->assertStringContainsString('Up-to-date', $site->updatesPage());
        $log = (string) file_get_contents($site->storage() . '/logs/' . $state['update_id'] . '.log');
        $this->assertStringContainsString('"update.log.migrated"', $log);
        $this->assertStringNotContainsString(UpdaterSandbox::PASSWORD, $log);
        $this->assertStringNotContainsString((string) $state['health_token'], $log, 'the health token stays out of the log');

        // The health check really requested the site through the maintenance gate.
        $health = array_column((array) $state['health'], 'status', 'name');
        $this->assertSame(['version' => 'ok', 'files' => 'ok', 'database' => 'ok', 'bootstrap' => 'ok', 'http' => 'ok'], $health);
    }

    /**
     * Point 25 of the brief: a brand-new installation, its database built from
     * zero by the release's own migrations, knows its version, opens the
     * Updates screen, checks the feed and updates itself — with nothing of
     * git or of this development checkout anywhere near it.
     */
    public function testAFreshInstallationKnowsItsVersionAndUpdatesItself(): void
    {
        $site = $this->sandbox = UpdaterSandbox::install('fresh', 'A', null, true);
        $site->login();

        // A brand-new installation opens the setup wizard first: that is the
        // wizard's rule (SETUP.md), and the Updates screen follows it.
        $this->assertStringEndsWith('/admin/setup.php', $site->get('/admin/updates.php')['location']);
        \App\Install\SetupState::markComplete($site->pdo());

        $this->assertFileDoesNotExist($site->root() . '/.git');
        $page = $site->updatesPage();
        $this->assertStringContainsString('data-current-version>0.1.0<', $page);
        $this->assertStringContainsString('0.1.0+e2e-a', $page, 'the build id comes from release.json');
        $this->assertStringContainsString('Geïnstalleerd vanuit een release', $page);

        $site->publish('B');
        $site->check();
        $this->assertStringContainsString('data-latest-version>0.2.0<', $site->updatesPage());

        $site->startUpdate();
        $answers = $site->runSteps();

        $this->assertSame('completed', end($answers)['status'], json_encode($answers, JSON_PRETTY_PRINT) . $site->serverLog());
        $this->assertSame("0.2.0
", file_get_contents($site->root() . '/VERSION'));
        $this->assertTrue($site->hasTable('updater_e2e_marker'));
    }

    public function testScenarioBSkipsAVersionAndRunsBothMigrationGenerationsInOneUpdate(): void
    {
        $site = $this->install('a2c');
        $site->publish('C');
        $site->check();

        $site->startUpdate();
        $answers = $site->runSteps();

        $this->assertSame('completed', end($answers)['status'], json_encode($answers, JSON_PRETTY_PRINT));
        $this->assertSame("0.3.0\n", file_get_contents($site->root() . '/VERSION'));
        $this->assertTrue($site->hasTable('updater_e2e_marker'), 'the 0.2.0 migration');
        $this->assertTrue($site->hasTable('updater_e2e_second'), 'the 0.3.0 migration');
        $this->assertFileExists($site->root() . '/assets/updater-e2e/new.txt');
        $this->assertFileExists($site->root() . '/assets/updater-e2e/third.txt');
        $this->assertFileDoesNotExist($site->root() . '/assets/updater-e2e/obsolete.txt');
        $this->assertSame(['0.1.0', '0.3.0'], [$site->state()['from_version'], $site->state()['to_version']]);
    }

    public function testScenarioHAHandEditedCoreFileBlocksTheUpdateAndIsLeftAlone(): void
    {
        $site = $this->install('local-change');
        file_put_contents($site->root() . '/index.php', "\n// edited on the server\n", FILE_APPEND);
        $before = $site->tree();

        $site->publish('B');
        $site->check();
        $site->startUpdate();
        $answers = $site->runSteps();

        $this->assertSame('failed', end($answers)['status']);
        $this->assertSame('preflight', $site->state()['step']);
        $checks = array_column((array) $site->state()['checks'], null, 'name');
        $this->assertSame('error', $checks['local_changes']['status']);
        $this->assertStringContainsString('index.php', (string) $checks['local_changes']['params']['paths']);

        $this->assertSame($before, $site->tree(), 'not a byte of the site changed');
        $this->assertFileDoesNotExist($site->root() . '/.maintenance');
        $this->assertStringContainsString('met de hand gewijzigd: index.php', $site->updatesPage());
    }

    /**
     * Scenario I: the update is interrupted INSIDE its two long steps, in
     * every way that can happen to a request on a host — the browser gives
     * up while a step runs, a second tab asks at the same time, the host kills
     * the request halfway — and every time it goes on from the state the
     * server kept, never from anything the browser says.
     */
    public function testScenarioIAnUpdateInterruptedInsideTheDownloadAndInsideTheApplyGoesOnFromItsState(): void
    {
        if (!function_exists('posix_kill') || !is_dir('/proc')) {
            $this->markTestSkipped('needs ext-posix and /proc to kill a request for real');
        }

        $site = $this->install('interrupted', ['MYGDALA_UPDATE_STEP_SECONDS' => '3']);
        $site->publish('B');
        $site->check();
        $site->startUpdate();
        $updateId = (string) $site->state()['update_id'];
        $size = (int) $site->state()['manifest']['size'];
        $work = $site->storage() . '/work/' . $updateId;
        $part = $work . '/package.zip.part';

        // --- Inside the download -------------------------------------------

        // The source is slow: ten seconds for the package, three per request.
        // The owner closes the tab while the first request runs; a second tab
        // asking for the same step meanwhile is turned away, and the request
        // finishes its budget on its own and saves how far it got.
        $site->feedMode(['rate' => intdiv($size, 10)]);
        $closed = $site->step($updateId, 'download', 0.5);
        $this->assertSame(0, $closed['status'], 'the browser gave up on the answer');
        $this->assertTrue($site->stepRunning(), 'the step goes on without the browser');
        $this->assertSame(423, $site->step($updateId, 'download')['status'], 'a second tab cannot run the same step alongside');
        $site->waitForIdle();

        $state = $site->state();
        $this->assertSame('download', $state['step']);
        $this->assertNull($state['in_step'] ?? null, 'that request ended normally, at its budget');
        $saved = (int) $state['cursor']['bytes'];
        $this->assertGreaterThan(0, $saved);
        $this->assertLessThan($size, $saved);
        clearstatcache();
        $this->assertSame($saved, filesize($part), 'the cursor is exactly the partial file');

        $page = $site->updatesPage();
        $this->assertStringContainsString('De update is onderbroken bij de stap &quot;Downloaden&quot;.', $page);
        $this->assertStringContainsString('data-update-progress', $page, 'with how far the download got');

        // Now the host kills a download request halfway: the source sends
        // another 30% and then stalls, and the request is killed while it waits.
        $site->feedMode(['stall_after' => (int) ($size * 0.3)]);
        $site->step($updateId, 'download', 0.5);
        $deadline = microtime(true) + 20;
        do {
            usleep(10000);
            clearstatcache();
        } while ((int) @filesize($part) < $saved + (int) ($size * 0.3) && microtime(true) < $deadline);
        $this->assertTrue($site->killWorkerHolding($part), 'a worker was downloading; ' . $site->serverProcesses());

        $state = $site->state();
        $this->assertSame('download', $state['in_step'] ?? null, 'the killed request never got to finish its step');
        $checkpoint = (int) $state['cursor']['bytes'];
        $this->assertGreaterThan($saved, $checkpoint, 'its checkpoints were saved while it downloaded');
        clearstatcache();
        $this->assertGreaterThanOrEqual($checkpoint, filesize($part));

        // Continuing asks the source for the rest, from exactly that checkpoint.
        $site->feedMode([]);
        $site->runSteps(static fn (array $answer): ?bool => $answer['ran'] === 'download' && $answer['step'] !== 'download' ? false : null);
        $asked = $site->feedRequests();
        $this->assertSame('bytes=' . $checkpoint . '-', end($asked)['range']);
        $this->assertNotNull(end($asked)['if_range']);
        $this->assertSame('verify', $site->state()['step']);

        // --- Inside the apply ----------------------------------------------

        // The first batch — about a third of the operations — and the tab closes.
        $site->runSteps(static fn (array $answer): ?bool => $answer['ran'] === 'apply' ? false : null);
        $state = $site->state();
        $this->assertSame('apply', $state['step']);
        $next = (int) $state['cursor']['next'];
        $total = (int) $state['cursor']['total'];
        $this->assertSame(FileApplier::BATCH, $next);
        $this->assertGreaterThanOrEqual(25, intdiv(100 * $next, $total));
        $this->assertLessThan(50, intdiv(100 * $next, $total));

        $bulk = count(glob($site->root() . '/assets/updater-e2e/bulk/*.txt') ?: []);
        $this->assertGreaterThan(0, $bulk);
        $this->assertLessThan(UpdaterSandbox::BULK, $bulk, 'part of the new files are there, part not yet');
        $this->assertSame('0.1.0', json_decode((string) file_get_contents($site->root() . '/release.json'), true)['version'], 'release.json is still the old release');
        $this->assertStringNotContainsString('0.2.0 test release', (string) file_get_contents($site->root() . '/.htaccess'), '.htaccess changes in the code switch, with the screens it serves');
        $this->assertFalse($site->hasTable('updater_e2e_marker'), 'no migration runs during the apply');
        $this->assertSame(503, $site->visit('/')['status'], 'still in maintenance while it waits');

        // What stays open in that half-updated tree runs one release: the
        // Updates screen and the login are old code until the code switch.
        $page = $site->updatesPage();
        $this->assertStringContainsString('De update is onderbroken bij de stap &quot;Bestanden bijwerken&quot;.', $page);
        $this->assertStringContainsString('De stap was voor ' . intdiv(100 * $next, $total) . '% klaar', $page);
        $this->assertStringContainsString('data-autorun="0"', $page);
        $this->assertStringContainsString('data-autorun="0"', $site->updatesPage('?run=1'), 'a link cannot make the screen continue');
        $this->assertSame(200, $site->visit('/admin/login.php')['status']);

        // A second tab asking for a step that is already past gets the server's truth.
        $stale = $site->step($updateId, 'backup_files');
        $this->assertSame(409, $stale['status']);
        $this->assertSame('apply', $stale['data']['step']);

        // Neither a made-up update id nor a made-up step gets anywhere.
        $this->assertSame(409, $site->step('20000101-000000-abcdef', 'apply')['status']);
        $this->assertSame(400, $site->step($updateId, '../../etc')['status']);

        // The next request is killed right after it wrote a file, before the
        // journal could record it: the journal is held, the write is not.
        $plan = UpdatePlan::fromJson((string) file_get_contents($work . '/plan.json'));
        [$action, $path] = FileApplier::operations($plan, (array) $state['cursor']['critical'])[$next];
        $this->assertSame('write', $action);
        $expected = $plan->add[$path] ?? $plan->replace[$path];

        $journal = fopen($work . '/' . FileApplier::JOURNAL, 'c');
        flock($journal, LOCK_EX);
        $site->step($updateId, 'apply', 0.5);
        $deadline = microtime(true) + 20;
        while (@hash_file('sha256', $site->root() . '/' . $path) !== $expected && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertSame($expected, @hash_file('sha256', $site->root() . '/' . $path), 'the request wrote its first file');
        $this->assertTrue($site->killWorkerHolding($site->storage() . '/update.lock'), 'a worker was applying; ' . $site->serverProcesses());
        flock($journal, LOCK_UN);
        fclose($journal);

        $this->assertCount($next, file($work . '/' . FileApplier::JOURNAL, FILE_IGNORE_NEW_LINES), 'that write never reached the journal');
        $this->assertSame('apply', $site->state()['in_step'] ?? null);

        // Continuing finishes the apply from its journal — and only then
        // migrates, checks and finishes.
        $order = [];
        $answers = $site->runSteps(function (array $answer) use ($site, &$order): ?bool {
            $order[] = $answer['ran'];
            $version = json_decode((string) file_get_contents($site->root() . '/release.json'), true)['version'];

            if ($answer['ran'] === 'apply') {
                $this->assertFalse($site->hasTable('updater_e2e_marker'), 'no migration before every file is in place');
                $this->assertSame($answer['step'] === 'apply' ? '0.1.0' : '0.2.0', $version, 'release.json changes with the last operation, not before');
            }

            return null;
        });

        $this->assertSame('completed', end($answers)['status'], json_encode($answers, JSON_PRETTY_PRINT) . $site->serverLog());
        $this->assertSame(['apply', 'migrate', 'health', 'finish'], array_values(array_unique($order)), 'apply, then the migrations, then the check, then finish');
        $this->assertGreaterThanOrEqual(3, count(array_keys($order, 'apply', true)), 'the rest of the apply took several requests');

        $this->assertSame("0.2.0\n", file_get_contents($site->root() . '/VERSION'));
        $this->assertCount(UpdaterSandbox::BULK, glob($site->root() . '/assets/updater-e2e/bulk/*.txt') ?: []);
        $this->assertTrue(LocalChanges::detect($site->root(), ReleaseDescriptor::installed($site->root()))->isClean(), 'every file is the new release');
        $this->assertSame('', trim((string) shell_exec('find ' . escapeshellarg($site->root()) . ' -name "*.mygdala-*.tmp"')), 'no temporary copy is left');
        $this->assertTrue($site->hasTable('updater_e2e_marker'));

        $log = (string) file_get_contents($site->storage() . '/logs/' . $updateId . '.log');
        $this->assertStringContainsString('"update.log.download_resumed"', $log);
        $this->assertStringContainsString('"update.log.apply_progress"', $log);
        $this->assertStringContainsString('"resumed"', $log, 'the log says which requests picked up a dead one');
    }

    public function testAnUpdateStepNeedsTheRightPermissionAndAToken(): void
    {
        $site = $this->install('guards');
        $site->publish('B');
        $site->check();
        $site->startUpdate();
        $state = $site->state();

        $forged = $site->post('/api/admin/updates-step.php', ['update_id' => $state['update_id'], 'step' => 'download', 'csrf_token' => 'forged'], ['Accept: application/json']);
        $this->assertSame(403, $forged['status']);
        $this->assertSame('download', $site->state()['step'], 'a forged request ran nothing');

        $this->assertSame(405, $site->get('/api/admin/updates-step.php')['status']);
    }
}
