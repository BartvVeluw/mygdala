<?php

/**
 * POST /api/admin/reset-theme-settings.php
 *
 * "Restore theme defaults": deletes the stored theme rows so every visual
 * setting falls back to what App\Service\Theme\ThemeSettings declares in
 * code.
 *
 * WHAT IT DOES NOT DO, on purpose. It does not touch the site name, the
 * logo, the alternate logo, the favicon, the default social image, the
 * company address, the KVK number, or any invoice or e-mail text. Those are
 * site_settings rows and this endpoint cannot reach that table at all — the
 * separation is a table boundary, not a promise in a comment. A destructive
 * "reset everything" is exactly the ambiguity this avoids.
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

try {
    ThemeSettings::reset();
} catch (\Throwable $e) {
    error_log('[api/admin/reset-theme-settings.php] ' . $e->getMessage());

    $_SESSION['admin_theme_errors'] = ['De vormgeving kon niet worden hersteld. Probeer het opnieuw.'];
    header('Location: /admin/theme.php');
    exit;
}

header('Location: /admin/theme.php?reset=1');
exit;
