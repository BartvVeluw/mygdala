<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_labels.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\WithdrawalRequestRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === null || $id === false || $id < 1) {
    http_response_code(404);
    exit(admin_t('screen.verzoek_gevonden'));
}

$request = (new WithdrawalRequestRepository())->findByIdForAdmin($id);
if ($request === null) {
    http_response_code(404);
    exit(admin_t('screen.verzoek_gevonden'));
}

$updated = isset($_GET['updated']);
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('shop.retourverzoek_admin', ['v1' => (int) $request['id']]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/withdrawal-requests.php"><?= admin_t('shop.terug_retourverzoeken') ?></a></p>
  <h1><?= admin_t('shop.retourverzoek_bestelling', ['v1' => (int) $request['order_id']]) ?></h1>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= admin_te('shop.gegevens') ?></h2>
    <table class="admin-table">
      <tbody>
        <tr><td><?= admin_te('shop.bestelling') ?></td><td><a href="/admin/order.php?id=<?= (int) $request['order_id'] ?>"><?= admin_t('shop.bekijken', ['v1' => (int) $request['order_id']]) ?></a></td></tr>
        <tr><td><?= admin_te('common.email_address') ?></td><td><?= $h((string) $request['customer_email']) ?></td></tr>
        <tr><td><?= admin_te('shop.ontvangen') ?></td><td><?= $h(date('d-m-Y H:i', strtotime((string) $request['created_at']))) ?></td></tr>
        <tr><td><?= admin_te('shop.ordertotaal') ?></td><td><?= admin_t('shop.amount_with', ['v1' => $h(number_format((float) $request['total'], 2, ',', '.'))]) ?></td></tr>
        <tr><td><?= admin_te('shop.betaalstatus_bestelling') ?></td><td><?= $h(adminPaymentStatusLabel((string) $request['order_status'])) ?></td></tr>
      </tbody>
    </table>

    <?php if (!empty($request['reason'])): ?>
      <h3 style="margin-top:var(--sp-3);"><?= admin_te('shop.toelichting_klant') ?></h3>
      <p style="white-space:pre-line;"><?= $h((string) $request['reason']) ?></p>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('shop.beoordeling') ?></h2>
    <p class="admin-text-muted"><?= admin_te('shop.controleer_zelf_bestelling_deel') ?></p>
    <?php if (!AdminAuth::can('orders.manage')): ?>
      <p class="admin-text-muted"><?= admin_t('shop.hebt_alleen_leesrechten_bestellingen') ?></p>
    <?php else: ?>
    <form method="post" action="/api/admin/update-withdrawal-request-status.php" class="admin-form-row">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
      <label><?= admin_te('common.status') ?>
        <select name="status">
          <?php foreach (\App\Repository\WithdrawalRequestRepository::STATUSES as $statusValue): ?>
            <option value="<?= $h($statusValue) ?>" <?= $request['status'] === $statusValue ? 'selected' : '' ?>><?= $h(adminWithdrawalStatusLabel($statusValue)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= admin_te('shop.interne_notitie_optioneel_zichtbaar') ?>
        <textarea name="admin_note" rows="4"><?= $h((string) ($request['admin_note'] ?? '')) ?></textarea>
      </label>
      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
