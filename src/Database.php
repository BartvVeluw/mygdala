<?php

namespace App;

use Dotenv\Dotenv;
use PDO;

/**
 * Single shared PDO connection, configured from .env.
 * Call Database::connection() to get it; repositories take it in their constructor.
 */
class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::open([PDO::ATTR_EMULATE_PREPARES => false]);
        }

        return self::$connection;
    }

    /**
     * A SEPARATE connection to the same database, with attributes of the
     * caller's choosing. Only for work that must not share the request's
     * connection state: the self-updater's database backup and restore
     * (App\Update\DatabaseBackup) read every value as the server's own text
     * and switch foreign-key checks off for their session.
     *
     * @param array<int, mixed> $attributes
     */
    public static function open(array $attributes = []): PDO
    {
        self::loadEnv();

        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $database = $_ENV['DB_DATABASE'] ?? '';
        $username = $_ENV['DB_USERNAME'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        return new PDO($dsn, $username, $password, $attributes + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private static function loadEnv(): void
    {
        $root = dirname(__DIR__);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
