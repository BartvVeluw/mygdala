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

/**
 * One portfolio item: the "Nieuw portfolio-item" form without ?id=, the item's
 * editor with one.
 *
 * AN IMAGE IS ENOUGH. Title, alt text, caption and categories are optional
 * (api/admin/create-portfolio-item.php), so only the image is marked required;
 * the info panel and each field's help say what the others are for. The chosen
 * image is shown the moment it is picked, before anything is uploaded
 * (admin_file_preview()); on the editor the same box shows the stored image
 * until another is chosen.
 *
 * Built from the shared admin controls (ADMIN-UI.md): field help, the file
 * input, a switch per on/off setting, a checkbox per category, and the
 * confirmation dialog before anything is deleted. The project page (its slug,
 * texts and extra images) is folded under "Geavanceerd": it works exactly as
 * it did and is due for a redesign of its own, so until then it stays out of
 * the way of an ordinary item.
 */

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

// A title is optional, so an item without one is still named on its own
// screen — never an empty heading.
$pageTitle = !$isEdit
    ? admin_t('portfolio.new_item')
    : ((string) $item['title_nl'] !== '' ? (string) $item['title_nl'] : admin_t('portfolio.untitled'));

/**
 * The category choice: one checkbox per CMS-managed category, and no box
 * ticked is a valid answer. A group of checkboxes rather than one select,
 * because an item may carry several categories — and a select would quietly
 * drop all but one of them on the next save.
 *
 * @param array<int, array<string, mixed>> $categories from PortfolioCategoryRepository::findAll()
 * @param list<int> $selectedIds
 */
function portfolioCategoryField(array $categories, array $selectedIds): string
{
    $html = '<div class="admin-field" role="group" aria-labelledby="portfolio-categories-label">'
        . '<div class="admin-field__label">'
        . '<span id="portfolio-categories-label">' . admin_te('portfolio.categories_field') . '</span>'
        . admin_help(admin_t('portfolio.categories_field'), admin_t('help.portfolio.categories'))
        . '</div>';

    if ($categories === []) {
        return $html . '<p class="admin-text-muted">' . admin_t('portfolio.no_categories_yet') . '</p></div>';
    }

    $html .= '<div class="admin-portfolio-categories">';

    foreach ($categories as $category) {
        $categoryId = (int) $category['id'];

        $html .= '<label class="admin-checkbox-label">'
            . '<input type="checkbox" class="admin-checkbox" name="categories[]" value="' . $categoryId . '"'
            . (in_array($categoryId, $selectedIds, true) ? ' checked' : '') . '> '
            . htmlspecialchars((string) $category['name_nl'], ENT_QUOTES, 'UTF-8')
            . '</label>';
    }

    return $html . '</div></div>';
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
      <?= admin_info_panel(admin_t('help.portfolio.new_item')) ?>
      <form method="post" action="/api/admin/create-portfolio-item.php" enctype="multipart/form-data" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-image', admin_t('common.image'), admin_t('help.portfolio.image'), true) ?>
            <?= admin_file_input(['name' => 'image', 'id' => 'portfolio-image', 'accept' => 'image/jpeg,image/png,image/webp', 'required' => true]) ?>
            <?= admin_file_preview('portfolio-image') ?>
          </div>
        </div>

        <?php admin_lang_bar(); ?>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-alt-nl', admin_t('common.alt_text'), admin_t('help.portfolio.alt')) ?>
            <input type="text" id="portfolio-alt-nl" name="alt_nl" maxlength="255" value="<?= $h(fieldValue($old, null, 'alt_nl')) ?>">
          </div>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-alt-en', admin_t('common.alt_text'), admin_t('help.portfolio.alt')) ?>
            <input type="text" id="portfolio-alt-en" name="alt_en" maxlength="255" value="<?= $h(fieldValue($old, null, 'alt_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </div>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-title-nl', admin_t('common.title'), admin_t('help.portfolio.title')) ?>
            <input type="text" id="portfolio-title-nl" name="title_nl" maxlength="150" value="<?= $h(fieldValue($old, null, 'title_nl')) ?>">
          </div>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-title-en', admin_t('common.title'), admin_t('help.portfolio.title')) ?>
            <input type="text" id="portfolio-title-en" name="title_en" maxlength="150" value="<?= $h(fieldValue($old, null, 'title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </div>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-subtitle-nl', admin_t('portfolio.onderschrift'), admin_t('help.portfolio.subtitle')) ?>
            <input type="text" id="portfolio-subtitle-nl" name="subtitle_nl" maxlength="150" value="<?= $h(fieldValue($old, null, 'subtitle_nl')) ?>">
          </div>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-subtitle-en', admin_t('portfolio.onderschrift'), admin_t('help.portfolio.subtitle')) ?>
            <input type="text" id="portfolio-subtitle-en" name="subtitle_en" maxlength="150" value="<?= $h(fieldValue($old, null, 'subtitle_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </div>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row">
          <?= portfolioCategoryField($allCategories, $selectedCategoryIds) ?>
        </div>

        <button type="submit" class="admin-btn-primary"><?= admin_te('portfolio.portfolio_item_aanmaken') ?></button>
      </form>
    </section>
  <?php else: ?>
    <form method="post" action="/api/admin/update-portfolio-item.php" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

      <section class="admin-card">
        <h2><?= admin_te('portfolio.hoofdafbeelding') ?></h2>
        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-image', admin_t('portfolio.vervangen_door_nieuw_bestand'), admin_t('help.portfolio.replace_image')) ?>
            <?= admin_file_input(['name' => 'image', 'id' => 'portfolio-image', 'accept' => 'image/jpeg,image/png,image/webp']) ?>
            <?= admin_file_preview('portfolio-image', $cmsImageSrc($item)) ?>
          </div>
        </div>

        <?php admin_lang_bar(); ?>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-alt-nl', admin_t('common.alt_text'), admin_t('help.portfolio.alt')) ?>
            <input type="text" id="portfolio-alt-nl" name="alt_nl" maxlength="255" value="<?= $h(fieldValue($old, $item, 'alt_nl')) ?>">
          </div>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-alt-en', admin_t('common.alt_text'), admin_t('help.portfolio.alt')) ?>
            <input type="text" id="portfolio-alt-en" name="alt_en" maxlength="255" value="<?= $h(fieldValue($old, $item, 'alt_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </div>
          <?php admin_lang_pane_end(); ?>
        </div>
      </section>

      <section class="admin-card">
        <h2><?= admin_te('portfolio.basisgegevens') ?></h2>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-title-nl', admin_t('common.title'), admin_t('help.portfolio.title')) ?>
            <input type="text" id="portfolio-title-nl" name="title_nl" maxlength="150" value="<?= $h(fieldValue($old, $item, 'title_nl')) ?>">
          </div>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-title-en', admin_t('common.title'), admin_t('help.portfolio.title')) ?>
            <input type="text" id="portfolio-title-en" name="title_en" maxlength="150" value="<?= $h(fieldValue($old, $item, 'title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </div>
          <?php admin_lang_pane_end(); ?>
        </div>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-subtitle-nl', admin_t('portfolio.onderschrift'), admin_t('help.portfolio.subtitle')) ?>
            <input type="text" id="portfolio-subtitle-nl" name="subtitle_nl" maxlength="150" value="<?= $h(fieldValue($old, $item, 'subtitle_nl')) ?>">
          </div>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <div class="admin-field">
            <?= admin_field_label('portfolio-subtitle-en', admin_t('portfolio.onderschrift'), admin_t('help.portfolio.subtitle')) ?>
            <input type="text" id="portfolio-subtitle-en" name="subtitle_en" maxlength="150" value="<?= $h(fieldValue($old, $item, 'subtitle_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </div>
          <?php admin_lang_pane_end(); ?>
        </div>
      </section>

      <section class="admin-card">
        <h2><?= admin_t('portfolio.zichtbaarheid_categorie_n') ?></h2>
        <div class="admin-form-row">
          <?= portfolioCategoryField($allCategories, $selectedCategoryIds) ?>
        </div>
        <?php /* Switches, not checkboxes: each is one on/off setting. Underneath
                 they are still checkboxes, so update-portfolio-item.php reads
                 isset($_POST[...]) exactly as it did (ADMIN-UI.md). */ ?>
        <div class="admin-field admin-field--inline">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-switch" role="switch" name="is_active" value="1" <?= $isActiveChecked ? 'checked' : '' ?>>
            <?= admin_te('portfolio.zichtbaar_portfolio_pagina') ?>
          </label>
          <?= admin_help(admin_t('portfolio.zichtbaar_portfolio_pagina'), admin_t('help.portfolio.visible')) ?>
        </div>
        <div class="admin-field admin-field--inline">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-switch" role="switch" name="is_featured" value="1" <?= $isFeaturedChecked ? 'checked' : '' ?>>
            <?= admin_te('portfolio.toon_homepage') ?>
          </label>
          <?= admin_help(admin_t('portfolio.toon_homepage'), admin_t('help.portfolio.featured')) ?>
        </div>
      </section>

      <section class="admin-card">
        <details class="admin-collapse admin-collapse--card"<?= $hasDetailPageChecked ? ' open' : '' ?>>
          <summary class="admin-collapse__summary">
            <span class="admin-collapse__caret" aria-hidden="true"></span>
            <h2 class="admin-collapse__title"><?= admin_te('portfolio.geavanceerd_projectpagina') ?></h2>
          </summary>
          <div class="admin-collapse__body">
            <div class="admin-field admin-field--inline">
              <label class="admin-checkbox-label">
                <input type="checkbox" class="admin-switch" role="switch" name="has_detail_page" value="1" data-detail-toggle <?= $hasDetailPageChecked ? 'checked' : '' ?>>
                <?= admin_te('portfolio.projectpagina_inschakelen') ?>
              </label>
              <?= admin_help(admin_t('portfolio.projectpagina_inschakelen'), admin_t('portfolio.ingeschakeld_portfolio_kaart_klikbaar')) ?>
            </div>

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
          </div>
        </details>
      </section>

      <section class="admin-card">
        <button type="submit" class="admin-btn-primary"><?= admin_te('common.save') ?></button>
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
                <?php /* No indicator of its own: every pane on every screen shows
                         the CMS-wide editing language. */ ?>
                <?php admin_lang_pane_start('nl'); ?>
                  <input type="text" name="alt_nl" maxlength="255" placeholder="Alt-tekst" value="<?= $h((string) ($image['alt_nl'] ?? '')) ?>">
                <?php admin_lang_pane_end(); ?>
                <?php admin_lang_pane_start('en'); ?>
                  <input type="text" name="alt_en" maxlength="255" placeholder="Alt-tekst" value="<?= $h((string) ($image['alt_en'] ?? '')) ?>">
                <?php admin_lang_pane_end(); ?>
                <button type="submit" class="admin-btn-text"><?= admin_te('common.save') ?></button>
              </form>
              <form method="post" action="/api/admin/delete-portfolio-item-image.php" class="admin-inline-form"<?= admin_confirm_attributes(
                  admin_t('portfolio.afbeelding_verwijderen_titel'),
                  admin_t('portfolio.afbeelding_verwijderen_uitleg'),
                  admin_t('common.delete')
              ) ?>>
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
          <?= admin_file_input(['name' => 'images[]', 'multiple' => true, 'accept' => '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp']) ?>
        </label>
        <button type="submit"><?= admin_te('common.add') ?></button>
      </form>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>
      <p class="admin-text-muted"><?= admin_te('portfolio.verwijdert_portfolio_item_hoofdafbeelding') ?></p>
      <form method="post" action="/api/admin/delete-portfolio-item.php"<?= admin_confirm_attributes(
          admin_t('portfolio.item_verwijderen_titel'),
          admin_t('portfolio.verwijdert_portfolio_item_hoofdafbeelding'),
          admin_t('common.delete')
      ) ?>>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
        <button type="submit" class="admin-btn-danger"><?= admin_te('portfolio.portfolio_item_verwijderen') ?></button>
      </form>
    </section>

    <?= admin_confirm_dialog() ?>
  <?php endif; ?>
</main>
<?php admin_lang_script(); ?>
</body>
</html>
