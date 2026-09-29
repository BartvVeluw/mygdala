<?php

/**
 * POST /api/admin/updates-check.php
 *
 * "Controleren op updates" on admin/updates.php: asks the configured feed for
 * its newest release (App\Update\Updater::check()), which verifies the
 * manifest's signature and runs the environment checks, and records the
 * answer for the Updates screen to show. It also refreshes the list of
 * earlier releases the screen shows under "Eerdere updates"
 * (App\Update\ReleaseHistory) — in the same click, so that list is fetched
 * once per check and never on a page render. That refresh never throws and
 * never changes the check's own answer or flash message.
 *
 * Takes nothing from the request but the CSRF token. The feed URL is
 * deployment configuration (App\Update\UpdateConfig), so this endpoint can
 * never be made to fetch an address somebody typed — no SSRF through the
 * Updates screen (docs/updates/ARCHITECTURE.md, "Beveiliging").
 *
 * Same PRG/session-flash pattern as api/admin/update-admin-theme.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Update\ReleaseHistory;
use App\Update\Updater;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('updates.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$record = Updater::fromConfig()->check();
ReleaseHistory::fromConfig()->refresh();

$_SESSION['admin_update_flash'] = is_array($record['error'] ?? null)
    ? ['type' => 'error', 'key' => $record['error']['key'], 'params' => $record['error']['params'] ?? []]
    : ['type' => 'success', 'key' => ($record['available'] ?? false) ? 'update.flash.available' : 'update.flash.up_to_date', 'params' => [
        'version' => (string) ($record['manifest']['version'] ?? ''),
    ]];

header('Location: /admin/updates.php', true, 303);
exit;
