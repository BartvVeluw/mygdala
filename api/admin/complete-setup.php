<?php

/**
 * POST /api/admin/complete-setup.php
 *
 * Finishes the Setup Wizard: the ONE write the wizard makes, and the only
 * place setup is ever marked complete.
 *
 * Same guard order and PRG/session-flash pattern as every other admin
 * endpoint: session login, permission, POST-only, CSRF, then server-side
 * validation. App\Install\SetupWizard owns what a valid answer is and what
 * finishing does — this file is the HTTP shell around it, exactly as
 * api/admin/create-page.php is the shell around PageTemplateInstaller.
 *
 * IT REFUSES TO RUN TWICE. An installation that is already configured — an
 * existing site, or one whose wizard has finished — gets sent back to
 * admin/setup.php, which shows it the "already done" page. Setup is not a
 * button that reconfigures a live site, and a replayed POST must not become
 * one.
 *
 * NOTHING IS MARKED COMPLETE UNLESS EVERYTHING SUCCEEDED. A validation
 * failure returns to the form with the submission intact; a failure while
 * writing leaves setup unfinished, so the owner returns to the wizard rather
 * than to a site that claims to be set up. What the wizard already managed
 * to create is idempotent, so finishing the job on a second attempt neither
 * duplicates a page nor a menu item (App\Install\SetupWizard).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Install\SetupState;
use App\Install\SetupWizard;
use App\Service\AdminAuth;
use App\Service\Csrf;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

if (!SetupState::isSetupRequired()) {
    header('Location: /admin/setup.php');
    exit;
}

/**
 * What goes back into the form when something is rejected. Only the fields
 * the wizard itself offers, so a crafted POST cannot fill the session with
 * arbitrary data.
 */
$old = [];
foreach (['site_name', 'email', 'footer_description', 'city', 'kvk_number', \App\Service\AppUrl::SETTING_KEY] as $key) {
    $old[$key] = trim((string) ($_POST[$key] ?? ''));
}
foreach (\App\Service\Branding::MEDIA_KEYS as $mediaKey) {
    $old[$mediaKey] = trim((string) ($_POST[$mediaKey] ?? ''));
}
foreach (\App\Service\Theme\ThemeSettings::keys() as $key) {
    $old[$key] = trim((string) ($_POST[$key] ?? ''));
}
$old['modules'] = is_array($_POST['modules'] ?? null) ? array_map('strval', array_keys(array_filter($_POST['modules']))) : [];
$old['modules'] = array_fill_keys($old['modules'], '1');
$old['pages'] = is_array($_POST['pages'] ?? null) ? array_keys(array_filter($_POST['pages'])) : [];
$old['pages'] = array_fill_keys(array_map('strval', $old['pages']), '1');

$validated = SetupWizard::validate($_POST);

if ($validated['errors'] !== []) {
    $_SESSION['admin_setup_errors'] = array_values($validated['errors']);
    $_SESSION['admin_setup_old'] = $old;
    header('Location: /admin/setup.php');
    exit;
}

try {
    SetupWizard::complete($_POST);
} catch (\Throwable $e) {
    error_log('[api/admin/complete-setup.php] ' . $e->getMessage());

    $_SESSION['admin_setup_errors'] = [
        'De installatie kon niet worden afgerond en is niet als voltooid gemarkeerd.'
            . ' Wat al gelukt was blijft staan; opnieuw afronden maakt niets dubbel.',
    ];
    $_SESSION['admin_setup_old'] = $old;
    header('Location: /admin/setup.php');
    exit;
}

header('Location: /admin/index.php?setup=1');
exit;
