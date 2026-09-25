<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_richtext_field.php';

use App\Service\AdminAuth;
use App\Service\AdminPermissions;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioProjectGallery;
use App\Service\PortfolioSlug;
use App\Repository\PageRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;

/**
 * One portfolio item: the "Nieuw portfolio-item" form without ?id=, the item's
 * editor with one.
 *
 * AN IMAGE IS ENOUGH. Title, alt text, short text and categories are optional
 * (api/admin/create-portfolio-item.php), so only the image is marked required;
 * the info panel and each field's help say what the others are for.
 *
 * THE IMAGE COMES FROM THE MEDIA LIBRARY (Media Library 2.0): the shared
 * picker (admin/_media_picker.php) opens the library, where an editor
 * chooses a picture or uploads a new one into it; the form posts the item's
 * id. There is no file input here, so choosing a picture never opens the
 * operating system's dialog first. An item from before the library keeps its
 * own picture, shown above the picker, until another one is chosen.
 *
 * THE PROJECT PAGE IS THE ITEM'S OWN (Portfolio 2.0, MODULES.md "Portfolio").
 * "Projectpagina tonen" switches /portfolio/<slug> on; its address, intro,
 * description and extra photos are edited right here, and
 * portfolio-detail.php renders them. No ordinary page is made for it, and
 * there is no "Nieuwe pagina maken" any more: an item's detail content is not
 * a page in the page builder.
 *
 * THE GALLERY is a pool of library pictures in their own order, chosen with
 * the shared picker in collect mode and ordered with ← →, a drag or ×
 * (admin/assets/product-gallery.js, the product editor's own script, which
 * serves both). × takes the picture off this project only; the library item
 * stays in the library. The main picture stands apart and is never also a
 * gallery photo (App\Service\PortfolioProjectGallery).
 *
 * A LEGACY LINKED PAGE (phase 4B) is shown only on an item that has one: its
 * name and status, where the project's button and address go now, and the
 * one thing that can still be done with it — unlink it, which hands the
 * address back to the item's own project page and leaves the page itself
 * exactly where it is.
 *
 * Built from the shared admin controls (ADMIN-UI.md): field help, the media
 * picker, a switch per on/off setting, a checkbox per category, the shared
 * rich-text field, and the confirmation dialog before anything is deleted.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('portfolio.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== null && $id !== false && $id >= 1;

$item = null;
$itemCategoryIds = [];
$photoRows = [];
$allCategories = (new PortfolioCategoryRepository())->findAll();

if ($isEdit) {
    $repository = new PortfolioGalleryRepository();
    $item = $repository->findItemById($id);

    if ($item === null) {
        http_response_code(404);
        exit(admin_t('screen.portfolio_item_gevonden'));
    }

    $itemCategoryIds = $repository->categoryIdsForItem($id);
    $photoRows = (new PortfolioItemImageRepository())->findByPortfolioItemId($id);
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

// The language this screen's words are in, and the words themselves: the
// refused POST first, so a rejected save keeps what was typed, then what is
// stored FOR THAT LANGUAGE with no fallback (the fallback is the placeholder).
$editingLanguage = admin_localized_language();

$word = static function (string $field) use ($old, $item, $editingLanguage): string {
    if ($old !== null && array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }

    return $item === null
        ? ''
        : PortfolioLocalization::rawItemValue((int) $item['id'], $field, $editingLanguage);
};

$selectedCategoryIds = $old !== null
    ? array_map('intval', is_array($old['categories'] ?? null) ? $old['categories'] : [])
    : $itemCategoryIds;

$isActiveChecked = $old !== null && array_key_exists('is_active', $old)
    ? (bool) $old['is_active']
    : ($item === null || (int) $item['is_active'] === 1);
$isFeaturedChecked = $old !== null && array_key_exists('is_featured', $old)
    ? (bool) $old['is_featured']
    : $item !== null && (int) $item['is_featured'] === 1;
$hasDetailPageChecked = $old !== null && array_key_exists('has_detail_page', $old)
    ? (bool) $old['has_detail_page']
    : $item !== null && (int) ($item['has_detail_page'] ?? 0) === 1;
$storedSlug = (string) ($item['slug'] ?? '');
$slugValue = $old !== null && array_key_exists('slug', $old) ? (string) $old['slug'] : $storedSlug;
$canManagePages = AdminAuth::can(AdminPermissions::PAGES_MANAGE);

// The item's legacy linked page (phase 4B), only when it has one.
$legacyPage = $item !== null && (int) ($item['page_id'] ?? 0) > 0
    ? (new PageRepository())->findById((int) $item['page_id'])
    : null;
if ($legacyPage !== null) {
    \App\Service\PageLocalization::preload([(int) $legacyPage['id']]);
}
$unlinkChecked = $old !== null && !empty($old['unlink_page']);

$csrfToken = Csrf::token();

// A title is optional, so an item without one is still named on its own
// screen — never an empty heading.
$pageTitle = !$isEdit
    ? admin_t('portfolio.new_item')
    : (PortfolioLocalization::itemLabel((int) $item['id']) !== ''
        ? PortfolioLocalization::itemLabel((int) $item['id'])
        : admin_t('portfolio.untitled'));

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
            . htmlspecialchars(PortfolioLocalization::categoryLabel($categoryId), ENT_QUOTES, 'UTF-8')
            . '</label>';
    }

    return $html . '</div></div>';
}

/**
 * The words admin/assets/product-gallery.js needs for this gallery, from the
 * catalog, as one JSON attribute — the Portfolio's own, so a message never
 * speaks of a product.
 */
function portfolioGalleryWords(): string
{
    return (string) json_encode([
        'left' => admin_t('portfolio.gallery.move_left'),
        'right' => admin_t('portfolio.gallery.move_right'),
        'remove' => admin_t('portfolio.gallery.remove'),
        'moved' => admin_t('portfolio.gallery.moved'),
        'removed' => admin_t('portfolio.gallery.removed'),
        'added' => admin_t('portfolio.gallery.added'),
        'duplicate' => admin_t('portfolio.gallery.duplicate'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * One photo of the project gallery, as the script also builds it: the
 * picture, its position, ← → × and the hidden input that carries its token.
 * The same markup as the product editor's card (admin/_product_gallery.php),
 * so the shared script and the shared .admin-gallery styles serve both.
 *
 * @param array{token: string, src: string, name: string, media_id: ?int} $photo
 */
function portfolioGalleryCard(array $photo, int $index, int $total): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <li class="admin-gallery__item" data-gallery-item draggable="true"
        data-token="<?= $h($photo['token']) ?>"
        data-src="<?= $h($photo['src']) ?>"
        data-name="<?= $h($photo['name']) ?>"<?= $photo['media_id'] !== null ? ' data-media-id="' . (int) $photo['media_id'] . '"' : '' ?>>
      <input type="hidden" name="gallery[]" value="<?= $h($photo['token']) ?>">
      <span class="admin-gallery__media"><img src="<?= $h($photo['src']) ?>" alt="" loading="lazy" draggable="false"></span>
      <span class="admin-gallery__position" aria-hidden="true"><?= $index + 1 ?></span>
      <span class="admin-gallery__name"><?= $h($photo['name']) ?></span>
      <span class="admin-gallery__actions">
        <button type="button" class="admin-gallery__btn" data-gallery-move="-1" aria-label="<?= admin_te('portfolio.gallery.move_left', ['name' => $photo['name']]) ?>"<?= $index === 0 ? ' disabled' : '' ?>>&larr;</button>
        <button type="button" class="admin-gallery__btn" data-gallery-move="1" aria-label="<?= admin_te('portfolio.gallery.move_right', ['name' => $photo['name']]) ?>"<?= $index === $total - 1 ? ' disabled' : '' ?>>&rarr;</button>
        <button type="button" class="admin-gallery__btn admin-gallery__btn--remove" data-gallery-remove aria-label="<?= admin_te('portfolio.gallery.remove', ['name' => $photo['name']]) ?>">&times;</button>
      </span>
    </li>
    <?php
}

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * CMS preview src for a Portfolio image: the small (~480px) thumbnail when
 * one exists, else the full image — see admin/portfolio.php's identical
 * helper docblock for why some rows have no thumbnail_path yet.
 */
$cmsImageSrc = static fn (array $row): string => '/' . ltrim((string) ($row['thumbnail_path'] ?: $row['image_path']), '/');

// The library picture the picker shows: what a refused save chose, else what
// the item has. Null for a new item, and for an item whose picture is still an
// old one on Portfolio's own path (shown beside the picker instead).
$chosenMediaId = (int) ($old['media_id'] ?? 0);
$chosenMedia = MediaService::findImage($chosenMediaId > 0 ? $chosenMediaId : (int) ($item['media_id'] ?? 0));
$hasLegacyImage = $item !== null && (int) ($item['media_id'] ?? 0) === 0 && trim((string) ($item['image_path'] ?? '')) !== '';

$photos = $isEdit
    ? PortfolioProjectGallery::forEditor($photoRows, is_array($old['gallery'] ?? null) ? $old['gallery'] : null)
    : [];

// Where the project lives now, said where it is decided: the legacy page while
// it is linked and published, else the item's own address once it is public.
$legacyPageIsLive = $legacyPage !== null && PageContent::isPublished($legacyPage) && PageContent::isServedByAnEnabledModule($legacyPage);
$ownPageIsPublic = $item !== null && PortfolioSlug::isPublic((int) $item['is_active'] === 1, (int) ($item['has_detail_page'] ?? 0) === 1, $storedSlug !== '' ? $storedSlug : null);
$writesDefaultLanguage = $editingLanguage === admin_localized_default();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> <?= admin_te('portfolio.admin') ?></title>
<?php if ($isEdit): ?>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<?php if ($isEdit): ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<?php endif; ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
<?php media_picker_script(); ?>
<?php if ($isEdit): ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/product-gallery.js') ?>" defer></script>
<?php endif; ?>
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
            <?php media_picker_field('media_id', $chosenMedia, admin_t('common.image') . ' *', admin_t('help.portfolio.image'), false); ?>
          </div>
        </div>

        <?php /* A NEW item is written in the DEFAULT language, like a new page
                 and every new child row since phase 3B; translating it happens
                 on the item itself afterwards. */ ?>
        <?= admin_localized_input(admin_localized_default()) ?>
        <?php admin_localized_new_item_note($editingLanguage); ?>
        <?php admin_localized_bar(admin_localized_default()); ?>
        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-alt', admin_t('common.alt_text'), admin_t('help.portfolio.alt')) ?>
            <input type="text" id="portfolio-alt" name="alt" maxlength="<?= PortfolioLocalization::ALT_MAX_LENGTH ?>" value="<?= $h((string) ($old['alt'] ?? '')) ?>">
          </div>
        </div>

        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-title', admin_t('common.title'), admin_t('help.portfolio.title')) ?>
            <input type="text" id="portfolio-title" name="title" maxlength="<?= PortfolioLocalization::TITLE_MAX_LENGTH ?>" value="<?= $h((string) ($old['title'] ?? '')) ?>">
          </div>
        </div>

        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-subtitle', admin_t('portfolio.short_text'), admin_t('help.portfolio.subtitle')) ?>
            <input type="text" id="portfolio-subtitle" name="subtitle" maxlength="<?= PortfolioLocalization::SUBTITLE_MAX_LENGTH ?>" value="<?= $h((string) ($old['subtitle'] ?? '')) ?>">
          </div>
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
      <?php /* ONE language per request: the endpoint writes exactly this one
               and leaves every other translation of this item alone. */ ?>
      <?= admin_localized_input($editingLanguage) ?>

      <section class="admin-card">
        <h2><?= admin_te('portfolio.hoofdafbeelding') ?></h2>
        <div class="admin-form-row">
          <?php if ($hasLegacyImage && $chosenMedia === null): ?>
            <?php /* A picture from before the library: it stays exactly where it
                     is until another one is chosen below. */ ?>
            <div class="admin-image-card admin-seo-image__preview">
              <div class="admin-image-card__media">
                <img src="<?= $h($cmsImageSrc($item)) ?>" alt="" loading="lazy">
              </div>
            </div>
            <p class="admin-text-muted"><?= admin_te('portfolio.image_legacy') ?></p>
          <?php endif; ?>
          <div class="admin-field">
            <?php media_picker_field('media_id', $chosenMedia, admin_t($hasLegacyImage && $chosenMedia === null ? 'portfolio.image_replace' : 'common.image'), admin_t('help.portfolio.replace_image'), false); ?>
          </div>
        </div>

        <?php admin_localized_bar($editingLanguage); ?>
        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-alt', admin_t('common.alt_text'), admin_t('help.portfolio.alt')) ?>
            <input type="text" id="portfolio-alt" name="alt" maxlength="<?= PortfolioLocalization::ALT_MAX_LENGTH ?>" value="<?= $h($word(PortfolioLocalization::ALT)) ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
          </div>
        </div>
      </section>

      <section class="admin-card">
        <h2><?= admin_te('portfolio.basisgegevens') ?></h2>
        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-title', admin_t('common.title'), admin_t('help.portfolio.title')) ?>
            <?php /* The address is made from the title in the DEFAULT language,
                     so only that language's title fills it in as it is typed
                     (admin/assets/admin.js, initSlugAutoFill()). */ ?>
            <input type="text" id="portfolio-title" name="title" maxlength="<?= PortfolioLocalization::TITLE_MAX_LENGTH ?>" value="<?= $h($word(PortfolioLocalization::TITLE)) ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?><?= $writesDefaultLanguage ? ' data-slug-source' : '' ?>>
          </div>
        </div>
        <div class="admin-form-row">
          <div class="admin-field">
            <?= admin_field_label('portfolio-subtitle', admin_t('portfolio.short_text'), admin_t('help.portfolio.subtitle')) ?>
            <input type="text" id="portfolio-subtitle" name="subtitle" maxlength="<?= PortfolioLocalization::SUBTITLE_MAX_LENGTH ?>" value="<?= $h($word(PortfolioLocalization::SUBTITLE)) ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
          </div>
        </div>
      </section>

      <?php /* Two cards, side by side on a wide screen and under each other on
               a narrow one: what the item is about, and where it is shown. */ ?>
      <div class="admin-card-pair">
        <section class="admin-card">
          <h2><?= admin_te('portfolio.categories_field') ?></h2>
          <?= portfolioCategoryField($allCategories, $selectedCategoryIds) ?>
        </section>

        <section class="admin-card">
          <h2><?= admin_te('portfolio.visibility') ?></h2>
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
      </div>

      <section class="admin-card">
        <h2><?= admin_te('portfolio.project_page') ?></h2>
        <?php /* Says the section was on the form, so an unticked switch means
                 "off" rather than "not sent" (validatePortfolioProjectPage()). */ ?>
        <input type="hidden" name="project_page_submitted" value="1">
        <div class="admin-field admin-field--inline">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-switch" role="switch" name="has_detail_page" value="1" <?= $hasDetailPageChecked ? 'checked' : '' ?>>
            <?= admin_te('portfolio.show_project_page') ?>
          </label>
          <?= admin_help(admin_t('portfolio.show_project_page'), admin_t('help.portfolio.show_project_page')) ?>
        </div>

        <div class="admin-product-form admin-product-form--wide">
          <div class="admin-field">
            <?= admin_field_label('portfolio-slug', admin_t('portfolio.slug'), admin_t('help.portfolio.slug')) ?>
            <?php /* Filled in from the title while no address is stored and the
                     editor has typed none (initSlugAutoFill()); `slug_auto`
                     tells the endpoint to make such an address unique itself,
                     while a typed one is checked as typed. */ ?>
            <input type="text" id="portfolio-slug" name="slug" maxlength="<?= PortfolioSlug::MAX_LENGTH ?>" value="<?= $h($slugValue) ?>" autocomplete="off" spellcheck="false" data-slug-target>
            <input type="hidden" name="slug_auto" value="0" data-slug-auto>
            <p class="admin-url-preview">
              <?= admin_te('page.url_preview') ?>
              <span class="admin-url-preview__address"><span><?= $h(\App\Service\AppUrl::canonical('/portfolio/')) ?></span><strong data-slug-preview-value data-slug-preview-empty="<?= admin_te('portfolio.slug_from_title') ?>"><?= $h($slugValue !== '' ? $slugValue : admin_t('portfolio.slug_from_title')) ?></strong></span>
            </p>
            <?php if ($ownPageIsPublic && !$legacyPageIsLive): ?>
              <p class="admin-text-muted">
                <?= admin_te('portfolio.project_page_live') ?>
                <a href="<?= $h(PortfolioGalleryContent::publicPath($storedSlug)) ?>" target="_blank" rel="noopener"><?= $h(PortfolioGalleryContent::publicPath($storedSlug)) ?></a>
              </p>
              <p class="admin-text-muted"><?= admin_te('portfolio.slug_change_redirects') ?></p>
            <?php endif; ?>
          </div>
        </div>

        <?php /* The project page's own words, in the language being edited
                 (the bar at the top of this form says which).
                 Sanitized when saved and again when shown
                 (RichTextSanitizer, PortfolioLocalization::itemRich()). */ ?>
        <?php renderRichTextField(PortfolioLocalization::INTRO, admin_t('portfolio.intro'), $word(PortfolioLocalization::INTRO), 'full', 'admin-richtext-editor--md'); ?>
        <p class="admin-text-muted"><?= admin_te('help.portfolio.intro') ?></p>
        <?php renderRichTextField(PortfolioLocalization::DESCRIPTION, admin_t('portfolio.description'), $word(PortfolioLocalization::DESCRIPTION), 'full', 'admin-richtext-editor--lg'); ?>
        <p class="admin-text-muted"><?= admin_te('help.portfolio.description') ?></p>
      </section>

      <section class="admin-card">
        <h2><?= admin_te('portfolio.gallery.heading') ?></h2>
        <div class="admin-gallery" data-picture-gallery data-gallery-input="gallery[]" data-gallery-first-badge="" data-gallery-exclude-input="media_id" data-gallery-words="<?= $h(portfolioGalleryWords()) ?>">
          <input type="hidden" name="gallery_submitted" value="1" data-gallery-marker>
          <p class="admin-text-muted"><?= admin_te('portfolio.gallery.intro') ?></p>
          <ol class="admin-gallery__grid" data-gallery-list aria-label="<?= admin_te('portfolio.gallery.list_label') ?>">
            <?php foreach ($photos as $index => $photo): ?>
              <?php portfolioGalleryCard($photo, $index, count($photos)); ?>
            <?php endforeach; ?>
          </ol>
          <p class="admin-text-muted" data-gallery-empty<?= $photos !== [] ? ' hidden' : '' ?>><?= admin_te('portfolio.gallery.empty') ?></p>
          <div class="admin-gallery__add" data-media-picker data-media-picker-kind="image" data-media-picker-collect>
            <button type="button" class="admin-btn-secondary" data-media-picker-open>+ <?= admin_te('portfolio.gallery.add') ?></button>
          </div>
          <p class="admin-visually-hidden" role="status" aria-live="polite" data-gallery-status></p>
        </div>
      </section>

      <?php if ($legacyPage !== null): ?>
        <section class="admin-card">
          <h2><?= admin_te('portfolio.legacy_page') ?></h2>
          <p><?= admin_te('portfolio.legacy_page_intro', ['title' => \App\Service\PageLocalization::name((int) $legacyPage['id'])]) ?></p>
          <p class="admin-text-muted">
            <?php if ($legacyPageIsLive): ?>
              <?= admin_te('portfolio.legacy_page_live', ['address' => PageContent::publicUrl($legacyPage)]) ?>
            <?php else: ?>
              <?= admin_te('portfolio.legacy_page_not_live') ?>
            <?php endif; ?>
            <?php if ($canManagePages): ?>
              <a href="/admin/page.php?id=<?= (int) $legacyPage['id'] ?>"><?= admin_te('portfolio.edit_page') ?></a>
            <?php endif; ?>
          </p>
          <div class="admin-field admin-field--inline">
            <label class="admin-checkbox-label">
              <input type="checkbox" class="admin-checkbox" name="unlink_page" value="1" <?= $unlinkChecked ? 'checked' : '' ?>>
              <?= admin_te('portfolio.unlink_page') ?>
            </label>
            <?= admin_help(admin_t('portfolio.unlink_page'), admin_t('help.portfolio.unlink_page')) ?>
          </div>
        </section>
      <?php endif; ?>

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
<?php media_picker_modal(); ?>
</body>
</html>
