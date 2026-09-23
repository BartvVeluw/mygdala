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
use App\Service\FeatureGridContent;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Repository\FeatureGridRepository;

/**
 * Editor for one Feature grid (?section=<page content_key>:<section_key>):
 * its heading, when it has one, whether it is shown, and its cards.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). The
 * heading, the switch and every card — its icon, words, whether it is shown,
 * its place, a removal mark, new cards — post to
 * api/admin/update-feature-grid.php together, and its one "Opslaan" (or the
 * save bar) stores all of it. ↑, ↓ and "Kaart toevoegen" work on screen
 * (admin/assets/row-list.js); without JavaScript ↑ and ↓ submit the whole
 * form and one empty card waits at the end of the list
 * (App\Service\Blocks\EditorChildList, admin/_editor_rows.php).
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the heading and every card's title and text show the language chosen in
 * the CMS shell, as stored and without the default language's words in an
 * empty translation, and are required only in the default language; a save
 * writes that language only. A card's icon and visibility are the same in
 * every language and stay on screen in each. A card keeps its id however
 * often it is saved or moved, so the words of the other languages stay with
 * it. A NEW card is written in the default language, like a new page. Input
 * a refused save hands back comes back as it was typed, with each message
 * next to its field, and the form then starts out unsaved in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = FeatureGridContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new FeatureGridRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit(admin_t('screen.onbekende_sectie'));
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => \App\Service\PageLocalization::name((int) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['id']),
        'section_label' => \App\Service\SectionRegistry::label('feature_grid'),
        'has_heading' => true,
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];
$hasHeading = $section['has_heading'];

$repository = new FeatureGridRepository();

$grid = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($grid === null) {
    // First time this section is opened in the admin: create the row now,
    // empty and active exactly as FeatureGridBlock::create() does, so cards
    // can be attached to it.
    $repository->upsertGrid($pageSlug, $sectionKeyPart, ['is_active' => true]);
    $grid = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$gridId = (int) $grid['id'];
$items = $repository->findItemsByGridId($gridId);

$errors = $_SESSION['admin_feature_grid_errors'] ?? [];
$fieldErrors = $_SESSION['admin_feature_grid_field_errors'] ?? [];
$old = $_SESSION['admin_feature_grid_old'] ?? null;
unset($_SESSION['admin_feature_grid_errors'], $_SESSION['admin_feature_grid_field_errors'], $_SESSION['admin_feature_grid_old']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();

// The words of the grid and of every card, in one query.
BlockLocalization::preloadBlocks(['feature_grids' => [$gridId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $grid['is_active'];

/** The heading's words on screen: typed and handed back in this language, else stored in it. */
$sectionWord = static function (string $field) use ($old, $oldInThisLanguage, $gridId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('feature_grids', $gridId, $field, $editLanguage);
};

// The cards on screen: as a refused save handed them back, else as stored.
$rows = editor_rows_on_screen(
    $items,
    $oldInThisLanguage ? (array) ($old['items'] ?? []) : null,
    static fn (array $item): array => [
        'icon_key' => (string) $item['icon_key'],
        'icon_media_id' => $item['icon_media_id'] !== null ? (string) $item['icon_media_id'] : '',
        'title' => BlockLocalization::raw('feature_grid_items', (int) $item['id'], 'title', $editLanguage),
        'body' => BlockLocalization::raw('feature_grid_items', (int) $item['id'], 'body', $editLanguage),
        'active' => (int) $item['is_active'] === 1 ? '1' : '',
    ]
);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);

/** One card; the template for a new one is the same markup with the key __KEY__. */
$cardRow = static function (string $key, array $fields, int $position, int $count) use ($h, $marker, $placeholder, $fieldErrors): void {
    [$star, $hint] = editor_row_word_hints($key, $marker, $placeholder);
    $iconId = editor_row_id('items', $key, 'icon_key');
    // A new card starts on the first standard icon, as it always did.
    $chosenIcon = (string) ($fields['icon_key'] ?? (string) array_key_first(FeatureGridContent::ICON_KEYS));
    $iconMediaId = (int) ($fields['icon_media_id'] ?? 0);
    editor_row_open('items', $key, admin_t('block_features.kaart'), $position, $count, ($fields['remove'] ?? '') !== '');
    ?>
        <div class="admin-field" data-feature-icon>
          <?= admin_field_label($iconId, admin_t('block_features.icoon')) ?>
          <select class="admin-select" id="<?= $h($iconId) ?>" name="<?= $h(editor_row_name('items', $key, 'icon_key')) ?>" data-feature-icon-select>
            <option value="<?= $h(FeatureGridContent::ICON_NONE) ?>"<?= $chosenIcon === FeatureGridContent::ICON_NONE ? ' selected' : '' ?>><?= admin_te('block_features.icoon_geen') ?></option>
            <optgroup label="<?= admin_te('block_features.icoon_standaard') ?>">
              <?php foreach (FeatureGridContent::ICON_KEYS as $iconKey => $label): ?>
                <option value="<?= $h($iconKey) ?>"<?= $chosenIcon === $iconKey ? ' selected' : '' ?>><?= $h($label) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <option value="<?= $h(FeatureGridContent::ICON_CUSTOM) ?>"<?= $chosenIcon === FeatureGridContent::ICON_CUSTOM ? ' selected' : '' ?>><?= admin_te('block_features.icoon_eigen') ?></option>
          </select>
          <div class="admin-feature-icon-custom" data-feature-icon-custom>
            <?php media_picker_field(
                editor_row_name('items', $key, 'icon_media_id'),
                $iconMediaId > 0 ? MediaService::findIcon($iconMediaId) : null,
                admin_t('media.picker.icon_label'),
                admin_t('block_features.icoon_eigen_help'),
                true,
                MediaType::ICON
            ); ?>
            <?php editor_field_error($fieldErrors, 'items.' . $key . '.icon_media_id'); ?>
          </div>
        </div>
    <?php
    editor_row_text('items', $key, 'title', admin_t('common.title'), 255, $fields, $fieldErrors, $hint !== '' ? $hint : ' placeholder="Optioneel"');
    editor_row_text('items', $key, 'body', admin_t('block_features.tekst') . $star, 500, $fields, $fieldErrors, $hint, 3);
    editor_row_switch('items', $key, $fields, admin_t('common.visible'));
    editor_row_close();
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> <?= admin_te('block_features.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($pageSlug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_features.text', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_features.sectie_wijzigingen_direct_zichtbaar', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></p>

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

  <form method="post" action="/api/admin/update-feature-grid.php" class="admin-product-form" data-save-name="<?= $h($section['section_label']) ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves a card. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">
    <?= admin_localized_input($editLanguage) ?>
    <?php admin_localized_bar($editLanguage); ?>

    <?php if ($hasHeading): ?>
    <section class="admin-card">
      <h2><?= admin_te('block_features.sectiekop') ?></h2>
      <div class="admin-field">
        <?= admin_field_label('feature-grid-eyebrow', admin_t('block_features.eyebrow')) ?>
        <input type="text" id="feature-grid-eyebrow" name="eyebrow" maxlength="150" value="<?= $h($sectionWord('eyebrow')) ?>"<?= admin_localized_optional_attr($editLanguage) ?><?= editor_field_invalid($fieldErrors, 'eyebrow') ?>>
        <?php editor_field_error($fieldErrors, 'eyebrow'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('feature-grid-title', admin_t('block_features.titel_h2') . $marker) ?>
        <input type="text" id="feature-grid-title" name="title" maxlength="255"<?= $required ?> value="<?= $h($sectionWord('title')) ?>"<?= $placeholder ?><?= editor_field_invalid($fieldErrors, 'title') ?>>
        <?php editor_field_error($fieldErrors, 'title'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('feature-grid-lead', admin_t('block_features.introtekst_lead')) ?>
        <textarea id="feature-grid-lead" name="lead" maxlength="500" rows="3"<?= $placeholder ?><?= editor_field_invalid($fieldErrors, 'lead') ?>><?= $h($sectionWord('lead')) ?></textarea>
        <?php editor_field_error($fieldErrors, 'lead'); ?>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_features.actief_uitgevinkt_hele_sectie') ?>
      </label>
    </section>
    <?php else: ?>
    <section class="admin-card">
      <h2><?= admin_te('block_features.zichtbaarheid') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_features.sectie_heeft_eigen_titel') ?></p>
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_features.actief_uitgevinkt_sectie_alle') ?>
      </label>
    </section>
    <?php endif; ?>

    <section class="admin-card" aria-labelledby="feature-grid-items-title">
      <h2 id="feature-grid-items-title"><?= admin_te('block_features.kaarten') ?></h2>

      <?php if ($rows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_features.kaarten_sectie') ?></p>
      <?php endif; ?>

      <input type="hidden" name="items_present" value="1">
      <div class="admin-row-cards" data-row-list="feature-grid-items">
        <?php foreach ($rows as $position => $row): ?>
          <?php $cardRow($row['key'], $row['fields'], $position, count($rows)); ?>
        <?php endforeach; ?>
        <noscript>
          <?php $cardRow(editor_rows_free_key($rows), ['active' => '1'], count($rows), count($rows) + 1); ?>
        </noscript>
      </div>
      <?php editor_rows_status('feature-grid-items'); ?>
      <?php editor_rows_add('feature-grid-items', admin_t('block_features.kaart_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="feature-grid-items"><?php $cardRow('__KEY__', ['active' => '1'], 0, 1); ?></template>
    </section>

    <div class="admin-form-actions">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </div>
  </form>
</main>
<?php media_picker_modal(); ?>
<?php save_bar(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
<?php media_picker_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/feature-grid.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
