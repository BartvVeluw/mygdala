<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\AdminPermissions;
use App\Service\Csrf;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;

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
 * THE PROJECT PAGE IS AN ORDINARY PAGE. The editor offers one choice: no page,
 * or one of the site's ordinary pages
 * (App\Service\PortfolioGalleryContent::linkablePages()). That page's texts,
 * images, SEO and publication belong to the page builder, so nothing here
 * edits them, and "Nieuwe pagina maken" opens the Pages screen itself rather
 * than a Portfolio copy of it — in a new tab, without linking back: the editor
 * picks the new page here afterwards. The project page the Portfolio used to
 * own (its slug, intro, description and extra photos) is not edited on this
 * screen any more (MODULES.md, "Portfolio").
 *
 * Built from the shared admin controls (ADMIN-UI.md): field help, the file
 * input, a switch per on/off setting, a checkbox per category, the shared
 * select, and the confirmation dialog before anything is deleted.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('portfolio.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== null && $id !== false && $id >= 1;

$item = null;
$itemCategoryIds = [];
$linkablePages = [];
$allCategories = (new PortfolioCategoryRepository())->findAll();

if ($isEdit) {
    $repository = new PortfolioGalleryRepository();
    $item = $repository->findItemById($id);

    if ($item === null) {
        http_response_code(404);
        exit(admin_t('screen.portfolio_item_gevonden'));
    }

    $itemCategoryIds = $repository->categoryIdsForItem($id);
    $linkablePages = PortfolioGalleryContent::linkablePages();
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
$selectedPageId = $item !== null ? (int) ($item['page_id'] ?? 0) : 0;
$canManagePages = AdminAuth::can(AdminPermissions::PAGES_MANAGE);

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
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
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
        <h2><?= admin_te('portfolio.project_page') ?></h2>
        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-page', admin_t('portfolio.project_page'), admin_t('help.portfolio.project_page')) ?>
            <select class="admin-select" id="portfolio-page" name="page_id">
              <option value=""><?= admin_te('portfolio.no_linked_page') ?></option>
              <?php foreach ($linkablePages as $page): ?>
                <?php
                  // A draft is offered too, marked the way the menu picker marks
                  // one: a card links to its page only once that page is published.
                  $pageLabel = PageContent::isPublished($page)
                      ? $h((string) $page['title'])
                      : admin_te('portfolio.page_option_draft', ['title' => (string) $page['title']]);
                ?>
                <option value="<?= (int) $page['id'] ?>"<?= (int) $page['id'] === $selectedPageId ? ' selected' : '' ?>><?= $pageLabel ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php
          // What the stored choice does in public, said where it is made:
          // whether the linked page is live yet, and — for an item that still
          // has its old project page — what that page's address does now
          // (App\Service\PortfolioGalleryContent::legacyProjectRedirectUrl()).
          $linkedPage = null;
          foreach ($linkablePages as $candidate) {
              if ((int) $candidate['id'] === $selectedPageId) {
                  $linkedPage = $candidate;
              }
          }
          $linkedPageIsLive = $linkedPage !== null && PageContent::isPublished($linkedPage);
          $oldProjectSlug = (string) ($item['slug'] ?? '');
          $hasOldProjectPage = $oldProjectSlug !== '' && !empty($item['has_detail_page']);
        ?>
        <?php if ($linkedPage !== null): ?>
          <p class="admin-text-muted">
            <?= admin_te($linkedPageIsLive ? 'portfolio.linked_page_published' : 'portfolio.linked_page_draft', ['address' => PageContent::publicUrl($linkedPage)]) ?>
            <?php if ($canManagePages): ?>
              <a href="/admin/page.php?id=<?= (int) $linkedPage['id'] ?>"><?= admin_te('portfolio.edit_page') ?></a>
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php if ($hasOldProjectPage && ($linkedPageIsLive || (int) $item['is_active'] === 1)): ?>
          <p class="admin-text-muted"><?= admin_te($linkedPageIsLive ? 'portfolio.old_page_redirects' : 'portfolio.old_page_live', ['address' => PortfolioGalleryContent::publicPath($oldProjectSlug)]) ?></p>
        <?php endif; ?>
        <?php if ($canManagePages): ?>
          <p>
            <a href="/admin/page-new.php" class="admin-btn-secondary" target="_blank" rel="noopener"><?= admin_te('portfolio.new_page') ?> &#8594;</a>
            <span class="admin-text-muted"><?= admin_te('portfolio.new_page_note') ?></span>
          </p>
        <?php endif; ?>
      </section>

      <section class="admin-card">
        <button type="submit" class="admin-btn-primary"><?= admin_te('common.save') ?></button>
      </section>
    </form>

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
