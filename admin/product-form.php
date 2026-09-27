<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_admin_editor.php';
require_once __DIR__ . '/_admin_collapse.php';
require_once __DIR__ . '/_product_inventory.php';
require_once __DIR__ . '/_product_order_fields.php';

use App\Service\AdminAuth;
use App\Service\ShopLocalization;
use App\Service\Csrf;
use App\Service\ProductGalleryTransition;
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
$lockedVariantIds = [];
$productCollectionIds = [];
$personalization = null;

if ($isEdit) {
    try {
        $product = (new ProductRepository())->findByIdForAdmin($id);
        $images = (new ProductImageRepository())->findByProductId($id);
        $options = (new ProductOptionRepository())->findByProductId($id);
        $variants = (new ProductVariantRepository())->findByProductId($id);
        // An order points at these, so they cannot be removed (only switched off).
        $lockedVariantIds = (new ProductVariantRepository())->idsInOrders($id);
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

// What a save refused, when it was posted without the editor script (the
// PRG path of api/admin/update-product.php). With the script, a refused save
// never leaves this page: its messages come back in JSON and land next to
// their fields (admin/assets/admin-editor.js).
$errors = $_SESSION['admin_product_errors'] ?? [];
$old = $_SESSION['admin_product_old'] ?? null;
unset($_SESSION['admin_product_errors'], $_SESSION['admin_product_old']);

$updated = isset($_GET['updated']);
$created = isset($_GET['created']);

// The pictures section (admin/_product_gallery.php): the product's own pool
// in its order, and per variant the pictures it shows plus its own
// description in the language being edited. A refused save shows what was
// sent (App\Service\ProductGallery tokens), everything else what is stored.
// Adding a variant never hides a picture: it belongs to the product.
require_once __DIR__ . '/_product_gallery.php';
$galleryPictures = product_gallery_pictures(
    $images,
    $old !== null && isset($old['gallery']) && is_array($old['gallery']) ? $old['gallery'] : null
);

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

// How the product is sold (App\Service\PurchaseMode): a refused save's
// choice, else the stored one; a new product is sold directly.
$purchaseModeValue = $old !== null && array_key_exists('purchase_mode', $old)
    ? \App\Service\PurchaseMode::normalise($old['purchase_mode'])
    : \App\Service\PurchaseMode::normalise($product['purchase_mode'] ?? null);

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

// The gallery's transition: a refused save's choice, else the stored one;
// null follows the Shop. A new product starts following the Shop.
$galleryTransitionValue = $old !== null && array_key_exists('gallery_transition', $old)
    ? ProductGalleryTransition::normalise($old['gallery_transition'])
    : ProductGalleryTransition::normalise($product['gallery_transition'] ?? null);

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
// The share image from the library: what a refused save chose, else the
// stored one (null for none, and for an old own file shown beside the picker).
$shareMedia = \App\Service\Media\MediaService::find(
    $old !== null && array_key_exists('og_media_id', $old) ? (int) $old['og_media_id'] : (int) ($product['og_media_id'] ?? 0)
);

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
require_once __DIR__ . '/_product_variants.php';

// The product's stock (Shop Product & Ordering 2.0): whether it is tracked,
// and where it lives — the product's own for a product without variants,
// every variant's own otherwise (App\Service\Inventory\ProductStock). A new
// product has none yet: untracked, no variants.
$productStock = $isEdit
    ? (new \App\Service\Inventory\Inventory())->forProduct((int) $product['id'])
    : new \App\Service\Inventory\ProductStock(0, false, 0, []);
$stockTracked = $old !== null && array_key_exists('track_stock', $old) ? (bool) $old['track_stock'] : $productStock->tracked;

// The order questions (Shop Product & Ordering 2.0, "Bestelvelden"): the
// switch and every question with its choices, their words in the language
// being edited (no fallback: an empty field is an empty field). Only for an
// existing product; a new one gets them after its first save, like variants.
$orderFieldsEnabled = false;
$orderFieldRows = [];
if ($isEdit) {
    $orderFieldRepository = new \App\Repository\OrderFieldRepository();
    $orderFieldsEnabled = $orderFieldRepository->isEnabled((int) $product['id']);
    $storedOrderFields = $orderFieldRepository->fieldsForProduct((int) $product['id']);
    ShopLocalization::preloadOrderFields(array_column($storedOrderFields, 'id'));
    foreach ($storedOrderFields as $storedField) {
        ShopLocalization::preloadOrderFieldOptions(array_column($storedField['options'], 'id'));
        $orderFieldRows[] = [
            'id' => $storedField['id'],
            'type' => $storedField['field_type'],
            'required' => $storedField['is_required'],
            'max_length' => $storedField['max_length'],
            'label' => ShopLocalization::rawOrderField($storedField['id'], ShopLocalization::LABEL, $editingLanguage),
            'help' => ShopLocalization::rawOrderField($storedField['id'], ShopLocalization::HELP_TEXT, $editingLanguage),
            'options' => array_map(static fn (array $option): array => [
                'id' => $option['id'],
                'label' => ShopLocalization::rawOrderFieldOption($option['id'], $editingLanguage),
            ], $storedField['options']),
        ];
    }
}

// Per variant, what its row in the Varianten section shows of the pictures
// and its own description: a refused save's, else what is stored.
$variantGallery = [];
if ($isEdit) {
    \App\Service\ShopLocalization::preloadVariants(array_map(static fn (array $v): int => (int) $v['id'], $variants));
    foreach ($variants as $variant) {
        $variantId = (int) $variant['id'];
        $oldTokens = $old['variant_images'][$variantId] ?? null;
        $oldDescription = $old['variant_descriptions'][$variantId] ?? null;
        $ownDescription = \App\Service\ShopLocalization::variantOwnDescription($variantId, $editingLanguage);

        $variantGallery[$variantId] = [
            'tokens' => is_array($oldTokens)
                ? $oldTokens
                : array_map(static fn (array $image): string => 'image:' . (int) $image['id'], $variant['images']),
            'own' => is_array($oldDescription) ? (bool) $oldDescription['own'] : $ownDescription !== '',
            'html' => is_array($oldDescription) ? (string) $oldDescription['html'] : $ownDescription,
        ];
    }
}

// The two big sections fold, each on its own. They start open, the editor's
// browser tab remembers how they were left (admin/assets/admin-collapse.js),
// and a refused save opens them again, so no message can hide in a closed one.
$sectionForcedOpen = $errors !== [] ? ' data-admin-collapse-open' : '';
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
<?php media_picker_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/product-gallery.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/product-variants.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/product-inventory.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/product-order-fields.js') ?>" defer></script>
<?php admin_collapse_script(); ?>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/products.php"><?= admin_t('shop.terug_producten') ?></a></p>
  <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('shop.product_aangemaakt') ?> <?= admin_te('shop.editor.created_hint') ?></p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('shop.product_opgeslagen') ?></p>
  <?php endif; ?>

  <?= admin_editor_summary($errors) ?>

  <?php /* ONE form for the whole product, stored by its one Opslaan in the
           bar at the bottom (admin/_admin_editor.php): the general fields,
           the pictures, the options and variants, the SEO card. The editor
           script sends it without a page load and keeps what is typed when
           the server refuses it; the endpoint says which field and which
           section, and the section opens. Folding a section is not a change. */ ?>
  <form method="post" action="/api/admin/<?= $isEdit ? 'update-product.php' : 'create-product.php' ?>" enctype="multipart/form-data" class="admin-product-editor" id="product-form"
        data-admin-editor data-admin-collapse-group="product-editor" data-admin-collapse-scope="product" data-admin-collapse-no-return<?= $errors !== [] ? ' data-admin-editor-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
    <?php endif; ?>

    <section class="admin-card admin-product-form" data-admin-editor-section="product">
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
          <input type="checkbox" class="admin-checkbox" name="active" value="1" <?= $activeChecked ? 'checked' : '' ?>>
          <?= admin_te('shop.actief_publiek_zichtbaar') ?>
        </label>
      </div>

      <?php /* Direct bestellen or Op aanvraag (Shop Product & Ordering 2.0):
               an "op aanvraag" product is shown without a price, a quantity
               or a cart anywhere, and the server keeps it out of the cart. */ ?>
      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('product-purchase-mode', admin_t('shop.purchase_mode.label'), admin_t('help.shop.purchase_mode')) ?>
          <select class="admin-select" id="product-purchase-mode" name="purchase_mode">
            <?php foreach (\App\Service\PurchaseMode::ALL as $modeOption): ?>
              <option value="<?= htmlspecialchars($modeOption, ENT_QUOTES, 'UTF-8') ?>"<?= $purchaseModeValue === $modeOption ? ' selected' : '' ?>><?= admin_te('shop.purchase_mode.' . $modeOption) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <h3><?= admin_te('shop.waar_product_koop') ?></h3>
      <div class="admin-form-row">
        <p class="admin-text-muted">
          <?= admin_te('shop.actief_hierboven_hoofdschakelaar_staat') ?>
        </p>
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-checkbox" name="in_shop" value="1" <?= $inShopChecked ? 'checked' : '' ?>>
          <span><strong><?= admin_t('shop.shop_zichtbaar_shopoverzicht_collectiepagina') ?></span>
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-checkbox" name="in_personalization_catalog" value="1" <?= $inPersonalizationChecked ? 'checked' : '' ?>>
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
              <input type="checkbox" class="admin-checkbox"
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
          <input type="checkbox" class="admin-checkbox" name="requires_parcel" value="1" <?= $requiresParcelChecked ? 'checked' : '' ?>>
          <?= admin_te('shop.altijd_pakket_verzenden_negeert') ?>
        </label>
      </div>
    </section>

    <?php /* Voorraad (Shop Product & Ordering 2.0): off is unlimited, as
             every product was before. A region, so a save draws it again
             with the stock as it is then (a sale may have changed it). */ ?>
    <section class="admin-card admin-editor-section" data-admin-editor-section="inventory">
      <details class="admin-collapse admin-collapse--card" id="product-inventory-section" data-admin-collapse-id="inventory" open<?= $sectionForcedOpen ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <h2 class="admin-collapse__title"><?= admin_te('shop.stock.heading') ?></h2>
        </summary>
        <div class="admin-collapse__body">
          <div data-admin-editor-region="inventory">
            <?php product_inventory_section($productStock, $old); ?>
          </div>
        </div>
      </details>
    </section>

    <?php /* The product's own pictures, and nothing else: which of them a
             variant shows is part of that variant, in Varianten below. */ ?>
    <section class="admin-card admin-editor-section" data-admin-editor-section="images">
      <details class="admin-collapse admin-collapse--card" id="product-images-section" data-admin-collapse-id="images" open<?= $sectionForcedOpen ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <h2 class="admin-collapse__title"><?= admin_te('shop.gallery.heading') ?></h2>
          <span class="admin-collapse__badges">
            <span class="admin-badge" title="<?= admin_te('shop.editor.images_count') ?>"><span data-product-gallery-count><?= count($galleryPictures) ?></span><span class="admin-visually-hidden"> <?= admin_te('shop.editor.images_count') ?></span></span>
          </span>
        </summary>
        <div class="admin-collapse__body">
          <div class="admin-alert admin-alert--error" data-admin-editor-errors="images" hidden></div>
          <div data-admin-editor-region="images">
            <?php product_gallery_pool($galleryPictures); ?>
          </div>

          <?php /* How the product page changes picture: the Shop's default
                   (an empty value, stored as NULL, so it follows a later
                   change there) or this product's own choice
                   (App\Service\ProductGalleryTransition). Part of the one
                   save; outside the region, because nothing about it
                   changes when the server draws the pictures again. */ ?>
          <div class="admin-form-row admin-form-row--split">
            <div class="admin-field">
              <?= admin_field_label('product-gallery-transition', admin_t('shop.gallery_transition.product_label'), admin_t('help.shop.gallery_transition.product')) ?>
              <select class="admin-select" id="product-gallery-transition" name="gallery_transition">
                <option value=""<?= $galleryTransitionValue === null ? ' selected' : '' ?>><?= admin_te('shop.gallery_transition.inherit', ['default' => admin_t('shop.gallery_transition.' . ProductGalleryTransition::shopDefault())]) ?></option>
                <?php foreach (ProductGalleryTransition::ALL as $transitionOption): ?>
                  <option value="<?= htmlspecialchars($transitionOption, ENT_QUOTES, 'UTF-8') ?>"<?= $galleryTransitionValue === $transitionOption ? ' selected' : '' ?>><?= admin_te('shop.gallery_transition.' . $transitionOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </details>
    </section>

    <section class="admin-card admin-editor-section" data-admin-editor-section="variants">
      <details class="admin-collapse admin-collapse--card" id="product-variants-section" data-admin-collapse-id="variants" open<?= $sectionForcedOpen ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <h2 class="admin-collapse__title"><?= admin_te('shop.varianten') ?></h2>
          <span class="admin-collapse__badges">
            <span class="admin-badge" title="<?= admin_te('shop.editor.variants_count') ?>"><span data-product-variants-count><?= count($variants) ?></span><span class="admin-visually-hidden"> <?= admin_te('shop.editor.variants_count') ?></span></span>
          </span>
        </summary>
        <div class="admin-collapse__body">
          <?php if ($isEdit): ?>
            <div data-admin-editor-region="variants">
              <?php product_variants_section($options, $variants, $galleryPictures, $variantGallery, $lockedVariantIds, $stockTracked); ?>
            </div>
          <?php else: ?>
            <?php /* A product needs its id before an option or a variant can
                     hang off it: saving this form creates it and opens its
                     own editor, where this section is complete. */ ?>
            <p class="admin-text-muted"><?= admin_te('shop.editor.variants_after_create') ?></p>
          <?php endif; ?>
        </div>
      </details>
    </section>

    <?php /* Bestelvelden (Shop Product & Ordering 2.0): the questions a
             customer answers before the product goes in the cart — "Naam op
             het bord". A region, so a save draws the rows again with ids. */ ?>
    <section class="admin-card admin-editor-section" data-admin-editor-section="order_fields">
      <details class="admin-collapse admin-collapse--card" id="product-order-fields-section" data-admin-collapse-id="order-fields" open<?= $sectionForcedOpen ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <h2 class="admin-collapse__title"><?= admin_te('shop.order_fields.heading') ?></h2>
          <span class="admin-collapse__badges">
            <span class="admin-badge" title="<?= admin_te('shop.order_fields.count') ?>"><?= count($orderFieldRows) ?><span class="admin-visually-hidden"> <?= admin_te('shop.order_fields.count') ?></span></span>
          </span>
        </summary>
        <div class="admin-collapse__body">
          <?php if ($isEdit): ?>
            <div data-admin-editor-region="order-fields">
              <?php product_order_fields_section($orderFieldsEnabled, $orderFieldRows); ?>
            </div>
          <?php else: ?>
            <p class="admin-text-muted"><?= admin_te('shop.order_fields.after_create') ?></p>
          <?php endif; ?>
        </div>
      </details>
    </section>

    <section class="admin-card" data-admin-editor-section="seo">
      <h2><?= admin_te('shop.seo') ?></h2>
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
        <?php /* The share image is a Media Library image (MediaType::SOCIAL_IMAGE:
                 raster only). One from before the library stays, shown here,
                 until another is chosen or it is removed on purpose
                 (api/admin/_shop_share_image.php). */ ?>
        <?php if ($ogImageValue !== '' && $shareMedia === null): ?>
          <div class="admin-image-card admin-seo-image__preview">
            <div class="admin-image-card__media">
              <img src="/<?= htmlspecialchars(ltrim($ogImageValue, '/'), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
            </div>
          </div>
        <?php endif; ?>
        <div class="admin-seo-image__fields">
          <?php media_picker_field('og_media_id', $shareMedia, admin_t('shop.deel_afbeelding_social_media'), admin_t('shop.optioneel_alleen_zichtbaar_voorbeeld'), true, \App\Service\Media\MediaType::SOCIAL_IMAGE); ?>
          <?php if ($ogImageValue !== '' && $shareMedia === null): ?>
            <p class="admin-text-muted"><?= admin_te('shop.share_image_legacy') ?></p>
            <label class="admin-checkbox-label">
              <input type="checkbox" class="admin-checkbox" name="remove_og_image" value="1" <?= $removeOgImageChecked ? 'checked' : '' ?>>
              <?= admin_te('shop.deel_afbeelding_verwijderen_opslaan') ?>
            </label>
          <?php endif; ?>
        </div>
      </div>

      <?php /* For a browser without the editor script only: with it, the bar's
               Opslaan is the one button (and Enter still saves). */ ?>
      <button type="submit" data-admin-editor-fallback><?= $isEdit ? admin_te('common.save') : admin_te('shop.create_product') ?></button>
    </section>
  </form>

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

</main>
<?php admin_editor_bar(); ?>
<?= admin_editor_leave_dialog() ?>
<?php media_picker_modal(); ?>
<?php admin_editor_script(); ?>
</body>
</html>
