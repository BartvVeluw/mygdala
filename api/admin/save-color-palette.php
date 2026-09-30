<?php

/**
 * POST /api/admin/save-color-palette.php
 *
 * Creates a colour palette (no id) or saves an existing one (id): its name
 * and its five colours, from admin/color-palette.php. Validation is
 * App\Service\Theme\ColorPaletteService::validate() — the site theme's own
 * colour rule (ThemeColor::normalise(), so nothing but #RRGGBB can reach a
 * stylesheet) and a required, unique name.
 *
 * Saving never ACTIVATES: a new palette and an inactive palette are saved
 * without the website changing (api/admin/activate-color-palette.php does
 * that). Saving the active palette changes the website, and the editor said
 * so before the save; the answer says so again. A refused save stores nothing
 * and goes back to the editor with the errors and what was typed. Same guards
 * and flash pattern as api/admin/save-page-theme.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Theme\ColorPaletteService;
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

$id = null;
$palette = null;
if (array_key_exists('id', $_POST)) {
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $palette = $id === false ? null : ColorPaletteService::find($id);

    if ($palette === null) {
        http_response_code(404);
        exit('Colour palette not found.');
    }
}

$editor = '/admin/color-palette.php' . ($id !== null ? '?id=' . $id : '');
$result = ColorPaletteService::validate($_POST, $id);

$handBack = static function (array $errors) use ($id, $editor): never {
    $old = ['id' => $id ?? 0];
    foreach (['name', ...ThemeSettings::COLOR_KEYS] as $field) {
        $old[$field] = is_scalar($_POST[$field] ?? null) ? trim((string) $_POST[$field]) : '';
    }

    $_SESSION['admin_palette_errors'] = $errors;
    $_SESSION['admin_palette_old'] = $old;
    header('Location: ' . $editor);
    exit;
};

if ($result['errors'] !== []) {
    $handBack($result['errors']);
}

try {
    if ($id === null) {
        $id = ColorPaletteService::create($result['values']);
        $done = 'created';
    } else {
        ColorPaletteService::update($id, $result['values']);
        $done = $palette['active'] ? 'saved_active' : 'saved';
    }
} catch (\Throwable $e) {
    error_log('[api/admin/save-color-palette.php] ' . $e->getMessage());
    $handBack(['save' => AdminTranslator::trans('palettes.error_save')]);
}

header('Location: /admin/color-palette.php?id=' . $id . '&done=' . $done);
exit;
