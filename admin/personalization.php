<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repository\ProductPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\ProductPersonalizationContent;

AdminAuth::requireLogin();
AdminAuth::requirePermission('personalization.manage');

/**
 * Personalisatie — the overview of every shop product that can be
 * personalized, and the one place a product is enrolled into the module.
 *
 * ## What this screen is NOT
 *
 * It is not a second product catalogue. "Product toevoegen" below creates no
 * `products` row and copies nothing out of one: it creates a personalization
 * CONFIGURATION for a product that already exists. `products` stays the
 * single source of truth for name, description, price, variants, photos and
 * public visibility; personalization is an optional satellite of one of those
 * rows and nothing more. Removing a configuration therefore removes exactly
 * that — the product itself keeps selling, minus the engraving.
 *
 * A product that is not listed here behaves like every other shop product.
 *
 * ## Why it lives outside the product editor
 *
 * Personalization has an overview, a per-product configuration with its own
 * dedicated preview images and zones, and a shop-wide font library. Folding
 * all of that into admin/product-form.php made the ordinary product form
 * unreadable and hid the fact that the fonts are global rather than
 * per-product. The product editor now only LINKS here (see MAIN.MD); there is
 * exactly one editor for a configuration, and it is admin/personalization-product.php.
 */
$loadError = null;

try {
    $repository = new ProductPersonalizationRepository();
    $configured = $repository->findAllConfigured();
    $available = $repository->findProductsWithoutConfiguration();
} catch (\Throwable $e) {
    error_log('[admin/personalization.php] ' . $e->getMessage());
    $configured = [];
    $available = [];
    $loadError = 'De personalisatie-instellingen konden niet worden geladen.';
}

$flashErrors = $_SESSION['admin_personalization_list_errors'] ?? [];
unset($_SESSION['admin_personalization_list_errors']);

$added = isset($_GET['added']);
$removed = isset($_GET['removed']);
$duplicate = isset($_GET['duplicate']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Personalisatie — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1>Personalisatie</h1>
    <a href="/admin/personalization-fonts.php" class="admin-btn-link">Lettertypes beheren</a>
  </div>

  <p class="admin-text-muted">
    Hier bepaal je <strong>welke bestaande shopproducten</strong> een klant zelf kan personaliseren. Een product dat
    hier niet tussen staat, werkt gewoon als elk ander product. Het product zelf — naam, omschrijving, prijs,
    varianten en productfoto's — blijft je in <a href="/admin/products.php">Producten</a> beheren; hier komt alleen de
    personalisatie bij.
  </p>

  <?php if ($added): ?>
    <p class="admin-alert admin-alert--success">Product toegevoegd aan Personalisatie. Voeg hieronder een weergave met een eigen voorbeeldafbeelding toe.</p>
  <?php endif; ?>
  <?php if ($removed): ?>
    <p class="admin-alert admin-alert--success">Personalisatie verwijderd. Het product zelf en alle bestaande bestellingen zijn ongewijzigd gebleven.</p>
  <?php endif; ?>
  <?php if ($duplicate): ?>
    <p class="admin-alert admin-alert--error">Dat product staat al in Personalisatie. Een product kan maar één personalisatie-configuratie hebben.</p>
  <?php endif; ?>
  <?php if ($loadError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= $h($loadError) ?></p>
  <?php endif; ?>
  <?php if ($flashErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($flashErrors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <h2>Product toevoegen</h2>
    <p class="admin-text-muted">
      Kies een bestaand product uit de shop. Er wordt <strong>geen nieuw product aangemaakt</strong> — je koppelt
      alleen personalisatie aan het product dat er al is. Producten die hier al staan, staan niet in de lijst.
    </p>

    <?php if ($available === []): ?>
      <p class="admin-text-muted">Alle producten zijn al toegevoegd.</p>
    <?php else: ?>
      <?php /* A <select> with a type-ahead <datalist> alternative would need
               JavaScript; a plain select with a search-friendly size is what
               the rest of this CMS uses (see admin/collection.php's picker),
               and the browser's own type-to-jump already searches it. */ ?>
      <form method="post" action="/api/admin/create-product-personalization.php" class="admin-form-row admin-form-row--split">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <label>Product
          <select name="product_id" required>
            <option value="">— Kies een product —</option>
            <?php foreach ($available as $product): ?>
              <option value="<?= (int) $product['id'] ?>">
                <?= $h((string) $product['name']) ?><?= (int) $product['active'] === 1 ? '' : ' (inactief)' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <div style="align-self:end;">
          <button type="submit">Toevoegen</button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2>Gepersonaliseerde producten</h2>

    <?php if ($configured === []): ?>
      <p class="admin-text-muted">Nog geen producten met personalisatie. Voeg er hierboven een toe.</p>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Zichtbaar</th>
              <th>Status</th>
              <th>Aankoop</th>
              <th>Weergaven</th>
              <th>Zones</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($configured as $row): ?>
              <?php
                $productId = (int) $row['product_id'];
                $isEnabled = (int) $row['is_enabled'] === 1;
                $mode = PersonalizationRules::purchaseMode($row['personalization_mode'] ?? null);
                $viewCount = (int) $row['view_count'];
                $viewsWithImage = (int) $row['view_with_image_count'];
                $zoneCount = (int) $row['zone_count'];
                $imagePath = trim((string) ($row['image_path'] ?? ''));
                $name = (string) $row['product_name'];
                $inShop = (int) ($row['in_shop'] ?? 1) === 1;
                $inCatalog = (int) ($row['in_personalization_catalog'] ?? 0) === 1;
                // The same "is there anything to show" rule the storefront
                // applies (App\Service\Personalization\ProductPersonalizationContent):
                // a view needs its OWN preview image, and there has to be a
                // zone. Flagged here so a configuration that renders nothing
                // is visible at a glance instead of being discovered on the
                // shop — and asked through the personalization module's own
                // helper, so this screen and the dashboard's "Aandacht nodig"
                // list can never disagree about what "onvolledig" means.
                $incomplete = $isEnabled && ProductPersonalizationContent::summaryIsIncomplete($viewsWithImage, $zoneCount);
              ?>
              <tr>
                <td>
                  <div class="admin-personalization-list__product">
                    <span class="admin-personalization-list__thumb">
                      <?php if ($imagePath !== ''): ?>
                        <img src="/<?= $h(ltrim($imagePath, '/')) ?>" alt="" loading="lazy">
                      <?php endif; ?>
                    </span>
                    <span>
                      <a href="/admin/personalization-product.php?product_id=<?= $productId ?>"><?= $h($name) ?></a>
                      <?php if ((int) $row['product_active'] !== 1): ?>
                        <br><span class="admin-text-muted">Product staat op inactief</span>
                      <?php endif; ?>
                      <?php if ($incomplete): ?>
                        <br><span class="admin-badge admin-badge--pending">Onvolledig</span>
                      <?php endif; ?>
                    </span>
                  </div>
                </td>
                <td>
                  <?php /* Where the product is actually for sale. A product
                           that is only in the personalization catalogue has
                           no ordinary purchase path at all, which is why it
                           is worth seeing at a glance. */ ?>
                  <?php if ($inShop && $inCatalog): ?>
                    <span class="admin-badge admin-badge--info">Shop + Personaliseren</span>
                  <?php elseif ($inCatalog): ?>
                    <span class="admin-badge admin-badge--editable">Alleen personaliseren</span>
                  <?php elseif ($inShop): ?>
                    <span class="admin-badge admin-badge--muted">Alleen shop</span>
                  <?php else: ?>
                    <span class="admin-badge admin-badge--pending">Nergens</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="admin-badge admin-badge--<?= $isEnabled ? 'paid' : 'muted' ?>">
                    <?= $isEnabled ? 'Aan' : 'Uit' ?>
                  </span>
                </td>
                <td>
                  <?php /* A personalization-only product is required by
                           construction, whatever its own setting says. */ ?>
                  <?= $mode === PersonalizationRules::PURCHASE_REQUIRED || !$inShop ? 'Verplicht' : 'Optioneel' ?>
                  <?php if (!$inShop && $mode !== PersonalizationRules::PURCHASE_REQUIRED): ?>
                    <br><span class="admin-text-muted">(niet in de shop)</span>
                  <?php endif; ?>
                </td>
                <td><?= $viewsWithImage ?> / <?= $viewCount ?><?php if ($viewCount > 0 && $viewsWithImage < $viewCount): ?> <span class="admin-text-muted">(met afbeelding)</span><?php endif; ?></td>
                <td><?= $zoneCount ?></td>
                <td>
                  <div class="admin-personalization-list__actions">
                    <a class="admin-btn-text" href="/admin/personalization-product.php?product_id=<?= $productId ?>">Bewerken</a>
                    <?php
                      $confirmMessage = 'Personalisatie voor dit product verwijderen? Het product zelf blijft gewoon bestaan en bestaande bestellingen veranderen niet.';
                    ?>
                    <form method="post" action="/api/admin/delete-product-personalization.php" class="admin-inline-form"
                          onsubmit="return confirm('<?= $h($confirmMessage) ?>');">
                      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                      <input type="hidden" name="product_id" value="<?= $productId ?>">
                      <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
