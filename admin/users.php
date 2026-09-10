<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gebruikers — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1>Gebruikers</h1>
    <a href="/admin/user-form.php" class="admin-btn-link">+ Nieuwe gebruiker</a>
  </div>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Gebruiker aangemaakt.</p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Gebruiker opgeslagen.</p>
  <?php endif; ?>

  <?php if ($users === null): ?>
    <p class="admin-alert admin-alert--error">Gebruikers konden niet worden geladen.</p>
  <?php elseif ($users === []): ?>
    <p>Nog geen CMS-gebruikers. <a href="/admin/user-form.php">Maak de eerste aan</a>.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Naam</th>
          <th>Gebruikersnaam</th>
          <th>E-mail</th>
          <th>Status</th>
          <th>Toegang</th>
          <th>Laatst ingelogd</th>
          <th>Actie</th>
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
                <?= $user['is_active'] === true ? 'Actief' : 'Gedeactiveerd' ?>
              </span>
            </td>
            <td>
              <?php if ($user['is_super_admin'] === true): ?>
                <span class="admin-badge admin-badge--paid">Super Admin</span>
              <?php elseif ($accessLabels === []): ?>
                <span class="admin-text-muted">Geen rechten</span>
              <?php else: ?>
                <?= $h(implode(', ', $accessLabels)) ?>
              <?php endif; ?>
            </td>
            <td><?= $lastLogin !== null ? $h($lastLogin) : '<span class="admin-text-muted">Nooit</span>' ?></td>
            <td>
              <?php if ($mayEdit): ?>
                <a href="/admin/user-form.php?id=<?= $userId ?>">Bewerken</a>
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
    <h2>Hoe rechten werken</h2>
    <p class="admin-text-muted">
      Een <strong>Super Admin</strong> heeft automatisch alle rechten en kan als enige andere Super Admins
      aanmaken of wijzigen, en als enige het recht &ldquo;Gebruikers beheren&rdquo; toekennen. Alle andere
      accounts krijgen losse rechten per onderdeel. Niemand kan zijn eigen rechten, Super Admin-status of
      actief/inactief-status wijzigen, en de laatste actieve Super Admin kan niet worden gedeactiveerd.
    </p>
    <p class="admin-text-muted">
      Rechten gelden serverzijdig: een onderdeel waar iemand geen recht op heeft, verdwijnt niet alleen uit
      het menu maar weigert ook een directe URL of een handmatig verstuurd formulier.
    </p>
  </div>
</main>
</body>
</html>
