<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\StepListContent;
use App\Repository\StepListRepository;

/**
 * Editor for one step list (?section=<page content_key>:<section_key>): its
 * heading, whether it is shown, and its steps. A step's number is its place
 * among the shown steps (StepListContent), so it follows every reorder.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). The
 * heading, the switch and every step — its words, whether it is shown, its
 * place, a removal mark, new steps — post to
 * api/admin/update-step-list-section.php together, and its one "Opslaan" (or the
 * save bar) stores all of it. ↑ and ↓ move a step on screen and "Stap
 * toevoegen" adds an empty one (admin/assets/row-list.js); without
 * JavaScript ↑ and ↓ submit the whole form, which stores what was typed and
 * then moves the step, and one empty step waits at the end of the list.
 * Verwijderen only marks a step; the save removes it
 * (App\Service\Blocks\EditorChildList, admin/_editor_rows.php).
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the heading and every step show the language chosen in the CMS shell,
 * as stored and without the default language's words in an empty
 * translation, and are required only in the default language; a save writes
 * that language only. A step keeps its id however often it is saved or
 * moved, so the words of the other languages stay with it. A NEW step is
 * written in the default language, like a new page. The language bar is
 * printed once, above everything it applies to. Input a refused save hands
 * back comes back as it was typed (steps, order and marks included),
 * with each message next to its field, and the form then starts out unsaved
 * in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = StepListContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StepListRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit(admin_t('screen.onbekende_sectie'));
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => \App\Service\PageLocalization::name((int) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['id']),
        'section_label' => \App\Service\SectionRegistry::label('step_list'),
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];

$repository = new StepListRepository();

$stepListSection = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($stepListSection === null) {
    // First time this section is opened in the admin: create the row now,
    // empty and active exactly as StepListBlock::create() does, so steps can
    // be attached to it.
    $repository->upsertSection($pageSlug, $sectionKeyPart, ['is_active' => true]);
    $stepListSection = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$sectionId = (int) $stepListSection['id'];
$items = $repository->findItemsBySectionId($sectionId);

$errors = $_SESSION['admin_step_list_errors'] ?? [];
$fieldErrors = $_SESSION['admin_step_list_field_errors'] ?? [];
$old = $_SESSION['admin_step_list_old'] ?? null;
unset($_SESSION['admin_step_list_errors'], $_SESSION['admin_step_list_field_errors'], $_SESSION['admin_step_list_old']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();

// The words of the section and of every step, in one query.
BlockLocalization::preloadBlocks(['step_list_sections' => [$sectionId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $stepListSection['is_active'];

/** The heading's words on screen: typed and handed back in this language, else stored in it. */
$sectionWord = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('step_list_sections', $sectionId, $field, $editLanguage);
};

// The steps on screen: as a refused save handed them back, else as stored.
$rows = editor_rows_on_screen(
    $items,
    $oldInThisLanguage ? (array) ($old['items'] ?? []) : null,
    static fn (array $item): array => [
        'title' => BlockLocalization::raw('step_list_items', (int) $item['id'], 'title', $editLanguage),
        'body' => BlockLocalization::raw('step_list_items', (int) $item['id'], 'body', $editLanguage),
        'active' => (int) $item['is_active'] === 1 ? '1' : '',
    ]
);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);

/** One step; the template for a new one is the same markup with the key __KEY__. */
$stepRow = static function (string $key, array $fields, int $position, int $count) use ($marker, $placeholder, $fieldErrors): void {
    [$star, $hint] = editor_row_word_hints($key, $marker, $placeholder);
    editor_row_open('items', $key, admin_t('block_steps.stap_noun'), $position, $count, ($fields['remove'] ?? '') !== '');
    editor_row_text('items', $key, 'title', admin_t('common.title') . $star, 255, $fields, $fieldErrors, $hint);
    editor_row_text('items', $key, 'body', admin_t('block_steps.omschrijving') . $star, 1000, $fields, $fieldErrors, $hint, 3);
    editor_row_switch('items', $key, $fields, admin_t('common.visible'));
    editor_row_close();
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> <?= admin_te('block_steps.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($pageSlug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_steps.text', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_steps.sectie_wijzigingen_direct_zichtbaar', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></p>

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

  <form method="post" action="/api/admin/update-step-list-section.php" class="admin-product-form" data-save-name="<?= $h($section['section_label']) ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves a step. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">
    <?= admin_localized_input($editLanguage) ?>
    <?php admin_localized_bar($editLanguage); ?>

    <section class="admin-card">
      <h2><?= admin_te('block_steps.sectiekop') ?></h2>
      <div class="admin-field">
        <?= admin_field_label('step-list-eyebrow', admin_t('block_steps.eyebrow') . $marker) ?>
        <input type="text" id="step-list-eyebrow" name="eyebrow" maxlength="150"<?= $required ?> value="<?= $h($sectionWord('eyebrow')) ?>"<?= $placeholder ?><?= editor_field_invalid($fieldErrors, 'eyebrow') ?>>
        <?php editor_field_error($fieldErrors, 'eyebrow'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('step-list-title', admin_t('block_steps.titel_h2') . $marker) ?>
        <input type="text" id="step-list-title" name="title" maxlength="255"<?= $required ?> value="<?= $h($sectionWord('title')) ?>"<?= $placeholder ?><?= editor_field_invalid($fieldErrors, 'title') ?>>
        <?php editor_field_error($fieldErrors, 'title'); ?>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_steps.actief_uitgevinkt_hele_sectie') ?>
      </label>
    </section>

    <section class="admin-card" aria-labelledby="step-list-items-title">
      <h2 id="step-list-items-title"><?= admin_te('block_steps.stappen') ?></h2>

      <?php if ($rows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_steps.stappen_sectie') ?></p>
      <?php endif; ?>

      <input type="hidden" name="items_present" value="1">
      <div class="admin-row-cards" data-row-list="step-list-items">
        <?php foreach ($rows as $position => $row): ?>
          <?php $stepRow($row['key'], $row['fields'], $position, count($rows)); ?>
        <?php endforeach; ?>
        <noscript>
          <?php $stepRow(editor_rows_free_key($rows),['active' => '1'], count($rows), count($rows) + 1); ?>
        </noscript>
      </div>
      <?php editor_rows_status('step-list-items'); ?>
      <?php editor_rows_add('step-list-items', admin_t('block_steps.stap_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="step-list-items"><?php $stepRow('__KEY__', ['active' => '1'], 0, 1); ?></template>
    </section>

    <div class="admin-form-actions">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </div>
  </form>
</main>
<?php save_bar(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
