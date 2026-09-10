<?php

/**
 * Builds (or refreshes) the dedicated TEST database, so `phpunit` never
 * touches the development database it mirrors.
 *
 * The test database is an exact copy of development: same schema (foreign
 * keys and all, taken from SHOW CREATE TABLE) and, unless --schema-only is
 * given, the same rows. That matters because a large part of this suite
 * asserts things about content that migrations backfilled — an empty schema
 * would make those tests skip instead of run, which is coverage lost rather
 * than coverage isolated.
 *
 * Development is only ever READ here. Nothing in this script writes to it.
 *
 * Usage (from the host):
 *   docker exec vvld_php php scripts/test-db.php
 *   docker exec vvld_php php scripts/test-db.php --schema-only
 *   docker exec vvld_php php scripts/test-db.php --drop
 *
 * See TESTING.md.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}

$options = array_slice($argv, 1);
$schemaOnly = in_array('--schema-only', $options, true);
$drop = in_array('--drop', $options, true);

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$development = (string) ($_ENV['DB_DATABASE'] ?? '');
$appUser = (string) ($_ENV['DB_USERNAME'] ?? '');
$rootPassword = (string) ($_ENV['DB_ROOT_PASSWORD'] ?? '');
$test = (string) ($_ENV['TEST_DB_DATABASE'] ?? '');

if ($test === '' && $development !== '') {
    $test = $development . '_test';
}

function fail(string $message): never
{
    fwrite(STDERR, "\n  test-db: {$message}\n\n");
    exit(1);
}

if ($development === '') {
    fail('DB_DATABASE is empty — is .env present?');
}
if ($test === '') {
    fail('could not work out a test database name.');
}
if ($test === $development) {
    fail("TEST_DB_DATABASE ({$test}) is the development database. Refusing to touch it.");
}
if ($rootPassword === '') {
    fail('DB_ROOT_PASSWORD is empty — creating a database and granting rights needs the MySQL root account.');
}

$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";

try {
    $db = new PDO($dsn, 'root', $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fail('could not connect to MySQL as root: ' . $e->getMessage());
}

$quotedTest = '`' . str_replace('`', '``', $test) . '`';
$quotedDevelopment = '`' . str_replace('`', '``', $development) . '`';

if ($drop) {
    $db->exec("DROP DATABASE IF EXISTS {$quotedTest}");
    echo "Dropped {$test}.\n";
    exit(0);
}

$db->exec("CREATE DATABASE IF NOT EXISTS {$quotedTest} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

if ($appUser !== '') {
    // MySQL's MYSQL_USER/MYSQL_DATABASE bootstrap only grants rights on the
    // development database, so the application user needs its own grant here.
    $escaped = str_replace('`', '``', $test);
    $db->exec("GRANT ALL PRIVILEGES ON `{$escaped}`.* TO " . $db->quote($appUser) . "@'%'");
    $db->exec('FLUSH PRIVILEGES');
}

$tables = $db->query(
    'SELECT table_name FROM information_schema.tables '
    . 'WHERE table_schema = ' . $db->quote($development) . " AND table_type = 'BASE TABLE' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

if ($tables === []) {
    fail("the development database ({$development}) has no tables — run `phinx migrate` first.");
}

$db->exec('SET FOREIGN_KEY_CHECKS = 0');

// Drop whatever the previous refresh left behind, so a table removed by a
// migration does not linger in the test database.
$stale = $db->query(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = ' . $db->quote($test)
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($stale as $table) {
    $db->exec("DROP TABLE IF EXISTS {$quotedTest}.`" . str_replace('`', '``', (string) $table) . '`');
}

$rows = 0;

foreach ($tables as $table) {
    $name = (string) $table;
    $quoted = '`' . str_replace('`', '``', $name) . '`';

    // SHOW CREATE TABLE rather than CREATE TABLE ... LIKE: the latter drops
    // foreign keys, and several tests lean on ON DELETE CASCADE.
    $create = $db->query("SHOW CREATE TABLE {$quotedDevelopment}.{$quoted}")->fetch();
    $sql = (string) ($create['Create Table'] ?? '');

    if ($sql === '') {
        fail("could not read the definition of {$name}.");
    }

    $sql = preg_replace(
        '/^CREATE TABLE `' . preg_quote(str_replace('`', '``', $name), '/') . '`/',
        "CREATE TABLE {$quotedTest}.{$quoted}",
        $sql,
        1
    );

    $db->exec($sql);

    if (!$schemaOnly) {
        $rows += $db->exec("INSERT INTO {$quotedTest}.{$quoted} SELECT * FROM {$quotedDevelopment}.{$quoted}");
    }
}

$db->exec('SET FOREIGN_KEY_CHECKS = 1');

printf(
    "Test database %s refreshed from %s: %d tables%s.\n",
    $test,
    $development,
    count($tables),
    $schemaOnly ? ' (schema only)' : ", {$rows} rows"
);
