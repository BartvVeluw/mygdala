<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Repository\AdminUserRepository;
use App\Service\AdminAuth;
use App\Service\AdminPermissions;

AdminAuth::requireLogin();
AdminAuth::requirePermission('users.manage');

/**
 * Overview of every CMS account: who they are, whether they can still log in
 * and what they are allowed to do. Editing happens on admin/user-form.php,
 * the same "overview + form screen" split the products and collections
 * sections use.
 *
 * There is no delete action on purpose — deactivating an account is the
 * supported way to revoke access, so an account stays resolvable for
 * anything that later records who changed what. See MAIN.MD.
 */

try {
    $users = (new AdminUserRepository())->findAll();
} catch (\Throwable $e) {
    error_log('[admin/users.php] ' . $e->getMessage());
    $users = null;
}

$currentUserId = AdminAuth::userId();
$isSuperAdmin = AdminAuth::isSuperAdmin();

$created = isset($_GET['created']);
$updated = isset($_GET['updated']);

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('users.gebruikers_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1><?= admin_te('users.gebruikers') ?></h1>
    <a href="/admin/user-form.php" class="admin-btn-link"><?= admin_te('users.nieuwe_gebruiker') ?></a>
  </div>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('users.gebruiker_aangemaakt') ?></p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('users.gebruiker_opgeslagen') ?></p>
  <?php endif; ?>

  <?php if ($users === null): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('users.gebruikers_konden_geladen') ?></p>
  <?php elseif ($users === []): ?>
    <p><?= admin_t('users.cms_gebruikers_maak_eerste') ?></p>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th><?= admin_te('common.name') ?></th>
          <th><?= admin_te('common.username') ?></th>
          <th><?= admin_te('common.email') ?></th>
          <th><?= admin_te('common.status') ?></th>
          <th><?= admin_te('users.toegang') ?></th>
          <th><?= admin_te('users.laatst_ingelogd') ?></th>
          <th><?= admin_te('common.action') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <?php
            $userId = (int) $user['id'];
            $isSelf = $currentUserId !== null && $currentUserId === $userId;
            // A users.manage holder may not open a Super Admin's account.
            $mayEdit = $isSuperAdmin || $user['is_super_admin'] !== true;
            $accessLabels = $user['is_super_admin'] === true
                ? []
                : AdminPermissions::summarize($user['granted_permissions']);
            $lastLogin = $user['last_login_at'] !== null
                ? date('d-m-Y H:i', strtotime((string) $user['last_login_at']))
                : null;
          ?>
          <tr>
            <td>
              <?= $h((string) $user['name']) ?>
              <?php if ($isSelf): ?><span class="admin-text-muted"> (jij)</span><?php endif; ?>
            </td>
            <td><?= $h((string) $user['username']) ?></td>
            <td><?= $user['email'] !== null ? $h((string) $user['email']) : '<span class="admin-text-muted">&mdash;</span>' ?></td>
            <td>
              <span class="admin-badge admin-badge--<?= $user['is_active'] === true ? 'paid' : 'canceled' ?>">
                <?= $user['is_active'] === true ? admin_t('common.active') : 'Gedeactiveerd' ?>
              </span>
            </td>
            <td>
              <?php if ($user['is_super_admin'] === true): ?>
                <span class="admin-badge admin-badge--paid">Super Admin</span>
              <?php elseif ($accessLabels === []): ?>
                <span class="admin-text-muted"><?= admin_te('users.no_permissions') ?></span>
              <?php else: ?>
                <?= $h(implode(', ', $accessLabels)) ?>
              <?php endif; ?>
            </td>
            <td><?= $lastLogin !== null ? $h($lastLogin) : '<span class="admin-text-muted">Nooit</span>' ?></td>
            <td>
              <?php if ($mayEdit): ?>
                <a href="/admin/user-form.php?id=<?= $userId ?>"><?= admin_te('common.edit') ?></a>
              <?php else: ?>
                <span class="admin-text-muted">&mdash;</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <div class="admin-card">
    <h2><?= admin_te('users.hoe_rechten_werken') ?></h2>
    <p class="admin-text-muted">
      <?= admin_te('users.super_admin_heeft_automatisch') ?>
    </p>
    <p class="admin-text-muted">
      <?= admin_te('users.rechten_gelden_serverzijdig_onderdeel') ?>
    </p>
  </div>
</main>
</body>
</html>
