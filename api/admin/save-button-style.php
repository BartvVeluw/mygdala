<?php

/**
 * POST /api/admin/save-button-style.php
 *
 * Creates a button style (no id) or saves an existing one (id), from
 * admin/button-style.php. Validation is App\Service\Theme\ButtonStyles::
 * validate(): every choice from its closed list, a colour a theme colour
 * word or a #RRGGBB, a required and unique name — nothing that could become
 * CSS, HTML or a class name of its own.
 *
 * Saving never makes a style a default (api/admin/set-default-button-style.php
 * does that). Saving a style that is a default, or that content buttons
 * chose, changes those buttons at once; the editor said so before the save.
 * A refused save stores nothing and goes back to the editor with the errors
 * and what was typed. Same guards and flash pattern as
 * api/admin/save-color-palette.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\ButtonStyleRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Theme\ButtonStyles;

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

    if ($id === false || ButtonStyles::find($id) === null) {
        http_response_code(404);
        exit('Button style not found.');
    }
}

$editor = '/admin/button-style.php' . ($id !== null ? '?id=' . $id : '');
$result = ButtonStyles::validate($_POST, $id);

$handBack = static function (array $errors) use ($id, $editor): never {
    $old = ['id' => $id ?? 0];
    foreach (['name', ...ButtonStyleRepository::COLUMNS] as $field) {
        $old[$field] = is_scalar($_POST[$field] ?? null) ? trim((string) $_POST[$field]) : '';
        if (is_scalar($_POST[$field . '_custom'] ?? null)) {
            $old[$field . '_custom'] = trim((string) $_POST[$field . '_custom']);
        }
    }

    $_SESSION['admin_button_style_errors'] = $errors;
    $_SESSION['admin_button_style_old'] = $old;
    header('Location: ' . $editor);
    exit;
};

if ($result['errors'] !== []) {
    $handBack($result['errors']);
}

try {
    if ($id === null) {
        $id = ButtonStyles::create($result['values']);
        $done = 'created';
    } else {
        ButtonStyles::update($id, $result['values']);
        $done = 'saved';
    }
} catch (\Throwable $e) {
    error_log('[api/admin/save-button-style.php] ' . $e->getMessage());
    $handBack(['save' => AdminTranslator::trans('buttons.error_save')]);
}

header('Location: /admin/button-style.php?id=' . $id . '&done=' . $done);
exit;
