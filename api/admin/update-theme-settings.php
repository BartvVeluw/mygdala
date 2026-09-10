<?php

/**
 * POST /api/admin/update-theme-settings.php
 *
 * Saves the Vormgeving & Branding form on admin/theme.php: the five colours,
 * the font pairing and the button shape.
 *
 * Only theme keys are reachable from here — nothing in this file can write a
 * site_settings row, which is what makes the appearance/identity split real
 * rather than a naming convention. Validation lives in
 * App\Service\Theme\ThemeSettings, so the same rules apply whether a value
 * arrives from this form, from a test or from anywhere else.
 *
 * Same PRG/session-flash pattern as api/admin/update-site-settings.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Theme\ThemeSettings;

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

$result = ThemeSettings::validate($_POST);

if ($result['errors'] !== []) {
    $_SESSION['admin_theme_errors'] = array_values($result['errors']);
    // Hand back what was typed so the form can show it again, but only the
    // values that passed — a rejected colour would just be rejected twice.
    $_SESSION['admin_theme_old'] = $result['values'];
    header('Location: /admin/theme.php');
    exit;
}

try {
    ThemeSettings::save($result['values']);
} catch (\Throwable $e) {
    error_log('[api/admin/update-theme-settings.php] ' . $e->getMessage());

    $_SESSION['admin_theme_errors'] = ['De vormgeving kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_theme_old'] = $result['values'];
    header('Location: /admin/theme.php');
    exit;
}

header('Location: /admin/theme.php?saved=1');
exit;
