<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

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
<title><?= admin_te('shop.carrier_tarieven_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('shop.carrier_tarieven') ?></h1>
  <p class="admin-text-muted"><?= admin_t('shop.centraal_beheerde_vervoerderstarieven_moment') ?></p>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
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
      <h2><?= admin_te('shop.resultaat_synchronisatie') ?></h2>
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
    <h2><?= admin_te('shop.postnl_synchronisatie') ?></h2>
    <p class="admin-text-muted">
      Laatste synchronisatie:
      <?php if ($lastRun === null): ?>
        <?= admin_te('shop.not_run_yet') ?>
      <?php else: ?>
        <?= admin_t('shop.sync_ran_at', [
            'v1' => formatDateTime($lastRun['ran_at']),
            'v2' => htmlspecialchars((string) $lastRun['status'], ENT_QUOTES, 'UTF-8'),
            'v3' => htmlspecialchars((string) ($lastRun['triggered_by'] ?? 'onbekend'), ENT_QUOTES, 'UTF-8'),
        ]) ?>
      <?php endif; ?>
    </p>
    <form method="post" action="/api/admin/sync-postnl-rates.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit"><?= admin_te('shop.postnl_tarieven_nu_bijwerken') ?></button>
    </form>
  </section>

  <?php if ($rates === null): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('shop.carrier_tarieven_konden_geladen') ?></p>
  <?php else: ?>
    <section class="admin-card">
      <h2><?= admin_te('shop.postnl') ?></h2>
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
                <?= $rate['is_active'] ? admin_t('common.active') : 'Uitgeschakeld' ?>
              </span>
              <?php if ($rate['needs_review']): ?>
                <span class="admin-badge admin-badge--pending">Controle vereist</span>
              <?php endif; ?>
            </div>

            <p class="admin-text-muted">
              <?= admin_t('shop.laatst_bijgewerkt_laatst_gecontroleerd', ['v1' => formatDateTime($rate['updated_at']), 'v2' => formatDateTime($rate['last_checked_at'])]) ?>
            </p>

            <?php if ($rate['pending_price'] !== null): ?>
              <p class="admin-alert admin-alert--<?= $rate['needs_review'] ? 'error' : 'success' ?>">
                <?= admin_t('shop.postnl_geeft_momenteel_gezien', ['v1' => htmlspecialchars(number_format($rate['pending_price'], 2, ',', '.'), ENT_QUOTES, 'UTF-8'), 'v2' => formatDateTime($rate['pending_detected_at'])]) ?>
                <form method="post" action="/api/admin/apply-carrier-rate-pending.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="carrier_rate_id" value="<?= (int) $rate['id'] ?>">
                  <button type="submit" class="admin-btn-text"><?= admin_te('shop.toepassen') ?></button>
                </form>
              </p>
            <?php endif; ?>

            <form method="post" action="/api/admin/update-carrier-rate.php" class="admin-inline-form admin-variant-panel__form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="carrier_rate_id" value="<?= (int) $rate['id'] ?>">
              <label><?= admin_te('shop.modus') ?>
                <select name="mode">
                  <option value="automatic" <?= $rate['mode'] === 'automatic' ? 'selected' : '' ?>><?= admin_te('shop.automatisch_postnl_sync') ?></option>
                  <option value="manual" <?= $rate['mode'] === 'manual' ? 'selected' : '' ?>><?= admin_te('shop.handmatig') ?></option>
                </select>
              </label>
              <label><?= admin_t('shop.prijs') ?>
                <input type="text" inputmode="decimal" name="price" value="<?= htmlspecialchars(number_format((float) $rate['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
              </label>
              <label class="admin-checkbox-label">
                <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $rate['is_active'] ? 'checked' : '' ?>>
                <?= admin_te('common.active') ?>
              </label>
              <button type="submit" class="admin-btn-text"><?= admin_te('common.save') ?></button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
