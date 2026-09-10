<?php

/**
 * POST /api/admin/update-account-preferences.php
 *
 * The signed-in person's own preferences — in V1 that is the language the
 * CMS interface is shown to them in, and nothing else.
 *
 * NO PERMISSION BEYOND BEING LOGGED IN, and that is the point: this endpoint
 * can only ever write to the account that is making the request. There is no
 * user id in the form, so there is nothing to tamper with — a CMS user
 * cannot change a colleague's preferences here whatever they post, and a
 * Super Admin cannot either. Changing somebody else's account is
 * admin/user-form.php's job, which has its own permission.
 *
 * IT CHANGES NOT ONE WORD OF THE WEBSITE. The interface language and the
 * website's content languages are separate settings in separate tables owned
 * by separate classes (MULTILINGUAL.md). This endpoint writes to
 * `admin_users` and never touches `site_settings`, which is what
 * Tests\Service\AdminLocaleTest checks.
 *
 * A BREAK-GLASS SESSION IS REFUSED rather than silently ignored: it has no
 * account row to store a preference on, and pretending the save worked would
 * leave somebody wondering why their choice keeps reverting.
 *
 * Same PRG/session-flash pattern as every other write endpoint here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminLocale;

AdminAuth::requireLoginForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$userId = AdminAuth::userId();

if ($userId === null) {
    // Break-glass, or a session whose account disappeared between the page
    // render and this request. Either way there is nowhere to write.
    $_SESSION['admin_account_errors'] = ['Deze sessie heeft geen account om voorkeuren op te bewaren.'];
    header('Location: /admin/account.php');
    exit;
}

// AdminLocale::persist() normalises against the closed language registry, so
// whatever the form sent stops being request input right there: an unknown
// code becomes the default instead of reaching the database.
try {
    AdminLocale::persist($userId, (string) ($_POST['interface_language'] ?? ''));
} catch (\Throwable $e) {
    error_log('[api/admin/update-account-preferences.php] ' . $e->getMessage());

    $_SESSION['admin_account_errors'] = ['Je voorkeuren konden niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/account.php');
    exit;
}

header('Location: /admin/account.php?saved=1');
exit;
