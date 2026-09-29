<?php

/**
 * POST /api/admin/save-page-theme.php
 *
 * Creates a page theme (no id) or saves an existing one (id): its name, the
 * five colours and the font pairing, from admin/page-theme.php. Validation is
 * App\Service\PageThemes\PageThemeService::validate() — the site theme's own
 * colour and font rules, a required unique name, and a slug made from the
 * name. A refused save stores nothing and goes back to the editor with the
 * errors and what was typed; a saved one goes to the overview (PRG). Same
 * guards and flash pattern as api/admin/update-theme-settings.php.
 *
 * page_themes.manage is held by nobody while the module is off, so this
 * refuses then without a module check of its own (MODULES.md, "Guards").
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\PageThemes\PageThemeService;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('page_themes.manage');

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
if (array_key_exists('id', $_POST)) {
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id === false || PageThemeService::theme($id) === null) {
        http_response_code(404);
        exit('Page theme not found.');
    }
}

$editor = '/admin/page-theme.php' . ($id !== null ? '?id=' . $id : '');
$result = PageThemeService::validate($_POST, $id);

$handBack = static function (array $errors) use ($id, $editor): never {
    $old = ['id' => $id ?? 0];
    foreach (['name', ...PageThemeService::VALUE_FIELDS] as $field) {
        $old[$field] = is_scalar($_POST[$field] ?? null) ? trim((string) $_POST[$field]) : '';
    }

    $_SESSION['admin_page_theme_errors'] = $errors;
    $_SESSION['admin_page_theme_old'] = $old;
    header('Location: ' . $editor);
    exit;
};

if ($result['errors'] !== []) {
    $handBack($result['errors']);
}

try {
    if ($id === null) {
        PageThemeService::create($result['values']);
    } else {
        PageThemeService::update($id, $result['values']);
    }
} catch (\Throwable $e) {
    error_log('[api/admin/save-page-theme.php] ' . $e->getMessage());
    $handBack(['save' => AdminTranslator::trans('pagethemes.error_save')]);
}

header('Location: /admin/page-themes.php?done=' . ($id === null ? 'created' : 'saved'));
exit;
