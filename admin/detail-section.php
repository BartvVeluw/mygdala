<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';
require __DIR__ . '/_richtext_field.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\SectionRegistry;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;

/**
 * Editor for one "Detailsectie" block instance
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page and its content row
 * really exist" gate as every other repeater section editor
 * (admin/text-image-split.php, admin/faq.php, ...).
 *
 * This screen replaced admin/service-detail.php, which was reachable only
 * through four hardcoded material keys and therefore could never edit a
 * fifth section or the same section on another page.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). The
 * section's words and settings, its main image with its alt text, the
 * "kenmerken" and the gallery post to api/admin/update-detail-section.php
 * together, and its one "Opslaan" (or the save bar) stores all of it. The
 * main image is part of the block, not a form of its own: choosing,
 * replacing or clearing it in the picker only fills the field until
 * "Opslaan". ↑, ↓ and "toevoegen" work on screen (admin/assets/row-list.js);
 * without JavaScript ↑ and ↓ submit the whole form and one empty kenmerk
 * waits at the end of its list (App\Service\Blocks\EditorChildList,
 * admin/_editor_rows.php).
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the section's words, the main image's alt text, every point and every
 * gallery image's alt text show the language chosen in the CMS shell, as
 * stored and without the default language's words in an empty translation,
 * and are required only in the default language; a save writes that
 * language only. The anchor, the image position, the CTA URL, the media and
 * visibility are the same in every language and stay on screen in each. A
 * point or an image keeps its id however often it is saved or moved, so the
 * words of the other languages stay with it; a NEW point or image is written
 * in the default language, like a new page. Input a refused save hands back
 * comes back as it was typed (both lists, order and marks included), with
 * each message next to its field, and the form then starts out unsaved in
 * the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new DetailSectionRepository();
$pages = new PageRepository();

if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || $pages->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$page = $pages->findByContentKey($pageSlug);
$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$sectionId = (int) $section['id'];

$errors = $_SESSION['admin_detail_section_errors'] ?? [];
$fieldErrors = $_SESSION['admin_detail_section_field_errors'] ?? [];
$old = $_SESSION['admin_detail_section_old'] ?? null;
unset($_SESSION['admin_detail_section_errors'], $_SESSION['admin_detail_section_field_errors'], $_SESSION['admin_detail_section_old']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();

// The words of the section, of every point and of every gallery image, in
// one query.
BlockLocalization::preloadBlocks(['detail_sections' => [$sectionId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The section's words on screen: typed and handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('detail_sections', $sectionId, $field, $editLanguage);
};

/** A language-neutral value: handed back, else stored. */
$setting = static fn (string $key): string => is_array($old) ? (string) ($old[$key] ?? '') : (string) ($section[$key] ?? '');
$imagePosition = is_array($old) ? (string) ($old['image_position'] ?? 'image_right') : (string) ($section['image_position'] ?? 'image_right');
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $section['is_active'];

// The main image as chosen on screen: handed back, else stored. A legacy
// path that predates the library has no item for the picker to show, so it
// gets a removal checkbox of its own.
$mainMediaId = is_array($old) ? (int) ($old['main_media_id'] ?? 0) : (int) ($section['main_media_id'] ?? 0);
$hasLegacyMainImageOnly = (int) ($section['main_media_id'] ?? 0) === 0 && trim((string) ($section['main_image_path'] ?? '')) !== '';

// Both lists on screen: as a refused save handed them back, else as stored.
$pointRows = editor_rows_on_screen(
    $repository->findPointsBySectionId($sectionId),
    $oldInThisLanguage ? (array) ($old['points'] ?? []) : null,
    static fn (array $point): array => [
        'title' => BlockLocalization::raw('detail_section_points', (int) $point['id'], 'title', $editLanguage),
        'body' => BlockLocalization::raw('detail_section_points', (int) $point['id'], 'body', $editLanguage),
        'active' => (bool) $point['is_active'] ? '1' : '',
    ]
);
$imageRows = editor_rows_on_screen(
    $repository->findImagesBySectionId($sectionId),
    $oldInThisLanguage ? (array) ($old['images'] ?? []) : null,
    static fn (array $image): array => [
        'media_id' => (string) (int) ($image['media_id'] ?? 0),
        'alt' => BlockLocalization::raw('detail_section_images', (int) $image['id'], 'alt', $editLanguage),
    ]
);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = $placeholder !== '' ? $placeholder : ' placeholder="Optioneel"';

/** One text field of the section, with its own message. */
$field = static function (string $name, string $label, int $maxLength, string $attributes, int $lines = 0, ?string $value = null) use ($h, $word, $fieldErrors): void {
    $id = 'detail-' . str_replace('_', '-', $name);
    $value ??= $word($name);
    echo '<div class="admin-field">' . admin_field_label($id, $label);
    if ($lines > 0) {
        echo '<textarea id="' . $h($id) . '" name="' . $h($name) . '" maxlength="' . $maxLength . '" rows="' . $lines . '"' . $attributes
            . editor_field_invalid($fieldErrors, $name) . '>' . $h($value) . '</textarea>';
    } else {
        echo '<input type="text" id="' . $h($id) . '" name="' . $h($name) . '" maxlength="' . $maxLength . '" value="' . $h($value) . '"' . $attributes
            . editor_field_invalid($fieldErrors, $name) . '>';
    }
    editor_field_error($fieldErrors, $name);
    echo '</div>';
};

/** One kenmerk; the template for a new one is the same markup with the key __KEY__. */
$pointRow = static function (string $key, array $fields, int $position, int $count) use ($marker, $placeholder, $fieldErrors): void {
    [$star, $hint] = editor_row_word_hints($key, $marker, $placeholder);
    editor_row_open('points', $key, admin_t('block_detail.kenmerk'), $position, $count, ($fields['remove'] ?? '') !== '');
    editor_row_text('points', $key, 'title', admin_t('common.title') . $star, 255, $fields, $fieldErrors, $hint);
    editor_row_text('points', $key, 'body', admin_t('block_detail.tekst') . $star, 500, $fields, $fieldErrors, $hint, 2);
    editor_row_switch('points', $key, $fields, admin_t('block_detail.actief_uitgevinkt_kenmerk_getoond'));
    editor_row_close();
};

/** One gallery image; the template for a new one is the same markup with the key __KEY__. */
$imageRow = static function (string $key, array $fields, int $position, int $count) use ($placeholder, $fieldErrors): void {
    editor_row_open('images', $key, admin_t('block_detail.afbeelding'), $position, $count, ($fields['remove'] ?? '') !== '');
    editor_row_media('images', $key, $fields, $fieldErrors, ctype_digit($key) ? 'Afbeelding' : 'Afbeelding*');
    editor_row_media_alt('images', $key, $fields, $fieldErrors, $placeholder);
    editor_row_close();
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('detail_section')) ?> <?= admin_t('block_detail.admin', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_detail.terug', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('detail_section')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_detail.sectie_pagina_wijzigingen_direct', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>

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

  <form method="post" action="/api/admin/update-detail-section.php" class="admin-product-form" data-save-name="<?= $h(SectionRegistry::label('detail_section')) ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves a row. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
    <?= admin_localized_input($editLanguage) ?>
    <?php admin_localized_bar($editLanguage); ?>

    <section class="admin-card">
      <h2><?= admin_te('block_detail.algemene_inhoud') ?></h2>
      <?php $field('title', admin_t('block_detail.titel_h2') . $marker, 255, $required . $placeholder); ?>
      <?php $field('lead', admin_t('block_detail.lead'), 500, $optional, 2); ?>

      <?php renderRichTextField('body', 'Tekst', $word('body'), 'full', 'admin-richtext-editor--lg'); ?>
      <?php editor_field_error($fieldErrors, 'body'); ?>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('block_detail.anker_url_id') ?>
          <input type="text" name="anchor" maxlength="100" value="<?= $h($setting('anchor')) ?>" placeholder="Bijv. hout — leeg = geen anker">
        </label>
        <label><?= admin_te('block_detail.beeldpositie') ?>
          <select name="image_position">
            <option value="image_right"<?= $imagePosition === 'image_right' ? ' selected' : '' ?>><?= admin_te('block_detail.afbeelding_rechts') ?></option>
            <option value="image_left"<?= $imagePosition === 'image_left' ? ' selected' : '' ?>><?= admin_te('block_detail.afbeelding_links') ?></option>
          </select>
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_t('block_detail.sectie_anker_bereikbaar_via') ?></p>

      <?php $field('nav_label', admin_t('block_detail.navigatielabel'), 100, ' placeholder="Leeg = de titel hierboven"'); ?>
      <p class="admin-text-muted"><?= admin_te('block_detail.korte_tekst_snelnavigatie_meestal') ?></p>

      <?php $field('closing_note', admin_t('block_detail.slotnotitie'), 1000, $optional, 2); ?>
      <p class="admin-text-muted"><?= admin_te('block_detail.optionele_extra_tekst_onderaan') ?></p>

      <?php $field('cta_label', admin_t('block_detail.cta_knoptekst'), 150, $optional); ?>
      <div class="admin-field">
        <?= admin_field_label('detail-cta-url', admin_t('block_detail.cta_knop_url')) ?>
        <input type="text" id="detail-cta-url" name="cta_url" maxlength="255" value="<?= $h($setting('cta_url')) ?>" placeholder="Bijv. contact.php — leeg = geen knop">
      </div>
      <p class="admin-text-muted"><?= admin_te('block_detail.knoptekst_url_horen_elkaar') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_detail.actief_uitgevinkt_hele_sectie') ?>
      </label>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_detail.hoofdafbeelding') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_detail.optioneel_staat_naast_tekst') ?></p>

      <div class="admin-field">
        <?php $mainMedia = $mainMediaId > 0 ? MediaService::find($mainMediaId) : null; ?>
        <?php media_picker_field('main_media_id', $mainMedia, 'Hoofdafbeelding', 'Kies er een uit de mediabibliotheek, of upload een nieuwe in het venster dat opent. "Wissen" haalt de afbeelding bij Opslaan weg.', true); ?>
        <?php editor_field_error($fieldErrors, 'main_media_id'); ?>
      </div>
      <?php if ($hasLegacyMainImageOnly): ?>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="remove_legacy_main_image" value="1"<?= is_array($old) && !empty($old['remove_legacy_main_image']) ? ' checked' : '' ?>>
          <?= admin_te('block_detail.hoofdafbeelding_oud_verwijderen') ?>
        </label>
      <?php endif; ?>
      <?php
      // The alt text this image really gets, visible and linked to the picker.
      $mainAlt = media_alt_field('main_media_id', $word('main_image_alt'), $mainMedia, $placeholder);
      $field('main_image_alt', admin_t('common.alt_text'), 255, $mainAlt['attributes'], 0, $mainAlt['value']);
      ?>
    </section>

    <section class="admin-card" aria-labelledby="detail-points-title">
      <h2 id="detail-points-title"><?= admin_te('block_detail.kenmerken') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_detail.vinkje_icoon_staat_vast') ?></p>

      <?php if ($pointRows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_detail.kenmerken_sectie') ?></p>
      <?php endif; ?>

      <input type="hidden" name="points_present" value="1">
      <div class="admin-row-cards" data-row-list="detail-section-points">
        <?php foreach ($pointRows as $position => $row): ?>
          <?php $pointRow($row['key'], $row['fields'], $position, count($pointRows)); ?>
        <?php endforeach; ?>
        <noscript>
          <?php $pointRow(editor_rows_free_key($pointRows), ['active' => '1'], count($pointRows), count($pointRows) + 1); ?>
        </noscript>
      </div>
      <?php editor_rows_status('detail-section-points'); ?>
      <?php editor_rows_add('detail-section-points', admin_t('block_detail.kenmerk_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="detail-section-points"><?php $pointRow('__KEY__', ['active' => '1'], 0, 1); ?></template>
    </section>

    <section class="admin-card" aria-labelledby="detail-images-title">
      <h2 id="detail-images-title"><?= admin_te('block_detail.galerij') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_detail.optioneel_rij_afbeeldingen_onder') ?></p>

      <?php if ($imageRows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_detail.afbeeldingen_galerij') ?></p>
      <?php endif; ?>

      <input type="hidden" name="images_present" value="1">
      <div class="admin-row-cards" data-row-list="detail-section-images">
        <?php foreach ($imageRows as $position => $row): ?>
          <?php $imageRow($row['key'], $row['fields'], $position, count($imageRows)); ?>
        <?php endforeach; ?>
      </div>
      <?php editor_rows_status('detail-section-images'); ?>
      <?php editor_rows_add('detail-section-images', admin_t('block_detail.afbeelding_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="detail-section-images"><?php $imageRow('__KEY__', [], 0, 1); ?></template>
    </section>

    <div class="admin-form-actions">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </div>
  </form>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
