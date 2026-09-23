<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Repository\AdminUserRepository;
use App\Service\AdminAuth;
use App\Service\AdminPermissions;
use App\Service\AdminUserService;
use App\Service\Csrf;

AdminAuth::requireLogin();
AdminAuth::requirePermission('users.manage');

/**
 * One screen for both creating and editing a CMS user — no ?id= means "new",
 * ?id=N means "edit", the same convention admin/collection.php and
 * admin/portfolio-item.php use.
 *
 * What this screen hides or disables (the Super Admin checkbox for a
 * non-Super-Admin, everything about your own access) is only the visible
 * half of the rule: App\Service\AdminUserService enforces exactly the same
 * restrictions on the submitted data, so a hand-made POST gets nowhere.
 * Nothing here can display an existing password — only replace it.
 */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== null && $id !== false && $id >= 1;

$repository = new AdminUserRepository();
$user = null;

if ($isEdit) {
    try {
        $user = $repository->findById($id);
    } catch (\Throwable $e) {
        error_log('[admin/user-form.php] ' . $e->getMessage());
        http_response_code(500);
        exit(admin_t('screen.gebruiker_kon_geladen'));
    }

    if ($user === null) {
        http_response_code(404);
        exit(admin_t('screen.gebruiker_gevonden'));
    }
}

$actorIsSuperAdmin = AdminAuth::isSuperAdmin();
$actorId = AdminAuth::userId();

// A users.manage holder manages colleagues, not the owner — same rule the
// service applies to the POST.
if ($user !== null && $user['is_super_admin'] === true && !$actorIsSuperAdmin) {
    AdminAuth::requireSuperAdmin();
}

$isSelf = $isEdit && $actorId !== null && $actorId === (int) $user['id'];

$errors = $_SESSION['admin_user_errors'] ?? [];
$old = $_SESSION['admin_user_old'] ?? null;
unset($_SESSION['admin_user_errors'], $_SESSION['admin_user_old']);

$updated = isset($_GET['updated']);

/**
 * Value precedence: freshly re-submitted (invalid) input, then the stored
 * account (edit), then a sane default — identical helper to
 * admin/collection.php's. Passwords are never part of this: they are not
 * echoed back, not even after a failed save.
 */
function adminUserFieldValue(?array $old, ?array $user, string $key, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    if ($user !== null && array_key_exists($key, $user)) {
        return (string) ($user[$key] ?? '');
    }

    return $default;
}

$isActiveChecked = $old !== null
    ? !empty($old['is_active'])
    : ($user !== null ? $user['is_active'] === true : true);

$isSuperAdminChecked = $old !== null
    ? !empty($old['is_super_admin'])
    : ($user !== null ? $user['is_super_admin'] === true : false);

$selectedPermissions = $old !== null
    ? AdminPermissions::sanitize($old['permissions'] ?? [])
    : ($user !== null ? $user['granted_permissions'] : []);

$csrfToken = Csrf::token();
$pageTitle = $isEdit ? (string) $user['name'] : admin_t('users.new');

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> <?= admin_te('users.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/users.php"><?= admin_t('users.terug_gebruikers') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('users.gebruiker_opgeslagen') ?></p>
  <?php endif; ?>
  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/<?= $isEdit ? 'update-admin-user.php' : 'create-admin-user.php' ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
    <?php endif; ?>

    <section class="admin-card">
      <h2><?= admin_te('users.account') ?></h2>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('common.name') ?>*
          <input type="text" name="name" maxlength="120" required value="<?= $h(adminUserFieldValue($old, $user, 'name')) ?>">
        </label>
        <label><?= admin_te('common.username') ?>*
          <input type="text" name="username" maxlength="60" required autocomplete="off" value="<?= $h(adminUserFieldValue($old, $user, 'username')) ?>">
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('common.email_address') ?>*
          <input type="email" name="email" maxlength="190" required autocomplete="off" value="<?= $h(adminUserFieldValue($old, $user, 'email')) ?>">
        </label>
        <p class="admin-text-muted"><?= admin_t('users.inloggen_gebruikersnaam_f_e') ?></p>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('common.password') ?></h2>
      <?php if ($isEdit): ?>
        <p class="admin-text-muted"><?= admin_te('users.laat_beide_velden_leeg') ?></p>
      <?php endif; ?>

      <div class="admin-form-row admin-form-row--split">
        <label><?= $isEdit ? admin_t('users.new_password') : 'Wachtwoord*' ?>
          <input type="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> minlength="<?= AdminUserService::PASSWORD_MIN_LENGTH ?>" maxlength="<?= AdminUserService::PASSWORD_MAX_LENGTH ?>">
        </label>
        <label><?= $isEdit ? admin_t('users.repeat_new_password') : 'Herhaal wachtwoord*' ?>
          <input type="password" name="password_confirmation" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> minlength="<?= AdminUserService::PASSWORD_MIN_LENGTH ?>" maxlength="<?= AdminUserService::PASSWORD_MAX_LENGTH ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('users.password_rule', ['min' => AdminUserService::PASSWORD_MIN_LENGTH, 'max' => AdminUserService::PASSWORD_MAX_LENGTH]) ?></p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('users.toegang') ?></h2>

      <?php if ($isSelf): ?>
        <p class="admin-alert admin-alert--error">
          <?= admin_te('users.eigen_account_hier_naam') ?>
        </p>
      <?php else: ?>
        <div class="admin-form-row">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActiveChecked ? 'checked' : '' ?>>
            <?= admin_te('users.actief_account_inloggen') ?>
          </label>
        </div>

        <?php if ($actorIsSuperAdmin): ?>
          <div class="admin-form-row">
            <label class="admin-checkbox-label">
              <input type="checkbox" class="admin-checkbox" name="is_super_admin" value="1" <?= $isSuperAdminChecked ? 'checked' : '' ?>>
              <?= admin_te('users.super_admin_alle_rechten') ?>
            </label>
            <p class="admin-text-muted"><?= admin_te('users.super_admin_heeft_automatisch') ?></p>
          </div>
        <?php endif; ?>

        <?php foreach (AdminPermissions::groupsForDisplay() as $group): ?>
          <?php
            $groupPermissions = $group['permissions'];
            // Only a Super Admin may hand out users.manage, so a
            // users.manage holder never even sees the checkbox.
            if (!$actorIsSuperAdmin) {
                $groupPermissions = array_filter(
                    $groupPermissions,
                    static fn (string $permission): bool => !in_array($permission, AdminPermissions::SUPER_ADMIN_GRANTABLE_ONLY, true),
                    ARRAY_FILTER_USE_KEY
                );
            }
          ?>
          <?php if ($groupPermissions === []): ?>
            <?php continue; ?>
          <?php endif; ?>
          <fieldset class="admin-permission-group">
            <legend><?= $h((string) $group['label']) ?></legend>
            <?php foreach ($groupPermissions as $permission => $meta): ?>
              <label class="admin-checkbox-label admin-permission-option">
                <input type="checkbox" class="admin-checkbox" name="permissions[]" value="<?= $h($permission) ?>" <?= in_array($permission, $selectedPermissions, true) ? 'checked' : '' ?>>
                <span>
                  <strong><?= $h((string) $meta['label']) ?></strong>
                  <span class="admin-text-muted"><?= $h((string) $meta['description']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </fieldset>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <div class="admin-form-row admin-form-actions">
      <button type="submit"><?= $isEdit ? 'Opslaan' : admin_t('users.create') ?></button>
      <a href="/admin/users.php"><?= admin_te('common.cancel') ?></a>
    </div>
  </form>
</main>
</body>
</html>
