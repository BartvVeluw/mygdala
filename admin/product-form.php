<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
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
        exit('Product kon niet worden geladen.');
    }

    if ($product === null) {
        http_response_code(404);
        exit('Product niet gevonden.');
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
$pageTitle = $isEdit ? 'Product bewerken' : 'Nieuw product';

// renderRichTextField() now lives in admin/_richtext_field.php, shared with
// admin/portfolio-item.php's Introtekst/Projectbeschrijving fields — its
// 'simple' toolbar default (bold/italic/link/unlink/clear only) keeps this
// call site's behaviour identical to before.
require __DIR__ . '/_richtext_field.php';
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/products.php">&larr; Terug naar producten</a></p>
  <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Product opgeslagen.</p>
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

      <div class="admin-form-row admin-form-row--split">
        <label>Naam (NL)*
          <input type="text" name="name" maxlength="150" required value="<?= htmlspecialchars(fieldValue($old, $product, 'name'), ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label>Naam (EN)
          <input type="text" name="name_en" maxlength="150" value="<?= htmlspecialchars(fieldValue($old, $product, 'name_en'), ENT_QUOTES, 'UTF-8') ?>">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php renderRichTextField('description', 'Beschrijving (NL)', fieldValue($old, $product, 'description')); ?>
        <?php renderRichTextField('description_en', 'Beschrijving (EN)', fieldValue($old, $product, 'description_en')); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Prijs (&euro;)*
          <input type="text" inputmode="decimal" name="price" required value="<?= htmlspecialchars($priceValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="0.00">
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="active" value="1" <?= $activeChecked ? 'checked' : '' ?>>
          Actief (publiek zichtbaar)
        </label>
      </div>

      <h3>Waar is dit product te koop?</h3>
      <div class="admin-form-row">
        <p class="admin-text-muted">
          "Actief" hierboven is de hoofdschakelaar: staat die uit, dan is het product nergens zichtbaar. Hieronder
          bepaal je in welke catalogus het staat. Een product kan in de shop staan, alleen bij Personaliseren, of in
          allebei.
        </p>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="in_shop" value="1" <?= $inShopChecked ? 'checked' : '' ?>>
          <span><strong>In de shop</strong> — zichtbaar in het shopoverzicht, op collectiepagina's en bij gerelateerde
          producten, en gewoon te bestellen.</span>
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="in_personalization_catalog" value="1" <?= $inPersonalizationChecked ? 'checked' : '' ?>>
          <span><strong>In de personalisatiecatalogus</strong> — zichtbaar op
          <a href="/personaliseren.php" target="_blank" rel="noopener">/personaliseren.php</a>, zodra dit product ook
          echt gepersonaliseerd kan worden (zie <a href="/admin/personalization.php">Personalisatie</a>).</span>
        </label>
        <?php if (!$inShopChecked && $isEdit): ?>
          <p class="admin-alert admin-alert--info">
            Dit product staat <strong>niet</strong> in de shop. Het is daarmee alleen te bestellen via een volledig
            ingevulde personalisatie — een klant kan het niet leeg in de winkelwagen leggen, en dat wordt ook
            server-side afgedwongen.
          </p>
        <?php endif; ?>
      </div>

      <h3>Collecties</h3>
      <div class="admin-form-row">
        <?php if ($allCollections === []): ?>
          <p class="admin-text-muted">Nog geen collecties. Maak er een aan via <a href="/admin/collections.php">Collecties</a> — een product hoeft niet in een collectie te zitten.</p>
        <?php else: ?>
          <p class="admin-text-muted">Optioneel. Dit product verschijnt op de collectiepagina van elke aangevinkte collectie en houdt altijd zijn eigen productpagina.</p>
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
              <?= htmlspecialchars((string) $collectionOption['name'], ENT_QUOTES, 'UTF-8') ?><?= (int) $collectionOption['is_active'] === 1 ? '' : ' (inactief)' ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <h3>Verzending</h3>
      <div class="admin-form-row admin-form-row--split">
        <label>Verzendprofiel*
          <select name="shipping_profile" required>
            <?php foreach (ShippingProfile::ALL as $profileValue): ?>
              <option value="<?= htmlspecialchars($profileValue, ENT_QUOTES, 'UTF-8') ?>" <?= $shippingProfileValue === $profileValue ? 'selected' : '' ?>>
                <?= htmlspecialchars(ShippingProfile::label($profileValue), ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Verzendgewicht in gram*
          <input type="text" inputmode="numeric" name="shipping_weight_grams" required value="<?= htmlspecialchars($shippingWeightValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="0">
        </label>
      </div>
      <div class="admin-form-row">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="requires_parcel" value="1" <?= $requiresParcelChecked ? 'checked' : '' ?>>
          Altijd als pakket verzenden (negeert het verzendprofiel hierboven zodra dit product in de bestelling zit)
        </label>
      </div>

      <?php if (!$isEdit): ?>
        <div class="admin-form-row">
          <label>Foto's (eerste foto wordt de hoofdfoto; volgorde/hoofdfoto later te wijzigen — alleen voor producten zonder varianten)
            <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
        </div>
      <?php endif; ?>

      <h3>SEO</h3>
      <?php /* Secondary to the product's own content and therefore last in
               the form: everything here is OPTIONAL. Leaving a field empty
               is not "no SEO" — it means the product's normal content is
               used automatically (App\Service\ProductSeo). Same two-column
               language layout, same field names and the same maxlengths as
               the CMS page editor's SEO card (admin/page.php), so the two
               screens teach each other. */ ?>
      <p class="admin-text-muted">
        Allemaal optioneel. Laat je een veld leeg, dan gebruikt de productpagina automatisch de gewone
        productinhoud: de SEO-titel wordt &ldquo;<em>Productnaam</em> | Shop &mdash; <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>&rdquo;,
        de meta description een korte platte-tekstversie van de productbeschrijving, en de deel-afbeelding de
        hoofdfoto van het product (of, bij varianten, de foto van de standaardvariant).
        Een Engels veld dat leeg blijft valt terug op het Nederlandse.
      </p>
      <div class="admin-seo-grid">
        <div class="admin-seo-lang">
          <h4 class="admin-seo-lang__title">Nederlands</h4>
          <div class="admin-form-row">
            <label>SEO-titel (NL)
              <input type="text" name="meta_title" maxlength="<?= Seo::MAX_META_TITLE_LENGTH ?>" data-char-count value="<?= htmlspecialchars(fieldValue($old, $product, 'meta_title'), ENT_QUOTES, 'UTF-8') ?>" placeholder="Leeg = automatische titel">
            </label>
          </div>
          <div class="admin-form-row">
            <label>Meta description (NL)
              <textarea name="meta_description" rows="3" maxlength="<?= Seo::MAX_META_DESCRIPTION_LENGTH ?>" data-char-count placeholder="Leeg = korte samenvatting van de beschrijving"><?= htmlspecialchars(fieldValue($old, $product, 'meta_description'), ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
          </div>
        </div>
        <div class="admin-seo-lang">
          <h4 class="admin-seo-lang__title">English</h4>
          <div class="admin-form-row">
            <label>SEO-titel (EN)
              <input type="text" name="meta_title_en" maxlength="<?= Seo::MAX_META_TITLE_LENGTH ?>" data-char-count value="<?= htmlspecialchars(fieldValue($old, $product, 'meta_title_en'), ENT_QUOTES, 'UTF-8') ?>" placeholder="Leeg = Nederlandse titel">
            </label>
          </div>
          <div class="admin-form-row">
            <label>Meta description (EN)
              <textarea name="meta_description_en" rows="3" maxlength="<?= Seo::MAX_META_DESCRIPTION_LENGTH ?>" data-char-count placeholder="Leeg = Nederlandse tekst"><?= htmlspecialchars(fieldValue($old, $product, 'meta_description_en'), ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
          </div>
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
          <label>Deel-afbeelding (social media)
            <input type="file" name="og_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          </label>
          <p class="admin-text-muted">Optioneel, en alleen zichtbaar als voorbeeld bij delen op social media (WhatsApp, Facebook, LinkedIn). Zonder eigen deel-afbeelding gebruikt de pagina automatisch de gewone productfoto.</p>
          <?php if ($ogImageValue !== ''): ?>
            <label class="admin-checkbox-label">
              <input type="checkbox" name="remove_og_image" value="1" <?= $removeOgImageChecked ? 'checked' : '' ?>>
              Deel-afbeelding verwijderen bij opslaan (terug naar de productfoto)
            </label>
          <?php endif; ?>
        </div>
      </div>

      <button type="submit"><?= $isEdit ? 'Opslaan' : 'Product aanmaken' ?></button>
    </form>
  </section>

  <?php if ($isEdit && $hasVariants): ?>
    <section class="admin-card">
      <h2>Foto's</h2>
      <p class="admin-text-muted">Dit product heeft varianten — foto's worden per variant beheerd (zie "Varianten" hieronder), niet op productniveau.</p>
    </section>
  <?php elseif ($isEdit): ?>
    <section class="admin-card">
      <h2>Foto's</h2>

      <?php if ($images === []): ?>
        <p class="admin-text-muted">Nog geen foto's voor dit product.</p>
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
                    <button type="submit" class="admin-btn-text">Maak hoofdfoto</button>
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
                  <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/api/admin/add-product-images.php" enctype="multipart/form-data" class="admin-form-row">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <label>Foto's toevoegen
          <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
        </label>
        <button type="submit">Toevoegen</button>
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
      <h2>Personalisatie</h2>
      <?php if ($personalization === null): ?>
        <p class="admin-text-muted">
          Dit product heeft geen personalisatie. Wil je dat klanten er zelf een naam, tekst of afbeelding op kunnen
          laten graveren? Voeg het product dan toe in
          <a href="/admin/personalization.php">Personalisatie</a>. Er wordt geen tweede product aangemaakt — de
          personalisatie wordt aan dit product gekoppeld.
        </p>
      <?php else: ?>
        <p class="admin-text-muted">
          Dit product is gepersonaliseerd
          (<?= (int) $personalization['settings']['is_enabled'] === 1 ? 'ingeschakeld' : 'nog uitgeschakeld' ?>).
          De voorbeeldafbeeldingen, zones en aankoopregels beheer je in de eigen sectie.
        </p>
        <p>
          <a class="admin-btn-link" href="/admin/personalization-product.php?product_id=<?= (int) $product['id'] ?>">
            Personalisatie beheren
          </a>
        </p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($isEdit): ?>
    <section class="admin-card">
      <h2>Varianten</h2>
      <p class="admin-text-muted">Optioneel. Voeg een optie toe (bijv. "Kleur") met waardes (bijv. "Noten", "Berken"), en maak daarna varianten aan als combinatie van die waardes. Een product zonder opties/varianten werkt precies als voorheen. Zodra een product varianten heeft, verdwijnt de gewone productfoto-sectie hierboven — foto's beheer je dan per variant, en de eerste variant (bovenaan) is de standaardvariant die in de shop en op de productpagina als eerste wordt getoond.</p>

      <?php if ($variantErrors !== []): ?>
        <div class="admin-alert admin-alert--error">
          <ul class="admin-error-list">
            <?php foreach ($variantErrors as $error): ?>
              <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <h3>Opties</h3>
      <?php if ($options === []): ?>
        <p class="admin-text-muted">Nog geen opties.</p>
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
                  <option value="standard" <?= $optionDisplayType === 'standard' ? 'selected' : '' ?>>Standaard</option>
                  <option value="color" <?= $optionDisplayType === 'color' ? 'selected' : '' ?>>Kleur</option>
                </select>
                <button type="submit" class="admin-btn-text">Opslaan</button>
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
                <button type="submit" class="admin-btn-text admin-btn-text--danger">Optie verwijderen</button>
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
                    <button type="submit" class="admin-btn-text">Opslaan</button>
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
                    <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
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
              <button type="submit">Waarde toevoegen</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <form method="post" action="/api/admin/create-product-option.php" class="admin-form-row">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <label>Nieuwe optie (bijv. Kleur, KM)
          <input type="text" name="name" maxlength="100" placeholder="Kleur">
        </label>
        <label>Weergave
          <select name="display_type">
            <option value="standard" selected>Standaard</option>
            <option value="color">Kleur</option>
          </select>
        </label>
        <button type="submit">Optie toevoegen</button>
      </form>

      <h3>Combinaties (varianten)</h3>
      <?php if ($options === []): ?>
        <p class="admin-text-muted">Voeg eerst een optie met waardes toe om varianten te kunnen maken.</p>
      <?php else: ?>
        <?php if ($variants === []): ?>
          <p class="admin-text-muted">Nog geen varianten.</p>
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
                    <?= $variantActive ? 'Actief' : 'Inactief' ?>
                  </span>
                </div>

                <form method="post" action="/api/admin/update-product-variant.php" class="admin-inline-form admin-variant-panel__form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                  <label>Prijs override (&euro;, leeg = productprijs)
                    <input type="text" inputmode="decimal" name="price" value="<?= $variant['price'] !== null ? htmlspecialchars(number_format((float) $variant['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="0.00">
                  </label>
                  <label class="admin-checkbox-label">
                    <input type="checkbox" name="active" value="1" <?= $variantActive ? 'checked' : '' ?>>
                    Actief
                  </label>
                  <button type="submit" class="admin-btn-text">Opslaan</button>
                </form>

                <div class="admin-variant-panel__order">
                  <form method="post" action="/api/admin/move-product-variant.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="admin-btn-text" <?= $varIndex === 0 ? 'disabled' : '' ?>>&uarr; Variant</button>
                  </form>
                  <form method="post" action="/api/admin/move-product-variant.php" class="admin-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="admin-btn-text" <?= $varIndex === count($variants) - 1 ? 'disabled' : '' ?>>&darr; Variant</button>
                  </form>

                  <form method="post" action="/api/admin/delete-product-variant.php" class="admin-inline-form" onsubmit="return confirm('Deze variant verwijderen?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                    <button type="submit" class="admin-btn-text admin-btn-text--danger">Variant verwijderen</button>
                  </form>
                </div>

                <h4>Foto's<?= $varIndex === 0 ? ' <span class="admin-text-muted">(eerste variant = standaardweergave in de shop)</span>' : '' ?></h4>

                <?php if ($variantImages === []): ?>
                  <p class="admin-text-muted">Nog geen foto's voor deze variant.</p>
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
                          <button type="submit" class="admin-btn-text">Opslaan</button>
                        </form>
                        <form method="post" action="/api/admin/delete-variant-image.php" class="admin-inline-form" onsubmit="return confirm('Deze foto verwijderen?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                          <input type="hidden" name="image_id" value="<?= $vImageId ?>">
                          <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <form method="post" action="/api/admin/add-variant-images.php" enctype="multipart/form-data" class="admin-form-row">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="variant_id" value="<?= $variantId ?>">
                  <label>Foto's toevoegen (kies er meerdere tegelijk)
                    <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                  </label>
                  <button type="submit">Toevoegen</button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <h4>Nieuwe variant</h4>
        <p class="admin-text-muted">Foto's voeg je na het aanmaken toe in de foto-sectie van de variant hierboven.</p>
        <form method="post" action="/api/admin/create-product-variant.php" class="admin-form-row">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
          <?php foreach ($options as $option): ?>
            <label><?= htmlspecialchars((string) $option['name'], ENT_QUOTES, 'UTF-8') ?>
              <select name="value_ids[<?= (int) $option['id'] ?>]" <?= $option['values'] === [] ? 'disabled' : '' ?>>
                <?php if ($option['values'] === []): ?>
                  <option value="">(nog geen waardes)</option>
                <?php else: ?>
                  <?php foreach ($option['values'] as $value): ?>
                    <option value="<?= (int) $value['id'] ?>"><?= htmlspecialchars((string) $value['value'], ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </label>
          <?php endforeach; ?>
          <label>Prijs override (&euro;, leeg = productprijs)
            <input type="text" inputmode="decimal" name="price" placeholder="0.00">
          </label>
          <label class="admin-checkbox-label">
            <input type="checkbox" name="active" value="1" checked>
            Actief
          </label>
          <button type="submit">Variant aanmaken</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
