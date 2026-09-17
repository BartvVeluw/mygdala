<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';
require __DIR__ . '/_richtext_field.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
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

$points = $repository->findPointsBySectionId($sectionId);
$images = $repository->findImagesBySectionId($sectionId);

$errors = $_SESSION['admin_detail_section_errors'] ?? [];
$old = $_SESSION['admin_detail_section_old'] ?? null;
unset($_SESSION['admin_detail_section_errors'], $_SESSION['admin_detail_section_old']);

$mainImageErrors = $_SESSION['admin_detail_section_main_image_errors'] ?? [];
unset($_SESSION['admin_detail_section_main_image_errors']);

$pointErrors = $_SESSION['admin_detail_section_point_errors'] ?? [];
unset($_SESSION['admin_detail_section_point_errors']);

$imageErrors = $_SESSION['admin_detail_section_image_errors'] ?? [];
unset($_SESSION['admin_detail_section_image_errors']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $values = $old;
} else {
    $values = [
        'anchor' => (string) ($section['anchor'] ?? ''),
        'nav_label_nl' => (string) ($section['nav_label_nl'] ?? ''),
        'nav_label_en' => (string) ($section['nav_label_en'] ?? ''),
        'title_nl' => (string) $section['title_nl'],
        'title_en' => (string) ($section['title_en'] ?? ''),
        'lead_nl' => (string) ($section['lead_nl'] ?? ''),
        'lead_en' => (string) ($section['lead_en'] ?? ''),
        'content_html' => (string) ($section['content_html'] ?? ''),
        'content_html_en' => (string) ($section['content_html_en'] ?? ''),
        'image_position' => (string) ($section['image_position'] ?? 'image_right'),
        'closing_note_nl' => (string) ($section['closing_note_nl'] ?? ''),
        'closing_note_en' => (string) ($section['closing_note_en'] ?? ''),
        'cta_label_nl' => (string) ($section['cta_label_nl'] ?? ''),
        'cta_label_en' => (string) ($section['cta_label_en'] ?? ''),
        'cta_url' => (string) ($section['cta_url'] ?? ''),
        'is_active' => (bool) $section['is_active'],
    ];
}

// "Does this section have a main image at all" — true for a Media Library
// reference and for a legacy path that predates it, which is what decides
// whether the "verwijderen" button is offered.
$mainImagePath = (string) ($section['main_image_path'] ?? '');
$hasMainImage = (int) ($section['main_media_id'] ?? 0) > 0 || $mainImagePath !== '';

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * @param array<string, mixed> $values
 */
function detailValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * @param list<string> $errors
 */
function detailErrorList(array $errors): void
{
    if ($errors === []) {
        return;
    }
    ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php
}
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

  <?php detailErrorList($errors); ?>
  <?php detailErrorList($mainImageErrors); ?>
  <?php detailErrorList($pointErrors); ?>
  <?php detailErrorList($imageErrors); ?>

  <section class="admin-card">
    <h2><?= admin_te('block_detail.algemene_inhoud') ?></h2>
    <form method="post" action="/api/admin/update-detail-section.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_detail.titel_h2') ?>*
          <input type="text" name="title_nl" maxlength="255" value="<?= detailValue($values, 'title_nl') ?>" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_detail.titel_h2_2') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= detailValue($values, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_detail.lead') ?>
          <textarea name="lead_nl" maxlength="500" rows="2" placeholder="Optioneel"><?= detailValue($values, 'lead_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_detail.lead_2') ?>
          <textarea name="lead_en" maxlength="500" rows="2"<?= admin_lang_placeholder_attr('en') ?>><?= detailValue($values, 'lead_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <?php admin_lang_pane_start('nl'); ?>
        <?php renderRichTextField('content_html', 'Tekst', (string) ($values['content_html'] ?? ''), 'full', 'admin-richtext-editor--lg'); ?>
      <?php admin_lang_pane_end(); ?>
      <?php admin_lang_pane_start('en'); ?>
        <?php renderRichTextField('content_html_en', 'Tekst', (string) ($values['content_html_en'] ?? ''), 'full', 'admin-richtext-editor--lg'); ?>
      <?php admin_lang_pane_end(); ?>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('block_detail.anker_url_id') ?>
          <input type="text" name="anchor" maxlength="100" value="<?= detailValue($values, 'anchor') ?>" placeholder="Bijv. hout — leeg = geen anker">
        </label>
        <label><?= admin_te('block_detail.beeldpositie') ?>
          <select name="image_position">
            <option value="image_right" <?= ($values['image_position'] ?? 'image_right') === 'image_right' ? 'selected' : '' ?>><?= admin_te('block_detail.afbeelding_rechts') ?></option>
            <option value="image_left" <?= ($values['image_position'] ?? '') === 'image_left' ? 'selected' : '' ?>><?= admin_te('block_detail.afbeelding_links') ?></option>
          </select>
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_t('block_detail.sectie_anker_bereikbaar_via') ?></p>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_detail.navigatielabel') ?>
          <input type="text" name="nav_label_nl" maxlength="100" value="<?= detailValue($values, 'nav_label_nl') ?>" placeholder="Leeg = de titel hierboven">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_detail.navigatielabel_2') ?>
          <input type="text" name="nav_label_en" maxlength="100" value="<?= detailValue($values, 'nav_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_detail.korte_tekst_snelnavigatie_meestal') ?></p>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_detail.slotnotitie') ?>
          <textarea name="closing_note_nl" maxlength="1000" rows="2" placeholder="Optioneel"><?= detailValue($values, 'closing_note_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_detail.slotnotitie_2') ?>
          <textarea name="closing_note_en" maxlength="1000" rows="2"<?= admin_lang_placeholder_attr('en') ?>><?= detailValue($values, 'closing_note_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_detail.optionele_extra_tekst_onderaan') ?></p>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_detail.cta_knoptekst') ?>
          <input type="text" name="cta_label_nl" maxlength="150" value="<?= detailValue($values, 'cta_label_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_detail.cta_knoptekst_2') ?>
          <input type="text" name="cta_label_en" maxlength="150" value="<?= detailValue($values, 'cta_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_detail.cta_knop_url') ?>
          <input type="text" name="cta_url" maxlength="255" value="<?= detailValue($values, 'cta_url') ?>" placeholder="Bijv. contact.php — leeg = geen knop">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_detail.knoptekst_url_horen_elkaar') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('block_detail.actief_uitgevinkt_hele_sectie') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_detail.hoofdafbeelding') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_detail.optioneel_staat_naast_tekst') ?></p>

    <form method="post" action="/api/admin/update-detail-section-main-image.php" class="admin-product-form" style="margin-top:0.75rem;">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <div class="admin-form-row">
        <?php media_picker_field('media_id', MediaService::find((int) ($section['main_media_id'] ?? 0)), 'Hoofdafbeelding', 'Kies er een uit de mediabibliotheek, of upload een nieuwe in het venster dat opent.', false); ?>
      </div>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="main_image_alt_nl" maxlength="255" value="<?= $h((string) ($section['main_image_alt_nl'] ?? '')) ?>" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="main_image_alt_en" maxlength="255" value="<?= $h((string) ($section['main_image_alt_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>

    <?php if ($hasMainImage): ?>
      <form method="post" action="/api/admin/update-detail-section-main-image.php" class="admin-inline-form" style="margin-top:0.75rem;" onsubmit="return confirm('Hoofdafbeelding verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
        <input type="hidden" name="remove_image" value="1">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('block_detail.hoofdafbeelding_verwijderen') ?></button>
      </form>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_detail.kenmerken') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_detail.vinkje_icoon_staat_vast') ?></p>

    <?php if ($points === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_detail.kenmerken_sectie') ?></p>
    <?php endif; ?>

    <?php foreach ($points as $index => $point): ?>
      <?php
        $pointId = (int) $point['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($points) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-detail-section-point.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="point_id" value="<?= $pointId ?>">

          <?php admin_lang_bar(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label><?= admin_te('common.title') ?>*
              <input type="text" name="title_nl" maxlength="255" value="<?= $h((string) $point['title_nl']) ?>" <?= admin_lang_required('nl') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label><?= admin_te('common.title') ?>
              <input type="text" name="title_en" maxlength="255" value="<?= $h((string) ($point['title_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label><?= admin_te('block_detail.tekst') ?>*
              <textarea name="body_nl" maxlength="500" rows="2" <?= admin_lang_required('nl') ?>><?= $h((string) $point['body_nl']) ?></textarea>
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label><?= admin_te('block_detail.tekst_2') ?>
              <textarea name="body_en" maxlength="500" rows="2"<?= admin_lang_placeholder_attr('en') ?>><?= $h((string) ($point['body_en'] ?? '')) ?></textarea>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= ((bool) $point['is_active']) ? 'checked' : '' ?>>
            <?= admin_te('block_detail.actief_uitgevinkt_kenmerk_getoond') ?>
          </label>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-detail-section-point.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="point_id" value="<?= $pointId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-detail-section-point.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="point_id" value="<?= $pointId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-detail-section-point.php" class="admin-inline-form" onsubmit="return confirm('Dit kenmerk definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="point_id" value="<?= $pointId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_detail.nieuw_kenmerk_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-detail-section-point.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title_en" maxlength="255"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_detail.tekst_3') ?>*
          <textarea name="body_nl" maxlength="500" rows="2" <?= admin_lang_required('nl') ?>></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_detail.tekst_4') ?>
          <textarea name="body_en" maxlength="500" rows="2"<?= admin_lang_placeholder_attr('en') ?>></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit"><?= admin_te('block_detail.kenmerk_toevoegen') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_detail.galerij') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_detail.optioneel_rij_afbeeldingen_onder') ?></p>

    <?php if ($images === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_detail.afbeeldingen_galerij') ?></p>
    <?php endif; ?>

    <?php foreach ($images as $index => $image): ?>
      <?php
        $imageId = (int) $image['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($images) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-detail-section-image.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="image_id" value="<?= $imageId ?>">

          <div class="admin-form-row">
            <?php media_picker_field('media_id', MediaService::find((int) ($image['media_id'] ?? 0)), 'Afbeelding', '', false); ?>
          </div>

          <?php admin_lang_bar(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label><?= admin_te('common.alt_text') ?>
              <input type="text" name="alt_nl" maxlength="255" value="<?= $h((string) ($image['alt_nl'] ?? '')) ?>" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label><?= admin_te('common.alt_text') ?>
              <input type="text" name="alt_en" maxlength="255" value="<?= $h((string) ($image['alt_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-detail-section-image.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-detail-section-image.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-detail-section-image.php" class="admin-inline-form" onsubmit="return confirm('Deze afbeelding definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_detail.nieuwe_afbeelding_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-detail-section-image.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">

      <div class="admin-form-row">
        <?php media_picker_field('media_id', null, 'Afbeelding*', 'Kies er een uit de bibliotheek, of upload een nieuwe in het venster dat opent.', false); ?>
      </div>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="alt_nl" maxlength="255" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="alt_en" maxlength="255"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit"><?= admin_te('block_detail.afbeelding_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
