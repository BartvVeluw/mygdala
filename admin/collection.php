<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_localized_fields.php';

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\Csrf;
use App\Service\Seo;
use App\Service\ShopLocalization;

AdminAuth::requireLogin();
AdminAuth::requirePermission('collections.manage');

/**
 * One screen for both creating and editing a collection — no ?id= means
 * "new", ?id=N means "edit", the same convention admin/portfolio-item.php
 * and admin/page.php already use.
 *
 * Everything a collection owns is saved by the one form: name, slug,
 * description, image, published state, AND its product membership + order.
 * The product picker is a single searchable list of every catalog product
 * (checkbox per row, selected ones sorted to the top with a drag handle),
 * so the submitted `product_ids[]` array arrives in exactly the order the
 * rows are in — which is what becomes collection_products.sort_order. That
 * keeps working without JavaScript: the checkboxes are ordinary form
 * controls and PHP renders the selected rows first, so a no-JS save
 * preserves the existing order instead of scrambling it.
 */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== null && $id !== false && $id >= 1;

$collectionRepository = new CollectionRepository();
$collection = null;
$selectedProductIds = [];

if ($isEdit) {
    try {
        $collection = $collectionRepository->findById($id);
        $selectedProductIds = $collection !== null ? $collectionRepository->productIdsForCollection($id) : [];
    } catch (\Throwable $e) {
        error_log('[admin/collection.php] ' . $e->getMessage());
        http_response_code(500);
        exit(admin_t('screen.collectie_kon_geladen'));
    }

    if ($collection === null) {
        http_response_code(404);
        exit(admin_t('screen.collectie_gevonden'));
    }
}

try {
    $products = (new ProductRepository())->findAllForAdmin();

    // Same thumbnail rule as admin/products.php: a product with variants
    // shows its default variant's first image rather than its own
    // product-level photo, so the picker shows what the shop shows.
    $variantRepository = new ProductVariantRepository();
    foreach ($products as &$productRow) {
        $defaultVariant = $variantRepository->findDefaultForProduct((int) $productRow['id']);
        if ($defaultVariant !== null) {
            $productRow['image_path'] = $defaultVariant['images'][0]['image_path'] ?? null;
        }
    }
    unset($productRow);
} catch (\Throwable $e) {
    error_log('[admin/collection.php] ' . $e->getMessage());
    $products = [];
}

$errors = $_SESSION['admin_collection_errors'] ?? [];
$old = $_SESSION['admin_collection_old'] ?? null;
unset($_SESSION['admin_collection_errors'], $_SESSION['admin_collection_old']);

$created = isset($_GET['created']);
$updated = isset($_GET['updated']);

/**
 * Value precedence: freshly re-submitted (invalid) input, then the stored
 * collection (edit), then a sane default — identical helper to
 * admin/product-form.php's.
 */
function collectionFieldValue(?array $old, ?array $collection, string $key, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    if ($collection !== null && array_key_exists($key, $collection)) {
        return (string) ($collection[$key] ?? '');
    }

    return $default;
}

/**
 * A collection's WORDS, in the one website language this screen is editing
 * (Multilingual 2.0 phase 5 wave C): the refused POST first, so a rejected
 * save keeps what was typed, then what is stored FOR THAT LANGUAGE with no
 * fallback — the fallback is the placeholder.
 */
function collectionWord(?array $old, ?int $collectionId, string $field, string $language): string
{
    if ($old !== null && array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }

    return $collectionId === null ? '' : ShopLocalization::rawCollection($collectionId, $field, $language);
}

$isActiveChecked = $old !== null
    ? !empty($old['is_active'])
    : ($collection !== null ? (int) $collection['is_active'] === 1 : true);

if ($old !== null && array_key_exists('product_ids', $old)) {
    $selectedProductIds = array_map('intval', (array) $old['product_ids']);
}

// Selected products first, in the collection's own order, then the rest in
// the catalog's order. This ordering IS the form's meaning, so it is built
// server-side rather than left to the browser.
$productsById = [];
foreach ($products as $productRow) {
    $productsById[(int) $productRow['id']] = $productRow;
}

$orderedProducts = [];
foreach ($selectedProductIds as $selectedId) {
    if (isset($productsById[$selectedId])) {
        $orderedProducts[] = $productsById[$selectedId];
        unset($productsById[$selectedId]);
    }
}
foreach ($productsById as $productRow) {
    $orderedProducts[] = $productRow;
}

// Every picker row's product name in one query rather than one per row. The
// picker names a product the way the rest of the CMS does, in the default
// language — a product is the same product in every language, and ticking it
// is not a translation.
ShopLocalization::preloadProducts(array_map(
    static fn (array $productRow): int => (int) $productRow['id'],
    $orderedProducts
));

$csrfToken = Csrf::token();
// The website language this screen's words are in, and the id they hang
// off. ONE language on the screen and in the request.
$editingLanguage = admin_localized_language();
$collectionId = $isEdit ? (int) $collection['id'] : null;

/**
 * A refused save's WORDS and ADDRESS only come back on a form showing the
 * language they were typed in, as on admin/page.php and the two blog taxonomy
 * screens: an editor who has moved to another language since sees that
 * language's stored text, not what was just refused in another one. A new
 * collection is always written in the default language, so everything handed
 * back to the new-collection form is its own. The fields that belong to no
 * language — active, products, social image — keep using $old.
 */
$oldWords = ($old !== null && (!$isEdit || ($old['language_code'] ?? null) === $editingLanguage)) ? $old : null;

/**
 * THE COLLECTION'S ADDRESS IN THE LANGUAGE BEING EDITED (Multilingual 2.0
 * phase 6, docs/multilingual/ROUTING.md): a refused save's own input first,
 * then this language's stored address, and '' when it has none — which means
 * this language has no public URL for the collection yet, a real and
 * ordinary state.
 *
 * Only the default language's address is required: it is the one kept
 * byte-identical to the neutral `collections.slug`.
 */
$slugValue = $oldWords !== null
    ? (string) ($oldWords['slug_input'] ?? '')
    : ($collection !== null ? (string) (ShopLocalization::collectionSlug($collection, $editingLanguage) ?? '') : '');

/** The path this collection can be visited at in the language being edited. */
$publicPath = $collection === null
    ? null
    : (static function (array $row, string $language): ?string {
        $slug = ShopLocalization::collectionSlug($row, $language);

        return $slug === null ? null : CollectionContent::publicPath($slug, $language);
    })($collection, $editingLanguage);

$pageTitle = $isEdit
    ? ShopLocalization::collectionName((int) $collection['id'])
    : admin_t('shop.new_collection');
$currentImagePath = $collection !== null ? (string) ($collection['image_path'] ?? '') : '';

// The SEO card's social image, and the "remove it on save" tick — which
// survives a failed save like every other field on this form.
$currentOgImagePath = $collection !== null ? (string) ($collection['og_image_path'] ?? '') : '';
$removeOgImageChecked = $old !== null && !empty($old['remove_og_image']);

// Only used in the SEO card's help text, to spell the automatic title
// fallback out for the administrator — the fallback itself lives in
// App\Service\CollectionContent, never here.
$siteName = \App\Service\SiteSettings::get('site_name');

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

require __DIR__ . '/_richtext_field.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> <?= admin_te('shop.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/collections-admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/collections.php"><?= admin_t('shop.terug_collecties') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('shop.collectie_aangemaakt') ?></p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('shop.collectie_opgeslagen') ?></p>
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

  <form method="post" action="/api/admin/<?= $isEdit ? 'update-collection.php' : 'create-collection.php' ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= (int) $collection['id'] ?>">
    <?php endif; ?>

    <section class="admin-card">
      <h2><?= admin_te('shop.basisgegevens') ?></h2>

      <?= admin_localized_input($editingLanguage) ?>
      <?php admin_localized_bar($editingLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.name') ?><?= admin_localized_required($editingLanguage) === '' ? '' : '*' ?>
          <input type="text" name="name" maxlength="<?= ShopLocalization::NAME_MAX_LENGTH ?>"<?= admin_localized_required($editingLanguage) ?> data-slug-source value="<?= $h(collectionWord($oldWords, $collectionId, ShopLocalization::NAME, $editingLanguage)) ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <?php /* Not `required`, in any language: blank means "make one from
                 the name" in the default language, and "no public URL in this
                 language yet" in a translation — see the endpoint. */ ?>
        <label><?= admin_te('shop.slug') ?>
          <input type="text" name="slug" maxlength="<?= ShopLocalization::SLUG_MAX_LENGTH ?>" data-slug-target value="<?= $h($slugValue) ?>" placeholder="Leeg = automatisch gegenereerd uit de naam">
        </label>
        <?php if ($isEdit && $publicPath !== null): ?>
          <p class="admin-text-muted">
            <?= admin_te('shop.publieke_pagina') ?>
            <a href="<?= $h($publicPath) ?>" target="_blank" rel="noopener"><?= $h($publicPath) ?></a>
            <?= (int) $collection['is_active'] === 1 ? '' : ' (nu niet zichtbaar — collectie staat op inactief)' ?>
          </p>
        <?php elseif ($isEdit): ?>
          <p class="admin-text-muted"><?= admin_te('shop.collection_url_none_in_language') ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-form-row">
        <?php renderRichTextField('description', 'Beschrijving', collectionWord($oldWords, $collectionId, ShopLocalization::DESCRIPTION, $editingLanguage), 'full', 'admin-richtext-editor--md'); ?>
      </div>

      <div class="admin-form-row">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_active" value="1" <?= $isActiveChecked ? 'checked' : '' ?>>
          <?= admin_te('shop.actief_zichtbaar_shop_pagina') ?>
        </label>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('common.image') ?></h2>
      <?php if ($currentImagePath !== ''): ?>
        <div class="admin-image-card" style="max-width:220px;">
          <div class="admin-image-card__media">
            <img src="/<?= $h(ltrim($currentImagePath, '/')) ?>" alt="" loading="lazy">
          </div>
        </div>
        <div class="admin-form-row" style="margin-top:0.75rem;">
          <label><?= admin_te('shop.vervangen_door_nieuw_bestand') ?>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
        </div>
      <?php else: ?>
        <p class="admin-text-muted"><?= admin_te('shop.afbeelding_gebruikt_collectiekaart_shop') ?></p>
        <div class="admin-form-row">
          <label><?= admin_te('shop.afbeelding_optioneel') ?>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('shop.seo') ?></h2>
      <?php /* Identical field names, limits and fallback wording to the
               product editor's SEO card (admin/product-form.php) and to the
               CMS page editor's (admin/page.php) — one SEO vocabulary across
               the whole CMS. Everything here is optional; empty means "use
               the collection's own content", see
               App\Service\CollectionContent. */ ?>
      <p class="admin-text-muted">
        <?= admin_t('shop.allemaal_optioneel_laat_veld', ['v1' => $h($siteName)]) ?>
      </p>
      <div class="admin-product-form admin-product-form--wide">
        <div class="admin-form-row">
          <label><?= admin_te('page.meta_title') ?>
            <input type="text" name="meta_title" maxlength="<?= Seo::MAX_META_TITLE_LENGTH ?>" data-char-count value="<?= $h(collectionWord($oldWords, $collectionId, ShopLocalization::META_TITLE, $editingLanguage)) ?>" placeholder="Leeg = automatische titel">
          </label>
        </div>
        <div class="admin-form-row">
          <label><?= admin_te('page.meta_description') ?>
            <textarea name="meta_description" rows="3" maxlength="<?= Seo::MAX_META_DESCRIPTION_LENGTH ?>" data-char-count placeholder="Leeg = korte samenvatting van de beschrijving"><?= $h(collectionWord($oldWords, $collectionId, ShopLocalization::META_DESCRIPTION, $editingLanguage)) ?></textarea>
          </label>
        </div>
      </div>

      <div class="admin-form-row admin-seo-image">
        <?php if ($currentOgImagePath !== ''): ?>
          <div class="admin-image-card admin-seo-image__preview">
            <div class="admin-image-card__media">
              <img src="/<?= $h(ltrim($currentOgImagePath, '/')) ?>" alt="" loading="lazy">
            </div>
          </div>
        <?php endif; ?>
        <div class="admin-seo-image__fields">
          <label><?= admin_te('shop.deel_afbeelding_social_media') ?>
            <input type="file" name="og_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
          <p class="admin-text-muted"><?= admin_te('shop.optioneel_alleen_zichtbaar_voorbeeld') ?></p>
          <?php if ($currentOgImagePath !== ''): ?>
            <label class="admin-checkbox-label">
              <input type="checkbox" name="remove_og_image" value="1" <?= $removeOgImageChecked ? 'checked' : '' ?>>
              <?= admin_te('shop.deel_afbeelding_verwijderen_opslaan') ?>
            </label>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('shop.producten_collectie') ?></h2>
      <p class="admin-text-muted"><?= admin_t('shop.vink_welke_producten_collectie') ?></p>

      <?php if ($products === []): ?>
        <p class="admin-text-muted"><?= admin_t('shop.producten_catalogus_maak_eerst') ?></p>
      <?php else: ?>
        <?php
          // Marks "the picker was actually rendered, so the absence of a
          // product_ids[] value genuinely means 'none selected'". Without it,
          // a save made while this list could not be built (an empty
          // catalogue, or a failed product query above) would look identical
          // to unticking everything and would silently empty the collection.
          // api/admin/update-collection.php only synchronises membership when
          // this is present.
        ?>
        <input type="hidden" name="products_submitted" value="1">

        <div class="admin-collection-picker-toolbar">
          <input type="search" placeholder="Zoek op productnaam…" aria-label="Zoek op productnaam" data-collection-product-search>
          <span class="admin-text-muted" data-collection-product-count></span>
        </div>

        <div class="admin-page-sections" data-collection-product-list>
          <?php foreach ($orderedProducts as $productRow): ?>
            <?php
              $productId = (int) $productRow['id'];
              $isSelected = in_array($productId, $selectedProductIds, true);
              $productName = ShopLocalization::productName($productId);
              $productActive = (int) $productRow['active'] === 1;
              $productImage = (string) ($productRow['image_path'] ?? '');
            ?>
            <div class="admin-section-row admin-collection-product-row<?= $isSelected ? ' is-selected' : '' ?>"
                 data-collection-product-row
                 data-id="<?= $productId ?>"
                 data-name="<?= $h(mb_strtolower($productName)) ?>">
              <span class="admin-drag-handle" data-collection-product-handle title="Sleep om te herordenen" aria-hidden="true">&#10021;</span>
              <label class="admin-checkbox-label admin-collection-product-row__pick">
                <input type="checkbox" name="product_ids[]" value="<?= $productId ?>" <?= $isSelected ? 'checked' : '' ?> data-collection-product-checkbox>
                <span class="admin-collection-product-row__thumb">
                  <?php if ($productImage !== ''): ?>
                    <img src="/<?= $h(ltrim($productImage, '/')) ?>" alt="" loading="lazy">
                  <?php endif; ?>
                </span>
                <span class="admin-section-row__body">
                  <span class="admin-section-row__name"><?= $h($productName) ?></span>
                  <span class="admin-text-muted"><?= admin_t('shop.amount_with', ['v1' => $h(number_format((float) $productRow['price'], 2, ',', '.'))]) ?></span>
                </span>
              </label>
              <?php if (!$productActive): ?>
                <span class="admin-badge admin-badge--muted" title="Dit product staat op inactief en is niet zichtbaar in de shop of op een collectiepagina.">Inactief</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="admin-text-muted" data-collection-product-empty hidden><?= admin_te('shop.producten_gevonden_zoekopdracht') ?></p>
      <?php endif; ?>
    </section>

    <div class="admin-form-row">
      <button type="submit"><?= $isEdit ? 'Opslaan' : admin_t('shop.create_collection') ?></button>
    </div>
  </form>

  <?php if ($isEdit): ?>
    <section class="admin-card">
      <h2><?= admin_te('shop.collectie_verwijderen') ?></h2>
      <p class="admin-text-muted"><?= admin_t('shop.hiermee_verdwijnt_alleen_collectie') ?></p>
      <form method="post" action="/api/admin/delete-collection.php" onsubmit="return confirm('Weet je zeker dat je deze collectie definitief wilt verwijderen? De producten in deze collectie blijven gewoon bestaan.');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $collection['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('shop.collectie_verwijderen_2') ?></button>
      </form>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
