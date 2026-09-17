<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

require_once __DIR__ . '/_media_picker.php';

/**
 * Editor for one Text + image split block (?section=<page content_key>:<section_key>):
 * its section fields, its paragraphs one card each and its images one card
 * each.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the eyebrow, title and button label, every paragraph and every image's alt
 * text show the language chosen in the CMS shell, as stored and without the
 * default language's words in an empty translation; a paragraph's text is
 * required only in the default language. Each save writes that language
 * only, for the section, that one paragraph or that one image. The layout,
 * the button URL, "Actief" and the chosen media are the same in every
 * language and stay on screen in each. A paragraph or an image keeps its id
 * however often it is saved or moved, so the words of the other languages
 * stay with it. A NEW paragraph or image is written in the default language,
 * like a new page, and translated afterwards on its own card. Input a
 * refused section save hands back comes back in the language it was typed
 * in, and that form then starts out unsaved in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = TextImageSplitContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new TextImageSplitRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit(admin_t('screen.onbekende_sectie'));
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => \App\Service\PageLocalization::name((int) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['id']),
        'section_label' => \App\Service\SectionRegistry::label('text_image_split'),
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];

$repository = new TextImageSplitRepository();

$split = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($split === null) {
    // First time this section is opened in the admin: create the row now,
    // empty and active exactly as TextImageSplitBlock::create() does, so
    // paragraphs/images can be attached.
    $repository->upsertSection($pageSlug, $sectionKeyPart, ['is_active' => true, 'layout' => 'image_right']);
    $split = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$splitId = (int) $split['id'];
$paragraphs = $repository->findParagraphsBySectionId($splitId);
$images = $repository->findImagesBySectionId($splitId);

$errors = $_SESSION['admin_tis_errors'] ?? [];
$old = $_SESSION['admin_tis_old'] ?? null;
unset($_SESSION['admin_tis_errors'], $_SESSION['admin_tis_old']);

$paragraphErrors = $_SESSION['admin_tis_paragraph_errors'] ?? [];
unset($_SESSION['admin_tis_paragraph_errors']);

$imageErrors = $_SESSION['admin_tis_image_errors'] ?? [];
unset($_SESSION['admin_tis_image_errors']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();

// The words of the section, of every paragraph and of every image, in one query.
BlockLocalization::preloadBlocks(['text_image_splits' => [$splitId]]);

// What is the same in every language: handed back, else stored.
$sectionValues = is_array($old) ? [
    'layout' => (string) ($old['layout'] ?? 'image_right'),
    'button_url' => (string) ($old['button_url'] ?? ''),
    'is_active' => !empty($old['is_active']),
] : [
    'layout' => (string) ($split['layout'] ?? 'image_right'),
    'button_url' => (string) ($split['button_url'] ?? ''),
    'is_active' => (bool) $split['is_active'],
];

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The section's words on screen: typed and handed back in this language, else stored in it. */
$sectionWord = static function (string $field) use ($old, $oldInThisLanguage, $splitId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('text_image_splits', $splitId, $field, $editLanguage);
};

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = $placeholder !== '' ? $placeholder : ' placeholder="Optioneel"';
$altPlaceholder = $placeholder !== '' ? $placeholder : ' placeholder="Leeg = alt-tekst uit de mediabibliotheek"';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> <?= admin_te('block_textimage.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($pageSlug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_textimage.text', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_textimage.sectie_wijzigingen_direct_zichtbaar', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></p>

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

  <?php if ($paragraphErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($paragraphErrors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($imageErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($imageErrors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= admin_te('block_textimage.sectie') ?></h2>
    <form method="post" action="/api/admin/update-text-image-split-section.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <div class="admin-form-row">
        <label><?= admin_te('block_textimage.afbeelding_positie') ?>
          <select name="layout">
            <option value="image_right" <?= $sectionValues['layout'] === 'image_right' ? 'selected' : '' ?>><?= admin_te('block_textimage.afbeelding_rechts_tekst_links') ?></option>
            <option value="image_left" <?= $sectionValues['layout'] === 'image_left' ? 'selected' : '' ?>><?= admin_te('block_textimage.afbeelding_links_tekst_rechts') ?></option>
          </select>
        </label>
      </div>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_textimage.eyebrow') ?>
          <input type="text" name="eyebrow" maxlength="150" value="<?= $h($sectionWord('eyebrow')) ?>"<?= $optional ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_textimage.titel_h2') ?>
          <input type="text" name="title" maxlength="255" value="<?= $h($sectionWord('title')) ?>"<?= $optional ?>>
        </label>
      </div>

      <p class="admin-text-muted"><?= admin_te('block_textimage.er_titel_ingevuld_krijgt') ?></p>

      <div class="admin-form-row">
        <label><?= admin_te('block_textimage.knoptekst') ?>
          <input type="text" name="button_label" maxlength="150" value="<?= $h($sectionWord('button_label')) ?>"<?= $optional ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_textimage.knop_url') ?>
          <input type="text" name="button_url" maxlength="255" value="<?= $h($sectionValues['button_url']) ?>" placeholder="Bijv. contact.php — leeg = geen knop">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_textimage.knoptekst_url_horen_elkaar') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $sectionValues['is_active'] ? 'checked' : '' ?>>
        <?= admin_te('block_textimage.actief_uitgevinkt_hele_sectie') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_textimage.alinea_s') ?></h2>

    <?php if ($paragraphs === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_textimage.alinea_s_sectie') ?></p>
    <?php endif; ?>

    <?php foreach ($paragraphs as $index => $paragraph): ?>
      <?php
        $paragraphId = (int) $paragraph['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($paragraphs) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-text-image-split-paragraph.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="paragraph_id" value="<?= $paragraphId ?>">
          <?= admin_localized_input($editLanguage) ?>

          <?php admin_localized_bar($editLanguage); ?>
          <div class="admin-form-row">
            <label><?= admin_te('block_textimage.tekst') ?><?= $marker ?>
              <textarea name="content" maxlength="1000" rows="3"<?= $required ?><?= $placeholder ?>><?= $h(BlockLocalization::raw('text_image_split_paragraphs', $paragraphId, 'content', $editLanguage)) ?></textarea>
            </label>
          </div>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-text-image-split-paragraph.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="paragraph_id" value="<?= $paragraphId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-text-image-split-paragraph.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="paragraph_id" value="<?= $paragraphId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-text-image-split-paragraph.php" class="admin-inline-form" onsubmit="return confirm('Deze alinea definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="paragraph_id" value="<?= $paragraphId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_textimage.nieuwe_alinea_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-text-image-split-paragraph.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_id" value="<?= $splitId ?>">

      <?php admin_localized_bar($defaultLanguage); ?>
      <?php admin_localized_new_item_note($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_textimage.tekst') ?>*
          <textarea name="content" maxlength="1000" rows="3" required></textarea>
        </label>
      </div>

      <button type="submit"><?= admin_te('block_textimage.alinea_toevoegen') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_textimage.afbeeldingen') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_textimage.1_afbeelding_toont_enkele') ?></p>

    <?php if ($images === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_textimage.afbeeldingen_sectie') ?></p>
    <?php endif; ?>

    <?php foreach ($images as $index => $image): ?>
      <?php
        $imageId = (int) $image['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($images) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-text-image-split-image.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="image_id" value="<?= $imageId ?>">
          <?= admin_localized_input($editLanguage) ?>

          <div class="admin-form-row">
            <?php media_picker_field('media_id', MediaService::find((int) ($image['media_id'] ?? 0)), 'Afbeelding', 'Kies dezelfde afbeelding gerust op meerdere plekken — hij wordt maar één keer opgeslagen.', false); ?>
          </div>

          <?php admin_localized_bar($editLanguage); ?>
          <div class="admin-form-row">
            <label><?= admin_te('common.alt_text') ?>
              <input type="text" name="alt" maxlength="255" value="<?= $h(BlockLocalization::raw('text_image_split_images', $imageId, 'alt', $editLanguage)) ?>"<?= $altPlaceholder ?>>
            </label>
          </div>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-text-image-split-image.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-text-image-split-image.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-text-image-split-image.php" class="admin-inline-form" onsubmit="return confirm('Deze afbeelding definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_textimage.nieuwe_afbeelding_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-text-image-split-image.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_id" value="<?= $splitId ?>">

      <div class="admin-form-row">
        <?php media_picker_field('media_id', null, 'Afbeelding*', 'Kies er een uit de bibliotheek, of upload een nieuwe in het venster dat opent.', false); ?>
      </div>

      <?php admin_localized_bar($defaultLanguage); ?>
      <?php admin_localized_new_item_note($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="alt" maxlength="255" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
        </label>
      </div>

      <button type="submit"><?= admin_te('block_textimage.afbeelding_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<?php save_bar_script(); ?>
</body>
</html>
