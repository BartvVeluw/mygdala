<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;

require __DIR__ . '/_richtext_field.php';

AdminAuth::requireLogin();
AdminAuth::requirePermission('portfolio.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== null && $id !== false && $id >= 1;

$item = null;
$extraImages = [];
$itemCategoryIds = [];
$allCategories = (new PortfolioCategoryRepository())->findAll();

if ($isEdit) {
    $repository = new PortfolioGalleryRepository();
    $item = $repository->findItemById($id);

    if ($item === null) {
        http_response_code(404);
        exit(admin_t('screen.portfolio_item_gevonden'));
    }

    $extraImages = (new PortfolioItemImageRepository())->findByPortfolioItemId($id);
    $itemCategoryIds = $repository->categoryIdsForItem($id);
}

$errors = $_SESSION['admin_portfolio_item_errors'] ?? [];
$old = $_SESSION['admin_portfolio_item_old'] ?? null;
unset($_SESSION['admin_portfolio_item_errors'], $_SESSION['admin_portfolio_item_old']);

$created = isset($_GET['created']);
$updated = isset($_GET['updated']);

// Back link to the overview: preserves whatever search/filter state was
// active there when this item was opened (see admin/portfolio.php, which
// appends ?back=... to every card link) — falls back to a plain link when
// absent (e.g. this item was opened fresh, not via a card).
$backQuery = (string) ($_GET['back'] ?? '');
$backUrl = '/admin/portfolio.php' . ($backQuery !== '' ? '?' . $backQuery : '');

/**
 * Value precedence: freshly re-submitted (invalid) input, then the stored
 * item (edit), then a sane default. Same helper as product-form.php's
 * fieldValue().
 */
function fieldValue(?array $old, ?array $item, string $key, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    if ($item !== null && array_key_exists($key, $item)) {
        return (string) ($item[$key] ?? '');
    }

    return $default;
}

$selectedCategoryIds = $old !== null
    ? array_map('intval', is_array($old['categories'] ?? null) ? $old['categories'] : [])
    : $itemCategoryIds;

$isActiveChecked = $old !== null ? true : ($item === null || (int) $item['is_active'] === 1);
$isFeaturedChecked = $item !== null && (int) $item['is_featured'] === 1;
$hasDetailPageChecked = $item !== null && !empty($item['has_detail_page']);

$csrfToken = Csrf::token();
$pageTitle = $isEdit ? (string) $item['title_nl'] : admin_t('portfolio.new_item');

/**
 * @param array<int, array<string, mixed>> $categories from PortfolioCategoryRepository::findAll()
 * @param list<int> $selectedIds
 */
function portfolioCategoryCheckboxes(array $categories, array $selectedIds): string
{
    if ($categories === []) {
        return '<p class="admin-text-muted">' . admin_t('portfolio.no_categories_yet') . '</p>';
    }

    $html = '';
    foreach ($categories as $category) {
        $categoryId = (int) $category['id'];
        $checked = in_array($categoryId, $selectedIds, true) ? 'checked' : '';
        $id = 'cat_' . $categoryId;
        $html .= '<label class="admin-checkbox-label" for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" style="margin-right:1rem;display:inline-flex;">'
            . '<input type="checkbox" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="categories[]" value="' . $categoryId . '" ' . $checked . '> '
            . htmlspecialchars((string) $category['name_nl'], ENT_QUOTES, 'UTF-8')
            . '</label>';
    }

    return $html;
}

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * CMS preview src for a Portfolio image: the small (~480px) thumbnail when
 * one exists, else the full image — see admin/portfolio.php's identical
 * helper docblock for why some rows have no thumbnail_path yet.
 */
$cmsImageSrc = static fn (array $row): string => '/' . ltrim((string) ($row['thumbnail_path'] ?: $row['image_path']), '/');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> <?= admin_te('portfolio.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="<?= $h($backUrl) ?>"><?= admin_t('portfolio.terug_portfolio') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('portfolio.portfolio_item_aangemaakt_vul') ?></p>
  <?php endif; ?>
  <?php if ($updated): ?>
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

  <?php if (!$isEdit): ?>
    <section class="admin-card">
      <h2><?= admin_te('portfolio.nieuw_portfolio_item') ?></h2>
      <p class="admin-text-muted"><?= admin_te('portfolio.na_aanmaken_hier_ook') ?></p>
      <form method="post" action="/api/admin/create-portfolio-item.php" enctype="multipart/form-data" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

        <div class="admin-form-row">
          <label><?= admin_te('common.image') ?>*
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
          </label>
        </div>

        <?php admin_lang_tabs(); ?>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('common.alt_text') ?>*
            <input type="text" name="alt_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= $h(fieldValue($old, null, 'alt_nl')) ?>">
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('common.alt_text') ?>
            <input type="text" name="alt_en" maxlength="255" value="<?= $h(fieldValue($old, null, 'alt_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('common.title') ?>*
            <input type="text" name="title_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= $h(fieldValue($old, null, 'title_nl')) ?>">
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('common.title') ?>
            <input type="text" name="title_en" maxlength="150" value="<?= $h(fieldValue($old, null, 'title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('portfolio.onderschrift') ?>*
            <input type="text" name="subtitle_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= $h(fieldValue($old, null, 'subtitle_nl')) ?>">
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('portfolio.onderschrift_2') ?>
            <input type="text" name="subtitle_en" maxlength="150" value="<?= $h(fieldValue($old, null, 'subtitle_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row">
          <span><?= admin_te('portfolio.categories_field') ?><?= admin_t('portfolio.text', ['v1' => portfolioCategoryCheckboxes($allCategories, $selectedCategoryIds)]) ?>
        </div>

        <button type="submit"><?= admin_te('portfolio.portfolio_item_aanmaken') ?></button>
      </form>
    </section>
  <?php else: ?>
    <form method="post" action="/api/admin/update-portfolio-item.php" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

      <section class="admin-card">
        <h2><?= admin_te('portfolio.basisgegevens') ?></h2>
        <?php admin_lang_tabs(); ?>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('common.title') ?>*
            <input type="text" name="title_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= $h(fieldValue($old, $item, 'title_nl')) ?>">
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('common.title') ?>
            <input type="text" name="title_en" maxlength="150" value="<?= $h(fieldValue($old, $item, 'title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('portfolio.onderschrift_3') ?>*
            <input type="text" name="subtitle_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= $h(fieldValue($old, $item, 'subtitle_nl')) ?>">
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('portfolio.onderschrift_4') ?>
            <input type="text" name="subtitle_en" maxlength="150" value="<?= $h(fieldValue($old, $item, 'subtitle_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>
      </section>

      <section class="admin-card">
        <h2><?= admin_te('portfolio.hoofdafbeelding') ?></h2>
        <div class="admin-image-card" style="max-width:220px;">
          <div class="admin-image-card__media">
            <img src="<?= $h($cmsImageSrc($item)) ?>" alt="" loading="lazy">
          </div>
        </div>
        <div class="admin-form-row" style="margin-top:0.75rem;">
          <label><?= admin_te('portfolio.vervangen_door_nieuw_bestand') ?>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
          </label>
        </div>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('common.alt_text') ?>*
            <input type="text" name="alt_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= $h(fieldValue($old, $item, 'alt_nl')) ?>">
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('common.alt_text') ?>
            <input type="text" name="alt_en" maxlength="255" value="<?= $h(fieldValue($old, $item, 'alt_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>
      </section>

      <section class="admin-card">
        <h2><?= admin_t('portfolio.zichtbaarheid_categorie_n') ?></h2>
        <div class="admin-form-row">
          <span><?= admin_t('portfolio.categories_required', ['v1' => portfolioCategoryCheckboxes($allCategories, $selectedCategoryIds)]) ?>
        </div>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_active" value="1" <?= $isActiveChecked ? 'checked' : '' ?>>
          <?= admin_te('portfolio.zichtbaar_portfolio_pagina') ?>
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_featured" value="1" <?= $isFeaturedChecked ? 'checked' : '' ?>>
          <?= admin_te('portfolio.toon_homepage') ?>
        </label>
      </section>

      <section class="admin-card">
        <h2><?= admin_te('portfolio.projectpagina') ?></h2>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="has_detail_page" value="1" data-detail-toggle <?= $hasDetailPageChecked ? 'checked' : '' ?>>
          <?= admin_te('portfolio.projectpagina_inschakelen') ?>
        </label>
        <p class="admin-text-muted"><?= admin_te('portfolio.ingeschakeld_portfolio_kaart_klikbaar') ?></p>

        <div data-detail-panel <?= $hasDetailPageChecked ? '' : 'hidden' ?>>
          <div class="admin-form-row">
            <label><?= admin_t('portfolio.slug_url_portfolio') ?>
              <input type="text" name="slug" maxlength="170" value="<?= $h(fieldValue($old, $item, 'slug')) ?>" placeholder="Leeg = automatisch gegenereerd uit de titel">
            </label>
            <?php if ($hasDetailPageChecked && (string) ($item['slug'] ?? '') !== ''): ?>
              <p class="admin-text-muted"><?= admin_te('portfolio.live') ?> <a href="/portfolio/<?= $h((string) $item['slug']) ?>" target="_blank" rel="noopener"><?= admin_t('portfolio.public_path', ['v1' => $h((string) $item['slug'])]) ?></a></p>
            <?php endif; ?>
          </div>

          <div class="admin-form-row">
            <?php admin_lang_pane_start('nl'); ?>
              <?php renderRichTextField('intro_nl', 'Introtekst', fieldValue($old, $item, 'intro_nl'), 'full', 'admin-richtext-editor--md'); ?>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
              <?php renderRichTextField('intro_en', 'Introtekst', fieldValue($old, $item, 'intro_en'), 'full', 'admin-richtext-editor--md'); ?>
            <?php admin_lang_pane_end(); ?>
          </div>

          <div class="admin-form-row">
            <?php admin_lang_pane_start('nl'); ?>
              <?php renderRichTextField('description_nl', 'Projectbeschrijving', fieldValue($old, $item, 'description_nl'), 'full', 'admin-richtext-editor--lg'); ?>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
              <?php renderRichTextField('description_en', 'Projectbeschrijving', fieldValue($old, $item, 'description_en'), 'full', 'admin-richtext-editor--lg'); ?>
            <?php admin_lang_pane_end(); ?>
          </div>
        </div>
      </section>

      <section class="admin-card">
        <button type="submit"><?= admin_te('common.save') ?></button>
      </section>
    </form>

    <section class="admin-card" data-detail-panel <?= $hasDetailPageChecked ? '' : 'hidden' ?>>
      <h2><?= admin_te('portfolio.projectafbeeldingen') ?></h2>
      <p class="admin-text-muted"><?= admin_te('portfolio.extra_foto_s_projectpagina') ?></p>

      <?php if ($extraImages === []): ?>
        <p class="admin-text-muted"><?= admin_te('portfolio.extra_afbeeldingen') ?></p>
      <?php else: ?>
        <div class="admin-portfolio-image-grid"
             data-portfolio-image-grid
             data-entity-id="<?= (int) $item['id'] ?>"
             data-reorder-url="/api/admin/reorder-portfolio-item-images.php"
             data-csrf-token="<?= $h($csrfToken) ?>">
          <?php foreach ($extraImages as $image): ?>
            <?php $imageId = (int) $image['id']; ?>
            <div class="admin-portfolio-image-card" draggable="true" data-image-id="<?= $imageId ?>">
              <div class="admin-portfolio-image-card__media">
                <img src="<?= $h($cmsImageSrc($image)) ?>" alt="" loading="lazy">
              </div>
              <form method="post" action="/api/admin/update-portfolio-item-image.php" class="admin-portfolio-image-card__meta">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="image_id" value="<?= $imageId ?>">
                <input type="hidden" name="portfolio_item_id" value="<?= (int) $item['id'] ?>">
                <?php /* No strip of its own: this little form follows the one
                         above it (admin/assets/admin-language-tabs.js). */ ?>
                <?php admin_lang_pane_start('nl'); ?>
                  <input type="text" name="alt_nl" maxlength="255" placeholder="Alt-tekst" value="<?= $h((string) ($image['alt_nl'] ?? '')) ?>">
                <?php admin_lang_pane_end(); ?>
                <?php admin_lang_pane_start('en'); ?>
                  <input type="text" name="alt_en" maxlength="255" placeholder="Alt-tekst" value="<?= $h((string) ($image['alt_en'] ?? '')) ?>">
                <?php admin_lang_pane_end(); ?>
                <button type="submit" class="admin-btn-text"><?= admin_te('common.save') ?></button>
              </form>
              <form method="post" action="/api/admin/delete-portfolio-item-image.php" class="admin-inline-form" onsubmit="return confirm('Deze afbeelding verwijderen?');">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="image_id" value="<?= $imageId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/api/admin/add-portfolio-item-images.php" enctype="multipart/form-data" class="admin-form-row" data-portfolio-add-images-form>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="portfolio_item_id" value="<?= (int) $item['id'] ?>">
        <label><?= admin_te('portfolio.afbeeldingen_toevoegen_kies_er') ?>
          <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
        </label>
        <button type="submit"><?= admin_te('common.add') ?></button>
      </form>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>
      <p class="admin-text-muted"><?= admin_te('portfolio.verwijdert_portfolio_item_hoofdafbeelding') ?></p>
      <form method="post" action="/api/admin/delete-portfolio-item.php" onsubmit="return confirm('Dit portfolio-item definitief verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('portfolio.portfolio_item_verwijderen') ?></button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
