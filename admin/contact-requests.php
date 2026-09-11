<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_labels.php';

use App\Service\AdminAuth;
use App\Repository\ContactRequestRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('contact.manage');

$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ContactRequestRepository::STATUSES, true)) {
    $statusFilter = 'all';
}

try {
    $requests = (new ContactRequestRepository())->findAllForAdmin($statusFilter === 'all' ? null : $statusFilter);
} catch (\Throwable $e) {
    error_log('[admin/contact-requests.php] ' . $e->getMessage());
    $requests = null;
}

$deleted = isset($_GET['deleted']);

$filters = [
    'all' => 'Alle',
    'nieuw' => 'Nieuw',
    'gelezen' => 'Gelezen',
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contactaanvragen — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1>Contactaanvragen</h1>

  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success">Aanvraag verwijderd.</p>
  <?php endif; ?>

  <div class="admin-filter-tabs" role="tablist" aria-label="Filter op status">
    <?php foreach ($filters as $value => $label): ?>
      <a href="/admin/contact-requests.php?status=<?= urlencode($value) ?>" class="admin-filter-tab<?= $statusFilter === $value ? ' is-active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($requests === null): ?>
    <p class="admin-alert admin-alert--error">Aanvragen konden niet worden geladen.</p>
  <?php elseif ($requests === []): ?>
    <p>Geen aanvragen gevonden.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Naam</th>
          <th>E-mail</th>
          <th>Voor wie</th>
          <th>Ontvangen</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $request): ?>
          <?php $isNew = $request['status'] === 'nieuw'; ?>
          <tr class="<?= $isNew ? 'admin-row--unread' : '' ?>">
            <td><a href="/admin/contact-request.php?id=<?= (int) $request['id'] ?>"><?= htmlspecialchars((string) $request['name'], ENT_QUOTES, 'UTF-8') ?></a></td>
            <td><?= htmlspecialchars((string) $request['email'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(adminContactAudienceLabel((string) $request['audience']), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $request['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="admin-badge admin-badge--<?= $isNew ? 'info' : 'muted' ?>"><?= htmlspecialchars(adminContactStatusLabel((string) $request['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
