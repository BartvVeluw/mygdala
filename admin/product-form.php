<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\ShopLocalization;
use App\Service\Csrf;
use App\Service\Seo;
use App\Service\Shipping\ShippingProfile;
use App\Repository\CollectionRepository;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductImageRepository;
use App\Repository\ProductOptionRepository;
use App\Repository\ProductVariantRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('products.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== null && $id !== false && $id >= 1;

$product = null;
$images = [];
$options = [];
$variants = [];
$productCollectionIds = [];
$personalization = null;

if ($isEdit) {
    try {
        $product = (new ProductRepository())->findByIdForAdmin($id);
        $images = (new ProductImageRepository())->findByProductId($id);
        $options = (new ProductOptionRepository())->findByProductId($id);
        $variants = (new ProductVariantRepository())->findByProductId($id);
        $productCollectionIds = (new CollectionRepository())->collectionIdsForProduct($id);
        // null for every product that has never been configured — which is
        // every existing product, and reads as "personalization disabled".
        $personalization = (new ProductPersonalizationRepository())->findForProduct($id);
    } catch (\Throwable $e) {
        error_log('[admin/product-form.php] ' . $e->getMessage());
        http_response_code(500);
        exit(admin_t('screen.product_kon_geladen'));
    }

    if ($product === null) {
        http_response_code(404);
        exit(admin_t('screen.product_gevonden'));
    }
}

/**
 * The collections this product can be filed into. A product may belong to
 * zero, one or many — the checkbox list below is the product-side view of
 * the same collection_products relation admin/collection.php edits from the
 * other side, and saving it synchronises only this product's memberships
 * (see App\Repository\CollectionRepository::setProductCollections()).
 * Loaded outside the $isEdit branch so a brand-new product can be filed into
 * collections immediately.
 */
try {
    $allCollections = (new CollectionRepository())->findAll();

    // Every tick box's collection name in one query rather than one per box.
    // A collection is named per website language since Multilingual 2.0
    // phase 5 wave C, and the picker uses the one name the CMS calls it by.
    ShopLocalization::preloadCollections(array_map(
        static fn (array $collection): int => (int) $collection['id'],
        $allCollections
    ));
} catch (\Throwable $e) {
    error_log('[admin/product-form.php] ' . $e->getMessage());
    $allCollections = [];
}

$errors = $_SESSION['admin_product_errors'] ?? [];
$old = $_SESSION['admin_product_old'] ?? null;
unset($_SESSION['admin_product_errors'], $_SESSION['admin_product_old']);

$variantErrors = $_SESSION['admin_variant_errors'] ?? [];
unset($_SESSION['admin_variant_errors']);

$updated = isset($_GET['updated']);

// A product with variants manages its photos inside each variant instead —
// its own product-level photo section is hidden entirely. See MAIN.MD.
$hasVariants = $variants !== [];

/**
 * Value precedence: freshly re-submitted (invalid) input, then the stored
 * product (edit), then a sane default.
 */
function fieldValue(?array $old, ?array $product, string $key, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    if ($product !== null && array_key_exists($key, $product)) {
        return (string) ($product[$key] ?? '');
    }

    return $default;
}

/**
 * A product's WORDS, in the one website language this screen is editing
 * (Multilingual 2.0 phase 5 wave C): the refused POST first, so a rejected
 * save keeps what was typed, then what is stored FOR THAT LANGUAGE with no
 * fallback — the fallback is the placeholder.
 */
function productWord(?array $old, ?int $productId, string $field, string $language): string
{
    if ($old !== null && array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }

    return $productId === null ? '' : ShopLocalization::rawProduct($productId, $field, $language);
}

$activeChecked = $old !== null
    ? !empty($old['active'])
    : ($product !== null ? (int) $product['active'] === 1 : true);

/**
 * Where this product may be sold. `active` above is the master switch (off =
 * nowhere at all, and the product page 404s); these two say WHICH public
 * catalogue it belongs to. A brand-new product starts as an ordinary shop
 * product, which is what every product on this site was before the channels
 * existed.
 */
$inShopChecked = $old !== null
    ? !empty($old['in_shop'])
    : ($product !== null ? (int) $product['in_shop'] === 1 : true);
$inPersonalizationChecked = $old !== null
    ? !empty($old['in_personalization_catalog'])
    : ($product !== null ? (int) $product['in_personalization_catalog'] === 1 : false);

$priceValue = $old !== null
    ? (string) ($old['price_input'] ?? '')
    : ($product !== null ? number_format((float) $product['price'], 2, '.', '') : '');

$shippingProfileValue = $old !== null
    ? (string) ($old['shipping_profile'] ?? '')
    : ($product !== null ? (string) $product['shipping_profile'] : ShippingProfile::PARCEL);

$shippingWeightValue = $old !== null
    ? (string) ($old['shipping_weight_grams_input'] ?? '')
    : ($product !== null ? (string) (int) $product['shipping_weight_grams'] : '0');

$requiresParcelChecked = $old !== null
    ? !empty($old['requires_parcel'])
    : ($product !== null ? (int) $product['requires_parcel'] === 1 : true);

// After a failed save the admin's own ticks win over what is in the
// database, same precedence rule as every other field on this form.
$selectedCollectionIds = $old !== null && array_key_exists('collection_ids', $old)
    ? array_map('intval', (array) $old['collection_ids'])
    : $productCollectionIds;

// The SEO card's social image. Only an existing product can have one (a
// brand-new product's file input has nothing to preview or remove yet), and
// the "remove" tick survives a failed save like every other field here.
$ogImageValue = $product !== null ? (string) ($product['og_image_path'] ?? '') : '';
$removeOgImageChecked = $old !== null && !empty($old['remove_og_image']);

// Only used in the SEO card's help text, to spell out the automatic title
// fallback for the administrator — the fallback itself lives in
// App\Service\ProductSeo, never here.
$siteName = \App\Service\SiteSettings::get('site_name');

$csrfToken = Csrf::token();
$pageTitle = $isEdit ? admin_t('shop.edit_product') : admin_t('shop.new_product');

// The website language this screen's words are in, and the id they hang
// off. ONE language on the screen and in the request (Multilingual 2.0
// phase 5 wave C), so saving Dutch can never overwrite an English
// translation with a stale copy.
$editingLanguage = admin_localized_language();
$productId = $isEdit ? (int) $product['id'] : null;

// renderRichTextField() now lives in admin/_richtext_field.php, shared with
// admin/portfolio-item.php's Introtekst/Projectbeschrijving fields — its
// 'simple' toolbar default (bold/italic/link/unlink/clear only) keeps this
// call site's behaviour identical to before.
require __DIR__ . '/_richtext_field.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> <?= admin_te('shop.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/products.php"><?= admin_t('shop.terug_producten') ?></a></p>
  <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('shop.product_opgeslagen') ?></p>
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

  <section class="admin-card">
    <form method="post" action="/api/admin/<?= $isEdit ? 'update-product.php' : 'create-product.php' ?>" enctype="multipart/form-data" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
      <?php endif; ?>

      <?= admin_localized_input($editingLanguage) ?>
      <?php admin_localized_bar($editingLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.name') ?><?= admin_localized_required($editingLanguage) === '' ? '' : '*' ?>
          <input type="text" name="name" maxlength="<?= ShopLocalization::NAME_MAX_LENGTH ?>"<?= admin_localized_required($editingLanguage) ?> value="<?= htmlspecialchars(productWord($old, $productId, ShopLocalization::NAME, $editingLanguage), ENT_QUOTES, 'UTF-8') ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <?php renderRichTextField('description', 'Beschrijving', productWord($old, $productId, ShopLocalization::DESCRIPTION, $editingLanguage)); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_t('shop.prijs') ?>*
          <input type="text" inputmode="decimal" name="price" required value="<?= htmlspecialchars($priceValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="0.00">
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="active" value="1" <?= $activeChecked ? 'checked' : '' ?>>
          <?= admin_te('shop.actief_publiek_zichtbaar') ?>
        </label>
      </div>

      <h3><?= admin_te('shop.waar_product_koop') ?></h3>
      <div class="admin-form-row">
        <p class="admin-text-muted">
          <?= admin_te('shop.actief_hierboven_hoofdschakelaar_staat') ?>
        </p>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="in_shop" value="1" <?= $inShopChecked ? 'checked' : '' ?>>
          <span><strong><?= admin_t('shop.shop_zichtbaar_shopoverzicht_collectiepagina') ?></span>
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="in_personalization_catalog" value="1" <?= $inPersonalizationChecked ? 'checked' : '' ?>>
          <span><strong><?= admin_t('shop.personalisatiecatalogus_zichtbaar_personalis') ?></span>
        </label>
        <?php if (!$inShopChecked && $isEdit): ?>
          <p class="admin-alert admin-alert--info">
            <?= admin_t('shop.product_staat_shop_daarmee') ?>
          </p>
        <?php endif; ?>
      </div>

      <h3><?= admin_te('shop.collecties') ?></h3>
      <div class="admin-form-row">
        <?php if ($allCollections === []): ?>
          <p class="admin-text-muted"><?= admin_t('shop.collecties_maak_er_via') ?></p>
        <?php else: ?>
          <p class="admin-text-muted"><?= admin_te('shop.optioneel_product_verschijnt_collectiepagina') ?></p>
          <?php foreach ($allCollections as $collectionOption): ?>
            <?php
              $collectionOptionId = (int) $collectionOption['id'];
              $collectionCheckboxId = 'collection_' . $collectionOptionId;
            ?>
            <label class="admin-checkbox-label" for="<?= htmlspecialchars($collectionCheckboxId, ENT_QUOTES, 'UTF-8') ?>" style="margin-right:1rem;display:inline-flex;">
              <input type="checkbox"
                     id="<?= htmlspecialchars($collectionCheckboxId, ENT_QUOTES, 'UTF-8') ?>"
                     name="collection_ids[]"
                     value="<?= $collectionOptionId ?>"
                     <?= in_array($collectionOptionId, $selectedCollectionIds, true) ? 'checked' : '' ?>>
              <?= htmlspecialchars(ShopLocalization::collectionName($collectionOptionId), ENT_QUOTES, 'UTF-8') ?><?= (int) $collectionOption['is_active'] === 1 ? '' : ' (inactief)' ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <h3><?= admin_te('shop.verzending') ?></h3>
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('shop.verzendprofiel') ?>*
          <select name="shipping_profile" required>
            <?php foreach (ShippingProfile::ALL as $profileValue): ?>
              <option value="<?= htmlspecialchars($profileValue, ENT_QUOTES, 'UTF-8') ?>" <?= $shippingProfileValue === $profileValue ? 'selected' : '' ?>>
                <?= htmlspecialchars(ShippingProfile::label($profileValue, \App\Service\Language\AdminLocale::current()), ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= admin_te('shop.verzendgewicht_gram') ?>*
          <input type="text" inputmode="numeric" name="shipping_weight_grams" required value="<?= htmlspecialchars($shippingWeightValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="0">
        </label>
      </div>
      <div class="admin-form-row">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="requires_parcel" value="1" <?= $requiresParcelChecked ? 'checked' : '' ?>>
          <?= admin_te('shop.altijd_pakket_verzenden_negeert') ?>
        </label>
      </div>

      <?php if (!$isEdit): ?>
        <div class="admin-form-row">
          <label><?= admin_te('shop.foto_s_eerste_foto') ?>
            <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
        </div>
      <?php endif; ?>

      <h3><?= admin_te('shop.seo') ?></h3>
      <?php /* Secondary to the product's own content and therefore last in
               the form: everything here is OPTIONAL. Leaving a field empty
               is not "no SEO" — it means the product's normal content is
               used automatically (App\Service\ProductSeo). Same two-column
               language layout, same field names and the same maxlengths as
               the CMS page editor's SEO card (admin/page.php), so the two
               screens teach each other. */ ?>
      <p class="admin-text-muted">
        <?= admin_t('shop.allemaal_optioneel_laat_veld', ['v1' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')]) ?>
      </p>
      <div class="admin-product-form admin-product-form--wide">
        <?php admin_localized_bar($editingLanguage); ?>
        <div class="admin-form-row">
          <label><?= admin_te('page.meta_title') ?>
            <input type="text" name="meta_title" maxlength="<?= Seo::MAX_META_TITLE_LENGTH ?>" data-char-count value="<?= htmlspecialchars(productWord($old, $productId, ShopLocalization::META_TITLE, $editingLanguage), ENT_QUOTES, 'UTF-8') ?>" placeholder="Leeg = automatische titel">
          </label>
        </div>
        <div class="admin-form-row">
          <label><?= admin_te('page.meta_description') ?>
            <textarea name="meta_description" rows="3" maxlength="<?= Seo::MAX_META_DESCRIPTION_LENGTH ?>" data-char-count placeholder="Leeg = korte samenvatting van de beschrijving"><?= htmlspecialchars(productWord($old, $productId, ShopLocalization::META_DESCRIPTION, $editingLanguage), ENT_QUOTES, 'UTF-8') ?></textarea>
          </label>
        </div>
      </div>

      <div class="admin-form-row admin-seo-image">
        <?php if ($ogImageValue !== ''): ?>
          <div class="admin-image-card admin-seo-image__preview">
            <div class="admin-image-card__media">
              <img src="/<?= htmlspecialchars(ltrim($ogImageValue, '/'), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
            </div>
          </div>
        <?php endif; ?>
        <div class="admin-seo-image__fields">
          <label><?= admin_te('shop.deel_afbeelding_social_media') ?>
            <input type="file" name="og_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
          <p class="admin-text-muted"><?= admin_te('shop.optioneel_alleen_zichtbaar_voorbeeld') ?></p>
          <?php if ($ogImageValue !== ''): ?>
            <label class="admin-checkbox-label">
              <input type="checkbox" name="remove_og_image" value="1" <?= $removeOgImageChecked ? 'checked' : '' ?>>
              <?= admin_te('shop.deel_afbeelding_verwijderen_opslaan') ?>
            </label>
          <?php endif; ?>
        </div>
      </div>

      <button type="submit"><?= $isEdit ? 'Opslaan' : admin_t('shop.create_product') ?></button>
    </form>
  </section>

  <?php if ($isEdit && $hasVariants): ?>
    <section class="admin-card">
      <h2><?= admin_te('shop.foto_s') ?></h2>
      <p class="admin-text-muted"><?= admin_te('shop.product_heeft_varianten_foto') ?></p>
    </section>
  <?php elseif ($isEdit): ?>
    <section class="admin-card">
      <h2><?= admin_te('shop.foto_s_2') ?></h2>

      <?php if ($images === []): ?>
        <p class="admin-text-muted"><?= admin_te('shop.foto_s_product') ?></p>
      <?php else: ?>
        <div class="admin-image-manage-grid">
          <?php foreach ($images as $index => $image): ?>
            <?php
              $imageId = (int) $image['id'];
              $isPrimary = (int) $image['is_primary'] === 1;
              $isFirst = $index === 0;
              $isLast = $index === count($images) - 1;
            ?>
            <article class="admin-image-card">
              <div class="admin-image-card__media">
                <img src="/<?= htmlspecialchars(ltrim((string) $image['image_path'], '/'), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                <?php if ($isPrimary): ?>
                  <span class="admin-image-card__primary-badge">Hoofdfoto</span>
                <?php endif; ?>
              </div>
              <div class="admin-image-card__actions">
                <?php if (!$isPrimary): ?>
                  <form method="post" action="/api/admin/set-primary-product-image.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="image_id" value="<?= $imageId ?>">
                    <button type="submit" class="admin-btn-text"><?= admin_te('shop.maak_hoofdfoto') ?></button>
                  </form>
                <?php endif; ?>

                <form method="post" action="/api/admin/move-product-image.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="image_id" value="<?= $imageId ?>">
                  <input type="hidden" name="direction" value="up">
                  <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr;</button>
                </form>
                <form method="post" action="/api/admin/move-product-image.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="image_id" value="<?= $imageId ?>">
                  <input type="hidden" name="direction" value="down">
                  <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr;</button>
                </form>

                <form method="post" action="/api/admin/delete-product-image.php" class="admin-inline-form" onsubmit="return confirm('Deze foto verwijderen?');">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="image_id" value="<?= $imageId ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/api/admin/add-product-images.php" enctype="multipart/form-data" class="admin-form-row">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <label><?= admin_te('shop.foto_s_toevoegen') ?>
          <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
        </label>
        <button type="submit"><?= admin_te('common.add') ?></button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($isEdit): ?>
    <?php /* Personalisatie has its own CMS section (admin/personalization.php)
             as of Phase 3: its overview, its per-product editor with dedicated
             preview images and zones, and its shop-wide font library all live
             there. This card is only a signpost — there is exactly ONE editor
             for a configuration, and it is not this page. See MAIN.MD. */ ?>
    <section class="admin-card">
      <h2><?= admin_te('shop.personalisatie') ?></h2>
      <?php if ($personalization === null): ?>
        <p class="admin-text-muted">
          <?= admin_t('shop.product_heeft_personalisatie_wil') ?>
        </p>
      <?php else: ?>
        <p class="admin-text-muted">
          <?= admin_t('shop.product_gepersonaliseerd_voorbeeldafbeelding', ['v1' => (int) $personalization['settings']['is_enabled'] === 1 ? 'ingeschakeld' : 'nog uitgeschakeld']) ?>
        </p>
        <p>
          <a class="admin-btn-link" href="/admin/personalization-product.php?product_id=<?= (int) $product['id'] ?>">
            <?= admin_te('shop.personalisatie_beheren') ?>
          </a>
        </p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($isEdit): ?>
    <section class="admin-card">
      <h2><?= admin_te('shop.varianten') ?></h2>
      <p class="admin-text-muted"><?= admin_te('shop.optioneel_voeg_optie_toe') ?></p>

      <?php if ($variantErrors !== []): ?>
        <div class="admin-alert admin-alert--error">
          <ul class="admin-error-list">
            <?php foreach ($variantErrors as $error): ?>
              <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <h3><?= admin_te('shop.opties') ?></h3>
      <?php if ($options === []): ?>
        <p class="admin-text-muted"><?= admin_te('shop.opties_2') ?></p>
      <?php else: ?>
        <?php foreach ($options as $optIndex => $option): ?>
          <?php $optionId = (int) $option['id']; $optionDisplayType = (string) ($option['display_type'] ?? 'standard'); ?>
          <div class="admin-option-block">
            <div class="admin-option-block__head">
              <form method="post" action="/api/admin/update-product-option.php" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="option_id" value="<?= $optionId ?>">
                <input type="text" name="name" maxlength="100" value="<?= htmlspecialchars((string) $option['name'], ENT_QUOTES, 'UTF-8') ?>">
                <select name="display_type">
                  <option value="standard" <?= $optionDisplayType === 'standard' ? 'selected' : '' ?>><?= admin_te('shop.standaard') ?></option>
                  <option value="color" <?= $optionDisplayType === 'color' ? 'selected' : '' ?>><?= admin_te('shop.kleur') ?></option>
                </select>
                <button type="submit" class="admin-btn-text"><?= admin_te('common.save') ?></button>
              </form>
              <form method="post" action="/api/admin/move-product-option.php" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="option_id" value="<?= $optionId ?>">
                <input type="hidden" name="direction" value="up">
                <button type="submit" class="admin-btn-text" <?= $optIndex === 0 ? 'disabled' : '' ?>>&uarr;</button>
              </form>
              <form method="post" action="/api/admin/move-product-option.php" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="option_id" value="<?= $optionId ?>">
                <input type="hidden" name="direction" value="down">
                <button type="submit" class="admin-btn-text" <?= $optIndex === count($options) - 1 ? 'disabled' : '' ?>>&darr;</button>
              </form>
              <form method="post" action="/api/admin/delete-product-option.php" class="admin-inline-form" onsubmit="return confirm('Deze optie (met alle waardes) verwijderen?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="option_id" value="<?= $optionId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('shop.optie_verwijderen') ?></button>
              </form>
            </div>

            <ul class="admin-option-values">
              <?php foreach ($option['values'] as $valIndex => $value): ?>
                <?php $valueId = (int) $value['id']; $valueHex = (string) ($value['hex_color'] ?? '') ?: '#A77A49'; ?>
                <li>
                  <form method="post" action="/api/admin/update-product-option-value.php" class="admin-inline-form admin-option-value-form" data-color-sync-form>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="value_id" value="<?= $valueId ?>">
                    <input type="text" name="value" maxlength="100" value="<?= htmlspecialchars((string) $value['value'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($optionDisplayType === 'color'): ?>
                      <input type="color" value="<?= htmlspecialchars($valueHex, ENT_QUOTES, 'UTF-8') ?>" data-color-picker aria-label="Kleur">
                      <input type="text" name="hex_color" maxlength="7" placeholder="#A77A49" pattern="^#[0-9A-Fa-f]{6}$" value="<?= htmlspecialchars((string) ($value['hex_color'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-color-hex class="admin-hex-input">
                    <?php endif; ?>
                    <button type="submit" class="admin-btn-text"><?= admin_te('common.save') ?></button>
                  </form>
                  <form method="post" action="/api/admin/move-product-option-value.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="value_id" value="<?= $valueId ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="admin-btn-text" <?= $valIndex === 0 ? 'disabled' : '' ?>>&uarr;</button>
                  </form>
                  <form method="post" action="/api/admin/move-product-option-value.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="value_id" value="<?= $valueId ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="admin-btn-text" <?= $valIndex === count($option['values']) - 1 ? 'disabled' : '' ?>>&darr;</button>
                  </form>
                  <form method="post" action="/api/admin/delete-product-option-value.php" class="admin-inline-form" onsubmit="return confirm('Deze waarde verwijderen?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="value_id" value="<?= $valueId ?>">
                    <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>

            <form method="post" action="/api/admin/create-product-option-value.php" class="admin-inline-form" data-color-sync-form>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="option_id" value="<?= $optionId ?>">
              <input type="text" name="value" maxlength="100" placeholder="Nieuwe waarde, bijv. Berken">
              <?php if ($optionDisplayType === 'color'): ?>
                <input type="color" value="#A77A49" data-color-picker aria-label="Kleur">
                <input type="text" name="hex_color" maxlength="7" placeholder="#A77A49" pattern="^#[0-9A-Fa-f]{6}$" data-color-hex class="admin-hex-input">
              <?php endif; ?>
              <button type="submit"><?= admin_te('shop.waarde_toevoegen') ?></button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <form method="post" action="/api/admin/create-product-option.php" class="admin-form-row">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <label><?= admin_te('shop.nieuwe_optie_bijv_kleur') ?>
          <input type="text" name="name" maxlength="100" placeholder="Kleur">
        </label>
        <label><?= admin_te('shop.weergave') ?>
          <select name="display_type">
            <option value="standard" selected><?= admin_te('shop.standaard_2') ?></option>
            <option value="color"><?= admin_te('shop.kleur_2') ?></option>
          </select>
        </label>
        <button type="submit"><?= admin_te('shop.optie_toevoegen') ?></button>
      </form>

      <h3><?= admin_te('shop.combinaties_varianten') ?></h3>
      <?php if ($options === []): ?>
        <p class="admin-text-muted"><?= admin_te('shop.voeg_eerst_optie_waardes') ?></p>
      <?php else: ?>
        <?php if ($variants === []): ?>
          <p class="admin-text-muted"><?= admin_te('shop.varianten_2') ?></p>
        <?php else: ?>
          <div class="admin-variant-list">
            <?php foreach ($variants as $varIndex => $variant): ?>
              <?php
                $variantId = (int) $variant['id'];
                $variantActive = (int) $variant['active'] === 1;
                $variantLabel = implode(', ', array_map(
                    static fn (array $v): string => $v['option_name'] . ': ' . $v['value'],
                    $variant['values']
                ));
                $variantImages = $variant['images'];
              ?>
              <article class="admin-variant-panel">
                <div class="admin-variant-panel__head">
                  <strong><?= htmlspecialchars($variantLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                  <span class="admin-badge admin-badge--<?= $variantActive ? 'paid' : 'canceled' ?>">
                    <?= $variantActive ? admin_t('common.active') : 'Inactief' ?>
                  </span>
                </div>

                <form method="post" action="/api/admin/update-product-variant.php" class="admin-inline-form admin-variant-panel__form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                  <label><?= admin_t('shop.prijs_override_leeg_productprijs') ?>
                    <input type="text" inputmode="decimal" name="price" value="<?= $variant['price'] !== null ? htmlspecialchars(number_format((float) $variant['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="0.00">
                  </label>
                  <label class="admin-checkbox-label">
                    <input type="checkbox" name="active" value="1" <?= $variantActive ? 'checked' : '' ?>>
                    <?= admin_te('common.active') ?>
                  </label>
                  <button type="submit" class="admin-btn-text"><?= admin_te('common.save') ?></button>
                </form>

                <div class="admin-variant-panel__order">
                  <form method="post" action="/api/admin/move-product-variant.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="admin-btn-text" <?= $varIndex === 0 ? 'disabled' : '' ?>><?= admin_t('shop.variant') ?></button>
                  </form>
                  <form method="post" action="/api/admin/move-product-variant.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="admin-btn-text" <?= $varIndex === count($variants) - 1 ? 'disabled' : '' ?>><?= admin_t('shop.variant_2') ?></button>
                  </form>

                  <form method="post" action="/api/admin/delete-product-variant.php" class="admin-inline-form" onsubmit="return confirm('Deze variant verwijderen?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                    <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('shop.variant_verwijderen') ?></button>
                  </form>
                </div>

                <h4><?= admin_t('shop.photos_suffix', ['v1' => $varIndex === 0 ? ' <span class="admin-text-muted">(eerste variant = standaardweergave in de shop)</span>' : '']) ?></h4>

                <?php if ($variantImages === []): ?>
                  <p class="admin-text-muted"><?= admin_te('shop.foto_s_variant') ?></p>
                <?php else: ?>
                  <div class="admin-variant-image-grid" data-variant-image-grid data-variant-id="<?= $variantId ?>" data-reorder-url="/api/admin/reorder-variant-images.php" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <?php foreach ($variantImages as $imgIndex => $vImage): ?>
                      <?php $vImageId = (int) $vImage['id']; ?>
                      <div class="admin-variant-image-card" draggable="true" data-image-id="<?= $vImageId ?>">
                        <div class="admin-variant-image-card__media">
                          <img src="/<?= htmlspecialchars(ltrim((string) $vImage['image_path'], '/'), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                          <?php if ($imgIndex === 0): ?>
                            <span class="admin-image-card__primary-badge">Standaard</span>
                          <?php endif; ?>
                        </div>
                        <form method="post" action="/api/admin/update-variant-image.php" class="admin-variant-image-card__meta">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                          <input type="hidden" name="image_id" value="<?= $vImageId ?>">
                          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                          <input type="text" name="image_name" maxlength="255" placeholder="Naam" value="<?= htmlspecialchars((string) ($vImage['image_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                          <input type="text" name="alt_text" maxlength="255" placeholder="Alt-tekst" value="<?= htmlspecialchars((string) ($vImage['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                          <button type="submit" class="admin-btn-text"><?= admin_te('common.save') ?></button>
                        </form>
                        <form method="post" action="/api/admin/delete-variant-image.php" class="admin-inline-form" onsubmit="return confirm('Deze foto verwijderen?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                          <input type="hidden" name="image_id" value="<?= $vImageId ?>">
                          <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <form method="post" action="/api/admin/add-variant-images.php" enctype="multipart/form-data" class="admin-form-row">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                  <label><?= admin_te('shop.foto_s_toevoegen_kies') ?>
                    <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                  </label>
                  <button type="submit"><?= admin_te('common.add') ?></button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <h4><?= admin_te('shop.nieuwe_variant') ?></h4>
        <p class="admin-text-muted"><?= admin_te('shop.foto_s_voeg_na') ?></p>
        <form method="post" action="/api/admin/create-product-variant.php" class="admin-form-row">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
          <?php foreach ($options as $option): ?>
            <label><?= htmlspecialchars((string) $option['name'], ENT_QUOTES, 'UTF-8') ?>
              <select name="value_ids[<?= (int) $option['id'] ?>]" <?= $option['values'] === [] ? 'disabled' : '' ?>>
                <?php if ($option['values'] === []): ?>
                  <option value=""><?= admin_te('shop.waardes') ?></option>
                <?php else: ?>
                  <?php foreach ($option['values'] as $value): ?>
                    <option value="<?= (int) $value['id'] ?>"><?= htmlspecialchars((string) $value['value'], ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </label>
          <?php endforeach; ?>
          <label><?= admin_t('shop.prijs_override_leeg_productprijs_2') ?>
            <input type="text" inputmode="decimal" name="price" placeholder="0.00">
          </label>
          <label class="admin-checkbox-label">
            <input type="checkbox" name="active" value="1" checked>
            <?= admin_te('common.active') ?>
          </label>
          <button type="submit"><?= admin_te('shop.variant_aanmaken') ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
