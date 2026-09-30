<?php

/**
 * POST /api/admin/save-font-family.php
 *
 * Creates a Font Library family (no id): its name, its kind (serif or sans,
 * the fallback while it loads), an optional source or licence link, and its
 * first font files, each with the variant the administrator picked
 * (font_files[] + variants[], index by index). Or saves an existing family's
 * own fields (id): renaming never touches a file or a page, because CSS
 * names a family by its id (App\Service\Theme\FontLibrary::cssFamilyName()).
 *
 * A new family is all or nothing: one refused file (not a font, too big, a
 * variant chosen twice) stores nothing, and the editor comes back with every
 * reason and what was typed. Validation is App\Service\Theme\FontLibrary and
 * App\Service\Theme\FontFileInspector. Same guards and flash pattern as
 * api/admin/save-color-palette.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Theme\FontLibrary;

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
if (array_key_exists('id', $_POST)) {
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || FontLibrary::family($id) === null) {
        http_response_code(404);
        exit('Font family not found.');
    }
}

$editor = '/admin/font-family.php' . ($id !== null ? '?id=' . $id : '');
$result = FontLibrary::validateFamily($_POST, $id);

$handBack = static function (array $errors) use ($editor): never {
    $old = [];
    foreach (['name', 'category', 'source_url'] as $field) {
        $old[$field] = is_scalar($_POST[$field] ?? null) ? trim((string) $_POST[$field]) : '';
    }

    $_SESSION['admin_font_errors'] = array_values($errors);
    $_SESSION['admin_font_old'] = $old;
    header('Location: ' . $editor);
    exit;
};

if ($result['errors'] !== []) {
    $handBack($result['errors']);
}

if ($id !== null) {
    try {
        FontLibrary::updateFamily($id, $result['values']);
    } catch (\Throwable $e) {
        error_log('[api/admin/save-font-family.php] ' . $e->getMessage());
        $handBack([AdminTranslator::trans('fonts.error_store')]);
    }

    header('Location: /admin/font-family.php?id=' . $id . '&done=saved');
    exit;
}

$created = FontLibrary::createFamily(
    $result['values'],
    FontLibrary::uploadsFromRequest($_FILES['font_files'] ?? null, $_POST['variants'] ?? null)
);

if ($created['id'] === null) {
    $handBack($created['errors']);
}

header('Location: /admin/font-family.php?id=' . $created['id'] . '&done=created');
exit;
