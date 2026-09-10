<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Shipping\ShippingProfile;
use App\Repository\CarrierRateRepository;
use App\Repository\ShippingZoneRepository;
use App\Repository\ShippingRateRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('shipping.manage');

$errors = $_SESSION['admin_shipping_errors'] ?? [];
unset($_SESSION['admin_shipping_errors']);
$updated = isset($_GET['updated']);

try {
    $rateRepository = new ShippingRateRepository();
    $zones = (new ShippingZoneRepository())->findAllWithCountries();
    foreach ($zones as &$zone) {
        $zone['rates'] = $rateRepository->findAllForZone((int) $zone['id']);
    }
    unset($zone);
    // Only active carrier rates are offered — see admin/carrier-rates.php,
    // where the eigenaar manages the PostNL-synced prices themselves.
    $carrierRates = (new CarrierRateRepository())->findActiveForProvider('postnl');
} catch (\Throwable $e) {
    error_log('[admin/shipping.php] ' . $e->getMessage());
    $zones = null;
    $carrierRates = [];
}

$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verzendinstellingen — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1>Verzendinstellingen</h1>
  <p class="admin-text-muted">Verzendkosten worden berekend op basis van bestemmingsland (zone), totaal verzendgewicht en verzendmethode van de producten in de bestelling. Wijzigingen hier gelden direct voor nieuwe bestellingen. Een tarief kan een vast, hier ingevuld bedrag gebruiken, of verwijzen naar een centraal <a href="/admin/carrier-rates.php">carrier-tarief</a> (bv. automatisch bijgewerkt via PostNL) — in dat geval geldt altijd de actuele prijs daarvan.</p>

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

  <?php if ($zones === null): ?>
    <p class="admin-alert admin-alert--error">Verzendzones konden niet worden geladen.</p>
  <?php else: ?>
    <?php foreach ($zones as $zone): ?>
      <section class="admin-card">
        <h2>
          <?= htmlspecialchars((string) $zone['name'], ENT_QUOTES, 'UTF-8') ?>
          <span class="admin-text-muted">(<?= htmlspecialchars(implode(', ', $zone['countries']), ENT_QUOTES, 'UTF-8') ?>)</span>
        </h2>

        <?php if ($zone['rates'] === []): ?>
          <p class="admin-text-muted">Nog geen verzendtarieven voor deze zone — bestellingen naar hier krijgen de melding dat er geen verzendmethode beschikbaar is totdat je hieronder een tarief toevoegt.</p>
        <?php else: ?>
          <div class="admin-variant-list">
            <?php foreach ($zone['rates'] as $rate): ?>
              <article class="admin-variant-panel">
                <div class="admin-variant-panel__head">
                  <strong><?= htmlspecialchars(ShippingProfile::label((string) $rate['shipping_profile']), ENT_QUOTES, 'UTF-8') ?></strong>
                  <span class="admin-badge admin-badge--<?= $rate['enabled'] ? 'paid' : 'canceled' ?>">
                    <?= $rate['enabled'] ? 'Actief' : 'Uitgeschakeld' ?>
                  </span>
                </div>

                <form method="post" action="/api/admin/update-shipping-rate.php" class="admin-inline-form admin-variant-panel__form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="rate_id" value="<?= (int) $rate['id'] ?>">
                  <label>Methode
                    <select name="shipping_profile">
                      <?php foreach (ShippingProfile::ALL as $profileValue): ?>
                        <option value="<?= htmlspecialchars($profileValue, ENT_QUOTES, 'UTF-8') ?>" <?= $rate['shipping_profile'] === $profileValue ? 'selected' : '' ?>>
                          <?= htmlspecialchars(ShippingProfile::label($profileValue), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Vanaf (g)
                    <input type="text" inputmode="numeric" name="min_weight_grams" value="<?= $rate['min_weight_grams'] !== null ? (int) $rate['min_weight_grams'] : '' ?>" placeholder="geen">
                  </label>
                  <label>Gewicht t/m (g)
                    <input type="text" inputmode="numeric" name="max_weight_grams" value="<?= $rate['max_weight_grams'] !== null ? (int) $rate['max_weight_grams'] : '' ?>" placeholder="onbeperkt">
                  </label>
                  <label>Carrier-tarief
                    <select name="carrier_rate_id">
                      <option value="">— Handmatig bedrag —</option>
                      <?php foreach ($carrierRates as $carrierRate): ?>
                        <option value="<?= (int) $carrierRate['id'] ?>" <?= (int) $rate['carrier_rate_id'] === (int) $carrierRate['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string) $carrierRate['label'], ENT_QUOTES, 'UTF-8') ?> (€<?= htmlspecialchars(number_format((float) $carrierRate['price'], 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Prijs (&euro;)<?= $rate['carrier_rate_id'] !== null ? ' <span class="admin-text-muted">(genegeerd, carrier-tarief geldt)</span>' : '' ?>
                    <input type="text" inputmode="decimal" name="price" value="<?= htmlspecialchars(number_format((float) $rate['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                  </label>
                  <label>Volgorde
                    <input type="text" inputmode="numeric" name="sort_order" value="<?= (int) $rate['sort_order'] ?>">
                  </label>
                  <label class="admin-checkbox-label">
                    <input type="checkbox" name="enabled" value="1" <?= $rate['enabled'] ? 'checked' : '' ?>>
                    Actief
                  </label>
                  <button type="submit" class="admin-btn-text">Opslaan</button>
                </form>

                <form method="post" action="/api/admin/delete-shipping-rate.php" class="admin-inline-form" onsubmit="return confirm('Dit verzendtarief verwijderen?');">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="rate_id" value="<?= (int) $rate['id'] ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <h3>Nieuw tarief voor <?= htmlspecialchars((string) $zone['name'], ENT_QUOTES, 'UTF-8') ?></h3>
        <form method="post" action="/api/admin/create-shipping-rate.php" class="admin-form-row">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="shipping_zone_id" value="<?= (int) $zone['id'] ?>">
          <label>Methode
            <select name="shipping_profile">
              <?php foreach (ShippingProfile::ALL as $profileValue): ?>
                <option value="<?= htmlspecialchars($profileValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ShippingProfile::label($profileValue), ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Vanaf (g)
            <input type="text" inputmode="numeric" name="min_weight_grams" placeholder="geen">
          </label>
          <label>Gewicht t/m (g)
            <input type="text" inputmode="numeric" name="max_weight_grams" placeholder="onbeperkt">
          </label>
          <label>Carrier-tarief
            <select name="carrier_rate_id">
              <option value="">— Handmatig bedrag —</option>
              <?php foreach ($carrierRates as $carrierRate): ?>
                <option value="<?= (int) $carrierRate['id'] ?>">
                  <?= htmlspecialchars((string) $carrierRate['label'], ENT_QUOTES, 'UTF-8') ?> (€<?= htmlspecialchars(number_format((float) $carrierRate['price'], 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Prijs (&euro;)
            <input type="text" inputmode="decimal" name="price" placeholder="0.00">
          </label>
          <label>Volgorde
            <input type="text" inputmode="numeric" name="sort_order" value="0">
          </label>
          <label class="admin-checkbox-label">
            <input type="checkbox" name="enabled" value="1" checked>
            Actief
          </label>
          <button type="submit">Tarief toevoegen</button>
        </form>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
</body>
</html>
