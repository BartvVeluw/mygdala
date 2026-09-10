<?php

namespace App\Service;

use Dotenv\Dotenv;
use Mollie\Api\MollieApiClient;

/**
 * Single shared Mollie API client, configured from .env (MOLLIE_API_KEY).
 * Mirrors App\Database's pattern: one static instance, lazily created.
 */
class MollieClientFactory
{
    private static ?MollieApiClient $client = null;

    public static function client(): MollieApiClient
    {
        if (self::$client === null) {
            self::loadEnv();

            $apiKey = $_ENV['MOLLIE_API_KEY'] ?? '';
            if ($apiKey === '') {
                throw new \RuntimeException('MOLLIE_API_KEY is not set (.env).');
            }

            self::$client = new MollieApiClient();
            self::$client->setApiKey($apiKey);
        }

        return self::$client;
    }

    private static function loadEnv(): void
    {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
