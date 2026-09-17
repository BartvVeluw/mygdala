<?php

namespace Tests\Support;

use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use PDO;

/**
 * A throwaway database that the migrations are run against from zero.
 *
 * The rest of the suite works on `mygdala_tests`, which is a copy of
 * development — exactly right for asking "did this backfill keep its
 * promise", and useless for asking "what does a brand-new installation get",
 * because it starts out full of the answer. Install behaviour can only be
 * proven on a database that has never held anything.
 *
 * Two kinds of scratch install, matching the two kinds of database in the
 * world (INSTALL-BOOTSTRAP.md):
 *
 *   fresh   every migration runs in order, from an empty schema. This is
 *           what someone deploying this CMS somewhere new ends up with.
 *   legacy  the same, except the install marker is flipped back to
 *           `legacy_existing_site` after the first migration and before any
 *           seed runs — which is precisely the state a database that
 *           predates the marker is in when it is caught up. It therefore
 *           takes the same code path a real existing installation takes.
 *
 * Needs the MySQL root account (DB_ROOT_PASSWORD in .env) to create and drop
 * databases, the same way scripts/test-db.php does. Where that is not
 * available — a production host, someone else's machine — {@see available()}
 * says so and the tests skip rather than fail for the wrong reason.
 *
 * AN UNCONFIGURED DEPLOYMENT. Every process run for a scratch install —
 * phinx, and whatever runScript() starts — sees the variables in which a
 * deployment makes its own choices as set but EMPTY: no APP_ENV, no APP_URL,
 * no MODULE_<KEY>_ENABLED, no .env admin account, no shop address. Empty
 * rather than absent, because Dotenv's immutable loader leaves a variable
 * that is already set alone, so a .env file on the machine cannot fill them
 * back in either. Without this, "what does a brand-new installation get" was
 * answered for whatever the developer's own environment happened to say:
 * robots.txt came out non-production and the Setup Wizard found its choices
 * already pinned. A test about a CONFIGURED deployment says so itself, by
 * passing those values to fresh().
 */
final class ScratchInstall
{
    /** The first migration: the one that writes the install marker. */
    private const FIRST_MIGRATION = '20260903120000';

    /** phinx.php's `default_migration_table`. */
    private const MIGRATION_LOG = 'phinx_migration_log';

    /**
     * What an installation reads from the environment to decide something
     * for itself. MODULE_<KEY>_ENABLED is not listed: it comes from
     * ModuleRegistry, so a new module is covered without editing this.
     */
    private const DEPLOYMENT_CHOICES = [
        'APP_ENV',
        'APP_URL',
        'ADMIN_USERNAME',
        'ADMIN_PASSWORD_HASH',
        'ADMIN_EMAIL',
        'MAIL_FROM_ADDRESS',
        'SHOP_NOTIFICATION_EMAIL',
    ];

    /**
     * @param array<string, string> $environment deployment configuration the
     *                                           test gave this installation
     */
    private function __construct(
        public readonly string $database,
        private readonly PDO $pdo,
        private readonly array $environment = []
    ) {
    }

    public static function available(): bool
    {
        return self::rootPassword() !== '' && self::appUser() !== '';
    }

    /**
     * Builds a database from zero with every migration applied, as a
     * brand-new installation of this CMS.
     *
     * @param array<string, string> $environment what this deployment has
     *        configured, on top of the unconfigured default — for example
     *        the .env admin account a test wants the migrations to find
     */
    public static function fresh(string $database, array $environment = []): self
    {
        $install = self::createEmpty($database, $environment);
        $install->migrate();

        return $install;
    }

    /**
     * Builds a database that behaves like an installation which predates the
     * install marker: schema from zero, but every historical backfill still
     * responsible for its own content.
     */
    public static function legacy(string $database): self
    {
        $install = self::createEmpty($database);

        $install->migrate(self::FIRST_MIGRATION);
        $install->pdo()->exec(
            "UPDATE install_state SET state_value = 'legacy_existing_site' WHERE state_key = 'install_kind'"
        );
        $install->migrate();

        return $install;
    }

    /**
     * Builds a database from zero with the migrations applied only UP TO AND
     * INCLUDING $version, so a test can stand where an installation stood
     * before a later migration existed, put content in it, and then let the
     * rest run.
     *
     * That is the only way to test a corrective migration honestly: the state
     * it repairs has to be produced by the migration that produced it in the
     * real world, not written by hand.
     */
    public static function upTo(string $database, string $version): self
    {
        $install = self::createEmpty($database);
        $install->migrate($version);

        return $install;
    }

    /**
     * Runs the migrations this database has not had yet, all of them or up to
     * and including $version.
     */
    public function catchUp(?string $version = null): void
    {
        $this->migrate($version);
    }

    /**
     * Runs one migration that already ran here a second time, through Phinx
     * itself: its line leaves the migration log, and the next migrate picks
     * it up again because Phinx runs every migration the log does not name.
     *
     * The honest proof of "idempotent": the file that ships, run again on
     * the state its own first run produced, rather than a copy of its SQL.
     *
     * $upTo keeps a database that was stopped at an older migration where it
     * stood, instead of letting the replay run every later one as well.
     */
    public function replay(string $version, ?string $upTo = null): void
    {
        $this->pdo
            ->prepare('DELETE FROM `' . self::MIGRATION_LOG . '` WHERE version = ?')
            ->execute([$version]);

        $this->migrate($upTo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function count(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    public function hasTable(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
        );
        $statement->execute([$this->database, $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Runs one of the project's own PHP entry points against this database,
     * in its own process.
     *
     * A subprocess and not a direct call: App\Database holds one static
     * connection for the whole process, so pointing it at a scratch database
     * would leave every later test in the run talking to the wrong one.
     *
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string} exit code and combined output
     */
    public function runScript(string $relativePath, array $arguments = []): array
    {
        $command = array_merge(
            ['php', self::root() . '/' . $relativePath],
            $arguments
        );

        return self::run($command, $this->environment());
    }

    public function drop(): void
    {
        self::rootConnection()->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
    }

    // ------------------------------------------------------------ internals

    /** @param array<string, string> $environment */
    private static function createEmpty(string $database, array $environment = []): self
    {
        $root = self::rootConnection();
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        $root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $root->exec('GRANT ALL ON `' . $database . '`.* TO ' . $root->quote(self::appUser()) . '@\'%\'');
        $root->exec('FLUSH PRIVILEGES');

        $dsn = 'mysql:host=' . self::host() . ';port=' . self::port() . ';dbname=' . $database . ';charset=utf8mb4';
        $pdo = new PDO($dsn, 'root', self::rootPassword(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return new self($database, $pdo, $environment);
    }

    private function migrate(?string $target = null): void
    {
        $command = ['php', self::root() . '/vendor/bin/phinx', 'migrate', '-c', self::root() . '/phinx.php'];
        if ($target !== null) {
            $command[] = '-t';
            $command[] = $target;
        }

        [$status, $output] = self::run($command, $this->environment());

        if ($status !== 0) {
            throw new \RuntimeException("Migrating {$this->database} failed:\n" . $output);
        }
    }

    /**
     * The environment every process for this install runs under: its own
     * database, then what the test configured, then an unconfigured
     * deployment for everything else (see the class docblock).
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $unconfigured = array_fill_keys(self::DEPLOYMENT_CHOICES, '');
        foreach (ModuleRegistry::keys() as $moduleKey) {
            $unconfigured[ModuleConfig::variableName($moduleKey)] = '';
        }

        return ['DB_DATABASE' => $this->database] + $this->environment + $unconfigured;
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string}
     */
    private static function run(array $command, array $environment): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // This install's own environment has to win over whatever the suite
        // is running under, so it goes first: `+` keeps the left key.
        $inherited = array_map(static fn ($value): string => (string) $value, getenv());
        $process = proc_open($command, $descriptors, $pipes, self::root(), $environment + $inherited);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start: ' . implode(' ', $command));
        }

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    private static function rootConnection(): PDO
    {
        return new PDO(
            'mysql:host=' . self::host() . ';port=' . self::port() . ';charset=utf8mb4',
            'root',
            self::rootPassword(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function host(): string
    {
        return (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
    }

    private static function port(): string
    {
        return (string) ($_ENV['DB_PORT'] ?? '3306');
    }

    private static function rootPassword(): string
    {
        return (string) ($_ENV['DB_ROOT_PASSWORD'] ?? '');
    }

    private static function appUser(): string
    {
        return (string) ($_ENV['DB_USERNAME'] ?? '');
    }
}
