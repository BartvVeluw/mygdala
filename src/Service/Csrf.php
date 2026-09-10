<?php

namespace App\Service;

/**
 * CSRF protection for admin forms/APIs. One token per admin session,
 * checked with a constant-time comparison.
 */
class Csrf
{
    public static function token(): string
    {
        AdminAuth::start();

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function validate(mixed $token): bool
    {
        AdminAuth::start();

        $expected = $_SESSION['csrf_token'] ?? null;

        return is_string($token) && is_string($expected) && $token !== '' && hash_equals($expected, $token);
    }
}
