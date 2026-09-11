<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_labels.php';

use App\Service\AdminAuth;
use App\Repository\WithdrawalRequestRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, WithdrawalRequestRepository::STATUSES, true)) {
    $statusFilter = 'all';
}

try {
    $requests = (new WithdrawalRequestRepository())->findAllForAdmin($statusFilter === 'all' ? null : $statusFilter);
} catch (\Throwable $e) {
    error_log('[admin/withdrawal-requests.php] ' . $e->getMessage());
    $requests = null;
}

$filters = [
    'all' => 'Alle',
    'nieuw' => admin_t('common.new_item'),
    'in_behandeling' => 'In behandeling',
    'afgehandeld' => 'Afgehandeld',
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('shop.retourverzoeken_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('shop.retourverzoeken_herroepingsrecht') ?></h1>
  <p class="admin-page-head__desc"><?= admin_te('shop.bekijk_zelf_per_verzoek') ?></p>

  <div class="admin-filter-tabs" role="tablist" aria-label="Filter op status">
    <?php foreach ($filters as $value => $label): ?>
      <a href="/admin/withdrawal-requests.php?status=<?= urlencode($value) ?>" class="admin-filter-tab<?= $statusFilter === $value ? ' is-active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($requests === null): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('shop.verzoeken_konden_geladen') ?></p>
  <?php elseif ($requests === []): ?>
    <p><?= admin_te('shop.retourverzoeken_gevonden') ?></p>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th><?= admin_te('shop.bestelling') ?></th>
          <th><?= admin_te('common.email') ?></th>
          <th><?= admin_te('shop.ontvangen') ?></th>
          <th><?= admin_te('common.status') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $request): ?>
          <?php $isNew = $request['status'] === 'nieuw'; ?>
          <tr class="<?= $isNew ? 'admin-row--unread' : '' ?>">
            <td><a href="/admin/withdrawal-request.php?id=<?= (int) $request['id'] ?>">#<?= (int) $request['order_id'] ?></a></td>
            <td><?= htmlspecialchars((string) $request['customer_email'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $request['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="admin-badge admin-badge--<?= $isNew ? 'info' : 'muted' ?>"><?= htmlspecialchars(adminWithdrawalStatusLabel((string) $request['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
