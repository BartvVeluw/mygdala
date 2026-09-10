<?php

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
}

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinx_migration_log',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'name' => $_ENV['DB_DATABASE'] ?? '',
            'user' => $_ENV['DB_USERNAME'] ?? '',
            'pass' => $_ENV['DB_PASSWORD'] ?? '',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
        ],
    ],
];
