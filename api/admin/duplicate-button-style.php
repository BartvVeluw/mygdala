<?php

/**
 * POST /api/admin/duplicate-button-style.php
 *
 * Copies a button style: the same design, named "<name> (kopie)", never a
 * default and chosen by no button, so a duplicate never changes the website.
 * Lands in the copy's editor, to change it on its own. Same guards and PRG
 * pattern as api/admin/duplicate-color-palette.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id === false || ButtonStyles::find($id) === null) {
    http_response_code(404);
    exit('Button style not found.');
}

try {
    $copy = ButtonStyles::duplicate($id);
} catch (\Throwable $e) {
    error_log('[api/admin/duplicate-button-style.php] ' . $e->getMessage());
    $copy = null;
}

if ($copy === null) {
    $_SESSION['admin_buttons_error'] = AdminTranslator::trans('buttons.error_save');
    header('Location: /admin/theme.php?tab=knoppen#knoppen');
    exit;
}

header('Location: /admin/button-style.php?id=' . $copy . '&done=duplicated');
exit;
