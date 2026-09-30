<?php

/**
 * POST /api/admin/set-default-button-style.php
 *
 * Makes a button style a default: `role=primary` the website's standard
 * button (every .btn without a choice of its own, content and functional),
 * `role=secondary` the second button (.btn--ghost). Changes the website at
 * once; the list asked first. A role is a word from the closed list and the
 * style must exist. Same guards and PRG pattern as
 * api/admin/activate-color-palette.php.
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

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';

if (!in_array($role, ButtonStyleRepository::ROLES, true)) {
    http_response_code(400);
    exit('Unknown button role.');
}

if ($id === false || ButtonStyles::find($id) === null) {
    http_response_code(404);
    exit('Button style not found.');
}

try {
    $done = ButtonStyles::setDefault($role, $id);
} catch (\Throwable $e) {
    error_log('[api/admin/set-default-button-style.php] ' . $e->getMessage());
    $done = false;
}

if (!$done) {
    $_SESSION['admin_buttons_error'] = AdminTranslator::trans('buttons.error_default');
    header('Location: /admin/theme.php?tab=knoppen#knoppen');
    exit;
}

header('Location: /admin/theme.php?tab=knoppen&buttons=default#knoppen');
exit;
