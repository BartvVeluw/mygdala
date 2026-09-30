<?php

/**
 * POST /api/admin/delete-color-palette.php
 *
 * Deletes a colour palette that is NOT active and not the last one. The
 * active palette is refused with a message saying to activate another first
 * (the SQL refuses it too: App\Repository\ColorPaletteRepository::deleteInactive()),
 * and the last palette is refused because the website always needs one.
 * Deleting an inactive palette never changes the website. Same guards and
 * PRG pattern as api/admin/delete-page-theme.php.
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
    $result = ColorPaletteService::delete((int) $palette['id']);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-color-palette.php] ' . $e->getMessage());
    $result = null;
}

if ($result === ColorPaletteService::DELETE_DELETED) {
    header('Location: /admin/theme.php?palette=deleted#paletten');
    exit;
}

$_SESSION['admin_palettes_error'] = AdminTranslator::trans(match ($result) {
    ColorPaletteService::DELETE_ACTIVE => 'palettes.error_delete_active',
    ColorPaletteService::DELETE_LAST => 'palettes.error_delete_last',
    default => 'palettes.error_delete',
});
header('Location: /admin/theme.php#paletten');
exit;
