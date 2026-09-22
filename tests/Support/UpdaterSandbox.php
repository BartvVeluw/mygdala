<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Update\Build\ReleaseBuilder;
use App\Update\Build\ReleaseSource;
use PDO;

/**
 * Test support: a throwaway Mygdala installation made from a REAL release
 * package, updated only the way an owner would — over HTTP, through the
 * Updates screen's own endpoints. The upgrade tests (tests/Update/Upgrade*)
 * are built on it; docs/updates/ARCHITECTURE.md lists what they prove.
 *
 *   releases    built once per test process from this checkout by the real
 *               ReleaseBuilder: A = 0.1.0, B = 0.2.0 (a changed class, a
 *               changed stylesheet, a changed .htaccess, a new file, a
 *               removed file, BULK new files — enough for the apply to take
 *               several requests — and a migration), C = 0.3.0 (B plus a
 *               second migration), BAD = B whose migration creates a table
 *               and then fails
 *   install     A's package unpacked into /tmp/mygdala-upd-e2e/<name>/site,
 *               its own database copied from the suite's test database (an
 *               existing installation's content), its own .env, a super
 *               admin with a known password, and PHP's built-in server with
 *               several workers — as www-data where the suite runs as root,
 *               so file permissions mean what they mean on a host
 *   feed        a second built-in server with a signed manifest for
 *               whichever release a test publishes, behind
 *               range-feed-router.php: it answers ranges like a release host,
 *               and feedMode() makes it drop, stall or ignore them
 *   Apache      with `root` and `url`, the installation is unpacked into a
 *               document root an Apache server already serves (the image's
 *               own, in a throwaway container: ApacheAcceptanceTest) instead
 *               of getting a built-in server of its own
 *
 * Needs the MySQL root account (like ScratchInstall) and `setpriv` for the
 * www-data server; available() says whether this machine has them.
 */
final class UpdaterSandbox
{
    public const BASE = '/tmp/mygdala-upd-e2e';
    public const PASSWORD = 'correct horse battery staple';
    public const USERNAME = 'e2e-owner';

    /** New files in release B, so its apply takes several requests (FileApplier::BATCH). */
    public const BULK = 600;

    /** @var array<string, string>|null name => build directory */
    private static ?array $releases = null;

    private static ?FeedFixture $keys = null;

    private ?SandboxServer $site = null;
    private ?SandboxServer $feed = null;
    private string $csrf = '';

    /** @var array<string, string> extra lines for the installation's .env */
    private array $env = [];

    private function __construct(
        public readonly string $name,
        public readonly string $directory,
        public readonly string $database,
        private readonly string $root,
        private readonly ?string $externalUrl = null
    ) {
    }

    public static function available(): bool
    {
        return ScratchInstall::available() && class_exists(\ZipArchive::class) && function_exists('sodium_crypto_sign_detached');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function storage(): string
    {
        return $this->env['MYGDALA_UPDATE_STORAGE_PATH'] ?? $this->directory . '/storage/updates';
    }

    // ------------------------------------------------------------ releases

    /** The build directory of release $name (A, B, C or BAD), building all of them once. */
    public static function release(string $name): string
    {
        self::keys();
        self::$releases ??= self::buildReleases();

        return self::$releases[$name];
    }

    /** @return array<string, string> */
    private static function buildReleases(): array
    {
        $checkout = dirname(__DIR__, 2);
        $overlays = self::BASE . '/overlays-' . getmypid();
        $releases = self::BASE . '/releases-' . getmypid();
        $options = static fn (string $version, string $name): array => [
            'version' => $version,
            'released_at' => '2026-10-01T12:00:00Z',
            'build_id' => $version . '+e2e-' . strtolower($name),
            'notes' => "Testrelease {$version} ({$name}).\nMet een regel.",
            'minimum_source_version' => '0.1.0',
        ];

        $write = static function (string $name, string $path, string $content) use ($overlays): string {
            $file = $overlays . '/' . $name . '/' . $path;
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $content);

            return $file;
        };

        $marker = static fn (string $class, string $table, bool $fail = false): string => "<?php\n\ndeclare(strict_types=1);\n\n"
            . "use Phinx\\Migration\\AbstractMigration;\n\n"
            . "/** Test release only (tests/Support/UpdaterSandbox.php). */\n"
            . "final class {$class} extends AbstractMigration\n{\n    public function up(): void\n    {\n"
            . "        if (!\$this->hasTable('{$table}')) {\n"
            . "            \$this->table('{$table}')->addColumn('note', 'string', ['limit' => 50, 'null' => true])->create();\n"
            . "        }\n"
            . ($fail ? "        throw new \\RuntimeException('Simulated failure of the test release migration');\n" : '')
            . "    }\n}\n";

        // A comes from this checkout, read once: over Docker Desktop's bind
        // mount every file read is slow. B, C and BAD are A's own unpacked
        // tree on the container's file system plus their changes.
        $a = ReleaseSource::fromDirectory($checkout, $checkout . '/vendor')
            ->with('VERSION', $write('A', 'VERSION', "0.1.0\n"))
            ->with('assets/updater-e2e/obsolete.txt', $write('A', 'assets/updater-e2e/obsolete.txt', "only in 0.1.0\n"));
        (new ReleaseBuilder())->build($a, $options('0.1.0', 'A'), $releases . '/A');

        $tree = $overlays . '/tree-A';
        $zip = new \ZipArchive();
        $zip->open($releases . '/A/mygdala-0.1.0.zip');
        $zip->extractTo($tree);
        $zip->close();
        $base = ReleaseSource::fromDirectory($tree, $tree . '/vendor')->without('assets/updater-e2e/obsolete.txt');

        $changed = static fn (string $path, string $suffix): string => (string) file_get_contents($tree . '/' . $path) . $suffix;

        $b = $base
            ->with('VERSION', $write('B', 'VERSION', "0.2.0\n"))
            ->with('.htaccess', $write('B', '.htaccess', $changed('.htaccess', "\n# Changed in the 0.2.0 test release.\n")))
            ->with('src/Service/HttpUserAgent.php', $write('B', 'src/Service/HttpUserAgent.php', $changed('src/Service/HttpUserAgent.php', "\n// Changed in the 0.2.0 test release.\n")))
            ->with('assets/css/core.css', $write('B', 'assets/css/core.css', $changed('assets/css/core.css', "\n/* 0.2.0 test release */\n")))
            ->with('assets/updater-e2e/new.txt', $write('B', 'assets/updater-e2e/new.txt', "new in 0.2.0\n"))
            ->with('db/migrations/20990101000000_updater_e2e_marker.php', $write('B', 'db/migrations/20990101000000_updater_e2e_marker.php', $marker('UpdaterE2eMarker', 'updater_e2e_marker')));

        for ($i = 0; $i < self::BULK; $i++) {
            $path = sprintf('assets/updater-e2e/bulk/%03d.txt', $i);
            $b = $b->with($path, $write('B', $path, 'bulk file ' . $i . " of the 0.2.0 test release\n"));
        }

        $c = $b
            ->with('VERSION', $write('C', 'VERSION', "0.3.0\n"))
            ->with('assets/updater-e2e/third.txt', $write('C', 'assets/updater-e2e/third.txt', "new in 0.3.0\n"))
            ->with('db/migrations/20990201000000_updater_e2e_second.php', $write('C', 'db/migrations/20990201000000_updater_e2e_second.php', $marker('UpdaterE2eSecond', 'updater_e2e_second')));

        $bad = $b
            ->with('db/migrations/20990101000000_updater_e2e_marker.php', $write('BAD', 'db/migrations/20990101000000_updater_e2e_marker.php', $marker('UpdaterE2eMarker', 'updater_e2e_half', true)));

        $built = ['A' => $releases . '/A'];
        foreach (['B' => [$b, '0.2.0'], 'C' => [$c, '0.3.0'], 'BAD' => [$bad, '0.2.0']] as $name => [$source, $version]) {
            (new ReleaseBuilder())->build($source, $options($version, $name), $releases . '/' . $name);
            $built[$name] = $releases . '/' . $name;
        }

        return $built;
    }

    public static function keys(): FeedFixture
    {
        if (self::$keys === null) {
            // Everything under BASE that is per process goes when the process ends.
            register_shutdown_function([self::class, 'forgetReleases']);
            self::$keys = new FeedFixture(self::BASE . '/keys-' . getmypid());
        }

        return self::$keys;
    }

    /** Removes what buildReleases() made (registered as a shutdown function there). */
    public static function forgetReleases(): void
    {
        FeedFixture::removeDirectory(self::BASE . '/releases-' . getmypid());
        FeedFixture::removeDirectory(self::BASE . '/overlays-' . getmypid());
        FeedFixture::removeDirectory(self::BASE . '/keys-' . getmypid());
        self::$releases = null;
        self::$keys = null;
    }

    // ------------------------------------------------------------ install

    /**
     * @param callable(PDO): void|null $seed          extra rows for the installation's database
     * @param bool                     $freshDatabase an empty database migrated from zero by the
     *                                                release's own Phinx, as on a brand-new
     *                                                installation, instead of a copy of existing content
     * @param string|null              $sourceDatabase the database to copy, only ever read;
     *                                                 the suite's test database by default
     * @param array{env?: array<string, string>, root?: string, url?: string} $options
     *        env   extra lines for the installation's .env (a step budget, a storage path)
     *        root  unpack into this document root, which `url` already serves (Apache),
     *              instead of starting a built-in server; it must be empty
     */
    public static function install(string $name, string $release = 'A', ?callable $seed = null, bool $freshDatabase = false, ?string $sourceDatabase = null, array $options = []): self
    {
        $directory = self::BASE . '/' . $name . '-' . bin2hex(random_bytes(3));
        $database = 'mygdala_upd_' . preg_replace('/[^a-z0-9]/', '', strtolower($name)) . '_' . bin2hex(random_bytes(2));
        $root = rtrim((string) ($options['root'] ?? $directory . '/site'), '/');
        $sandbox = new self($name, $directory, $database, $root, isset($options['url']) ? rtrim($options['url'], '/') : null);
        $sandbox->env = $options['env'] ?? [];

        if (is_dir($root) && (scandir($root) ?: []) !== ['.', '..']) {
            throw new \RuntimeException('The document root ' . $root . ' is not empty; refusing to install into it');
        }

        @mkdir($sandbox->root(), 0777, true);
        mkdir($directory . '/feed', 0777, true);

        $zip = new \ZipArchive();
        $zip->open(self::release($release) . '/mygdala-' . self::versionOf($release) . '.zip');
        $zip->extractTo($sandbox->root());
        $zip->close();

        if ($freshDatabase) {
            $sandbox->createEmptyDatabase();
            $sandbox->writeEnv(0, 0);
            $sandbox->migrateFromZero();
            $sandbox->addOwner();
        } else {
            $sandbox->copyDatabase($sourceDatabase ?? (string) $_ENV['DB_DATABASE']);
        }
        $seed !== null && $seed($sandbox->pdo());

        file_put_contents($directory . '/router.php', <<<'PHP'
            <?php
            // The rewrites .htaccess does on a host: real files are served as
            // they are, every other URL goes through the dispatcher.
            $root = __DIR__ . '/site';
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            if ($path !== '/' && (is_file($root . $path) || is_dir($root . $path))) {
                return false;
            }
            if ($path === '/sitemap.xml') { require $root . '/sitemap.php'; return true; }
            if ($path === '/robots.txt') { require $root . '/robots.php'; return true; }
            chdir($root);
            require $root . '/dispatcher.php';
            return true;
            PHP);

        $feedPort = SandboxServer::freePort();
        $sitePort = $sandbox->externalUrl !== null ? (int) (parse_url($sandbox->externalUrl, PHP_URL_PORT) ?? 80) : SandboxServer::freePort();
        $sandbox->writeEnv((int) $sitePort, (int) $feedPort);
        $sandbox->handToWebUser();

        $sandbox->feed = SandboxServer::start($directory . '/feed', [], __DIR__ . '/range-feed-router.php', false, $feedPort);
        $sandbox->site = $sandbox->externalUrl !== null
            ? SandboxServer::attach($sitePort, $sandbox->root())
            : SandboxServer::start($sandbox->root(), [], $directory . '/router.php', false, $sitePort, self::asWebUser());

        if ($sandbox->feed === null || $sandbox->site === null) {
            $sandbox->destroy();
            throw new \RuntimeException('Could not start the sandbox servers');
        }

        return $sandbox;
    }

    private static function versionOf(string $release): string
    {
        return ['A' => '0.1.0', 'B' => '0.2.0', 'C' => '0.3.0', 'BAD' => '0.2.0'][$release];
    }

    /**
     * Puts release $release into this sandbox's feed as the newest one,
     * signed with the test key; $changes overrides manifest fields after the
     * builder wrote them (a lying hash, an impossible PHP version, …).
     *
     * @param array<string, mixed> $changes
     * @param string|null          $packageBytes replaces the package with these bytes
     */
    public function publish(string $release, array $changes = [], ?string $packageBytes = null): void
    {
        $built = self::release($release);
        $package = 'mygdala-' . self::versionOf($release) . '.zip';
        $manifest = json_decode((string) file_get_contents($built . '/manifest.json'), true);

        if ($packageBytes !== null) {
            file_put_contents($this->directory . '/feed/' . $package, $packageBytes);
            $manifest['sha256'] = hash('sha256', $packageBytes);
            $manifest['size'] = strlen($packageBytes);
        } else {
            copy($built . '/' . $package, $this->directory . '/feed/' . $package);
        }

        $manifest = array_replace($manifest, $changes);
        self::keys()->signInto($this->directory . '/feed', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function destroy(): void
    {
        $this->site?->stop();
        $this->feed?->stop();
        self::rootConnection()->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        FeedFixture::removeDirectory($this->directory);

        if ($this->externalUrl !== null) {
            // The document root of a server this sandbox did not start: empty it, keep it.
            FeedFixture::removeDirectory($this->root);
            @mkdir($this->root, 0755, true);
        }
    }

    /**
     * How the feed answers package requests from now on (range-feed-router.php).
     *
     * @param array<string, mixed> $mode
     */
    public function feedMode(array $mode): void
    {
        file_put_contents($this->directory . '/feed/mode.json', json_encode($mode));
        @unlink($this->directory . '/feed/package-requests.count');
    }

    /** @return list<array{range: ?string, if_range: ?string}> every package request the feed got */
    public function feedRequests(): array
    {
        $lines = @file($this->directory . '/feed/requests.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(static fn (string $line): array => (array) json_decode($line, true), $lines);
    }

    // ------------------------------------------------------------ HTTP

    /** @return array{status: int, location: string, body: string, headers: string} */
    public function get(string $path, array $headers = []): array
    {
        return $this->site->request('GET', $path, [], $headers);
    }

    /**
     * A first-time visitor: no session, no language cookie from an earlier
     * request of the same test.
     *
     * @return array{status: int, location: string, body: string, headers: string}
     */
    public function visit(string $path, array $headers = []): array
    {
        return $this->site->request('GET', $path, [], $headers, 120, false);
    }

    /**
     * A stranger's POST: no session, no CSRF token of this site.
     *
     * @param array<string, string> $fields
     *
     * @return array{status: int, location: string, body: string, headers: string}
     */
    public function strangerPost(string $path, array $fields): array
    {
        return $this->site->request('POST', $path, $fields, ['Accept: application/json'], 120, false);
    }

    /**
     * The same installation in a second browser: its own cookies, not logged
     * in. Only for requests — destroy() stays with the original.
     */
    public function inAnotherBrowser(): self
    {
        $other = clone $this;
        $other->site = SandboxServer::attach($this->site->port, $this->root);
        $other->feed = null;
        $other->csrf = '';

        return $other;
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    public function post(string $path, array $fields, array $headers = []): array
    {
        return $this->site->request('POST', $path, $fields + ['csrf_token' => $this->csrf], $headers);
    }

    public function login(): void
    {
        $page = $this->get('/admin/login.php');
        $this->csrf = self::csrfFrom($page['body']);
        $answer = $this->post('/admin/login.php', ['username' => self::USERNAME, 'password' => self::PASSWORD]);

        if ($answer['status'] !== 302) {
            throw new \RuntimeException('Login failed: HTTP ' . $answer['status'] . "\n" . substr($answer['body'], 0, 2000));
        }

        $this->refreshCsrf();
    }

    /** The Updates screen, and the CSRF token on it. */
    public function updatesPage(string $query = ''): string
    {
        $page = $this->get('/admin/updates.php' . $query);

        if ($page['status'] !== 200) {
            throw new \RuntimeException('Updates screen: HTTP ' . $page['status'] . "\n" . substr($page['body'], 0, 3000));
        }

        $this->csrf = self::csrfFrom($page['body']);

        return $page['body'];
    }

    /** Takes the CSRF token from the Updates screen when it opens (a fresh installation shows the wizard first). */
    public function refreshCsrf(): void
    {
        $page = $this->get('/admin/updates.php');
        if ($page['status'] === 200) {
            $this->csrf = self::csrfFrom($page['body']);
        }
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    public function check(): array
    {
        return $this->post('/api/admin/updates-check.php', []);
    }

    /**
     * "Update installeren", and then what a browser does next: follow the
     * redirect to the Updates screen (its HTML is in `page`), which is the
     * one render that runs the steps by itself.
     *
     * @return array{status: int, location: string, body: string, headers: string, page: string}
     */
    public function startUpdate(): array
    {
        $answer = $this->post('/api/admin/updates-start.php', []);
        $answer['page'] = $answer['status'] === 303 ? $this->get('/admin/updates.php')['body'] : '';

        return $answer;
    }

    /**
     * One step, the way admin/assets/updates.js asks for it.
     *
     * @param float $clientTimeout seconds until the browser gives up on the
     *                             answer: a tab that is closed while the step runs
     *
     * @return array{status: int, data: array<string, mixed>|null, body: string}
     */
    public function step(string $updateId, string $step, float $clientTimeout = 120): array
    {
        $answer = $this->site->request('POST', '/api/admin/updates-step.php', ['update_id' => $updateId, 'step' => $step, 'csrf_token' => $this->csrf], ['Accept: application/json'], $clientTimeout);

        return ['status' => $answer['status'], 'data' => json_decode($answer['body'], true), 'body' => $answer['body']];
    }

    /** Is a step running right now? (Its request holds update.lock.) */
    public function stepRunning(): bool
    {
        $handle = @fopen($this->storage() . '/update.lock', 'c');
        if ($handle === false) {
            return false;
        }

        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    /** Waits until no step runs any more (a step whose browser went away finishes on its own). */
    public function waitForIdle(float $seconds = 60): void
    {
        $deadline = microtime(true) + $seconds;
        while ($this->stepRunning()) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('A step was still running after ' . $seconds . ' seconds');
            }
            usleep(20000);
        }
    }

    /** The web server's processes, for a failure message. */
    public function serverProcesses(): string
    {
        return $this->site?->processTree() ?? '';
    }

    /**
     * Kills — SIGKILL, the way a host ends a request that ran too long — the
     * web server worker that has $path open, and says whether there was one.
     */
    public function killWorkerHolding(string $path): bool
    {
        $pid = $this->site?->workerHolding($path);
        if ($pid === null) {
            return false;
        }

        posix_kill($pid, 9);
        for ($i = 0; $i < 100 && is_dir('/proc/' . $pid); $i++) {
            usleep(10000);
        }

        return true;
    }

    /**
     * Keeps asking for the next step until the update is no longer running,
     * as the script does. $afterStep sees every answer and may return false
     * to stop early (a closed tab).
     *
     * @param callable(array<string, mixed>): (bool|null)|null $afterStep
     *
     * @return list<array<string, mixed>> every answer
     */
    public function runSteps(?callable $afterStep = null, int $limit = 400): array
    {
        $state = $this->state();
        $updateId = (string) $state['update_id'];
        $step = (string) $state['step'];
        $answers = [];

        for ($i = 0; $i < $limit; $i++) {
            $answer = $this->step($updateId, $step);

            if (!is_array($answer['data'])) {
                throw new \RuntimeException("Step {$step} did not answer JSON (HTTP {$answer['status']}):\n" . substr($answer['body'], 0, 4000));
            }

            $answers[] = $answer['data'] + ['http' => $answer['status'], 'ran' => $step];

            if ($afterStep !== null && $afterStep($answer['data'] + ['ran' => $step]) === false) {
                return $answers;
            }

            if (($answer['data']['running'] ?? false) !== true) {
                return $answers;
            }

            $step = (string) $answer['data']['step'];
        }

        throw new \RuntimeException('The update did not settle within ' . $limit . ' steps');
    }

    /** @return array<string, mixed> the update state as the updater stored it */
    public function state(): array
    {
        $path = $this->storage() . '/state.json';

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }

    // ------------------------------------------------------------ inspection

    /** @return array<string, string> every file under the site root => sha256 */
    public function tree(): array
    {
        $files = [];
        $root = $this->root();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[ltrim(substr($file->getPathname(), strlen($root)), '/')] = (string) hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    public function pdo(): PDO
    {
        return new PDO(
            'mysql:host=' . self::host() . ';port=' . self::port() . ';dbname=' . $this->database . ';charset=utf8mb4',
            'root',
            (string) ($_ENV['DB_ROOT_PASSWORD'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    public function hasTable(string $table): bool
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $statement->execute([$this->database, $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Every table's rows as one checksum per table: what "content preserved"
     * is measured by.
     *
     * @param list<string> $except
     *
     * @return array<string, string>
     */
    public function databaseSnapshot(array $except = []): array
    {
        $pdo = $this->pdo();
        $snapshot = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
            if (in_array($table, $except, true)) {
                continue;
            }
            $rows = array_map(static fn (array $row): string => serialize($row), $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll());
            sort($rows);
            $snapshot[$table] = count($rows) . ':' . hash('sha256', implode("\n", $rows));
        }
        ksort($snapshot);

        return $snapshot;
    }

    public function serverLog(): string
    {
        return ($this->site?->log() ?? '') . ($this->feed?->log() ?? '');
    }

    // ------------------------------------------------------------ internals

    private function createEmptyDatabase(): void
    {
        $root = self::rootConnection();
        $root->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        $root->exec('CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $root->exec('GRANT ALL ON `' . $this->database . '`.* TO ' . $root->quote((string) $_ENV['DB_USERNAME']) . "@'%'");
    }

    /** The release's own `vendor/bin/phinx migrate`, reading the sandbox's own .env. */
    private function migrateFromZero(): void
    {
        $process = proc_open(
            [PHP_BINARY, $this->root() . '/vendor/bin/phinx', 'migrate', '-c', $this->root() . '/phinx.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root(),
            ['PATH' => (string) getenv('PATH')]
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new \RuntimeException("Migrating the fresh sandbox database failed:\n" . $output);
        }
    }

    private function addOwner(): void
    {
        $pdo = $this->pdo();
        $pdo->prepare('DELETE FROM admin_users WHERE username = ?')->execute([self::USERNAME]);
        $pdo->prepare(
            'INSERT INTO admin_users (name, username, email, password_hash, is_super_admin, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, 1, NOW(), NOW())'
        )->execute(['E2E Owner', self::USERNAME, 'e2e-owner@example.test', password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
    }

    /** Copies $source into this sandbox's own database; $source is only read. */
    private function copyDatabase(string $source): void
    {
        $root = self::rootConnection();

        $this->createEmptyDatabase();
        $root->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $root->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
        $tables->execute([$source]);

        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $create = $root->query('SHOW CREATE TABLE `' . $source . '`.`' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            $root->exec('USE `' . $this->database . '`');
            $root->exec($create);
            $root->exec('INSERT INTO `' . $this->database . '`.`' . $table . '` SELECT * FROM `' . $source . '`.`' . $table . '`');
        }

        $this->addOwner();
    }

    private function writeEnv(int $sitePort, int $feedPort): void
    {
        $quote = static fn (string $value): string => '"' . addcslashes($value, "\"\\\$") . '"';
        $lines = [
            'APP_ENV' => 'testing',
            'APP_URL' => $this->externalUrl ?? 'http://127.0.0.1:' . $sitePort,
            'DB_HOST' => self::host(),
            'DB_PORT' => self::port(),
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => (string) $_ENV['DB_USERNAME'],
            'DB_PASSWORD' => (string) ($_ENV['DB_PASSWORD'] ?? ''),
            'MODULE_SHOP_ENABLED' => 'true',
            'MODULE_BLOG_ENABLED' => 'true',
            'MODULE_PERSONALIZATION_ENABLED' => 'true',
            'MODULE_PORTFOLIO_ENABLED' => 'true',
            'MYGDALA_UPDATE_MANIFEST_URL' => 'http://127.0.0.1:' . $feedPort . '/manifest.json',
            'MYGDALA_UPDATE_PUBLIC_KEY' => self::keys()->publicKey(),
        ] + $this->env;

        $env = '';
        foreach ($lines as $key => $value) {
            $env .= $key . '=' . $quote($value) . "\n";
        }

        file_put_contents($this->root() . '/.env', $env);
    }

    /**
     * The web server runs as www-data where the suite runs as root, like
     * Apache on a host. Call again after a test put files in the sandbox,
     * or the site cannot write where it could on a real host.
     */
    public function handToWebUser(): void
    {
        if (self::asWebUser() === []) {
            return;
        }

        exec('chown -R 33:33 ' . escapeshellarg($this->directory) . ($this->root !== $this->directory . '/site' ? ' ' . escapeshellarg($this->root) : ''));
    }

    /** @return list<string> */
    public static function asWebUser(): array
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0 && is_executable('/usr/bin/setpriv')) {
            return ['/usr/bin/setpriv', '--reuid=33', '--regid=33', '--clear-groups'];
        }

        return [];
    }

    private static function csrfFrom(string $html): string
    {
        if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $match) !== 1) {
            throw new \RuntimeException("No CSRF token on the page:\n" . substr($html, 0, 2000));
        }

        return $match[1];
    }

    private static function rootConnection(): PDO
    {
        return new PDO('mysql:host=' . self::host() . ';port=' . self::port() . ';charset=utf8mb4', 'root', (string) ($_ENV['DB_ROOT_PASSWORD'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private static function host(): string
    {
        return (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
    }

    private static function port(): string
    {
        return (string) ($_ENV['DB_PORT'] ?? '3306');
    }
}
