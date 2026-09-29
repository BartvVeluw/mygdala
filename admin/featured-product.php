<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This screen belongs to the Shop. It is guarded like every block editor, by
// the permission of the list the block is on (ContentBlockAccess: pages.manage
// for a page, a Core permission held while the Shop is off), so the Shop
// itself is checked first: with the Shop off there is no product to choose
// and no block to edit (App\Module\ModuleGuard).
\App\Module\ModuleGuard::requireAdmin('shop');

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_admin_collapse.php';
require_once __DIR__ . '/_editor_rows.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Repository\FeaturedProductRepository;
use App\Repository\ProductRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\FeaturedProductContent;
use App\Service\PurchaseMode;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;

/**
 * Editor for one Uitgelicht product block (?section=<page content_key>:<section_key>):
 * which product, and how this block shows it. The same "valid only when the
 * page and its content row really exist" gate as admin/media-banner.php.
 *
 * THE PRODUCT IS CHOSEN, NEVER COPIED. The list is every product of the
 * catalogue (the collection editor's product rows, with one radio instead of
 * a checkbox, and the same search); what is stored is the product's id. Its
 * name, price, pictures, variants and stock are read live on the page
 * (App\Service\FeaturedProductContent), so nothing here needs updating when
 * the product changes. Saving without a product is allowed: a block may be
 * placed first and set up later, and shows nothing until then. A product that
 * is not active is said so, since the block cannot show it.
 *
 * SIX FOLDING CARDS (admin/_admin_collapse.php): Product, Inhoud, Afbeeldingen,
 * Bestellen, Productknop and Uitlijning. One form, one save, the save bar.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (admin/_localized_fields.php): the intro and
 * the button's label are words of the language chosen in the CMS shell, both
 * optional; everything else is the same in every language. Whether the block
 * shows is the page builder's eye, like for every block; this screen has no
 * second switch for it.
 */

AdminAuth::requireLogin();
\App\Service\ContentOwners\ContentBlockAccess::requireAny();

$sectionParam = (string) ($_GET['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new FeaturedProductRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : \App\Service\ContentOwners\ContentBlockAccess::pageForKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$editLanguage = admin_localized_language();

$errors = $_SESSION['admin_featured_product_errors'] ?? [];
$fieldErrors = $_SESSION['admin_featured_product_field_errors'] ?? [];
$old = $_SESSION['admin_featured_product_old'] ?? null;
unset($_SESSION['admin_featured_product_errors'], $_SESSION['admin_featured_product_field_errors'], $_SESSION['admin_featured_product_old']);
$saved = isset($_GET['saved']);

$sectionId = (int) $section['id'];
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The words of one field on screen: typed and handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw(FeaturedProductContent::TABLE, $sectionId, $field, $editLanguage);
};

// The choices on screen: as a refused save handed them back, else as
// stored — each checked against its list either way.
$settings = FeaturedProductContent::settings(is_array($old) ? $old : $section);
$chosenProduct = is_array($old)
    ? (ctype_digit((string) ($old['product_id'] ?? '')) ? (int) $old['product_id'] : 0)
    : (int) ($section['product_id'] ?? 0);

// The catalogue, by name. A product the list does not know (deleted since)
// is simply not chosen.
try {
    $products = (new ProductRepository())->findAllForAdmin();
} catch (\Throwable $e) {
    error_log('[admin/featured-product.php] ' . $e->getMessage());
    $products = [];
}
ShopLocalization::preloadProducts(array_map(static fn (array $product): int => (int) $product['id'], $products));
foreach ($products as &$product) {
    $product['name'] = ShopLocalization::productName((int) $product['id']);
}
unset($product);
usort($products, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));

$productsById = [];
foreach ($products as $product) {
    $productsById[(int) $product['id']] = $product;
}
if (!isset($productsById[$chosenProduct])) {
    $chosenProduct = 0;
}
$productStatus = FeaturedProductContent::productStatus($chosenProduct);
$canEditProducts = AdminAuth::can('products.manage');

$pageLabel = \App\Service\PageLocalization::name((int) $page['id']);
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$optional = admin_localized_optional_attr($editLanguage);

// The button's placeholder is what a visitor reading this language gets while
// the field stays empty: the default language's own label when that has one
// (the usual fallback of a translation), else "Bekijk product" in this language.
$defaultLanguage = BlockLocalization::defaultLanguage();
$fallbackLabel = $editLanguage !== $defaultLanguage
    ? BlockLocalization::raw(FeaturedProductContent::TABLE, $sectionId, FeaturedProductContent::LINK_LABEL, $defaultLanguage)
    : '';
$linkPlaceholder = $fallbackLabel !== '' ? $fallbackLabel : FeaturedProductContent::defaultLinkLabel($editLanguage);
$forcedOpen = $errors !== [] ? ' data-admin-collapse-open' : '';

/** What a product costs, as the CMS says it: the amount, or "Op aanvraag". */
$priceText = static function (array $product): string {
    if (PurchaseMode::isInquiry($product['purchase_mode'] ?? null)) {
        return admin_t('block_featured_product.on_request');
    }

    return admin_t('shop.amount_with', ['v1' => htmlspecialchars(number_format((float) $product['price'], 2, ',', '.'), ENT_QUOTES, 'UTF-8')]);
};

/** A product's small picture in the list, or an empty square. */
$thumb = static function (array $product) use ($h): string {
    $image = (string) ($product['image_path'] ?? '');

    return '<span class="admin-collection-product-row__thumb">'
        . ($image !== '' ? '<img src="/' . $h(ltrim($image, '/')) . '" alt="" loading="lazy">' : '')
        . '</span>';
};

/**
 * One closed-list choice as a segmented control in a fieldset with a legend
 * (the same markup as admin/media-banner.php), its words under
 * block_featured_product.<name>_<value>.
 *
 * @param list<string> $values
 */
$choice = static function (string $name, array $values, string $chosen) use ($h, $fieldErrors): void {
    $legend = admin_t('block_featured_product.' . $name);
    ?>
      <fieldset class="admin-segmented-field"<?= editor_field_invalid($fieldErrors, $name) ?>>
        <legend><?= $h($legend) ?> <?= admin_help($legend, admin_t('help.block_featured_product.' . $name)) ?></legend>
        <div class="admin-segmented">
          <?php foreach ($values as $value): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="<?= $h($name) ?>" value="<?= $h($value) ?>"<?= $chosen === $value ? ' checked' : '' ?>>
              <span><?= admin_te('block_featured_product.' . $name . '_' . $value) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php editor_field_error($fieldErrors, $name); ?>
      </fieldset>
    <?php
};

/** One on/off setting as a switch, with its help button when it has one. */
$switch = static function (string $name, bool $checked, bool $withHelp = false) use ($h, $fieldErrors): void {
    $label = admin_t('block_featured_product.' . $name);
    ?>
      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="<?= $h($name) ?>" value="1"<?= $checked ? ' checked' : '' ?><?= editor_field_invalid($fieldErrors, $name) ?>>
          <?= $h($label) ?>
        </label>
        <?php if ($withHelp): ?>
          <?= admin_help($label, admin_t('help.block_featured_product.' . $name)) ?>
        <?php endif; ?>
      </div>
      <?php editor_field_error($fieldErrors, $name); ?>
    <?php
};

/** One folding card of the form, open unless its reader closed it. */
$cardStart = static function (string $id, string $titleKey) use ($forcedOpen): void {
    ?>
    <section class="admin-card">
      <details class="admin-collapse admin-collapse--card" data-admin-collapse-id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" open<?= $forcedOpen ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <h2 class="admin-collapse__title"><?= admin_te($titleKey) ?></h2>
        </summary>
        <div class="admin-collapse__body">
    <?php
};

$cardEnd = static function (): void {
    ?>
        </div>
      </details>
    </section>
    <?php
};
?>
<!doctype html>
<html lang="<?= $h(\App\Service\Language\AdminLocale::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('featured_product')) ?> — <?= $h($pageLabel) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\ContentOwners\ContentBlockAccess::listUrl($page), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_featured_product.terug', ['v1' => $h($pageLabel)]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('featured_product')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_featured_product.uitleg', ['v1' => $h($pageLabel)]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error" role="alert">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/update-featured-product.php" class="admin-product-form" data-featured-product-form<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
    <?= admin_localized_input($editLanguage) ?>

    <div data-admin-collapse-group="featured-product" data-admin-collapse-scope="<?= $h($sectionParam) ?>" data-admin-collapse-no-return>

    <?php $cardStart('product', 'block_featured_product.group_product'); ?>
      <?php /* What is chosen right now, and whether a visitor can see it.
               admin/assets/featured-product.js keeps this in step with the
               list below, from the chosen row's own name, price, picture and
               "Inactief" mark; without the script the list's checked row
               says it, and a save draws it again. */
        $current = $chosenProduct > 0 ? $productsById[$chosenProduct] : null; ?>
      <div class="admin-featured-product-current" data-featured-product-current data-none-label="<?= admin_te('block_featured_product.none_chosen') ?>">
        <span class="admin-collection-product-row__thumb" data-featured-product-current-thumb><?php if ($current !== null && (string) ($current['image_path'] ?? '') !== ''): ?><img src="/<?= $h(ltrim((string) $current['image_path'], '/')) ?>" alt=""><?php endif; ?></span>
        <span class="admin-section-row__body">
          <span class="admin-section-row__name" data-featured-product-current-name><?= $current !== null ? $h((string) $current['name']) : admin_te('block_featured_product.none_chosen') ?></span>
          <span class="admin-text-muted" data-featured-product-current-price><?= $current !== null ? $priceText($current) : '' ?></span>
        </span>
        <?php if ($canEditProducts): ?>
          <a class="admin-btn-text" href="/admin/product-form.php?id=<?= $chosenProduct ?>" data-featured-product-current-edit<?= $current === null ? ' hidden' : '' ?>><?= admin_te('block_featured_product.edit_product') ?></a>
        <?php endif; ?>
      </div>

      <p class="admin-alert admin-alert--info" data-featured-product-none-note<?= $productStatus === FeaturedProductContent::PRODUCT_NONE ? '' : ' hidden' ?>><?= admin_te('block_featured_product.none_note') ?></p>
      <p class="admin-alert admin-alert--warning" role="status" data-featured-product-unavailable<?= $productStatus === FeaturedProductContent::PRODUCT_UNAVAILABLE ? '' : ' hidden' ?>><?= admin_te('block_featured_product.unavailable') ?></p>

      <?php if ($products === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_featured_product.no_products') ?></p>
        <input type="hidden" name="product_id" value="">
      <?php else: ?>
        <fieldset class="admin-featured-product-picker"<?= editor_field_invalid($fieldErrors, 'product_id') ?>>
          <legend><?= admin_te('block_featured_product.product_legend') ?> <?= admin_help(admin_t('block_featured_product.product_legend'), admin_t('help.block_featured_product.product')) ?></legend>

          <div class="admin-collection-picker-toolbar">
            <label class="admin-search">
              <span class="admin-visually-hidden"><?= admin_te('block_featured_product.search_label') ?></span>
              <input type="search" placeholder="<?= admin_te('block_featured_product.search_placeholder') ?>" data-featured-product-search>
            </label>
          </div>

          <div class="admin-page-sections admin-featured-product-list" data-featured-product-list>
            <div class="admin-section-row admin-collection-product-row<?= $chosenProduct === 0 ? ' is-selected' : '' ?>" data-featured-product-row data-name="">
              <label class="admin-checkbox-label admin-collection-product-row__pick">
                <input type="radio" name="product_id" value=""<?= $chosenProduct === 0 ? ' checked' : '' ?>>
                <span class="admin-collection-product-row__thumb"></span>
                <span class="admin-section-row__body">
                  <span class="admin-section-row__name"><?= admin_te('block_featured_product.none') ?></span>
                </span>
              </label>
            </div>
            <?php foreach ($products as $product):
                $productId = (int) $product['id'];
                $isChosen = $productId === $chosenProduct;
                ?>
            <div class="admin-section-row admin-collection-product-row<?= $isChosen ? ' is-selected' : '' ?>" data-featured-product-row data-name="<?= $h(mb_strtolower((string) $product['name'])) ?>"<?= (int) $product['active'] !== 1 ? ' data-inactive' : '' ?>>
              <label class="admin-checkbox-label admin-collection-product-row__pick">
                <input type="radio" name="product_id" value="<?= $productId ?>"<?= $isChosen ? ' checked' : '' ?>>
                <?= $thumb($product) ?>
                <span class="admin-section-row__body">
                  <span class="admin-section-row__name" data-featured-product-row-name><?= $h((string) $product['name']) ?></span>
                  <span class="admin-text-muted" data-featured-product-row-price><?= $priceText($product) ?></span>
                </span>
              </label>
              <?php if ((int) $product['active'] !== 1): ?>
                <span class="admin-badge admin-badge--muted" title="<?= admin_te('block_featured_product.inactive_title') ?>"><?= admin_te('block_featured_product.inactive') ?></span>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <p class="admin-text-muted" data-featured-product-empty hidden><?= admin_te('block_featured_product.no_match') ?></p>
          <?php editor_field_error($fieldErrors, 'product_id'); ?>
        </fieldset>
      <?php endif; ?>
    <?php $cardEnd(); ?>

    <?php $cardStart('content', 'block_featured_product.group_content'); ?>
      <?php admin_localized_bar($editLanguage); ?>
      <?php $switch('show_name', $settings['show_name']); ?>
      <?php $switch('show_price', $settings['show_price'], true); ?>
      <?php $switch('show_description', $settings['show_description'], true); ?>
      <?php $switch('show_specifications', $settings['show_specifications'], true); ?>

      <div class="admin-field">
        <?= admin_field_label('featured-product-intro', admin_t('block_featured_product.intro'), admin_t('help.block_featured_product.intro')) ?>
        <textarea id="featured-product-intro" name="<?= FeaturedProductContent::INTRO ?>" maxlength="500" rows="3"<?= $optional ?><?= editor_field_invalid($fieldErrors, FeaturedProductContent::INTRO) ?>><?= $h($word(FeaturedProductContent::INTRO)) ?></textarea>
        <?php editor_field_error($fieldErrors, FeaturedProductContent::INTRO); ?>
      </div>
    <?php $cardEnd(); ?>

    <?php $cardStart('images', 'block_featured_product.group_images'); ?>
      <?php $choice('image_mode', FeaturedProductContent::IMAGE_MODES, $settings['image_mode']); ?>
      <?php $choice('image_position', FeaturedProductContent::IMAGE_POSITIONS, $settings['image_position']); ?>
      <?php $choice('image_size', FeaturedProductContent::IMAGE_SIZES, $settings['image_size']); ?>
    <?php $cardEnd(); ?>

    <?php $cardStart('ordering', 'block_featured_product.group_ordering'); ?>
      <?php $choice('ordering', FeaturedProductContent::ORDERINGS, $settings['ordering']); ?>
      <p class="admin-text-muted"><?= admin_te('block_featured_product.ordering_uitleg') ?></p>
    <?php $cardEnd(); ?>

    <?php $cardStart('link', 'block_featured_product.group_link'); ?>
      <?php $switch('show_product_link', $settings['show_product_link']); ?>
      <div class="admin-field">
        <?= admin_field_label('featured-product-link-label', admin_t('block_featured_product.link_label'), admin_t('help.block_featured_product.link_label')) ?>
        <input type="text" id="featured-product-link-label" name="<?= FeaturedProductContent::LINK_LABEL ?>" maxlength="150" value="<?= $h($word(FeaturedProductContent::LINK_LABEL)) ?>" placeholder="<?= $h($linkPlaceholder) ?>"<?= editor_field_invalid($fieldErrors, FeaturedProductContent::LINK_LABEL) ?>>
        <?php editor_field_error($fieldErrors, FeaturedProductContent::LINK_LABEL); ?>
      </div>
    <?php $cardEnd(); ?>

    <?php $cardStart('layout', 'block_featured_product.group_layout'); ?>
      <?php $choice('content_align', FeaturedProductContent::ALIGNMENTS, $settings['content_align']); ?>
    <?php $cardEnd(); ?>

    </div>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_collapse_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/featured-product.js') ?>" defer></script>
</body>
</html>
