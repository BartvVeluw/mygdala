<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gerelateerde producten — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1>Gerelateerde producten</h1>
  <p class="admin-text-muted">Onderaan elke productpagina kan automatisch een rijtje andere producten worden getoond. Die komen uit de <a href="/admin/collections.php">collectie</a> waar het product in zit &mdash; je hoeft dus nergens handmatig producten te koppelen. Het product dat de bezoeker bekijkt wordt uiteraard zelf overgeslagen, en de volgorde is die van de collectie.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
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
      <h2>Algemene instellingen</h2>

      <div class="admin-product-form admin-product-form--wide">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="enabled" value="1" <?= $globals['enabled'] ? 'checked' : '' ?>>
          Gerelateerde producten tonen (uitgevinkt = nergens op de site, ongeacht de instellingen hieronder)
        </label>

        <div class="admin-form-row admin-form-row--split">
          <label>Titel (NL)*
            <input type="text" name="heading_nl" maxlength="255" required value="<?= $h((string) $globals['heading_nl']) ?>">
          </label>
          <label>Titel (EN)
            <input type="text" name="heading_en" maxlength="255" value="<?= $h((string) $globals['heading_en']) ?>" placeholder="Leeg = zelfde als NL">
          </label>
        </div>

        <div class="admin-form-row">
          <label>Maximum aantal producten*
            <input type="number" name="max_items" min="<?= RelatedProductsContent::MIN_MAX_ITEMS ?>" max="<?= RelatedProductsContent::MAX_MAX_ITEMS ?>" step="1" required value="<?= $h((string) $globals['max_items']) ?>">
          </label>
          <p class="admin-text-muted">Zijn er minder geschikte producten in de collectie, dan worden alleen die getoond &mdash; er wordt nooit aangevuld met producten uit een andere collectie.</p>
        </div>
      </div>
    </section>

    <section class="admin-card">
      <h2>Per collectie</h2>
      <p class="admin-text-muted">Zet uit voor welke collecties je g&eacute;&eacute;n gerelateerde producten wilt. Producten uit zo'n collectie tonen het blok niet. Zit een product in meerdere collecties, dan wordt de <strong>eerste collectie in deze volgorde</strong> gebruikt waarvoor het vinkje aanstaat en die zelf ook actief is. De volgorde van collecties pas je aan op <a href="/admin/collections.php">Collecties</a>.</p>

      <?php if ($collections === []): ?>
        <p class="admin-text-muted">Er zijn nog geen collecties. <a href="/admin/collection.php">Maak eerst een collectie aan</a> &mdash; zonder collecties kunnen er geen gerelateerde producten worden bepaald.</p>
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
              <label class="admin-related-collection-row__override">
                <span class="admin-text-muted">Eigen titel (NL)</span>
                <input type="text" name="collections[<?= $collectionId ?>][heading_nl]" maxlength="255" value="<?= $h($headingNl) ?>" placeholder="Leeg = algemene titel">
              </label>
              <label class="admin-related-collection-row__override">
                <span class="admin-text-muted">Eigen titel (EN)</span>
                <input type="text" name="collections[<?= $collectionId ?>][heading_en]" maxlength="255" value="<?= $h($headingEn) ?>" placeholder="Leeg = zelfde als NL">
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-card">
      <button type="submit">Opslaan</button>
    </section>
  </form>
</main>
</body>
</html>
