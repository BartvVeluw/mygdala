<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_image_focus.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Media\BlockImage;
use App\Service\Media\MediaService;
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

/**
 * Editor for one Tekst met afbeelding block (?section=<page content_key>:<section_key>):
 * whether it shows, and its items (Tekst met afbeelding 2.0,
 * App\Service\TextImageSplitContent). An item is an eyebrow, a title, a
 * rich-text body and an optional button beside at most one picture, with the
 * picture's side, share of the row, height and focus point.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor").
 * Everything — "Actief" and every item with its words, picture, layout,
 * order, removal mark, and the new ones — posts to
 * api/admin/update-text-image-split-section.php together, and its one
 * "Opslaan" (or the save bar) stores all of it. Choosing a picture only fills
 * the item's field. ↑, ↓, Verwijderen and "Item toevoegen" work on screen
 * (admin/assets/row-list.js); a new item gets the rich-text editor
 * (admin/assets/admin.js) and the focus preview (admin/assets/image-focus.js)
 * like the ones the server printed. Without JavaScript ↑ and ↓ submit the
 * whole form, one empty item waits at the end of the list, and the body is a
 * plain textarea of HTML.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * an item's eyebrow, title, body, button label and alt text show the language
 * chosen in the CMS shell, as stored and without the default language's
 * words in an empty translation. A save writes that language only. The
 * picture, the layout, the button URL and "Actief" are the same in every
 * language and stay on screen in each. An item keeps its id however often it
 * is saved or moved, so the words of the other languages stay with it. A NEW
 * item is written in the default language, like a new page. Input a refused
 * save hands back comes back as it was typed (order and marks included), with
 * each message next to its field, and the form then starts out unsaved in the
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
    // items can be attached.
    $repository->upsertSection($pageSlug, $sectionKeyPart, ['is_active' => true]);
    $split = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$splitId = (int) $split['id'];

$errors = $_SESSION['admin_tis_errors'] ?? [];
$fieldErrors = $_SESSION['admin_tis_field_errors'] ?? [];
$old = $_SESSION['admin_tis_old'] ?? null;
unset($_SESSION['admin_tis_errors'], $_SESSION['admin_tis_field_errors'], $_SESSION['admin_tis_old']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();

// The words of every item, in one query.
BlockLocalization::preloadBlocks(['text_image_splits' => [$splitId]]);

$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $split['is_active'];

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

// The items on screen: as a refused save handed them back, else as stored.
$itemRows = editor_rows_on_screen(
    $repository->findItemsBySectionId($splitId),
    $oldInThisLanguage ? (array) ($old['items'] ?? []) : null,
    static function (array $item) use ($editLanguage): array {
        $fields = [
            'media_id' => (string) (int) ($item['media_id'] ?? 0),
            'button_url' => (string) ($item['button_url'] ?? ''),
        ] + TextImageSplitContent::layout($item);

        foreach (array_keys(BlockLocalization::fields('text_image_split_items')) as $field) {
            $fields[$field] = BlockLocalization::raw('text_image_split_items', (int) $item['id'], $field, $editLanguage);
        }

        return $fields;
    }
);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$placeholder = admin_localized_placeholder_attr($editLanguage);

$sides = ['left' => admin_t('block_textimage.links'), 'right' => admin_t('block_textimage.rechts')];
$columns = [
    '25' => admin_t('block_textimage.breedte_25'),
    '50' => admin_t('block_textimage.breedte_50'),
    '75' => admin_t('block_textimage.breedte_75'),
];
$heights = [
    'small' => admin_t('block_textimage.hoogte_small'),
    'medium' => admin_t('block_textimage.hoogte_medium'),
    'large' => admin_t('block_textimage.hoogte_large'),
];

/**
 * One item; the template for a new one is the same markup with the key __KEY__.
 *
 * @param array<string, mixed>|null $stored the stored row, null for a new item
 */
$itemRow = static function (string $key, array $fields, int $position, int $count, ?array $stored) use ($placeholder, $fieldErrors, $h, $sides, $columns, $heights): void {
    [, $hint] = editor_row_word_hints($key, '', $placeholder);
    $layout = TextImageSplitContent::layout($fields);

    // What the focus preview shows: the chosen library item, else a picture
    // from before the library that only the stored row knows.
    $mediaId = (int) ($fields['media_id'] ?? 0);
    $media = $mediaId > 0 ? MediaService::find($mediaId) : null;
    $legacyPath = $stored !== null && (int) ($stored['media_id'] ?? 0) === 0 ? BlockImage::fromOwner($stored, null)['image_path'] : '';
    $previewSrc = $media !== null ? $media->displayPath() : $legacyPath;

    editor_row_open('items', $key, admin_t('block_textimage.item'), $position, $count, ($fields['remove'] ?? '') !== '', 'admin-tis-item');
    editor_row_text('items', $key, 'eyebrow', admin_t('block_textimage.eyebrow'), 150, $fields, $fieldErrors, $hint !== '' ? $hint : ' placeholder="Optioneel"');
    editor_row_text('items', $key, 'title', admin_t('block_textimage.titel_h2'), 255, $fields, $fieldErrors, $hint);
    editor_row_rich('items', $key, 'body', admin_t('block_textimage.tekst'), $fields, $fieldErrors);
    echo '<p class="admin-text-muted">' . admin_te('block_textimage.er_titel_ingevuld_krijgt') . '</p>';

    editor_row_media('items', $key, $fields, $fieldErrors, admin_t('block_textimage.afbeelding'), admin_t('block_textimage.afbeelding_uitleg'), true);
    if ($legacyPath !== '' && $mediaId === 0) {
        echo '<p class="admin-text-muted">' . $h(admin_t('block_textimage.afbeelding_zonder_bibliotheek', ['path' => $legacyPath])) . '</p>';
    }
    editor_row_media_alt('items', $key, $fields, $fieldErrors, $placeholder);

    echo '<div class="admin-tis-layout">';
    editor_row_choice('items', $key, 'image_side', admin_t('block_textimage.afbeelding_positie'), $sides, $layout['image_side']);
    editor_row_choice('items', $key, 'image_column', admin_t('block_textimage.breedte'), $columns, $layout['image_column'], 'tis-column');
    editor_row_choice('items', $key, 'image_height', admin_t('block_textimage.hoogte'), $heights, $layout['image_height'], 'tis-height');
    echo '</div>';
    echo '<p class="admin-text-muted">' . admin_te('block_textimage.layout_uitleg') . '</p>';

    media_focus_field(
        editor_row_name('items', $key, 'image_focus'),
        $layout['image_focus'],
        $previewSrc,
        admin_t('block_textimage.focus'),
        admin_t('help.block_textimage.focus'),
        admin_t('block_textimage.focus_voorbeeld')
    );

    editor_row_text('items', $key, 'button_label', admin_t('block_textimage.knoptekst'), 150, $fields, $fieldErrors, $hint !== '' ? $hint : ' placeholder="Optioneel"');
    editor_row_text('items', $key, 'button_url', admin_t('block_textimage.knop_url'), 255, $fields, $fieldErrors, ' placeholder="Bijv. /contact — leeg = geen knop"');
    echo '<p class="admin-text-muted">' . admin_te('block_textimage.knoptekst_url_horen_elkaar') . '</p>';
    editor_row_close();
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> <?= admin_te('block_textimage.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
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
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_textimage.actief_uitgevinkt_hele_sectie') ?>
      </label>
    </section>

    <section class="admin-card" aria-labelledby="tis-items-title">
      <h2 id="tis-items-title"><?= admin_te('block_textimage.items') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_textimage.items_uitleg') ?></p>

      <?php if ($itemRows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_textimage.items_leeg') ?></p>
      <?php endif; ?>

      <input type="hidden" name="items_present" value="1">
      <div class="admin-row-cards" data-row-list="text-image-split-items">
        <?php foreach ($itemRows as $position => $row): ?>
          <?php $itemRow($row['key'], $row['fields'], $position, count($itemRows), $row['stored']); ?>
        <?php endforeach; ?>
        <noscript>
          <?php $itemRow(editor_rows_free_key($itemRows), [], count($itemRows), count($itemRows) + 1, null); ?>
        </noscript>
      </div>
      <?php editor_rows_status('text-image-split-items'); ?>
      <?php editor_rows_add('text-image-split-items', admin_t('block_textimage.item_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="text-image-split-items"><?php $itemRow('__KEY__', [], 0, 1, null); ?></template>
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
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/image-focus.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
