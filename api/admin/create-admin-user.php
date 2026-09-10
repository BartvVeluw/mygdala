<?php

/**
 * POST /api/admin/create-admin-user.php
 *
 * Creates a CMS user account from admin/user-form.php. Plain HTML form post,
 * PRG redirect back — same shape as create-collection.php.
 *
 * Guard order is the project's standard one plus authorisation: session
 * login, the users.manage permission, POST-only, CSRF, and only then any
 * write. Which fields this request is actually allowed to set (Super Admin,
 * restricted permissions) is decided by App\Service\AdminUserService against
 * the *signed-in* account, never against anything the browser sent, so a
 * hand-made POST cannot widen its own author's reach.
 *
 * On failure the submitted values are flashed back so nothing is retyped —
 * except the two password fields, which are deliberately never echoed back
 * into a page, a session or a log.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\AdminUserForbiddenException;
use App\Service\AdminUserService;
use App\Service\AdminUserValidationException;
use App\Service\Csrf;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('users.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

/**
 * Everything the form may safely re-render after a failed save. The password
 * fields are absent on purpose.
 *
 * @return array<string, mixed>
 */
function adminUserFlashableInput(array $input): array
{
    $fields = AdminUserService::normalizeInput($input);
    unset($fields['password'], $fields['password_confirmation']);

    return $fields;
}

try {
    (new AdminUserService())->create(AdminAuth::user() ?? [], $_POST);
} catch (AdminUserForbiddenException $e) {
    error_log('[api/admin/create-admin-user.php] ' . $e->getMessage());
    http_response_code(403);
    exit('Forbidden: missing permission.');
} catch (AdminUserValidationException $e) {
    $_SESSION['admin_user_errors'] = $e->getErrors();
    $_SESSION['admin_user_old'] = adminUserFlashableInput($_POST);
    header('Location: /admin/user-form.php');
    exit;
} catch (\Throwable $e) {
    error_log('[api/admin/create-admin-user.php] ' . $e->getMessage());
    $_SESSION['admin_user_errors'] = ['Gebruiker kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_user_old'] = adminUserFlashableInput($_POST);
    header('Location: /admin/user-form.php');
    exit;
}

header('Location: /admin/users.php?created=1');
exit;
