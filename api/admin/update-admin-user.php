<?php

/**
 * POST /api/admin/update-admin-user.php
 *
 * Saves an existing CMS user account from admin/user-form.php: name, login
 * details, active state, Super Admin status, permissions, and optionally a
 * new password. Leaving the password fields empty keeps the current one —
 * editing a name must never force a password change.
 *
 * Same guard order as every other admin mutation (login, permission,
 * POST-only, CSRF, server-side id validation) before anything is written.
 * The safeguards that make this endpoint safe to expose — only a Super Admin
 * edits a Super Admin, nobody edits their own access, the last active Super
 * Admin cannot be deactivated or demoted, unknown permission names are
 * dropped — all live in App\Service\AdminUserService and are applied to the
 * *signed-in* account, not to anything the browser claims about itself.
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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid user id.');
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
    (new AdminUserService())->update(AdminAuth::user() ?? [], $id, $_POST);
} catch (AdminUserForbiddenException $e) {
    error_log('[api/admin/update-admin-user.php] ' . $e->getMessage());
    http_response_code(403);
    exit('Forbidden: missing permission.');
} catch (AdminUserValidationException $e) {
    $_SESSION['admin_user_errors'] = $e->getErrors();
    $_SESSION['admin_user_old'] = adminUserFlashableInput($_POST);
    header('Location: /admin/user-form.php?id=' . $id);
    exit;
} catch (\Throwable $e) {
    error_log('[api/admin/update-admin-user.php] ' . $e->getMessage());
    $_SESSION['admin_user_errors'] = ['Gebruiker kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_user_old'] = adminUserFlashableInput($_POST);
    header('Location: /admin/user-form.php?id=' . $id);
    exit;
}

header('Location: /admin/users.php?updated=1');
exit;
