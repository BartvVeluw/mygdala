<?php

/**
 * POST /api/admin/activate-color-palette.php
 *
 * Makes one colour palette the website's palette, from the overview on
 * admin/theme.php. Atomic (App\Repository\ColorPaletteRepository::activate()):
 * the previous palette stops being active in the same transaction, so there
 * is never a moment with two, or none. Every palette and its colours are
 * kept. Same guards and PRG pattern as api/admin/delete-page-theme.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Theme\ColorPaletteService;

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

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$palette = $id === false ? null : ColorPaletteService::find($id);

if ($palette === null) {
    http_response_code(404);
    exit('Colour palette not found.');
}

try {
    $done = ColorPaletteService::activate((int) $palette['id']);
} catch (\Throwable $e) {
    error_log('[api/admin/activate-color-palette.php] ' . $e->getMessage());
    $done = false;
}

if (!$done) {
    $_SESSION['admin_palettes_error'] = AdminTranslator::trans('palettes.error_activate');
    header('Location: /admin/theme.php#paletten');
    exit;
}

header('Location: /admin/theme.php?palette=activated#paletten');
exit;
