<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('products.view');

$productRepository = new ProductRepository();

try {
    $products = $productRepository->findAllForAdmin();
    $referencedIds = $productRepository->referencedProductIds();

    // A product with variants no longer shows its own product-level photo —
    // the overview thumbnail mirrors the public shop card here too (default
    // variant = first active by sort_order, its first image). See MAIN.MD.
    if ($products !== null) {
        $variantRepository = new ProductVariantRepository();
        foreach ($products as &$product) {
            $defaultVariant = $variantRepository->findDefaultForProduct((int) $product['id']);
            if ($defaultVariant !== null) {
                $product['image_path'] = $defaultVariant['images'][0]['image_path'] ?? null;
            }
        }
        unset($product);
    }
} catch (\Throwable $e) {
    error_log('[admin/products.php] ' . $e->getMessage());
    $products = null;
    $referencedIds = [];
}

$created = isset($_GET['created']);
$deleted = isset($_GET['deleted']);
$listError = $_SESSION['admin_product_list_error'] ?? null;
unset($_SESSION['admin_product_list_error']);

$csrfToken = Csrf::token();

/**
 * products.view opens this overview read-only; every action that changes a
 * product needs products.manage. Hiding the controls keeps the screen honest
 * — the endpoints behind them refuse the request either way (see
 * AdminAuth::requirePermissionForApi in api/admin/*-product*.php).
 */
$canManageProducts = AdminAuth::can('products.manage');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Producten — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1>Producten</h1>
    <?php if ($canManageProducts): ?>
      <a href="/admin/product-form.php" class="admin-btn-link">+ Nieuw product</a>
    <?php endif; ?>
  </div>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Product aangemaakt.</p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success">Product verwijderd.</p>
  <?php endif; ?>
  <?php if ($listError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

  <?php if ($products === null): ?>
    <p class="admin-alert admin-alert--error">Producten konden niet worden geladen.</p>
  <?php elseif ($products === []): ?>
    <?php if ($canManageProducts): ?>
      <p>Nog geen producten. <a href="/admin/product-form.php">Maak het eerste product aan</a>.</p>
    <?php else: ?>
      <p>Nog geen producten.</p>
    <?php endif; ?>
  <?php else: ?>
    <div class="admin-product-grid">
      <?php foreach ($products as $product): ?>
        <?php
          $productId = (int) $product['id'];
          $isActive = (int) $product['active'] === 1;
          $inUse = in_array($productId, $referencedIds, true);
          $name = (string) $product['name'];
          $editUrl = '/admin/product-form.php?id=' . $productId;
        ?>
        <article class="admin-product-card">
          <?php // Read-only accounts get the same card, without the edit link. ?>
          <?php if ($canManageProducts): ?>
          <a href="<?= $editUrl ?>" class="admin-product-card__link" aria-label="Bewerken: <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
          <?php else: ?>
          <div class="admin-product-card__link">
          <?php endif; ?>
            <div class="admin-product-card__media">
              <?php if (!empty($product['image_path'])): ?>
                <img src="/<?= htmlspecialchars(ltrim((string) $product['image_path'], '/'), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="admin-product-card__media-empty">Geen foto</span>
              <?php endif; ?>
              <span class="admin-badge admin-product-card__status admin-badge--<?= $isActive ? 'paid' : 'canceled' ?>">
                <?= $isActive ? 'Actief' : 'Inactief' ?>
              </span>
            </div>
            <div class="admin-product-card__body">
              <p class="admin-product-card__name"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></p>
              <p class="admin-product-card__price">&euro; <?= htmlspecialchars(number_format((float) $product['price'], 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          <?php if (!$canManageProducts): ?>
          </div>
          <?php else: ?>
          </a>
          <div class="admin-product-card__footer">
            <form method="post" action="/api/admin/update-product-status.php" class="admin-inline-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="id" value="<?= $productId ?>">
              <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
              <button type="submit" class="admin-btn-text"><?= $isActive ? 'Deactiveren' : 'Activeren' ?></button>
            </form>
            <?php
              // A product that has been ordered can be deleted too: order_items
              // keeps its own snapshot of title/variant/quantity/price and the
              // foreign key is ON DELETE SET NULL, so the historical order stays
              // intact. The confirm text says so when that applies.
              $confirmMessage = $inUse
                ? 'Weet je zeker dat je dit product definitief wilt verwijderen? Bestaande bestellingen met dit product blijven ongewijzigd.'
                : 'Weet je zeker dat je dit product definitief wilt verwijderen?';
            ?>
            <form method="post" action="/api/admin/delete-product.php" class="admin-inline-form" onsubmit="return confirm('<?= htmlspecialchars($confirmMessage, ENT_QUOTES, 'UTF-8') ?>');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="id" value="<?= $productId ?>">
              <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
            </form>
          </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
