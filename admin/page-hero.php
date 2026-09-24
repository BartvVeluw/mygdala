<?php

declare(strict_types=1);

/**
 * The Paginakop editor for one page: its texts, its picture and its
 * presentation choices, in one form to api/admin/update-page-hero.php.
 *
 * Three groups — Inhoud, Afbeelding, Vormgeving — inside that one form, the
 * way admin/project-cards.php groups its settings, so the save bar watches one
 * form and one save stores everything. The image comes from the shared media
 * picker (MEDIA.md), and each choice offers exactly PageHeroContent's closed
 * list, so the form cannot send a value the endpoint refuses.
 *
 * THE AFBEELDING GROUP IS CONDITIONAL, and only it. "Afbeeldingsweergave"
 * decides which of its parts matter: the picker for any place of the picture,
 * the height for a picture behind the text, the alt text and a short note for
 * a picture beside it, the focus point for both. admin/assets/page-hero.js
 * shows exactly those at once, and the server prints the same `hidden`, so
 * the form looks the same after a reload and without the script. A hidden
 * part still posts its value; the endpoint decides what it means. The
 * choices in Vormgeving apply with and without a picture and are always shown.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the eyebrow, title and lead show the language chosen in the CMS shell, as
 * stored and without the default language's words in an empty translation,
 * and the title is required only in the default language; the save writes
 * that language only. The image, the choices and "Tonen op de pagina" are the
 * same in every language and stay on screen in each. Input a refused save
 * hands back comes back in the language it was typed in, and the form then
 * starts out unsaved in the save bar.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_image_focus.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\PageHeroContent;
use App\Repository\PageHeroRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$slug = (string) ($_GET['slug'] ?? '');

// Known keys (PageHeroContent::PAGES) are the originally hardcoded pages;
// any other slug is only valid when the page builder has actually attached
// a Page Hero to it (App\Service\SectionRegistry::create()) — never trust an
// arbitrary slug from the query string beyond that.
$isDynamicallyAttached = (new \App\Repository\PageRepository())->findByContentKey($slug) !== null
    && (new PageHeroRepository())->findBySlug($slug) !== null;

if (!array_key_exists($slug, PageHeroContent::PAGES) && !$isDynamicallyAttached) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_pagina'));
}

$pageLabelRow = (new \App\Repository\PageRepository())->findByContentKey($slug);
$pageLabel = PageHeroContent::PAGES[$slug]
    ?? ($pageLabelRow !== null ? \App\Service\PageLocalization::name((int) $pageLabelRow['id']) : $slug);

$errors = $_SESSION['admin_page_hero_errors'] ?? [];
$old = $_SESSION['admin_page_hero_old'] ?? null;
unset($_SESSION['admin_page_hero_errors'], $_SESSION['admin_page_hero_old']);

$saved = isset($_GET['saved']);
$editLanguage = admin_localized_language();

try {
    $row = (new PageHeroRepository())->findBySlug($slug);
} catch (\Throwable $e) {
    error_log('[admin/page-hero.php] ' . $e->getMessage());
    $row = null;
}

// What is the same in every language: handed back, else stored, else what a
// header that does not exist yet starts out with.
if ($old !== null) {
    $values = $old;
} elseif ($row !== null) {
    $values = [
        'media_id' => isset($row['media_id']) ? (int) $row['media_id'] : null,
        'content_position' => (string) ($row['content_position'] ?? PageHeroContent::POSITION_LEFT),
        'title_size' => (string) ($row['title_size'] ?? PageHeroContent::SIZE_NORMAL),
        'text_size' => (string) ($row['text_size'] ?? PageHeroContent::SIZE_NORMAL),
        'image_mode' => (string) ($row['image_mode'] ?? PageHeroContent::IMAGE_NONE),
        'hero_height' => (string) ($row['hero_height'] ?? PageHeroContent::HEIGHT_MEDIUM),
        'image_focus' => (string) ($row['image_focus'] ?? ImageFocus::DEFAULT),
        'is_active' => (bool) $row['is_active'],
    ];
} else {
    $values = PageHeroContent::startingValues() + ['is_active' => true];
}

$heroId = (int) ($row['id'] ?? 0);
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/**
 * The words of one field on screen: typed and handed back in this language,
 * else stored in it, else (a header without a row yet, in the default
 * language only) its starting words.
 */
$word = static function (string $field) use ($old, $oldInThisLanguage, $heroId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    if ($heroId > 0) {
        return BlockLocalization::raw('page_heroes', $heroId, $field, $editLanguage);
    }

    return $editLanguage === BlockLocalization::defaultLanguage() ? (PageHeroContent::startingWords()[$field] ?? '') : '';
};

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$placeholder = admin_localized_placeholder_attr($editLanguage);
$required = admin_localized_required($editLanguage);

// The options of the three choices, in the order the selects offer them. The
// values are PageHeroContent's constants, so the lists stay the ones the
// endpoint checks against.
$positionLabels = [
    PageHeroContent::POSITION_LEFT => admin_t('block_pagehero.position_left'),
    PageHeroContent::POSITION_CENTER => admin_t('block_pagehero.position_center'),
    PageHeroContent::POSITION_RIGHT => admin_t('block_pagehero.position_right'),
];

$sizeLabels = [
    PageHeroContent::SIZE_SMALL => admin_t('block_pagehero.size_small'),
    PageHeroContent::SIZE_NORMAL => admin_t('block_pagehero.size_normal'),
    PageHeroContent::SIZE_LARGE => admin_t('block_pagehero.size_large'),
];

$imageModeLabels = [
    PageHeroContent::IMAGE_NONE => admin_t('block_pagehero.image_mode_none'),
    PageHeroContent::IMAGE_BACKGROUND => admin_t('block_pagehero.image_mode_background'),
    PageHeroContent::IMAGE_LEFT => admin_t('block_pagehero.image_mode_left'),
    PageHeroContent::IMAGE_RIGHT => admin_t('block_pagehero.image_mode_right'),
];

$heightLabels = [
    PageHeroContent::HEIGHT_SMALL => admin_t('block_pagehero.height_small'),
    PageHeroContent::HEIGHT_MEDIUM => admin_t('block_pagehero.height_medium'),
    PageHeroContent::HEIGHT_LARGE => admin_t('block_pagehero.height_large'),
];

// The picture and its choices, each read back as one of its closed list, so
// a hand-edited row cannot select nothing.
$heroMedia = MediaService::find(isset($values['media_id']) ? (int) $values['media_id'] : null);
$imageMode = in_array($values['image_mode'] ?? null, PageHeroContent::IMAGE_MODES, true) ? (string) $values['image_mode'] : PageHeroContent::IMAGE_NONE;
$heroHeight = in_array($values['hero_height'] ?? null, PageHeroContent::HEIGHTS, true) ? (string) $values['hero_height'] : PageHeroContent::HEIGHT_MEDIUM;
$imageFocus = ImageFocus::normalise($values['image_focus'] ?? null);
$isBeside = in_array($imageMode, [PageHeroContent::IMAGE_LEFT, PageHeroContent::IMAGE_RIGHT], true);

// The alt text this picture really gets beside the text: its own, else the
// library's, filled in and linked to the picker (media_alt_field()).
$heroAlt = media_alt_field('media_id', $word('image_alt'), $heroMedia, $placeholder);

/**
 * The <option>s of one choice, with the current value selected.
 *
 * @param array<string, string> $labels value => label
 */
function pageHeroOptions(array $labels, string $current): string
{
    $html = '';

    foreach ($labels as $value => $label) {
        $html .= '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"'
            . ((string) $value === $current ? ' selected' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</option>';
    }

    return $html;
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('block_pagehero.page_hero_admin', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($slug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_pagehero.text', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= admin_t('block_pagehero.page_hero', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_pagehero.bovenste_sectie_breadcrumb_eyebrow', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
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
    <form method="post" action="/api/admin/update-page-hero.php" class="admin-product-form" data-page-hero-form<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
      <?= admin_localized_input($editLanguage) ?>

      <h2><?= admin_te('block_pagehero.group_content') ?></h2>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-field">
        <?= admin_field_label('page-hero-eyebrow', admin_t('block_pagehero.eyebrow'), admin_t('help.page_hero.eyebrow')) ?>
        <input type="text" id="page-hero-eyebrow" name="eyebrow" maxlength="150" value="<?= $h($word('eyebrow')) ?>"<?= $placeholder ?>>
      </div>

      <div class="admin-field">
        <?= admin_field_label('page-hero-title', admin_t('block_pagehero.titel_h1'), admin_t('help.page_hero.title'), $required !== '') ?>
        <input type="text" id="page-hero-title" name="title" maxlength="255"<?= $required ?> value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
      </div>

      <div class="admin-field">
        <?= admin_field_label('page-hero-lead', admin_t('block_pagehero.introtekst_lead'), admin_t('help.page_hero.lead')) ?>
        <textarea id="page-hero-lead" name="lead" maxlength="500" rows="3"<?= $placeholder ?>><?= $h($word('lead')) ?></textarea>
      </div>

      <?php /* There is no "Naam in het kruimelpad" here any more. The
               breadcrumb is the page's own navigation, its label is the
               page's own title, and whether it shows is a switch on the page
               itself (Pagina bewerken → Pagina). See HEADER-FOOTER.md. */ ?>

      <h2 style="margin-top:2rem;"><?= admin_te('block_pagehero.group_image') ?></h2>

      <div class="admin-field">
        <?= admin_field_label('page-hero-image-mode', admin_t('block_pagehero.image_mode'), admin_t('help.page_hero.image_mode')) ?>
        <select class="admin-select" id="page-hero-image-mode" name="image_mode" data-page-hero-image-mode>
          <?= pageHeroOptions($imageModeLabels, $imageMode) ?>
        </select>
      </div>

      <?php /* Everything below belongs to one place of the picture or more,
               named in data-page-hero-part; admin/assets/page-hero.js shows
               exactly the parts of the chosen place, and the parts that need
               a chosen picture (data-page-hero-needs-image) only once there
               is one. The server prints the same `hidden`, so a reload shows
               the same form, and the one form always posts everything: the
               endpoint decides what a value means for the chosen place. */ ?>
      <div data-page-hero-part="background left right"<?= $imageMode === PageHeroContent::IMAGE_NONE ? ' hidden' : '' ?>>
        <?php media_picker_field(
            'media_id',
            $heroMedia,
            admin_t('block_pagehero.image'),
            admin_t('block_pagehero.image_help')
        ); ?>

        <p class="admin-text-muted" data-page-hero-part="left right"<?= $isBeside ? '' : ' hidden' ?>><?= admin_te('block_pagehero.side_note') ?></p>

        <div class="admin-field" data-page-hero-part="left right" data-page-hero-needs-image<?= $isBeside && $heroMedia !== null ? '' : ' hidden' ?>>
          <?= admin_field_label('page-hero-image-alt', admin_t('common.alt_text'), admin_t('help.page_hero.image_alt')) ?>
          <input type="text" id="page-hero-image-alt" name="image_alt" maxlength="255" value="<?= $h($heroAlt['value']) ?>"<?= $heroAlt['attributes'] ?>>
        </div>

        <div class="admin-form-row" data-page-hero-part="background" data-page-hero-needs-image<?= $imageMode === PageHeroContent::IMAGE_BACKGROUND && $heroMedia !== null ? '' : ' hidden' ?>>
          <span class="admin-form-row__label" id="page-hero-height-label"><?= admin_te('block_pagehero.hero_height') ?> <?= admin_help(admin_t('block_pagehero.hero_height'), admin_t('help.page_hero.hero_height')) ?></span>
          <div class="admin-segmented" role="radiogroup" aria-labelledby="page-hero-height-label">
            <?php foreach ($heightLabels as $height => $heightLabel): ?>
              <label class="admin-segmented__option">
                <input type="radio" name="hero_height" value="<?= $h($height) ?>"<?= $heroHeight === $height ? ' checked' : '' ?>>
                <span><?= $h($heightLabel) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <?php /* The focus point: the shared field of every place with one
                 (media_focus_field(), ImageFocus), with its preview kept in
                 step by admin/assets/image-focus.js. Its frame takes the
                 shape of the chosen place from admin.css
                 ([data-page-hero-form]). */ ?>
        <div data-page-hero-needs-image<?= $heroMedia !== null ? '' : ' hidden' ?>>
          <?php media_focus_field(
              'image_focus',
              $imageFocus,
              $heroMedia !== null ? $heroMedia->displayPath() : '',
              admin_t('block_pagehero.focus'),
              admin_t('help.page_hero.focus'),
              admin_t('block_pagehero.focus_voorbeeld')
          ); ?>
        </div>
      </div>

      <h2 style="margin-top:2rem;"><?= admin_te('block_pagehero.group_layout') ?></h2>

      <div class="admin-field">
        <?= admin_field_label('page-hero-content-position', admin_t('block_pagehero.content_position'), admin_t('help.page_hero.content_position')) ?>
        <select class="admin-select" id="page-hero-content-position" name="content_position">
          <?= pageHeroOptions($positionLabels, (string) ($values['content_position'] ?? PageHeroContent::POSITION_LEFT)) ?>
        </select>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('page-hero-title-size', admin_t('block_pagehero.title_size'), admin_t('help.page_hero.title_size')) ?>
          <select class="admin-select" id="page-hero-title-size" name="title_size">
            <?= pageHeroOptions($sizeLabels, (string) ($values['title_size'] ?? PageHeroContent::SIZE_NORMAL)) ?>
          </select>
        </div>
        <div class="admin-field">
          <?= admin_field_label('page-hero-text-size', admin_t('block_pagehero.text_size'), admin_t('help.page_hero.text_size')) ?>
          <select class="admin-select" id="page-hero-text-size" name="text_size">
            <?= pageHeroOptions($sizeLabels, (string) ($values['text_size'] ?? PageHeroContent::SIZE_NORMAL)) ?>
          </select>
        </div>
      </div>

      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="is_active" value="1"<?= ($values['is_active'] ?? true) ? ' checked' : '' ?>>
          <?= admin_te('block_pagehero.show_on_page') ?>
        </label>
        <?= admin_help(admin_t('block_pagehero.show_on_page'), admin_t('help.page_hero.is_active')) ?>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php media_picker_modal(); ?>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php media_picker_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/image-focus.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/page-hero.js') ?>" defer></script>
</body>
</html>
