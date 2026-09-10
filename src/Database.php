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
            self::loadEnv();

            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $database = $_ENV['DB_DATABASE'] ?? '';
            $username = $_ENV['DB_USERNAME'] ?? '';
            $password = $_ENV['DB_PASSWORD'] ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$connection;
    }

    private static function loadEnv(): void
    {
        $root = dirname(__DIR__);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
