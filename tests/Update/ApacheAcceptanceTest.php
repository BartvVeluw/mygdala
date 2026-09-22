<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Service\AppEnvironment;
use App\Update\HttpFetcher;
use App\Update\PackageDownload;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;
use Tests\Support\UpdaterSandbox;

/**
 * The updater's protection on a REAL Apache with .htaccess active — the
 * image's own server and configuration (docker/Dockerfile,
 * docker/apache-app.conf), in a throwaway container of that image — where
 * the other upgrade tests use PHP's built-in server, which reads no
 * .htaccess at all:
 *
 *   - the release metadata (VERSION, release.json, .maintenance) and the rest
 *     of what .htaccess denies are never served, before, during or after;
 *   - nothing of the updater's own storage is served, not even with the
 *     storage deliberately INSIDE the web root, where only the updater's own
 *     .htaccess stands between it and a visitor: the state, the lock, the
 *     logs, the partial and the whole package, the staging, the plan, the
 *     journal, the database dump and the file backup, probed at every step;
 *   - without the flag the site answers; with it a visitor gets a 503, the
 *     admin is sent to the Updates screen, login and logout work, the steps
 *     run, only the health token of this update gets through, and a stranger
 *     gets nowhere on any updater path;
 *   - the update REPLACES .htaccess (release B changes it), and afterwards
 *     every one of those rules is still in force;
 *   - a download resumes against Apache's own Range support.
 *
 * Opt-in, because it needs a container whose document root is empty
 * (TESTING.md, "De Apache-acceptatie"):
 *
 *     UPDATER_APACHE_ROOT=/var/www/html     the document root Apache serves
 *     UPDATER_APACHE_URL=http://127.0.0.1   where it answers
 */
final class ApacheAcceptanceTest extends TestCase
{
    /** Never served, whatever the state of the update. */
    private const DENIED = [
        '/VERSION', '/release.json', '/.maintenance', '/.env', '/.htaccess',
        '/composer.json', '/composer.lock', '/phinx.php',
        '/vendor/autoload.php', '/vendor/composer/installed.json', '/src/Update/Updater.php', '/src/Update/UpdateState.php',
        '/db/migrations/', '/docker/', '/docker/Dockerfile', '/storage/probe.txt',
        // No release has this file, so only .htaccess's rule for the whole
        // folder can answer 403 rather than 404; the scripts a release does
        // ship also refuse a web request themselves.
        '/scripts/release.php',
        // An installation that runs in Docker keeps its Compose file next to
        // the release (mygdala-test); the test puts one there.
        '/docker-compose.yml', '/compose.yaml',
        // What a crashed update could leave in the web root.
        '/index.php.mygdala-0123abcd.tmp', '/.maintenance.1a2b3c4d.tmp',
    ];

    private ?UpdaterSandbox $sandbox = null;

    /** @var array<string, int> every storage URL asked for => its status */
    private array $storageAnswers = [];

    protected function setUp(): void
    {
        if (getenv('UPDATER_APACHE_ROOT') === false || getenv('UPDATER_APACHE_URL') === false) {
            $this->markTestSkipped('needs a throwaway Apache container: UPDATER_APACHE_ROOT and UPDATER_APACHE_URL (TESTING.md)');
        }

        if (!UpdaterSandbox::available()) {
            $this->markTestSkipped('needs the MySQL root account (DB_ROOT_PASSWORD), ext-zip and ext-sodium');
        }
    }

    protected function tearDown(): void
    {
        AppEnvironment::overrideForTests(null);
        $this->sandbox?->destroy();
    }

    private function assertDenied(UpdaterSandbox $site, string $moment): void
    {
        foreach (self::DENIED as $path) {
            $answer = $site->visit($path);
            $this->assertSame(403, $answer['status'], $path . ' ' . $moment);
            // Apache's own refusal: no path on the server, no PHP error.
            $this->assertStringNotContainsString($site->root(), $answer['body'], $path . ' ' . $moment);
            $this->assertDoesNotMatchRegularExpression('/Stack trace|Fatal error|Warning:|\/var\/www/', $answer['body'], $path . ' ' . $moment);
        }
    }

    /**
     * Asks Apache for every file in the updater's storage right now — all of
     * it, apart from the thousands in the staging and the file backup, of
     * which a handful stand in — and remembers the answers.
     */
    private function probeStorage(UpdaterSandbox $site, string $moment): void
    {
        $root = $site->root();
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site->storage(), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = substr($item->getPathname(), strlen($root));
            $bulky = preg_match('#/(staging|files)/#', $relative) === 1;
            if ($bulky && preg_match('#/(staging|files)/(VERSION|release\.json|index\.php|\.env\.example|src/Service/HttpUserAgent\.php|assets/css/core\.css)$#', $relative) !== 1) {
                continue;
            }
            $files[] = $relative . ($item->isDir() ? '/' : '');
        }

        foreach ($files as $url) {
            $status = $site->visit(implode('/', array_map('rawurlencode', explode('/', $url))))['status'];
            $this->storageAnswers[$url] = $status;
            $this->assertSame(403, $status, $url . ' ' . $moment);
        }
    }

    public function testTheUpdaterIsSafeBehindApacheBeforeDuringAndAfterAnUpdateThatReplacesHtaccess(): void
    {
        $root = rtrim((string) getenv('UPDATER_APACHE_ROOT'), '/');
        $storage = $root . '/updater-private';
        $site = $this->sandbox = UpdaterSandbox::install('apache', 'A', null, false, null, [
            'root' => $root,
            'url' => (string) getenv('UPDATER_APACHE_URL'),
            'env' => ['MYGDALA_UPDATE_STORAGE_PATH' => $storage, 'MYGDALA_UPDATE_STEP_SECONDS' => '3'],
        ]);

        file_put_contents($root . '/index.php.mygdala-0123abcd.tmp', "<?php // the source of a half-copied file\n");
        file_put_contents($root . '/.maintenance.1a2b3c4d.tmp', '{"update_id":"half-written"}');
        mkdir($root . '/storage');
        file_put_contents($root . '/storage/probe.txt', 'private');
        file_put_contents($root . '/docker-compose.yml', "services:\n  php:\n    env_file: .env\n");
        file_put_contents($root . '/compose.yaml', "services: {}\n");
        // Not a Compose file: an ordinary YAML file stays public.
        file_put_contents($root . '/openapi.yaml', "openapi: 3.1.0\n");
        $site->handToWebUser();
        $site->login();

        // --- No update running: the site answers, the metadata does not ----

        $this->assertSame(200, $site->visit('/')['status']);
        $this->assertSame(200, $site->visit('/admin/login.php')['status']);
        $this->assertSame(200, $site->get('/admin/updates.php')['status']);
        $this->assertStringContainsString('ETag', $site->visit('/assets/css/core.css')['headers'], 'and Apache serves the static files itself');
        $this->assertSame(200, $site->visit('/openapi.yaml')['status'], 'the Compose rule names Compose files, not YAML');
        $this->assertDenied($site, 'before the update');

        $site->publish('B');
        $this->assertSame(303, $site->check()['status']);
        $this->assertFileExists($storage . '/.htaccess', 'the updater shuts its own folder');
        $this->probeStorage($site, 'after the check');

        // --- The update, probed at every step --------------------------------

        $site->startUpdate();
        $updateId = (string) $site->state()['update_id'];
        // A slow source: the download takes more than one request, so a
        // partial package sits in the storage between two of them.
        $site->feedMode(['rate' => intdiv((int) $site->state()['manifest']['size'], 5)]);

        $checked = [];
        $answers = $site->runSteps(function (array $answer) use ($site, $updateId, &$checked): ?bool {
            $this->probeStorage($site, 'after ' . $answer['ran']);
            $state = $site->state();

            if ($state['step'] === 'download' && is_file($site->storage() . '/work/' . $updateId . '/package.zip.part')) {
                $checked['partial'] = true;
                $site->feedMode([]);
            }

            if ($answer['ran'] === 'maintenance') {
                $this->duringMaintenance($site, $state);
                $checked['maintenance'] = true;
            }

            if ($answer['ran'] === 'apply' && $answer['step'] === 'apply' && !isset($checked['mixed'])) {
                // Half of the files are new, half old: what stays open still works.
                $page = $site->get('/admin/updates.php');
                $this->assertSame(200, $page['status']);
                $this->assertStringContainsString('Bestanden bijwerken', $page['body']);
                $this->assertSame(200, $site->visit('/admin/login.php')['status']);
                $this->assertSame(503, $site->visit('/')['status']);
                $this->assertDenied($site, 'between two apply requests');
                $checked['mixed'] = true;
            }

            return null;
        });

        $this->assertSame('completed', end($answers)['status'], json_encode($answers, JSON_PRETTY_PRINT));
        $this->assertTrue($checked['partial'] ?? false, 'a partial package lay in the storage between two requests');
        $this->assertTrue($checked['maintenance'] ?? false);
        $this->assertTrue($checked['mixed'] ?? false, 'the apply took several requests');
        $health = array_column((array) $site->state()['health'], 'status', 'name');
        $this->assertSame('ok', $health['http'], 'the health check reached the site through Apache, with its token');

        $kinds = array_keys($this->storageAnswers);
        foreach (['/state.json', '/update.lock', '/last-check.json', '/logs/' . $updateId . '.log', '/work/' . $updateId . '/package.zip.part',
            '/work/' . $updateId . '/package.zip', '/work/' . $updateId . '/plan.json', '/work/' . $updateId . '/apply.journal',
            '/work/' . $updateId . '/staging/VERSION', '/backups/' . $updateId . '/database.sql.gz', '/backups/' . $updateId . '/database.json',
            '/backups/' . $updateId . '/HERSTEL.txt', '/backups/' . $updateId . '/files/src/Service/HttpUserAgent.php'] as $needed) {
            $this->assertContains('/updater-private' . $needed, $kinds, 'probed while it existed');
        }
        $this->assertSame([403], array_values(array_unique($this->storageAnswers)), count($this->storageAnswers) . ' storage URLs, all refused');

        // --- After: B's .htaccess, and every rule still in force -------------

        $this->assertStringContainsString('# Changed in the 0.2.0 test release.', (string) file_get_contents($root . '/.htaccess'), '.htaccess was replaced by the update');
        $this->assertSame("0.2.0\n", file_get_contents($root . '/VERSION'));
        $this->assertFileDoesNotExist($root . '/.maintenance');
        $this->assertFileExists($root . '/docker-compose.yml', "the update leaves the installation's Compose file alone");
        $this->assertDenied($site, 'after the update');
        $this->probeStorage($site, 'after the update');
        $this->assertSame(200, $site->visit('/')['status']);
        $this->assertSame(200, $site->visit('/admin/login.php')['status']);
        $this->assertSame(200, $site->get('/admin/index.php')['status']);
    }

    /**
     * The maintenance window, as Apache serves it.
     *
     * @param array<string, mixed> $state
     */
    private function duringMaintenance(UpdaterSandbox $site, array $state): void
    {
        $this->assertFileExists($site->root() . '/.maintenance');
        $this->assertDenied($site, 'during maintenance');

        // Visitors: the maintenance page, for every public URL.
        foreach (['/', '/en/', '/shop.php', '/sitemap.xml', '/does-not-exist'] as $path) {
            $answer = $site->visit($path);
            $this->assertSame(503, $answer['status'], $path);
            $this->assertStringContainsString('Retry-After: 120', $answer['headers'], $path);
        }
        $this->assertStringContainsString('Deze website wordt bijgewerkt', $site->visit('/')['body']);

        // The admin: sent to the Updates screen, which answers.
        foreach (['/admin/pages.php', '/admin/index.php'] as $path) {
            $answer = $site->get($path);
            $this->assertSame(302, $answer['status'], $path);
            $this->assertStringEndsWith('/admin/updates.php', $answer['location'], $path);
        }
        $this->assertSame(200, $site->get('/admin/updates.php')['status']);
        $this->assertSame(503, $site->post('/api/admin/update-page.php', [])['status'], 'other admin endpoints are closed');

        // Login and logout work in another browser.
        $other = $site->inAnotherBrowser();
        $this->assertSame(302, $other->get('/admin/updates.php')['status'], 'not logged in: to the login');
        $this->assertSame(200, $other->get('/admin/login.php')['status']);
        $other->login();
        $this->assertSame(200, $other->get('/admin/updates.php')['status']);
        $this->assertSame(302, $other->post('/admin/logout.php', [])['status']);
        $this->assertStringContainsString('/admin/login.php', $other->get('/admin/updates.php')['location']);

        // Only this update's health token gets a visitor through.
        $token = (string) $state['health_token'];
        $this->assertSame(200, $site->visit('/', ['X-Mygdala-Health: ' . $token])['status']);
        $this->assertSame(503, $site->visit('/', ['X-Mygdala-Health: ' . str_repeat('0', strlen($token))])['status']);
        $this->assertSame(503, $site->visit('/', ['X-Mygdala-Health: '])['status']);

        // A stranger gets nowhere on any updater path.
        $before = $site->state();
        $fields = ['update_id' => (string) $state['update_id'], 'step' => (string) $state['step'], 'csrf_token' => 'guessed'];
        foreach (['/api/admin/updates-step.php', '/api/admin/updates-abort.php', '/api/admin/updates-resolve.php'] as $path) {
            $this->assertSame(401, $site->strangerPost($path, $fields)['status'], $path);
        }
        foreach (['/api/admin/updates-start.php', '/api/admin/updates-check.php'] as $path) {
            $this->assertSame(503, $site->strangerPost($path, $fields)['status'], $path);
        }
        $this->assertStringContainsString('/admin/login.php', $site->visit('/admin/updates.php')['location']);
        $after = $site->state();
        $this->assertSame([$before['status'], $before['step'], $before['cursor']], [$after['status'], $after['step'], $after['cursor']], 'nothing a stranger sent changed the update');
    }

    public function testADownloadResumesAgainstApachesOwnRangeSupport(): void
    {
        AppEnvironment::overrideForTests('testing');
        $root = rtrim((string) getenv('UPDATER_APACHE_ROOT'), '/');
        $url = rtrim((string) getenv('UPDATER_APACHE_URL'), '/') . '/range-probe.zip';
        $work = UpdaterSandbox::BASE . '/apache-range-' . bin2hex(random_bytes(3));
        mkdir($work, 0777, true);

        try {
            $package = (string) file_get_contents(UpdaterSandbox::release('B') . '/mygdala-0.2.0.zip');
            $size = strlen($package);
            $cut = intdiv($size * 2, 5);
            file_put_contents($root . '/range-probe.zip', $package);
            // A published package is not a second old; Apache gives a file that
            // young only a weak ETag, which If-Range cannot use.
            touch($root . '/range-probe.zip', time() - 60);

            // Two fifths arrived in an earlier request.
            file_put_contents($work . '/package.zip.part', substr($package, 0, $cut));
            $download = PackageDownload::open($work . '/package.zip', $size, ['bytes' => $cut, 'sha256' => hash('sha256', substr($package, 0, $cut))]);
            (new HttpFetcher(5))->resume($url, $download, microtime(true) + 30);
            $cursor = $download->close();

            $this->assertTrue($download->isComplete());
            $this->assertTrue($cursor['ranges'], 'Apache answered the range with a matching 206');
            $this->assertSame($size - $cut, $download->received(), 'only the rest came over the wire');
            $this->assertMatchesRegularExpression('/^"[0-9a-f-]+"$/', (string) $cursor['validator'], "Apache's own ETag");
            $download->finish();
            $this->assertSame(hash('sha256', $package), hash_file('sha256', $work . '/package.zip'));

            // The file behind the URL changes between two requests — so newly
            // that Apache gives it only a weak ETag: If-Range makes Apache send
            // all of the new one, it is seen as another file, nothing is mixed.
            unlink($work . '/package.zip');
            file_put_contents($work . '/package.zip.part', substr($package, 0, $cut));
            $changed = strrev($package);
            file_put_contents($root . '/range-probe.zip', $changed);
            touch($root . '/range-probe.zip', time() + 10);

            $download = PackageDownload::open($work . '/package.zip', $size, ['bytes' => $cut, 'sha256' => hash('sha256', substr($package, 0, $cut)), 'validator' => $cursor['validator']]);
            (new HttpFetcher(5))->resume($url, $download, microtime(true) + 30);
            $download->close();

            $this->assertSame('update.log.download_restarted', $download->events()[1][0] ?? null);
            $this->assertSame('source_changed', $download->events()[1][1]['reason'] ?? null);
            $download->finish();
            $this->assertSame(hash('sha256', $changed), hash_file('sha256', $work . '/package.zip'));
        } finally {
            @unlink($root . '/range-probe.zip');
            FeedFixture::removeDirectory($work);
        }
    }
}
