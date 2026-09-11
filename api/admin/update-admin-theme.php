<?php

/**
 * POST /api/admin/update-admin-theme.php
 *
 * Saves the "Dashboard uiterlijk" card on admin/settings.php: one key out of
 * App\Service\AdminTheme's closed set, and nothing else.
 *
 * A form of its own, next to the several other independent forms on that
 * page, so it can never take a site setting with it — this endpoint cannot
 * write a site_settings row at all. That is also why it is not folded into
 * api/admin/update-site-settings.php: a partial POST there would be a POST
 * against a full-form handler, and the theme card does not show, let alone
 * resubmit, the company address.
 *
 * Validation lives in AdminTheme, so a crafted value is rejected by the same
 * rule that rejects a stale row on the way out.
 *
 * Same PRG/session-flash pattern as api/admin/update-theme-settings.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\AdminTheme;
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

$requested = AdminTheme::normalise(isset($_POST['admin_theme']) ? (string) $_POST['admin_theme'] : null);

if ($requested === null) {
    $_SESSION['admin_theme_choice_error'] = AdminTranslator::trans('validation.choose_dashboard_theme');
    header('Location: /admin/settings.php#dashboard-uiterlijk');
    exit;
}

try {
    AdminTheme::save($requested);
} catch (\Throwable $e) {
    error_log('[api/admin/update-admin-theme.php] ' . $e->getMessage());

    $_SESSION['admin_theme_choice_error'] = AdminTranslator::trans('validation.uiterlijk_kon_opgeslagen_probeer_opnieuw');
    header('Location: /admin/settings.php#dashboard-uiterlijk');
    exit;
}

/**
 * ?saved=1 is this project's success marker on every admin write endpoint,
 * and the save bar reads it as "the server confirmed". `section` says which
 * of the several independent forms on the settings page it confirmed, so a
 * saved theme does not print "Instellingen opgeslagen" over the identity
 * card above it.
 */
header('Location: /admin/settings.php?saved=1&section=dashboard-uiterlijk#dashboard-uiterlijk');
exit;
