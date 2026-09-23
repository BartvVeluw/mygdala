<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

/**
 * Editor for one Text + image split block (?section=<page content_key>:<section_key>):
 * its section fields, its paragraphs and its images.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). The
 * section fields and both lists — every paragraph, every image with its
 * media item and alt text, their order, removal marks, new ones — post to
 * api/admin/update-text-image-split-section.php together, and its one
 * "Opslaan" (or the save bar) stores all of it. Choosing an image in the
 * picker only fills the row's field; nothing is saved until "Opslaan". ↑, ↓
 * and "toevoegen" work on screen (admin/assets/row-list.js); without
 * JavaScript ↑ and ↓ submit the whole form and one empty paragraph waits at
 * the end of its list (App\Service\Blocks\EditorChildList,
 * admin/_editor_rows.php). An image needs the picker, which needs
 * JavaScript anyway.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the eyebrow, title and button label, every paragraph and every image's alt
 * text show the language chosen in the CMS shell, as stored and without the
 * default language's words in an empty translation; a paragraph's text is
 * required only in the default language. A save writes that language only.
 * The layout, the button URL, "Actief" and the chosen media are the same in
 * every language and stay on screen in each. A paragraph or an image keeps
 * its id however often it is saved or moved, so the words of the other
 * languages stay with it. A NEW paragraph or image is written in the
 * default language, like a new page. Input a refused save hands back comes
 * back as it was typed (both lists, order and marks included), with each
 * message next to its field, and the form then starts out unsaved in the
 * save bar.
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

$errors = $_SESSION['admin_tis_errors'] ?? [];
$fieldErrors = $_SESSION['admin_tis_field_errors'] ?? [];
$old = $_SESSION['admin_tis_old'] ?? null;
unset($_SESSION['admin_tis_errors'], $_SESSION['admin_tis_field_errors'], $_SESSION['admin_tis_old']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();

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

// Both lists on screen: as a refused save handed them back, else as stored.
$paragraphRows = editor_rows_on_screen(
    $repository->findParagraphsBySectionId($splitId),
    $oldInThisLanguage ? (array) ($old['paragraphs'] ?? []) : null,
    static fn (array $paragraph): array => [
        'content' => BlockLocalization::raw('text_image_split_paragraphs', (int) $paragraph['id'], 'content', $editLanguage),
    ]
);
$imageRows = editor_rows_on_screen(
    $repository->findImagesBySectionId($splitId),
    $oldInThisLanguage ? (array) ($old['images'] ?? []) : null,
    static fn (array $image): array => [
        'media_id' => (string) (int) ($image['media_id'] ?? 0),
        'alt' => BlockLocalization::raw('text_image_split_images', (int) $image['id'], 'alt', $editLanguage),
    ]
);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$marker = admin_localized_required($editLanguage) !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = $placeholder !== '' ? $placeholder : ' placeholder="Optioneel"';

/** One paragraph; the template for a new one is the same markup with the key __KEY__. */
$paragraphRow = static function (string $key, array $fields, int $position, int $count) use ($marker, $placeholder, $fieldErrors): void {
    [$star, $hint] = editor_row_word_hints($key, $marker, $placeholder);
    editor_row_open('paragraphs', $key, admin_t('block_textimage.alinea'), $position, $count, ($fields['remove'] ?? '') !== '');
    editor_row_text('paragraphs', $key, 'content', admin_t('block_textimage.tekst') . $star, 1000, $fields, $fieldErrors, $hint, 3);
    editor_row_close();
};

/** One image; the template for a new one is the same markup with the key __KEY__. */
$imageRow = static function (string $key, array $fields, int $position, int $count) use ($placeholder, $fieldErrors): void {
    editor_row_open('images', $key, admin_t('block_textimage.afbeelding'), $position, $count, ($fields['remove'] ?? '') !== '');
    editor_row_media('images', $key, $fields, $fieldErrors, ctype_digit($key) ? 'Afbeelding' : 'Afbeelding*', 'Kies dezelfde afbeelding gerust op meerdere plekken — hij wordt maar één keer opgeslagen.');
    editor_row_media_alt('images', $key, $fields, $fieldErrors, $placeholder);
    editor_row_close();
};
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
    <div class="admin-alert admin-alert--error" role="alert">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/update-text-image-split-section.php" class="admin-product-form" data-save-name="<?= $h($section['section_label']) ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves a row. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">
    <?= admin_localized_input($editLanguage) ?>
    <?php admin_localized_bar($editLanguage); ?>

    <section class="admin-card">
      <h2><?= admin_te('block_textimage.sectie') ?></h2>
      <div class="admin-field">
        <?= admin_field_label('tis-layout', admin_t('block_textimage.afbeelding_positie')) ?>
        <select class="admin-select" id="tis-layout" name="layout">
          <option value="image_right"<?= $sectionValues['layout'] === 'image_right' ? ' selected' : '' ?>><?= admin_te('block_textimage.afbeelding_rechts_tekst_links') ?></option>
          <option value="image_left"<?= $sectionValues['layout'] === 'image_left' ? ' selected' : '' ?>><?= admin_te('block_textimage.afbeelding_links_tekst_rechts') ?></option>
        </select>
      </div>

      <div class="admin-field">
        <?= admin_field_label('tis-eyebrow', admin_t('block_textimage.eyebrow')) ?>
        <input type="text" id="tis-eyebrow" name="eyebrow" maxlength="150" value="<?= $h($sectionWord('eyebrow')) ?>"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'eyebrow') ?>>
        <?php editor_field_error($fieldErrors, 'eyebrow'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('tis-title', admin_t('block_textimage.titel_h2')) ?>
        <input type="text" id="tis-title" name="title" maxlength="255" value="<?= $h($sectionWord('title')) ?>"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'title') ?>>
        <?php editor_field_error($fieldErrors, 'title'); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_textimage.er_titel_ingevuld_krijgt') ?></p>

      <div class="admin-field">
        <?= admin_field_label('tis-button-label', admin_t('block_textimage.knoptekst')) ?>
        <input type="text" id="tis-button-label" name="button_label" maxlength="150" value="<?= $h($sectionWord('button_label')) ?>"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'button_label') ?>>
        <?php editor_field_error($fieldErrors, 'button_label'); ?>
      </div>
      <div class="admin-field">
        <?= admin_field_label('tis-button-url', admin_t('block_textimage.knop_url')) ?>
        <input type="text" id="tis-button-url" name="button_url" maxlength="255" value="<?= $h($sectionValues['button_url']) ?>" placeholder="Bijv. contact.php — leeg = geen knop">
      </div>
      <p class="admin-text-muted"><?= admin_te('block_textimage.knoptekst_url_horen_elkaar') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $sectionValues['is_active'] ? 'checked' : '' ?>>
        <?= admin_te('block_textimage.actief_uitgevinkt_hele_sectie') ?>
      </label>
    </section>

    <section class="admin-card" aria-labelledby="tis-paragraphs-title">
      <h2 id="tis-paragraphs-title"><?= admin_te('block_textimage.alinea_s') ?></h2>

      <?php if ($paragraphRows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_textimage.alinea_s_sectie') ?></p>
      <?php endif; ?>

      <input type="hidden" name="paragraphs_present" value="1">
      <div class="admin-row-cards" data-row-list="text-image-split-paragraphs">
        <?php foreach ($paragraphRows as $position => $row): ?>
          <?php $paragraphRow($row['key'], $row['fields'], $position, count($paragraphRows)); ?>
        <?php endforeach; ?>
        <noscript>
          <?php $paragraphRow(editor_rows_free_key($paragraphRows), [], count($paragraphRows), count($paragraphRows) + 1); ?>
        </noscript>
      </div>
      <?php editor_rows_status('text-image-split-paragraphs'); ?>
      <?php editor_rows_add('text-image-split-paragraphs', admin_t('block_textimage.alinea_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="text-image-split-paragraphs"><?php $paragraphRow('__KEY__', [], 0, 1); ?></template>
    </section>

    <section class="admin-card" aria-labelledby="tis-images-title">
      <h2 id="tis-images-title"><?= admin_te('block_textimage.afbeeldingen') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_textimage.1_afbeelding_toont_enkele') ?></p>

      <?php if ($imageRows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_textimage.afbeeldingen_sectie') ?></p>
      <?php endif; ?>

      <input type="hidden" name="images_present" value="1">
      <div class="admin-row-cards" data-row-list="text-image-split-images">
        <?php foreach ($imageRows as $position => $row): ?>
          <?php $imageRow($row['key'], $row['fields'], $position, count($imageRows)); ?>
        <?php endforeach; ?>
      </div>
      <?php editor_rows_status('text-image-split-images'); ?>
      <?php editor_rows_add('text-image-split-images', admin_t('block_textimage.afbeelding_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="text-image-split-images"><?php $imageRow('__KEY__', [], 0, 1); ?></template>
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
