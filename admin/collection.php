<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/_language_fields.php';

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\Csrf;
use App\Service\Seo;

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
        exit('Collectie kon niet worden geladen.');
    }

    if ($collection === null) {
        http_response_code(404);
        exit('Collectie niet gevonden.');
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

$slugValue = $old !== null
    ? (string) ($old['slug_input'] ?? '')
    : ($collection !== null ? (string) $collection['slug'] : '');

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

$csrfToken = Csrf::token();
$pageTitle = $isEdit ? (string) $collection['name'] : 'Nieuwe collectie';
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
<title><?= $h($pageTitle) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/collections-admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/collections.php">&larr; Terug naar collecties</a></p>
  <h1><?= $h($pageTitle) ?></h1>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Collectie aangemaakt.</p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Collectie opgeslagen.</p>
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
      <h2>Basisgegevens</h2>

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Naam*
          <input type="text" name="name" maxlength="150" <?= admin_lang_required('nl') ?> data-slug-source value="<?= $h(collectionFieldValue($old, $collection, 'name')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Naam
          <input type="text" name="name_en" maxlength="150" value="<?= $h(collectionFieldValue($old, $collection, 'name_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row">
        <label>Slug
          <input type="text" name="slug" maxlength="170" data-slug-target value="<?= $h($slugValue) ?>" placeholder="Leeg = automatisch gegenereerd uit de naam">
        </label>
        <?php if ($isEdit && (string) $collection['slug'] !== ''): ?>
          <p class="admin-text-muted">
            Publieke pagina:
            <a href="<?= $h(CollectionContent::publicPath((string) $collection['slug'])) ?>" target="_blank" rel="noopener"><?= $h(CollectionContent::publicPath((string) $collection['slug'])) ?></a>
            <?= (int) $collection['is_active'] === 1 ? '' : ' (nu niet zichtbaar — collectie staat op inactief)' ?>
          </p>
        <?php endif; ?>
      </div>

      <div class="admin-form-row">
        <?php admin_lang_pane_start('nl'); ?>
          <?php renderRichTextField('description', 'Beschrijving', collectionFieldValue($old, $collection, 'description'), 'full', 'admin-richtext-editor--md'); ?>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
          <?php renderRichTextField('description_en', 'Beschrijving', collectionFieldValue($old, $collection, 'description_en'), 'full', 'admin-richtext-editor--md'); ?>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_active" value="1" <?= $isActiveChecked ? 'checked' : '' ?>>
          Actief (zichtbaar op de shop-pagina en via de eigen collectiepagina)
        </label>
      </div>
    </section>

    <section class="admin-card">
      <h2>Afbeelding</h2>
      <?php if ($currentImagePath !== ''): ?>
        <div class="admin-image-card" style="max-width:220px;">
          <div class="admin-image-card__media">
            <img src="/<?= $h(ltrim($currentImagePath, '/')) ?>" alt="" loading="lazy">
          </div>
        </div>
        <div class="admin-form-row" style="margin-top:0.75rem;">
          <label>Vervangen door nieuw bestand (optioneel)
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
        </div>
      <?php else: ?>
        <p class="admin-text-muted">Nog geen afbeelding. Deze wordt gebruikt op de collectiekaart in de shop en bovenaan de collectiepagina.</p>
        <div class="admin-form-row">
          <label>Afbeelding (optioneel)
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-card">
      <h2>SEO</h2>
      <?php /* Identical field names, limits and fallback wording to the
               product editor's SEO card (admin/product-form.php) and to the
               CMS page editor's (admin/page.php) — one SEO vocabulary across
               the whole CMS. Everything here is optional; empty means "use
               the collection's own content", see
               App\Service\CollectionContent. */ ?>
      <p class="admin-text-muted">
        Allemaal optioneel. Laat je een veld leeg, dan gebruikt de collectiepagina automatisch de gewone inhoud:
        de SEO-titel wordt &ldquo;<em>Collectienaam</em> | Shop &mdash; <?= $h($siteName) ?>&rdquo;, de meta description
        een korte platte-tekstversie van de beschrijving, en de deel-afbeelding de collectie-afbeelding hierboven
        (of anders de foto van het eerste product in deze collectie).
        Een Engels veld dat leeg blijft valt terug op het Nederlandse.
      </p>
      <div class="admin-product-form admin-product-form--wide">
        <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_title') ?>
              <input type="text" name="meta_title" maxlength="<?= Seo::MAX_META_TITLE_LENGTH ?>" data-char-count value="<?= $h(collectionFieldValue($old, $collection, 'meta_title')) ?>" placeholder="Leeg = automatische titel">
            </label>
          </div>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_description') ?>
              <textarea name="meta_description" rows="3" maxlength="<?= Seo::MAX_META_DESCRIPTION_LENGTH ?>" data-char-count placeholder="Leeg = korte samenvatting van de beschrijving"><?= $h(collectionFieldValue($old, $collection, 'meta_description')) ?></textarea>
            </label>
          </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_title') ?>
              <input type="text" name="meta_title_en" maxlength="<?= Seo::MAX_META_TITLE_LENGTH ?>" data-char-count value="<?= $h(collectionFieldValue($old, $collection, 'meta_title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
          </div>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_description') ?>
              <textarea name="meta_description_en" rows="3" maxlength="<?= Seo::MAX_META_DESCRIPTION_LENGTH ?>" data-char-count<?= admin_lang_placeholder_attr('en') ?>><?= $h(collectionFieldValue($old, $collection, 'meta_description_en')) ?></textarea>
            </label>
          </div>
        <?php admin_lang_pane_end(); ?>
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
          <label>Deel-afbeelding (social media)
            <input type="file" name="og_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
          <p class="admin-text-muted">Optioneel, en alleen zichtbaar als voorbeeld bij delen op social media (WhatsApp, Facebook, LinkedIn). Zonder eigen deel-afbeelding gebruikt de pagina automatisch de collectie-afbeelding.</p>
          <?php if ($currentOgImagePath !== ''): ?>
            <label class="admin-checkbox-label">
              <input type="checkbox" name="remove_og_image" value="1" <?= $removeOgImageChecked ? 'checked' : '' ?>>
              Deel-afbeelding verwijderen bij opslaan (terug naar de collectie-afbeelding)
            </label>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="admin-card">
      <h2>Producten in deze collectie</h2>
      <p class="admin-text-muted">Vink aan welke producten in deze collectie horen. Aangevinkte producten staan bovenaan; sleep aan de <strong>&#10021;</strong>-greep om hun volgorde op de collectiepagina te bepalen. Een product mag in meerdere collecties zitten en blijft altijd zijn eigen productpagina houden.</p>

      <?php if ($products === []): ?>
        <p class="admin-text-muted">Nog geen producten in de catalogus. <a href="/admin/product-form.php">Maak eerst een product aan</a>.</p>
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
              $productName = (string) $productRow['name'];
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
                  <span class="admin-text-muted">&euro; <?= $h(number_format((float) $productRow['price'], 2, ',', '.')) ?></span>
                </span>
              </label>
              <?php if (!$productActive): ?>
                <span class="admin-badge admin-badge--muted" title="Dit product staat op inactief en is niet zichtbaar in de shop of op een collectiepagina.">Inactief</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="admin-text-muted" data-collection-product-empty hidden>Geen producten gevonden voor deze zoekopdracht.</p>
      <?php endif; ?>
    </section>

    <div class="admin-form-row">
      <button type="submit"><?= $isEdit ? 'Opslaan' : 'Collectie aanmaken' ?></button>
    </div>
  </form>

  <?php if ($isEdit): ?>
    <section class="admin-card">
      <h2>Collectie verwijderen</h2>
      <p class="admin-text-muted">Hiermee verdwijnt alleen de collectie zelf, haar afbeelding en de koppelingen met producten. <strong>De producten blijven volledig bestaan</strong>, inclusief hun eigen foto's en productpagina's.</p>
      <form method="post" action="/api/admin/delete-collection.php" onsubmit="return confirm('Weet je zeker dat je deze collectie definitief wilt verwijderen? De producten in deze collectie blijven gewoon bestaan.');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $collection['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger">Collectie verwijderen</button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
