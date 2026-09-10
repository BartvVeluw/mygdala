<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
        exit('Gebruiker kon niet worden geladen.');
    }

    if ($user === null) {
        http_response_code(404);
        exit('Gebruiker niet gevonden.');
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
$pageTitle = $isEdit ? (string) $user['name'] : 'Nieuwe gebruiker';

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/users.php">&larr; Terug naar gebruikers</a></p>
  <h1><?= $h($pageTitle) ?></h1>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Gebruiker opgeslagen.</p>
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
      <h2>Account</h2>

      <div class="admin-form-row admin-form-row--split">
        <label>Naam*
          <input type="text" name="name" maxlength="120" required value="<?= $h(adminUserFieldValue($old, $user, 'name')) ?>">
        </label>
        <label>Gebruikersnaam*
          <input type="text" name="username" maxlength="60" required autocomplete="off" value="<?= $h(adminUserFieldValue($old, $user, 'username')) ?>">
        </label>
      </div>

      <div class="admin-form-row">
        <label>E-mailadres*
          <input type="email" name="email" maxlength="190" required autocomplete="off" value="<?= $h(adminUserFieldValue($old, $user, 'email')) ?>">
        </label>
        <p class="admin-text-muted">Inloggen kan met de gebruikersnaam &oacute;f het e-mailadres.</p>
      </div>
    </section>

    <section class="admin-card">
      <h2>Wachtwoord</h2>
      <?php if ($isEdit): ?>
        <p class="admin-text-muted">Laat beide velden leeg om het huidige wachtwoord ongewijzigd te laten. Een bestaand wachtwoord kan nooit worden getoond, alleen vervangen.</p>
      <?php endif; ?>

      <div class="admin-form-row admin-form-row--split">
        <label><?= $isEdit ? 'Nieuw wachtwoord' : 'Wachtwoord*' ?>
          <input type="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> minlength="<?= AdminUserService::PASSWORD_MIN_LENGTH ?>" maxlength="<?= AdminUserService::PASSWORD_MAX_LENGTH ?>">
        </label>
        <label><?= $isEdit ? 'Herhaal nieuw wachtwoord' : 'Herhaal wachtwoord*' ?>
          <input type="password" name="password_confirmation" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> minlength="<?= AdminUserService::PASSWORD_MIN_LENGTH ?>" maxlength="<?= AdminUserService::PASSWORD_MAX_LENGTH ?>">
        </label>
      </div>
      <p class="admin-text-muted">Minimaal <?= AdminUserService::PASSWORD_MIN_LENGTH ?> tekens, maximaal <?= AdminUserService::PASSWORD_MAX_LENGTH ?>, en niet gelijk aan de gebruikersnaam of het e-mailadres.</p>
    </section>

    <section class="admin-card">
      <h2>Toegang</h2>

      <?php if ($isSelf): ?>
        <p class="admin-alert admin-alert--error">
          Dit is je eigen account. Je kunt hier je naam, e-mailadres en wachtwoord wijzigen, maar niet je eigen
          rechten, je eigen Super Admin-status of je eigen actief/inactief-status — dat voorkomt dat iemand
          zichzelf meer rechten geeft of zichzelf buitensluit. Laat een andere Super Admin dat doen.
        </p>
      <?php else: ?>
        <div class="admin-form-row">
          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= $isActiveChecked ? 'checked' : '' ?>>
            Actief (dit account kan inloggen)
          </label>
        </div>

        <?php if ($actorIsSuperAdmin): ?>
          <div class="admin-form-row">
            <label class="admin-checkbox-label">
              <input type="checkbox" name="is_super_admin" value="1" <?= $isSuperAdminChecked ? 'checked' : '' ?>>
              Super Admin (alle rechten, nu en in de toekomst)
            </label>
            <p class="admin-text-muted">Een Super Admin heeft automatisch elk recht hieronder; de losse vinkjes worden dan niet gebruikt.</p>
          </div>
        <?php endif; ?>

        <?php foreach (AdminPermissions::groups() as $group): ?>
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
                <input type="checkbox" name="permissions[]" value="<?= $h($permission) ?>" <?= in_array($permission, $selectedPermissions, true) ? 'checked' : '' ?>>
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
      <button type="submit"><?= $isEdit ? 'Opslaan' : 'Gebruiker aanmaken' ?></button>
      <a href="/admin/users.php">Annuleren</a>
    </div>
  </form>
</main>
</body>
</html>
