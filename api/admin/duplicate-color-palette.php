<?php

/**
 * POST /api/admin/duplicate-color-palette.php
 *
 * Copies a colour palette: the same five colours under "<name> (kopie)" (or
 * "(kopie 2)", …), NOT active, so the website does not change. Lands in the
 * copy's editor, where it can be renamed and changed on its own. Same guards
 * and PRG pattern as api/admin/duplicate-page-theme.php.
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
    $copyId = ColorPaletteService::duplicate((int) $palette['id']);
} catch (\Throwable $e) {
    error_log('[api/admin/duplicate-color-palette.php] ' . $e->getMessage());
    $copyId = null;
}

if ($copyId === null) {
    $_SESSION['admin_palettes_error'] = AdminTranslator::trans('palettes.error_save');
    header('Location: /admin/theme.php#paletten');
    exit;
}

header('Location: /admin/color-palette.php?id=' . $copyId . '&done=duplicated');
exit;
