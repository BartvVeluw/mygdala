<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_font_library.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Theme\FontFileInspector;
use App\Service\Theme\FontLibrary;

/**
 * One family of the Font Library: new (no id) or an existing one (?id=).
 * Vormgeving → Lettertypen, behind settings.manage. See THEMING.md, "Font
 * Library".
 *
 * NEW: a name, its kind (with or without serifs: the fallback while it
 * loads), an optional source or licence link, and the files, each with the
 * variant it is ("Normaal", "Vet", "Cursief"). admin/assets/font-library-admin.js
 * gives every chosen file its own row, guesses the variant from the file
 * name (Roboto-BoldItalic.ttf → Vet cursief) and shows the file in its own
 * font straight from the computer, before anything is uploaded. Without
 * script there is one file with one variant choice. Nothing is saved until
 * "Lettertype opslaan", and then all or nothing.
 *
 * EXISTING: the same fields (renaming changes no page: CSS names the family
 * by its id), every variant with a sample in that weight and style, Vervangen
 * and Verwijderen per variant, more variants, a preview of the family as the
 * website will show it (from the stored files, through the same @font-face
 * rules a page gets), who uses it, and deleting the family — refused, with
 * who uses it, while anybody does.
 *
 * The licence warning and the manual (admin_font_help()) are on this screen
 * because this is where a file is chosen.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$family = null;

if ($idParam !== null) {
    $family = $idParam === false ? null : FontLibrary::family($idParam);

    if ($family === null) {
        http_response_code(404);
        exit(admin_t('fonts.not_found'));
    }
}

$isNew = $family === null;
$familyId = $isNew ? 0 : (int) $family['id'];

$errors = $_SESSION['admin_font_errors'] ?? [];
$old = $_SESSION['admin_font_old'] ?? null;
unset($_SESSION['admin_font_errors'], $_SESSION['admin_font_old']);
$errors = is_array($errors) ? array_values(array_map('strval', $errors)) : [];

$value = static function (string $field) use ($old, $family): string {
    if (is_array($old) && array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($family[$field] ?? ($field === 'category' ? 'sans' : ''));
};

$notices = ['created', 'saved', 'added', 'replaced', 'variant_deleted'];
$notice = is_string($_GET['done'] ?? null) && in_array($_GET['done'], $notices, true) ? $_GET['done'] : '';

$usage = $isNew ? ['site' => [], 'others' => []] : FontLibrary::usage($familyId);
$inUse = FontLibrary::inUse($usage);
$variants = $isNew ? [] : $family['variants'];
$usable = !$isNew && FontLibrary::isUsable($familyId);

$csrfToken = Csrf::token();
$h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$accept = '.' . implode(',.', array_keys(FontFileInspector::FORMATS));
$category = $value('category');

$pageTitle = $isNew ? admin_t('fonts.new') : admin_t('fonts.edit_title', ['name' => (string) $family['name']]);

/**
 * The sample texts of a preview, in one family: running text, two headings,
 * bold and italic, figures and Dutch characters. `$style` is the attribute
 * for a stored family; a new family's preview gets its font from the script.
 */
$previewSample = static function (string $style) use ($h): string {
    return '<div class="admin-font-specimen" data-font-preview' . $style . '>'
        . '<p class="admin-font-specimen__h1">' . admin_te('fonts.sample_h1') . '</p>'
        . '<p class="admin-font-specimen__h2">' . admin_te('fonts.sample_h2') . '</p>'
        . '<p>' . admin_te('fonts.sample_text') . '</p>'
        . '<p><strong>' . admin_te('fonts.sample_bold') . '</strong></p>'
        . '<p><em>' . admin_te('fonts.sample_italic') . '</em></p>'
        . '<p class="admin-font-specimen__chars">' . $h('0123456789 · € & @ · ë ï é è ü ö ĳ · „Het café’s” — ¿?') . '</p>'
        . '</div>';
};

/** The file choice with a variant per file; enhanced by the script. */
$uploadField = static function (string $id) use ($accept): string {
    return '<div class="admin-field" data-font-uploads>'
        . admin_field_label($id, admin_t('fonts.files_label'), admin_t('help.fonts.files'), true)
        . admin_file_input(['name' => 'font_files[]', 'id' => $id, 'accept' => $accept, 'multiple' => true, 'required' => true, 'data-font-files' => true])
        . '<p class="admin-text-muted">' . admin_te('fonts.files_formats', ['max' => FontFileInspector::maxMegabytes()]) . '</p>'
        . '<div class="admin-field" data-font-variant-fallback>'
        . '<label for="' . $id . '-variant">' . admin_te('fonts.variant_label') . '</label>'
        . '<select id="' . $id . '-variant" name="variants[]" class="admin-select">' . admin_font_variant_options('400') . '</select>'
        . '</div>'
        . '<ul class="admin-font-uploads" data-font-upload-rows hidden></ul>'
        . '<template data-font-variant-options>' . admin_font_variant_options() . '</template>'
        . '</div>';
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<?= $usable ? admin_font_faces([$familyId]) : '' ?>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main admin-font-editor" data-font-editor="<?= $isNew ? 'new' : $familyId ?>"
      data-font-max-bytes="<?= FontFileInspector::MAX_BYTES ?>"
      data-font-text-unreadable="<?= admin_te('fonts.local_unreadable') ?>"
      data-font-text-too-large="<?= admin_te('fonts.local_too_large', ['max' => FontFileInspector::maxMegabytes()]) ?>"
      data-font-text-extension="<?= admin_te('fonts.local_extension') ?>"
      data-font-text-sample="<?= admin_te('fonts.sample_short') ?>">
  <p><a href="/admin/theme.php?tab=lettertypen#lettertypen"><?= admin_te('fonts.back') ?></a></p>
  <h1>
    <?= $h($pageTitle) ?>
    <?php if (!$isNew): ?>
      <?php if ($inUse): ?>
        <span class="admin-badge admin-badge--published" data-font-status="in-use"><?= admin_te('fonts.status_in_use') ?></span>
      <?php else: ?>
        <span class="admin-badge admin-badge--muted" data-font-status="unused"><?= admin_te('fonts.status_unused') ?></span>
      <?php endif; ?>
    <?php endif; ?>
  </h1>

  <?php if ($notice !== '' && $errors === []): ?>
    <p class="admin-alert admin-alert--success" data-font-notice="<?= $h($notice) ?>"><?= admin_te('fonts.done_' . $notice) ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error" data-font-errors role="alert">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?= admin_font_licence_warning() ?>

  <form method="post" action="/api/admin/save-font-family.php" class="admin-product-form admin-font-form" autocomplete="off"
        <?= $isNew ? 'enctype="multipart/form-data" data-font-new-form' : 'data-font-meta-form' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= $familyId ?>">
    <?php endif; ?>

    <section class="admin-card">
      <h2><?= admin_te('fonts.details_title') ?></h2>
      <div class="admin-field">
        <?= admin_field_label('font-name', admin_t('fonts.name'), admin_t('help.fonts.name'), true) ?>
        <input type="text" id="font-name" name="name" maxlength="<?= FontLibrary::MAX_NAME_LENGTH ?>" required value="<?= $h($value('name')) ?>" data-font-name>
      </div>
      <div class="admin-field">
        <?= admin_field_label('font-category', admin_t('fonts.category'), admin_t('help.fonts.category')) ?>
        <select id="font-category" name="category" class="admin-select" data-font-category>
          <?php foreach (array_keys(FontLibrary::CATEGORIES) as $categoryKey): ?>
            <option value="<?= $h($categoryKey) ?>"<?= $category === $categoryKey ? ' selected' : '' ?>><?= admin_te('fonts.category_' . $categoryKey) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <?= admin_field_label('font-source', admin_t('fonts.source'), admin_t('help.fonts.source')) ?>
        <input type="url" id="font-source" name="source_url" maxlength="<?= FontLibrary::MAX_SOURCE_LENGTH ?>" placeholder="https://fonts.google.com/…" value="<?= $h($value('source_url')) ?>">
      </div>
      <?php if (!$isNew): ?>
        <button type="submit"><?= admin_te('common.save') ?></button>
      <?php endif; ?>
    </section>

    <?php if ($isNew): ?>
      <section class="admin-card">
        <h2><?= admin_te('fonts.files_title') ?></h2>
        <p class="admin-text-muted"><?= admin_te('fonts.files_intro') ?></p>
        <?= $uploadField('font-files') ?>
      </section>

      <section class="admin-card">
        <h2><?= admin_te('fonts.preview_title') ?></h2>
        <p class="admin-text-muted" data-font-preview-hint><?= admin_te('fonts.preview_new_hint') ?></p>
        <?= $previewSample('') ?>
      </section>

      <section class="admin-card admin-card--actions">
        <button type="submit"><?= admin_te('fonts.create') ?></button>
        <a href="/admin/theme.php?tab=lettertypen#lettertypen" class="admin-btn-secondary"><?= admin_te('fonts.cancel') ?></a>
      </section>
    <?php endif; ?>
  </form>

  <?php if (!$isNew): ?>
    <section class="admin-card" id="varianten" data-font-variants>
      <h2><?= admin_te('fonts.variants_title') ?></h2>
      <?php if ($variants === []): ?>
        <p class="admin-alert admin-alert--warning"><?= admin_te('fonts.no_variants_warning') ?></p>
      <?php else: ?>
        <div class="admin-page-sections">
          <?php foreach ($variants as $variant): ?>
            <?php
            $variantId = (int) $variant['id'];
            $weight = (int) $variant['weight'];
            $style = (string) $variant['style'];
            $variantLabel = admin_font_variant_label($weight, $style);
            $missing = !FontLibrary::storage()->exists((string) $variant['file_name']);
            ?>
            <div class="admin-section-row admin-font-variant" data-font-variant="<?= $h(\App\Service\Theme\FontVariant::key($weight, $style)) ?>">
              <div class="admin-section-row__body">
                <p class="admin-section-row__name">
                  <?= $h($variantLabel) ?> <span class="admin-text-muted">(<?= $weight ?><?= $style === 'italic' ? ' italic' : '' ?>)</span>
                  <?php if ($missing): ?>
                    <span class="admin-badge admin-badge--canceled" data-font-missing><?= admin_te('fonts.file_missing') ?></span>
                  <?php endif; ?>
                </p>
                <p class="admin-font-variant__sample"<?= admin_font_sample_style($familyId, $category, $weight, $style) ?>><?= admin_te('fonts.sample_short') ?></p>
                <p class="admin-section-row__note"><?= $h((string) $variant['original_filename']) ?> · <?= $h(strtoupper((string) $variant['format'])) ?> · <?= $h(admin_font_kilobytes((int) $variant['byte_size'])) ?></p>
              </div>
              <div class="admin-section-row__actions">
                <details class="admin-font-replace">
                  <summary class="admin-btn-text"><?= admin_te('fonts.replace') ?></summary>
                  <form method="post" action="/api/admin/replace-font-file.php" enctype="multipart/form-data" class="admin-font-replace__form">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="file_id" value="<?= $variantId ?>">
                    <label for="font-replace-<?= $variantId ?>"><?= admin_te('fonts.replace_label', ['variant' => $variantLabel]) ?></label>
                    <?= admin_file_input(['name' => 'font_file', 'id' => 'font-replace-' . $variantId, 'accept' => $accept, 'required' => true]) ?>
                    <button type="submit" class="admin-btn-secondary"><?= admin_te('fonts.replace_submit') ?></button>
                  </form>
                </details>
                <form method="post" action="/api/admin/delete-font-file.php" class="admin-inline-form"<?= admin_confirm_attributes(
                    admin_t('fonts.variant_delete_title'),
                    admin_t('fonts.variant_delete_confirm', ['variant' => $variantLabel]),
                    admin_t('common.delete')
                ) ?>>
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="file_id" value="<?= $variantId ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/api/admin/add-font-files.php" enctype="multipart/form-data" class="admin-font-add" data-font-add-form>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $familyId ?>">
        <h3><?= admin_te('fonts.add_title') ?></h3>
        <p class="admin-text-muted"><?= admin_te('fonts.add_intro') ?></p>
        <?= $uploadField('font-add-files') ?>
        <button type="submit" class="admin-btn-secondary"><?= admin_te('fonts.add_submit') ?></button>
      </form>
    </section>

    <section class="admin-card" id="voorbeeld">
      <h2><?= admin_te('fonts.preview_title') ?></h2>
      <p class="admin-text-muted"><?= admin_te('fonts.preview_stored_hint') ?></p>
      <?= $previewSample($usable ? admin_font_sample_style($familyId, $category, 400, 'normal') : '') ?>
    </section>

    <section class="admin-card" id="gebruik" data-font-usage-card>
      <h2><?= admin_te('fonts.usage_title') ?></h2>
      <?php if (!$inUse): ?>
        <p class="admin-text-muted" data-font-usage-none><?= admin_te('fonts.usage_none') ?></p>
      <?php else: ?>
        <ul data-font-usage-list>
          <?php if ($usage['site'] !== []): ?>
            <li><a href="/admin/theme.php#typografie"><?= $h(admin_t('fonts.usage_site', ['roles' => implode(', ', array_map(static fn (string $role): string => admin_t('fonts.role_' . $role), $usage['site']))])) ?></a></li>
          <?php endif; ?>
          <?php foreach ($usage['others'] as $use): ?>
            <li><a href="<?= $h($use['url']) ?>"><?= $h($use['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="admin-card" id="verwijderen">
      <h2><?= admin_te('fonts.delete_title') ?></h2>
      <?php if ($inUse): ?>
        <p class="admin-text-muted" data-font-delete-blocked><?= $h(FontLibrary::usageSentence($usage)) ?></p>
      <?php else: ?>
        <p class="admin-text-muted"><?= admin_te('fonts.delete_intro') ?></p>
        <form method="post" action="/api/admin/delete-font-family.php" class="admin-inline-form"<?= admin_confirm_attributes(
            admin_t('fonts.delete_confirm_title'),
            admin_t('fonts.delete_confirm', ['name' => (string) $family['name']]),
            admin_t('common.delete')
        ) ?>>
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $familyId ?>">
          <input type="hidden" name="from" value="editor">
          <button type="submit" class="admin-btn-danger"><?= admin_te('fonts.delete_submit') ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?= admin_font_help($isNew) ?>
</main>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/font-library-admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
</body>
</html>
