<?php

declare(strict_types=1);

/**
 * Runs before any test, and its whole job is to make sure the suite cannot
 * reach the development database.
 *
 * How it works: App\Database (like phinx.php) reads its DB_* settings from
 * $_ENV, filled by Dotenv::createImmutable() — and "immutable" means Dotenv
 * never overwrites a variable that is already set. Setting DB_DATABASE here,
 * before the first connection is opened, therefore wins over .env for the
 * whole run without a single test having to know about it.
 *
 * Anything that goes wrong stops the run with an explanation rather than
 * quietly falling back to development.
 *
 * See TESTING.md.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use Tests\Support\TestEnvironment;

(static function (): void {
    $root = dirname(__DIR__);

    // Read .env without publishing it: the values below must be decided
    // before anything else populates $_ENV.
    $file = [];
    if (file_exists($root . '/.env')) {
        $file = Dotenv\Dotenv::createArrayBacked($root)->load();
    }

    // .env before the environment, for the development name: the php_test
    // container already has DB_DATABASE pointed at the test database, and
    // deriving "development" from that would append the suffix a second time.
    $development = trim((string) ($file['DB_DATABASE'] ?? ''));
    if ($development === '') {
        $development = TestEnvironment::developmentDatabaseName();
    }

    $test = trim((string) ($_ENV['TEST_DB_DATABASE'] ?? getenv('TEST_DB_DATABASE') ?: ''));
    if ($test === '') {
        $test = trim((string) ($file['TEST_DB_DATABASE'] ?? ''));
    }
    if ($test === '' && $development !== '') {
        $test = $development . '_test';
    }

    $abort = static function (string $message): never {
        fwrite(STDERR, PHP_EOL . '  Test bootstrap: ' . $message . PHP_EOL
            . '  See TESTING.md.' . PHP_EOL . PHP_EOL);
        exit(1);
    };

    if ($development === '') {
        $abort('DB_DATABASE is not configured, so there is nothing to isolate the suite from.');
    }

    if ($test === '') {
        $abort('could not work out a test database name (set TEST_DB_DATABASE).');
    }

    if ($test === $development) {
        $abort(sprintf(
            'TEST_DB_DATABASE and DB_DATABASE are both "%s". The suite writes to its database; '
                . 'it will not run against development.',
            $test
        ));
    }

    foreach (['DB_DATABASE' => $test, 'TEST_DB_DATABASE' => $test] as $key => $value) {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    // Prove it, rather than trusting it: open the connection the whole suite
    // shares and ask the server which database it actually landed on.
    try {
        $connection = Database::connection();
    } catch (PDOException $e) {
        // Two very different problems land here, and they need different
        // answers. Ask the server itself which one it is: a database that has
        // not been created yet reads as "Unknown database" (1049) or, when the
        // application user simply has no rights on a name that is not there,
        // as "Access denied ... to database" (1044) — but either way the
        // server answers. If it does not answer at all, there is no server.
        // The environment wins for these, exactly as it does in App\Database:
        // docker-compose points DB_HOST at the "mysql" service, overriding the
        // 127.0.0.1 in .env that is meant for tools on the host machine.
        $setting = static fn (string $key, string $fallback): string => (string) ($_ENV[$key] ?? $file[$key] ?? $fallback);

        $serverIsUp = false;
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s', $setting('DB_HOST', '127.0.0.1'), $setting('DB_PORT', '3306')),
                $setting('DB_USERNAME', ''),
                $setting('DB_PASSWORD', '')
            );
            $serverIsUp = true;
        } catch (PDOException) {
            // Leave $serverIsUp false.
        }

        if ($serverIsUp) {
            $abort(sprintf(
                'cannot open the test database "%s" (%s).' . PHP_EOL
                    . '  Create or refresh it with:' . PHP_EOL
                    . '    docker exec mygdala_php php scripts/test-db.php',
                $test,
                $e->getMessage()
            ));
        }

        // No database server at all: the tiers that need one fail on their own
        // terms, and the ones that do not can still run.
        fwrite(STDERR, PHP_EOL . '  Test bootstrap: no database server reachable (' . $e->getMessage() . ').'
            . PHP_EOL . '  Database-backed tests will fail; the unit and contract suites do not need one.'
            . PHP_EOL . PHP_EOL);

        return;
    }

    $actual = (string) $connection->query('SELECT DATABASE()')->fetchColumn();

    if ($actual !== $test) {
        $abort(sprintf('expected to be connected to "%s" but the server reports "%s".', $test, $actual));
    }

    if ($actual === $development) {
        $abort('the connection landed on the development database.');
    }
})();
