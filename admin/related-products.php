<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Repository\CollectionRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\RelatedProductsContent;
use App\Service\SiteSettings;

/**
 * The "Gerelateerde producten" CMS screen: the whole configuration surface
 * of the automatic related-products section on product detail pages.
 *
 * There is nothing to pick here and no per-product setting — the shop's
 * collections already decide which products belong together (see
 * App\Service\RelatedProductsContent). This screen only answers three
 * questions: is the feature on, what does its heading say, how many products
 * fit, and — per collection — may that collection be used as a source at
 * all.
 *
 * It sits in the Shop group of the sidebar, right after Collecties, because
 * that is what it configures; it is deliberately not a page-builder section
 * editor and is reached from the menu, not from a page.
 *
 * One form saves everything, the same "one screen, one save" shape
 * admin/collection.php and admin/settings.php use.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('collections.manage');

try {
    // findAllWithProductCounts() already returns every collection in the
    // CMS's own order plus its product count — the same list and the same
    // ordering the overview and the "first eligible collection" rule use.
    $collections = (new CollectionRepository())->findAllWithProductCounts();
} catch (\Throwable $e) {
    error_log('[admin/related-products.php] ' . $e->getMessage());
    $collections = [];
}

$errors = $_SESSION['admin_related_products_errors'] ?? [];
$old = $_SESSION['admin_related_products_old'] ?? null;
unset($_SESSION['admin_related_products_errors'], $_SESSION['admin_related_products_old']);

$saved = isset($_GET['saved']);

$globals = [
    'enabled' => RelatedProductsContent::isEnabled(),
    'heading_nl' => SiteSettings::get('related_products_heading_nl'),
    'heading_en' => SiteSettings::get('related_products_heading_en'),
    'max_items' => SiteSettings::get('related_products_max_items'),
];

if ($old !== null) {
    $globals = [
        'enabled' => !empty($old['enabled']),
        'heading_nl' => (string) ($old['heading_nl'] ?? ''),
        'heading_en' => (string) ($old['heading_en'] ?? ''),
        'max_items' => (string) ($old['max_items'] ?? ''),
    ];
}

/** @var array<int, array{enabled: bool, heading_nl: string, heading_en: string}> */
$oldCollections = ($old !== null && is_array($old['collections'] ?? null)) ? $old['collections'] : [];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('shop.gerelateerde_producten_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('shop.gerelateerde_producten') ?></h1>
  <p class="admin-text-muted"><?= admin_t('shop.onderaan_elke_productpagina_automatisch') ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>
  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/update-related-products-settings.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

    <section class="admin-card">
      <h2><?= admin_te('shop.algemene_instellingen') ?></h2>

      <div class="admin-product-form admin-product-form--wide">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="enabled" value="1" <?= $globals['enabled'] ? 'checked' : '' ?>>
          <?= admin_te('shop.gerelateerde_producten_tonen_uitgevinkt') ?>
        </label>

        <?php admin_lang_tabs(); ?>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('common.title') ?>*
            <input type="text" name="heading_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= $h((string) $globals['heading_nl']) ?>">
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('common.title') ?>
            <input type="text" name="heading_en" maxlength="255" value="<?= $h((string) $globals['heading_en']) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row">
          <label><?= admin_te('shop.maximum_aantal_producten') ?>*
            <input type="number" name="max_items" min="<?= RelatedProductsContent::MIN_MAX_ITEMS ?>" max="<?= RelatedProductsContent::MAX_MAX_ITEMS ?>" step="1" required value="<?= $h((string) $globals['max_items']) ?>">
          </label>
          <p class="admin-text-muted"><?= admin_t('shop.er_minder_geschikte_producten') ?></p>
        </div>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('shop.per_collectie') ?></h2>
      <p class="admin-text-muted"><?= admin_t('shop.zet_uit_welke_collecties') ?></p>

      <?php if ($collections === []): ?>
        <p class="admin-text-muted"><?= admin_t('shop.er_collecties_maak_eerst') ?></p>
      <?php else: ?>
        <?php
          // Marks "the collection list was actually rendered, so an absent
          // checkbox genuinely means 'switched off'". Without it, a save
          // made while this list could not be built (a failed query above)
          // would look identical to unticking every collection and would
          // silently disable the feature everywhere. Same guard as
          // admin/collection.php's products_submitted.
        ?>
        <input type="hidden" name="collections_submitted" value="1">

        <div class="admin-page-sections">
          <?php foreach ($collections as $collection): ?>
            <?php
              $collectionId = (int) $collection['id'];
              $stored = $oldCollections[$collectionId] ?? null;
              $isEnabled = $stored !== null
                  ? !empty($stored['enabled'])
                  : (int) $collection['show_related_products'] === 1;
              $headingNl = $stored !== null
                  ? (string) ($stored['heading_nl'] ?? '')
                  : (string) ($collection['related_heading_nl'] ?? '');
              $headingEn = $stored !== null
                  ? (string) ($stored['heading_en'] ?? '')
                  : (string) ($collection['related_heading_en'] ?? '');
              $productCount = (int) ($collection['product_count'] ?? 0);
              $isActive = (int) $collection['is_active'] === 1;
            ?>
            <div class="admin-section-row admin-related-collection-row">
              <label class="admin-checkbox-label admin-related-collection-row__pick">
                <input type="checkbox" name="collections[<?= $collectionId ?>][enabled]" value="1" <?= $isEnabled ? 'checked' : '' ?>>
                <span class="admin-section-row__body">
                  <span class="admin-section-row__name"><?= $h((string) $collection['name']) ?></span>
                  <span class="admin-text-muted"><?= $productCount === 1 ? '1 product' : $productCount . ' producten' ?></span>
                </span>
              </label>

              <?php if (!$isActive): ?>
                <span class="admin-badge admin-badge--muted" title="Deze collectie staat op inactief. Een niet-gepubliceerde collectie wordt nooit als bron voor gerelateerde producten gebruikt, ook niet als dit vinkje aanstaat.">Inactief</span>
              <?php endif; ?>

              <?php // Visually secondary on purpose: the checkbox is the
                    // setting that matters, the override is a nicety. ?>
              <?php admin_lang_pane_start('nl'); ?>
              <label class="admin-related-collection-row__override">
                <span class="admin-text-muted"><?= admin_te('shop.eigen_titel') ?></span>
                <input type="text" name="collections[<?= $collectionId ?>][heading_nl]" maxlength="255" value="<?= $h($headingNl) ?>" placeholder="Leeg = algemene titel">
              </label>
              <?php admin_lang_pane_end(); ?>
              <?php admin_lang_pane_start('en'); ?>
              <label class="admin-related-collection-row__override">
                <span class="admin-text-muted"><?= admin_te('shop.eigen_titel_2') ?></span>
                <input type="text" name="collections[<?= $collectionId ?>][heading_en]" maxlength="255" value="<?= $h($headingEn) ?>"<?= admin_lang_placeholder_attr('en') ?>>
              </label>
              <?php admin_lang_pane_end(); ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
