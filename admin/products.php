<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ProductAdminOverview;
use App\Service\ShopLocalization;
use App\Repository\ProductRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('products.view');

$productRepository = new ProductRepository();

try {
    // Every row with its thumbnail and its stock in a fixed number of
    // queries (App\Service\ProductAdminOverview), however many products.
    $products = (new ProductAdminOverview())->rows();
    $referencedIds = $productRepository->referencedProductIds();

    // Every card's name in one query rather than one per card. The
    // overview is language-neutral: a product is listed under the name
    // the CMS calls it by (its default language's, see
    // App\Service\Language\LanguageFallback::name()), in the
    // language-neutral order the repository returned.
    ShopLocalization::preloadProducts(array_map(
        static fn (array $product): int => (int) $product['id'],
        $products
    ));
} catch (\Throwable $e) {
    error_log('[admin/products.php] ' . $e->getMessage());
    $products = null;
    $referencedIds = [];
}

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
<title><?= admin_te('shop.producten_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1><?= admin_te('shop.producten') ?></h1>
    <?php if ($canManageProducts): ?>
      <a href="/admin/product-form.php" class="admin-btn-link">+ Nieuw product</a>
    <?php endif; ?>
  </div>

  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('shop.product_verwijderd') ?></p>
  <?php endif; ?>
  <?php if ($listError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

  <?php if ($products === null): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('shop.producten_konden_geladen') ?></p>
  <?php elseif ($products === []): ?>
    <?php if ($canManageProducts): ?>
      <p><?= admin_t('shop.producten_maak_eerste_product') ?></p>
    <?php else: ?>
      <p><?= admin_te('shop.producten_2') ?></p>
    <?php endif; ?>
  <?php else: ?>
    <?php
      /* GRID OR LIST is a view preference, not data: one card markup, drawn
         two ways by CSS (data-product-view), remembered in this browser's
         localStorage by admin/assets/product-overview.js — the way the Media
         Library remembers its view. Without JavaScript the grid stays and
         the switch stays hidden. */
    ?>
    <div class="admin-view-toggle" role="group" aria-label="<?= admin_te('shop.products_view.label') ?>" data-product-view-toggle hidden>
      <button type="button" class="admin-view-toggle__option" data-product-view-option="grid" aria-pressed="true"><?= admin_te('shop.products_view.grid') ?></button>
      <button type="button" class="admin-view-toggle__option" data-product-view-option="list" aria-pressed="false"><?= admin_te('shop.products_view.list') ?></button>
    </div>
    <div class="admin-product-grid" data-product-overview data-product-view="grid">
      <?php foreach ($products as $product): ?>
        <?php
          $productId = (int) $product['id'];
          $isActive = (int) $product['active'] === 1;
          $inUse = in_array($productId, $referencedIds, true);
          $name = ShopLocalization::productName($productId);
          $editUrl = '/admin/product-form.php?id=' . $productId;
          $isInquiry = \App\Service\PurchaseMode::isInquiry($product['purchase_mode'] ?? null);
          /** @var \App\Service\Inventory\StockSummary $stock */
          $stock = $product['stock'];
        ?>
        <article class="admin-product-card">
          <?php // Read-only accounts get the same card, without the edit link. ?>
          <?php if ($canManageProducts): ?>
          <a href="<?= $editUrl ?>" class="admin-product-card__link" aria-label="<?= admin_te('common.edit') ?>: <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
          <?php else: ?>
          <div class="admin-product-card__link">
          <?php endif; ?>
            <div class="admin-product-card__media">
              <?php if ($product['thumbnail'] !== null): ?>
                <img src="/<?= htmlspecialchars(ltrim((string) $product['thumbnail'], '/'), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="admin-product-card__media-empty"><?= admin_te('shop.no_photo') ?></span>
              <?php endif; ?>
            </div>
            <div class="admin-product-card__body">
              <p class="admin-product-card__name"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></p>
              <p class="admin-product-card__status">
                <span class="admin-badge admin-badge--<?= $isActive ? 'paid' : 'canceled' ?>"><?= $isActive ? admin_t('common.active') : admin_te('common.inactive') ?></span>
              </p>
              <p class="admin-product-card__price">
                <?php if ($isInquiry): ?>
                  <span class="admin-badge admin-badge--info"><?= admin_te('shop.purchase_mode.inquiry_badge') ?></span>
                <?php else: ?>
                  <?= admin_t('shop.amount_with', ['v1' => htmlspecialchars(number_format((float) $product['price'], 2, ',', '.'), ENT_QUOTES, 'UTF-8')]) ?>
                <?php endif; ?>
              </p>
              <p class="admin-product-card__stock">
                <span class="admin-visually-hidden"><?= admin_te('shop.stock_summary.label') ?>:</span>
                <span class="admin-badge admin-badge--<?= htmlspecialchars($stock->tone, ENT_QUOTES, 'UTF-8') ?>" data-stock-kind="<?= htmlspecialchars($stock->kind, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($stock->text(), ENT_QUOTES, 'UTF-8') ?></span>
              </p>
            </div>
          <?php if (!$canManageProducts): ?>
          </div>
          <?php else: ?>
          </a>
          <div class="admin-product-card__footer">
            <a href="<?= $editUrl ?>" class="admin-btn-text admin-product-card__edit" aria-label="<?= admin_te('common.edit') ?>: <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= admin_te('common.edit') ?></a>
            <form method="post" action="/api/admin/update-product-status.php" class="admin-inline-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="id" value="<?= $productId ?>">
              <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
              <button type="submit" class="admin-btn-text"><?= $isActive ? admin_t('shop.deactiveren') : admin_t('shop.activeren') ?></button>
            </form>
            <?php
              // A product that has been ordered can be deleted too: order_items
              // keeps its own snapshot of title/variant/quantity/price and the
              // foreign key is ON DELETE SET NULL, so the historical order stays
              // intact. The confirm text says so when that applies.
              $confirmMessage = $inUse
                ? admin_t('shop.confirm_delete_product_with_orders')
                : admin_t('shop.confirm_delete_product');
            ?>
            <form method="post" action="/api/admin/delete-product.php" class="admin-inline-form" onsubmit="return confirm('<?= htmlspecialchars($confirmMessage, ENT_QUOTES, 'UTF-8') ?>');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="id" value="<?= $productId ?>">
              <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
            </form>
          </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/product-overview.js') ?>" defer></script>
</body>
</html>
