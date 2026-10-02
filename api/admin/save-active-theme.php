<?php

/**
 * POST /api/admin/save-active-theme.php
 *
 * Makes one Global Theme the website's theme, from the tab Thema on
 * admin/theme.php. Same guards and PRG pattern as
 * api/admin/activate-color-palette.php, its neighbour on that screen.
 *
 * The one input is `theme`, a key. It counts only when it IS a key of the
 * closed list (App\Service\Theme\ThemeRegistry::find()): membership, not a
 * pattern, so `LEGACY`, `minimal.css`, a path or a URL are simply not found.
 * Anything else is a 404 and nothing is written. The key is then stored as
 * it is (ThemeSettings::saveActiveThemeKey()): one row, `active_theme`, and
 * nothing else — no palette, font, button style, page theme or content
 * changes with it. `legacy` (Klassiek) is stored too rather than deleting
 * the row, so a deliberate Klassiek is a choice like any other.
 *
 * No path, file or stylesheet ever comes from the request: the definition
 * in code decides what the website loads (THEMING.md, "Theme kiezen").
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Theme\ThemeRegistry;
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

$key = $_POST['theme'] ?? null;
$theme = is_string($key) ? ThemeRegistry::find($key) : null;

if ($theme === null) {
    http_response_code(404);
    exit('Theme not found.');
}

try {
    ThemeSettings::saveActiveThemeKey($theme->key);
} catch (\Throwable $e) {
    error_log('[api/admin/save-active-theme.php] ' . $e->getMessage());
    $_SESSION['admin_themes_error'] = AdminTranslator::trans('themes.error_activate');
    header('Location: /admin/theme.php?tab=thema#thema');
    exit;
}

header('Location: /admin/theme.php?themes=activated&tab=thema#thema');
exit;
