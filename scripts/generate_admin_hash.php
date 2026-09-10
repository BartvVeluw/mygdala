<?php

/**
 * CLI helper: generates a bcrypt hash for the admin password, to put in
 * .env as ADMIN_PASSWORD_HASH. Never store the plain password anywhere.
 *
 * Usage: php scripts/generate_admin_hash.php "your-password"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$password = $argv[1] ?? null;

if (!is_string($password) || $password === '') {
    fwrite(STDERR, "Usage: php scripts/generate_admin_hash.php \"your-password\"\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
