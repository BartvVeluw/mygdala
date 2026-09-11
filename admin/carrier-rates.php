<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\CarrierRateRepository;
use App\Repository\CarrierRateSyncRunRepository;
use App\Service\Shipping\PostNl\PostNlSyncResult;

AdminAuth::requireLogin();
AdminAuth::requirePermission('shipping.manage');

$errors = $_SESSION['admin_carrier_rates_errors'] ?? [];
unset($_SESSION['admin_carrier_rates_errors']);
$updated = isset($_GET['updated']);

$syncResult = null;
if (isset($_SESSION['admin_carrier_rates_sync_result'])) {
    $syncResult = PostNlSyncResult::fromArray($_SESSION['admin_carrier_rates_sync_result']);
    unset($_SESSION['admin_carrier_rates_sync_result']);
}

const PROVIDER = 'postnl';

try {
    $rates = (new CarrierRateRepository())->findAllForProvider(PROVIDER);
    $lastRun = (new CarrierRateSyncRunRepository())->findMostRecentForProvider(PROVIDER);
} catch (\Throwable $e) {
    error_log('[admin/carrier-rates.php] ' . $e->getMessage());
    $rates = null;
    $lastRun = null;
}

function formatDateTime(?string $value): string
{
    if ($value === null) {
        return '—';
    }
    $timestamp = strtotime($value);

    return $timestamp === false ? '—' : date('d-m-Y H:i', $timestamp);
}

$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Carrier-tarieven — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1>Carrier-tarieven</h1>
  <p class="admin-text-muted">Centraal beheerde vervoerderstarieven (op dit moment alleen PostNL). Een verzendtarief in <a href="/admin/shipping.php">Verzendinstellingen</a> kan naar een tarief hieronder verwijzen — wijzig je hier de prijs, dan geldt dat direct voor elk verzendtarief dat ernaar verwijst.</p>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($syncResult !== null): ?>
    <section class="admin-card">
      <h2>Resultaat synchronisatie</h2>
      <p class="admin-alert admin-alert--<?= $syncResult->success ? 'success' : 'error' ?>"><?= htmlspecialchars($syncResult->message, ENT_QUOTES, 'UTF-8') ?></p>

      <?php foreach (['Gewijzigd' => $syncResult->changed, 'Ongewijzigd' => $syncResult->unchanged, 'Handmatige modus (niet toegepast)' => $syncResult->pendingManual, 'Gemarkeerd voor controle' => $syncResult->flaggedForReview, 'Waarschuwingen' => $syncResult->warnings] as $heading => $lines): ?>
        <?php if ($lines !== []): ?>
          <h3><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h3>
          <ul>
            <?php foreach ($lines as $line): ?>
              <li><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <section class="admin-card">
    <h2>PostNL-synchronisatie</h2>
    <p class="admin-text-muted">
      Laatste synchronisatie:
      <?php if ($lastRun === null): ?>
        nog niet uitgevoerd.
      <?php else: ?>
        <?= formatDateTime($lastRun['ran_at']) ?>
        (<?= htmlspecialchars((string) $lastRun['status'], ENT_QUOTES, 'UTF-8') ?>,
        <?= htmlspecialchars((string) ($lastRun['triggered_by'] ?? 'onbekend'), ENT_QUOTES, 'UTF-8') ?>)
        — draait ook automatisch via een cronjob, zie MAIN.MD.
      <?php endif; ?>
    </p>
    <form method="post" action="/api/admin/sync-postnl-rates.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit">PostNL-tarieven nu bijwerken</button>
    </form>
  </section>

  <?php if ($rates === null): ?>
    <p class="admin-alert admin-alert--error">Carrier-tarieven konden niet worden geladen.</p>
  <?php else: ?>
    <section class="admin-card">
      <h2>PostNL</h2>
      <div class="admin-variant-list">
        <?php foreach ($rates as $rate): ?>
          <article class="admin-variant-panel">
            <div class="admin-variant-panel__head">
              <strong><?= htmlspecialchars((string) $rate['label'], ENT_QUOTES, 'UTF-8') ?></strong>
              <code><?= htmlspecialchars((string) $rate['rate_code'], ENT_QUOTES, 'UTF-8') ?></code>
              <span class="admin-badge admin-badge--<?= $rate['mode'] === 'automatic' ? 'paid' : 'muted' ?>">
                <?= $rate['mode'] === 'automatic' ? 'Automatisch' : 'Handmatig' ?>
              </span>
              <span class="admin-badge admin-badge--<?= $rate['is_active'] ? 'paid' : 'canceled' ?>">
                <?= $rate['is_active'] ? 'Actief' : 'Uitgeschakeld' ?>
              </span>
              <?php if ($rate['needs_review']): ?>
                <span class="admin-badge admin-badge--pending">Controle vereist</span>
              <?php endif; ?>
            </div>

            <p class="admin-text-muted">
              Laatst bijgewerkt: <?= formatDateTime($rate['updated_at']) ?> —
              laatst gecontroleerd: <?= formatDateTime($rate['last_checked_at']) ?>
            </p>

            <?php if ($rate['pending_price'] !== null): ?>
              <p class="admin-alert admin-alert--<?= $rate['needs_review'] ? 'error' : 'success' ?>">
                PostNL geeft momenteel <strong>€<?= htmlspecialchars(number_format($rate['pending_price'], 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?></strong>
                (gezien op <?= formatDateTime($rate['pending_detected_at']) ?>) — nog niet toegepast.
                <form method="post" action="/api/admin/apply-carrier-rate-pending.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="carrier_rate_id" value="<?= (int) $rate['id'] ?>">
                  <button type="submit" class="admin-btn-text">Toepassen</button>
                </form>
              </p>
            <?php endif; ?>

            <form method="post" action="/api/admin/update-carrier-rate.php" class="admin-inline-form admin-variant-panel__form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="carrier_rate_id" value="<?= (int) $rate['id'] ?>">
              <label>Modus
                <select name="mode">
                  <option value="automatic" <?= $rate['mode'] === 'automatic' ? 'selected' : '' ?>>Automatisch (PostNL-sync)</option>
                  <option value="manual" <?= $rate['mode'] === 'manual' ? 'selected' : '' ?>>Handmatig</option>
                </select>
              </label>
              <label>Prijs (&euro;)
                <input type="text" inputmode="decimal" name="price" value="<?= htmlspecialchars(number_format((float) $rate['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
              </label>
              <label class="admin-checkbox-label">
                <input type="checkbox" name="is_active" value="1" <?= $rate['is_active'] ? 'checked' : '' ?>>
                Actief
              </label>
              <button type="submit" class="admin-btn-text">Opslaan</button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
