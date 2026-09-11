<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_labels.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\OrderRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

/**
 * `handling` (not `status`) because an order has two independent statuses:
 * Mollie's payment status and the owner's own handling status — see MAIN.MD
 * "Afhandelingsstatus". Filtering happens server-side in the repository;
 * anything unrecognised falls back to showing everything.
 */
$handlingFilter = $_GET['handling'] ?? 'all';
if (!in_array($handlingFilter, OrderRepository::FULFILMENT_STATUSES, true)) {
    $handlingFilter = 'all';
}

try {
    $orders = (new OrderRepository())->findAllForAdmin($handlingFilter === 'all' ? null : $handlingFilter);
} catch (\Throwable $e) {
    error_log('[admin/orders.php] ' . $e->getMessage());
    $orders = null;
}

$updated = isset($_GET['updated']);
$csrfToken = Csrf::token();

// orders.view opens this list read-only; changing the handling status needs
// orders.manage (enforced again in api/admin/update-fulfilment-status.php).
$canManageOrders = AdminAuth::can('orders.manage');

$filters = [
    'all' => 'Alle',
    OrderRepository::FULFILMENT_OPEN => 'Open',
    OrderRepository::FULFILMENT_HANDLED => 'Afgehandeld',
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('shop.bestellingen_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('shop.bestellingen') ?></h1>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('shop.afhandelingsstatus_bijgewerkt') ?></p>
  <?php endif; ?>

  <div class="admin-filter-tabs" role="tablist" aria-label="Filter op afhandeling">
    <?php foreach ($filters as $value => $label): ?>
      <a href="/admin/orders.php?handling=<?= urlencode((string) $value) ?>" class="admin-filter-tab<?= $handlingFilter === $value ? ' is-active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="/admin/orders-export.php" class="admin-export-form">
    <label><?= admin_te('shop.text') ?> <input type="date" name="from"></label>
    <label><?= admin_te('shop.t_m') ?> <input type="date" name="to"></label>
    <button type="submit"><?= admin_te('shop.exporteer_csv') ?></button>
  </form>

  <?php if ($orders === null): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('shop.bestellingen_konden_geladen') ?></p>
  <?php elseif ($orders === []): ?>
    <p><?= admin_te('shop.bestellingen_gevonden') ?></p>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th><?= admin_te('shop.order') ?></th>
          <th><?= admin_te('common.date') ?></th>
          <th><?= admin_te('shop.klant') ?></th>
          <th><?= admin_te('shop.betaalstatus') ?></th>
          <th><?= admin_te('shop.afhandeling') ?></th>
          <th><?= admin_te('shop.verzendmethode') ?></th>
          <th><?= admin_te('shop.totaal') ?></th>
          <th><?= admin_te('common.action') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $order): ?>
          <?php
            $orderNumber = OrderRepository::formatOrderNumber((int) $order['id'], new \DateTimeImmutable((string) $order['created_at']));
            $fulfilmentStatus = (string) $order['fulfilment_status'];
            $isHandled = $fulfilmentStatus === OrderRepository::FULFILMENT_HANDLED;
            $isPaid = $order['status'] === 'paid';
          ?>
          <tr class="<?= $isHandled ? 'admin-row--handled' : '' ?>">
            <td><a href="/admin/order.php?id=<?= (int) $order['id'] ?>"><?= htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') ?></a></td>
            <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $order['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $order['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="admin-badge admin-badge--<?= htmlspecialchars((string) $order['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(adminPaymentStatusLabel((string) $order['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><span class="admin-badge admin-badge--<?= adminFulfilmentBadgeModifier($fulfilmentStatus) ?>"><?= htmlspecialchars($fulfilmentStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><?= htmlspecialchars(adminShippingMethodLabel($order['shipping_method'] ?? null, (string) $order['shipping_cost']), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= admin_t('shop.amount_with', ['v1' => htmlspecialchars(number_format((float) $order['total'], 2, ',', '.'), ENT_QUOTES, 'UTF-8')]) ?></td>
            <td>
              <?php if ($canManageOrders && ($isHandled || $isPaid)): ?>
                <?php /* Quick action: same POST + CSRF + server-side validation as the detail page, so no status ever changes through a GET link. */ ?>
                <form method="post" action="/api/admin/update-fulfilment-status.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <input type="hidden" name="fulfilment_status" value="<?= htmlspecialchars($isHandled ? OrderRepository::FULFILMENT_OPEN : OrderRepository::FULFILMENT_HANDLED, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="return_to" value="orders">
                  <input type="hidden" name="handling" value="<?= htmlspecialchars($handlingFilter, ENT_QUOTES, 'UTF-8') ?>">
                  <button type="submit" class="admin-btn-text"><?= $isHandled ? 'Heropen' : 'Afhandelen' ?></button>
                </form>
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
</main>
</body>
</html>
