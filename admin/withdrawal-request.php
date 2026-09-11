<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_labels.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\WithdrawalRequestRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === null || $id === false || $id < 1) {
    http_response_code(404);
    exit('Verzoek niet gevonden.');
}

$request = (new WithdrawalRequestRepository())->findByIdForAdmin($id);
if ($request === null) {
    http_response_code(404);
    exit('Verzoek niet gevonden.');
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
<title>Retourverzoek #<?= (int) $request['id'] ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/withdrawal-requests.php">&larr; Terug naar retourverzoeken</a></p>
  <h1>Retourverzoek voor bestelling #<?= (int) $request['order_id'] ?></h1>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
  <?php endif; ?>

  <section class="admin-card">
    <h2>Gegevens</h2>
    <table class="admin-table">
      <tbody>
        <tr><td>Bestelling</td><td><a href="/admin/order.php?id=<?= (int) $request['order_id'] ?>">#<?= (int) $request['order_id'] ?> bekijken &#8594;</a></td></tr>
        <tr><td>E-mailadres</td><td><?= $h((string) $request['customer_email']) ?></td></tr>
        <tr><td>Ontvangen op</td><td><?= $h(date('d-m-Y H:i', strtotime((string) $request['created_at']))) ?></td></tr>
        <tr><td>Ordertotaal</td><td>&euro; <?= $h(number_format((float) $request['total'], 2, ',', '.')) ?></td></tr>
        <tr><td>Betaalstatus bestelling</td><td><?= $h(adminPaymentStatusLabel((string) $request['order_status'])) ?></td></tr>
      </tbody>
    </table>

    <?php if (!empty($request['reason'])): ?>
      <h3 style="margin-top:var(--sp-3);">Toelichting van de klant</h3>
      <p style="white-space:pre-line;"><?= $h((string) $request['reason']) ?></p>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2>Beoordeling</h2>
    <p class="admin-text-muted">Controleer zelf in de bestelling of (een deel van) de producten gepersonaliseerd/op maat gemaakt was — dit wordt niet automatisch bepaald (zie MAIN.MD).</p>
    <?php if (!AdminAuth::can('orders.manage')): ?>
      <p class="admin-text-muted">Je hebt alleen leesrechten voor bestellingen; een retourverzoek beoordelen vereist het recht &ldquo;Bestellingen beheren&rdquo;.</p>
    <?php else: ?>
    <form method="post" action="/api/admin/update-withdrawal-request-status.php" class="admin-form-row">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
      <label>Status
        <select name="status">
          <?php foreach (\App\Repository\WithdrawalRequestRepository::STATUSES as $statusValue): ?>
            <option value="<?= $h($statusValue) ?>" <?= $request['status'] === $statusValue ? 'selected' : '' ?>><?= $h(adminWithdrawalStatusLabel($statusValue)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Interne notitie (optioneel, niet zichtbaar voor de klant)
        <textarea name="admin_note" rows="4"><?= $h((string) ($request['admin_note'] ?? '')) ?></textarea>
      </label>
      <button type="submit">Opslaan</button>
    </form>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
