<?php

/**
 * Runs inside every `require vendor/autoload.php` (composer.json, autoload
 * "files"), which is the only place every entry point of this project
 * shares. One is_file() per request or script; the guard class is only
 * loaded while an update has the site in maintenance. See
 * App\Update\MaintenanceGuard.
 */

declare(strict_types=1);

if (is_file(dirname(__DIR__, 2) . '/.maintenance')) {
    \App\Update\MaintenanceGuard::enforce();
}
