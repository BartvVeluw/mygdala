<?php

declare(strict_types=1);

namespace Tests\Update;

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
 *       changed stylesheet, a migration and the version bump
 *   B   0.1.0 → 0.3.0 in one update, across two migration generations
 *   H   a Core file changed by hand blocks the update
 *   I   an update left halfway (a closed tab) is continued from its state
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

    private function install(string $name): UpdaterSandbox
    {
        $this->sandbox = UpdaterSandbox::install($name);
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
        $this->assertStringEndsWith('/admin/updates.php?run=1', $start['location']);

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
        foreach (['download', 'verify', 'extract', 'preflight', 'maintenance', 'backup_database', 'backup_files', 'apply', 'migrate', 'health', 'finish'] as $step) {
            $this->assertContains($step, $ran);
        }

        // The release is the new one, file by file.
        $this->assertSame("0.2.0\n", file_get_contents($site->root() . '/VERSION'));
        $this->assertSame('0.2.0', json_decode((string) file_get_contents($site->root() . '/release.json'), true)['version']);
        $this->assertStringContainsString('Changed in the 0.2.0 test release', (string) file_get_contents($site->root() . '/src/Service/HttpUserAgent.php'));
        $this->assertStringContainsString('0.2.0 test release', (string) file_get_contents($site->root() . '/assets/css/core.css'));
        $this->assertFileExists($site->root() . '/assets/updater-e2e/new.txt');
        $this->assertFileDoesNotExist($site->root() . '/assets/updater-e2e/obsolete.txt');

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

    public function testScenarioIAnUpdateLeftHalfwayIsContinuedFromItsState(): void
    {
        $site = $this->install('interrupted');
        $site->publish('B');
        $site->check();
        $site->startUpdate();

        // The owner closes the tab once the backup of the files is done.
        $site->runSteps(static fn (array $answer): ?bool => $answer['ran'] === 'backup_files' ? false : null);
        $state = $site->state();
        $this->assertSame('running', $state['status']);
        $this->assertSame('apply', $state['step']);

        // Coming back: the screen says where it stopped instead of carrying on by itself.
        $page = $site->updatesPage();
        $this->assertStringContainsString('De update is onderbroken bij de stap &quot;Bestanden bijwerken&quot;.', $page);
        $this->assertStringContainsString('data-autorun="0"', $page);
        $this->assertSame(503, $site->get('/')['status'], 'still in maintenance while it waits');

        // A second tab asking for a step that is already past gets the server's truth.
        $stale = $site->step((string) $state['update_id'], 'backup_files');
        $this->assertSame(409, $stale['status']);
        $this->assertSame('apply', $stale['data']['step']);

        // Neither a made-up update id nor a made-up step gets anywhere.
        $this->assertSame(409, $site->step('20000101-000000-abcdef', 'apply')['status']);
        $this->assertSame(400, $site->step((string) $state['update_id'], '../../etc')['status']);

        $answers = $site->runSteps();
        $this->assertSame('completed', end($answers)['status']);
        $this->assertSame("0.2.0\n", file_get_contents($site->root() . '/VERSION'));
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
