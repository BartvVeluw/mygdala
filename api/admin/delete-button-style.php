<?php

/**
 * POST /api/admin/delete-button-style.php
 *
 * Deletes a button style nobody uses. A default is refused with a message to
 * make another style the default first; a style content buttons chose is
 * refused with how many there are and what to do. The foreign keys refuse
 * both as well (App\Repository\ButtonStyleRepository::delete()), so a choice
 * made between the check and the delete never leaves a button pointing at
 * nothing. Same guards and PRG pattern as api/admin/delete-color-palette.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
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
    $result = ButtonStyles::delete($id);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-button-style.php] ' . $e->getMessage());
    $result = null;
}

if ($result === ButtonStyles::DELETE_DELETED) {
    header('Location: /admin/theme.php?tab=knoppen&buttons=deleted#knoppen');
    exit;
}

$_SESSION['admin_buttons_error'] = ButtonStyles::refusal((string) $result, $id);
header('Location: /admin/theme.php?tab=knoppen#knoppen');
exit;
