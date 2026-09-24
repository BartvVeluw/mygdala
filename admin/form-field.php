<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_form_fields.php';
require_once __DIR__ . '/_save_bar.php';

use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypeChange;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormFieldWidth;
use App\Service\Forms\FormFileTypes;
use App\Service\Forms\FormLocalization;

/**
 * One field of one form: what kind of field it is, what the visitor reads,
 * whether it must be filled in and — for a dropdown or a radio group — its
 * options and the one it starts on.
 *
 * Its own screen for the same reason a carousel card has one
 * (admin/carousel-card.php): nine settings inline, repeated per field, makes
 * the form editor unreadable at five fields.
 *
 * ONLY WHAT THIS KIND OF FIELD USES. Each section asks the type itself
 * whether it belongs here (FormFieldType::usesPlaceholder(), usesOptions(),
 * usesDefaultValue(), requiredIsFixed()), so this screen keeps no list of
 * types and never shows a setting with a note that it is ignored. A setting
 * that is not shown is not sent, and api/admin/update-form-field.php leaves
 * an unsent setting as it is stored.
 *
 * THE TYPE ON SCREEN is the stored one, or — when a save that changed it
 * came back unwritten (a change waiting for confirmation, or a refused save)
 * — the one that was sent. Then this is already the new type's editor,
 * with a card on top saying what the change will throw away
 * (App\Service\Forms\FormFieldTypeChange) and carrying `confirmed_type`.
 * Nothing has been written until that form is sent. Every type card under
 * "Ander soort veld kiezen" says the same thing in advance, so choosing is
 * never a surprise.
 *
 * OPTIONS AND THEIR DEFAULT IN ONE SAVE. One row per option, with a radio
 * that marks it as the default, so an option typed a moment ago can be the
 * default right away. Three empty rows follow the filled ones, which is
 * how options are added without JavaScript; admin/assets/forms-admin.js adds
 * and removes rows in place.
 *
 * THE ORDER OF THE OPTIONS IS THE ORDER OF THE ROWS when the form is sent:
 * the endpoint reads them in that order and stores them in that order, and
 * the public form shows them in stored order. forms-admin.js moves a row up
 * or down, its index travelling with it, so both languages and the
 * "Standaard" mark stay with the option. Without JavaScript the order is
 * changed by retyping the rows.
 *
 * FILES. A type that accepts a file (FormFieldType::acceptsFile()) has a card
 * "Bestanden": which kinds, as a checkbox per key of the closed list
 * App\Service\Forms\FormFileTypes, and the largest size, as a select of the
 * sizes this installation can really take. Never a free MIME string. A hidden
 * `file_settings` marker makes "no kind ticked" arrive, so the endpoint can
 * refuse it instead of leaving the stored kinds as they were.
 *
 * THE WIDTH is one of six shares of a row (App\Service\Forms\FormFieldWidth),
 * a select that the endpoint accepts nothing else from. Every type has it,
 * and it is the same in every language (FORMS.md, "Breedte van een veld").
 *
 * A SAVE THAT GOES THROUGH LEAVES THIS SCREEN: api/admin/update-form-field.php
 * sends the editor back to the form, at this field's row, with the preview
 * already showing the change. A refused save and a type change waiting for
 * confirmation come back here with what was sent.
 *
 * UNSAVED CHANGES are the save bar's (admin/_save_bar.php), which watches
 * the settings form like any other. Adding, removing or moving an option row
 * is an edit as well, so forms-admin.js reports it with a change event.
 * Input that came back unwritten — a refused save, or a type change waiting
 * for confirmation — starts out unsaved (`data-save-bar-unsaved`), and the
 * confirmation's "Annuleren" throws it away without a second question. The
 * delete form, "Technische gegevens" and the language switch hold nothing an
 * editor types into this field, so they never make the screen unsaved.
 *
 * DELETING ASKS FIRST, in the CMS's own dialog (admin_confirm_dialog(),
 * ADMIN-UI.md). Without JavaScript the form is sent straight away;
 * api/admin/delete-form-field.php keeps every guard.
 *
 * THE POST NAME IS NOT EDITABLE, and not on the everyday part of the screen
 * either: it sits under "Technische gegevens". It was generated from the
 * label when the field was created, and answers have been filed under it
 * ever since; letting an editor change it would silently orphan every stored
 * submission that used the old one. Renaming the LABEL is free and does
 * exactly what an editor means by "rename this field" — history keeps the
 * label it was sent with (FORMS.md, "Wat een inzending bewaart").
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit(admin_t('screen.ongeldig_veld_id'));
}

try {
    $repository = new FormRepository();
    $field = $repository->findField($id);
    $form = $field === null ? null : $repository->find((int) $field['form_id']);
} catch (\Throwable $e) {
    error_log('[admin/form-field.php] ' . $e->getMessage());
    http_response_code(500);
    exit(admin_t('screen.veld_kon_geladen'));
}

if ($field === null || $form === null) {
    http_response_code(404);
    exit(admin_t('screen.veld_gevonden'));
}

$formId = (int) $form['id'];

$errors = $_SESSION['admin_form_field_errors'] ?? [];
$old = $_SESSION['admin_form_field_old'] ?? null;
unset($_SESSION['admin_form_field_errors'], $_SESSION['admin_form_field_old']);
$saved = isset($_GET['saved']);

$storedKey = (string) $field['field_type'];
$storedType = FormFieldTypes::get($storedKey);

// One website language at a time (admin/_localized_fields.php): the one
// chosen in the CMS shell, as stored, with the default language's words only
// as a placeholder. The options, the type, the switch and the default are
// the same in every language.
[$field] = FormLocalization::attachFieldWords([$field]);
$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();
$word = static fn (string $key): string => (string) ($field['translations'][$editLanguage][$key] ?? '');

// The stored options as rows, and which row holds the default. `fallback` is
// the option's name in the default language, which a translation's row shows
// as its placeholder so an editor can tell the rows apart.
$storedRows = [];
$storedDefault = '';
$storedDefaultValue = trim((string) ($field['default_value'] ?? ''));

foreach ($field['choices'] as $position => $option) {
    $storedRows[] = [
        'index' => (string) $position,
        'id' => (int) $option['id'],
        'label' => (string) ($option['labels'][$editLanguage] ?? ''),
        'fallback' => (string) ($option['labels'][$defaultLanguage] ?? $option['value']),
    ];

    if ($storedDefaultValue !== '' && $storedDefaultValue === (string) $option['value']) {
        $storedDefault = (string) $position;
    }
}

$values = [
    'field_type' => $storedKey,
    'label' => $word(FormLocalization::LABEL),
    'placeholder' => $word(FormLocalization::PLACEHOLDER),
    'help_text' => $word(FormLocalization::HELP_TEXT),
    'is_required' => (bool) $field['is_required'],
    'layout_width' => FormFieldWidth::fromStored($field['layout_width'] ?? null),
    'file_types' => FormFileTypes::fromStored($field['file_types'] ?? null),
    'file_max_bytes' => FormFileTypes::effectiveMaxBytes($field['file_max_bytes'] ?? null),
    'option_rows' => $storedRows,
    'default_option' => $storedDefault,
];

// What came back from the endpoint wins, group by group — but only when it
// was typed in the language now on screen; otherwise the stored words of
// this language are what an editor must see.
if (is_array($old) && ($old['language_code'] ?? null) === $editLanguage) {
    $values = array_replace($values, array_intersect_key($old, $values));
}

$type = FormFieldTypes::get((string) $values['field_type']) ?? $storedType;
$typeKey = $type === null ? '' : $type->key();
$changingType = $type !== null && $typeKey !== $storedKey;
$losses = $changingType ? FormFieldTypeChange::losses($field, $type, $form['reply_to_field_key'] ?? null) : [];

// The option rows on screen: the filled ones, then three empty ones to add
// to, never past the maximum. An empty row sent back is not kept.
$optionRows = array_values(array_filter(
    is_array($values['option_rows']) ? $values['option_rows'] : [],
    static fn (array $row): bool => (int) ($row['id'] ?? 0) > 0 || ($row['label'] ?? '') !== ''
));
$nextIndex = 0;
foreach ($optionRows as $row) {
    $nextIndex = max($nextIndex, (int) $row['index'] + 1);
}
for ($blank = 0; $blank < 3 && count($optionRows) < FormFieldOptions::MAX_OPTIONS; $blank++) {
    $optionRows[] = ['index' => (string) $nextIndex++, 'id' => 0, 'label' => '', 'fallback' => ''];
}

// What each type card says choosing it would lose, from the STORED field.
$typeNotes = [];
foreach (FormFieldTypes::all() as $key => $candidate) {
    $typeNotes[$key] = $key === $storedKey
        ? admin_t('forms.type_card.current')
        : form_field_loss_note(FormFieldTypeChange::losses($field, $candidate, $form['reply_to_field_key'] ?? null));
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$v = static fn (array $values, string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(FormLocalization::fieldName($id)) ?> <?= admin_te('forms.veld_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/form.php?id=<?= $formId ?>"><?= admin_t('forms.text', ['v1' => $h((string) $form['name'])]) ?></a></p>
  <h1><?= $h(FormLocalization::fieldName($id)) ?></h1>
  <p class="admin-text-muted"><?= admin_t('forms.field_in_form', ['form' => $h((string) $form['name'])]) ?></p>

  <?php if ($saved): ?>
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

  <form method="post" action="/api/admin/update-form-field.php" class="admin-product-form" data-form-field-editor<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="field_id" value="<?= $id ?>">

    <?php if ($changingType): ?>
      <?php /* Nothing is written yet. Inside the settings form on purpose, as
               admin/page.php's address confirmation is: the fields below
               already hold what was sent, so confirming is sending this same
               form again with the type that was agreed to. */ ?>
      <section class="admin-card admin-type-change" aria-labelledby="form-field-type-change-title">
        <h2 id="form-field-type-change-title"><?= admin_te('forms.type_change.title', ['from' => form_field_type_label($storedKey), 'to' => form_field_type_label($typeKey)]) ?></h2>
        <p><?= admin_te('forms.type_change.nothing_saved') ?></p>
        <?php if ($losses === []): ?>
          <p><?= admin_te('forms.type_change.loses_nothing') ?></p>
        <?php else: ?>
          <p><strong><?= admin_te('forms.type_change.loses_intro') ?></strong></p>
          <ul class="admin-type-change__losses">
            <?php foreach (form_field_loss_sentences($losses, $field) as $sentence): ?>
              <li><?= $h($sentence) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <input type="hidden" name="confirmed_type" value="<?= $h($typeKey) ?>">
        <div class="admin-type-change__actions">
          <button type="submit"><?= admin_te($losses === [] ? 'forms.type_change.submit' : 'forms.type_change.submit_losing') ?></button>
          <a href="/admin/form-field.php?id=<?= $id ?>" class="admin-btn-secondary" data-save-bar-discard><?= admin_te('forms.type_change.cancel') ?></a>
        </div>
      </section>
    <?php endif; ?>

    <section class="admin-card">
      <h2 id="form-field-type-title"><?= admin_te('forms.field_type') ?></h2>
      <?php if ($storedType === null): ?>
        <p class="admin-alert admin-alert--error"><?= admin_te('forms.veldtype_bestaat_meer_veld') ?></p>
      <?php else: ?>
        <p class="admin-field-type-current">
          <strong><?= $h(form_field_type_label($storedKey)) ?></strong>
          <span class="admin-text-muted"><?= $h(form_field_type_description($storedKey)) ?></span>
        </p>
      <?php endif; ?>

      <?php /* A native <details> in the collapse styling, shut unless a type
               change is on screen or the stored type is unknown. Shut or open,
               the checked card is sent, so a save that changes nothing sends
               the stored type. */ ?>
      <details class="admin-collapse" data-form-field-type-choice<?= $changingType || $storedType === null ? ' open' : '' ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <span class="admin-collapse__title"><?= admin_te('forms.type_choose_other') ?></span>
        </summary>
        <div class="admin-collapse__body">
          <p class="admin-text-muted"><?= admin_te('forms.type_choose_other_intro') ?></p>
          <fieldset class="admin-field-picker__types" aria-labelledby="form-field-type-title">
            <?php form_field_type_cards('field_type', $typeKey, $typeNotes); ?>
          </fieldset>
        </div>
      </details>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('forms.field_texts') ?></h2>

      <?php admin_localized_bar($editLanguage); ?>
      <?= admin_localized_input($editLanguage) ?>
      <div class="admin-field">
        <?= admin_field_label('form-field-label', admin_t('forms.label'), admin_t('help.forms.field_label'), admin_localized_required($editLanguage) !== '') ?>
        <input type="text" id="form-field-label" name="label" maxlength="200"<?= admin_localized_required($editLanguage) ?> value="<?= $v($values, 'label') ?>"<?= admin_localized_placeholder_attr($editLanguage) ?>>
      </div>

      <div class="admin-field">
        <?= admin_field_label('form-field-help', admin_t('forms.field_help'), admin_t('help.forms.field_help')) ?>
        <input type="text" id="form-field-help" name="help_text" maxlength="500" value="<?= $v($values, 'help_text') ?>"<?= admin_localized_placeholder_attr($editLanguage) ?>>
      </div>

      <?php if ($type !== null && $type->usesPlaceholder()): ?>
        <div class="admin-field">
          <?= admin_field_label('form-field-placeholder', admin_t('forms.field_placeholder'), admin_t('help.forms.field_placeholder')) ?>
          <input type="text" id="form-field-placeholder" name="placeholder" maxlength="200" value="<?= $v($values, 'placeholder') ?>"<?= admin_localized_placeholder_attr($editLanguage) ?>>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($type !== null && $type->usesOptions()): ?>
      <section class="admin-card">
        <h2 id="form-field-options-title"><?= admin_te('forms.options.title') ?></h2>
        <p class="admin-text-muted"><?= admin_te($type->usesDefaultValue() ? 'forms.options.intro_with_default' : 'forms.options.intro', ['max' => FormFieldOptions::MAX_OPTIONS]) ?></p>

        <fieldset class="admin-option-rows" aria-labelledby="form-field-options-title" data-form-options data-form-options-max="<?= FormFieldOptions::MAX_OPTIONS ?>">
          <div class="admin-option-rows__list" data-form-option-list>
            <?php /* One row per option: its number, the option's id, its label
                     in the language on screen, the radio that makes it the
                     default, and the move and remove buttons forms-admin.js
                     reveals. The ID says WHICH option a row is, so renaming or
                     translating it keeps the value a submission stores; an
                     empty id is a new option. The row's INDEX ties the label
                     and the radio together (`option_id[i]`,
                     `option_label[i]`, `default_option` = i) and is not a
                     position: the position is where the row sits when the form
                     is sent. */ ?>
            <?php foreach ($optionRows as $position => $row): ?>
              <?php
                $number = $position + 1;
                $index = (string) $row['index'];
                // A translation's row shows the option's name in the default
                // language as its placeholder, so an editor can tell an
                // untranslated row from an empty one.
                $fallback = $editLanguage === $defaultLanguage ? '' : (string) ($row['fallback'] ?? '');
              ?>
              <div class="admin-option-row" data-form-option-row>
                <span class="admin-option-row__number" aria-hidden="true" data-form-option-number><?= $number ?></span>
                <input type="hidden" name="option_id[<?= $h($index) ?>]" value="<?= (int) ($row['id'] ?? 0) ?: '' ?>">
                <input type="text" name="option_label[<?= $h($index) ?>]" maxlength="<?= FormFieldOptions::MAX_LENGTH ?>" value="<?= $h((string) $row['label']) ?>" aria-label="<?= admin_te('forms.option.label', ['n' => $number]) ?>"<?= $fallback === '' ? '' : ' placeholder="' . $h($fallback) . '"' ?>>
                <span class="admin-option-row__actions">
                  <?php if ($type->usesDefaultValue()): ?>
                    <label class="admin-option-row__default">
                      <input type="radio" name="default_option" value="<?= $h($index) ?>"<?= (string) $values['default_option'] === $index ? ' checked' : '' ?> aria-label="<?= admin_te('forms.option.default_label', ['n' => $number]) ?>">
                      <span aria-hidden="true"><?= admin_te('forms.option.default') ?></span>
                    </label>
                  <?php endif; ?>
                  <span class="admin-option-row__move" data-form-option-move-group hidden>
                    <button type="button" class="admin-btn-ghost admin-option-row__move-button" data-form-option-move="up" aria-label="<?= admin_te('forms.option.move_up_label', ['n' => $number]) ?>"><span aria-hidden="true">&uarr;</span></button>
                    <button type="button" class="admin-btn-ghost admin-option-row__move-button" data-form-option-move="down" aria-label="<?= admin_te('forms.option.move_down_label', ['n' => $number]) ?>"><span aria-hidden="true">&darr;</span></button>
                  </span>
                  <button type="button" class="admin-btn-text admin-btn-text--danger" data-form-option-remove hidden aria-label="<?= admin_te('forms.option.remove_label', ['n' => $number]) ?>"><?= admin_te('common.delete') ?></button>
                </span>
              </div>
            <?php endforeach; ?>
          </div>

          <?php /* Where a moved row landed, for whoever cannot see it move.
                   The words are the catalogue's; the script only fills in
                   the number. */ ?>
          <p class="admin-visually-hidden" role="status" aria-live="polite" data-form-option-status data-form-option-moved="<?= admin_te('forms.option.moved') ?>"></p>

          <div class="admin-option-rows__tools">
            <button type="button" class="admin-btn-secondary" data-form-option-add hidden><?= admin_te('forms.option.add') ?></button>
            <?php if ($type->usesDefaultValue()): ?>
              <label class="admin-option-row__default admin-option-rows__none">
                <input type="radio" name="default_option" value=""<?= (string) $values['default_option'] === '' ? ' checked' : '' ?>>
                <span><?= admin_te('forms.option.no_default') ?></span>
              </label>
            <?php endif; ?>
          </div>
          <?php admin_localized_new_item_note($editLanguage); ?>
        </fieldset>
      </section>
    <?php endif; ?>

    <?php if ($type !== null && $type->acceptsFile()): ?>
      <?php
        $chosenTypes = is_array($values['file_types']) ? $values['file_types'] : FormFileTypes::DEFAULT_TYPES;
        $chosenMax = (int) $values['file_max_bytes'];
      ?>
      <section class="admin-card">
        <h2><?= admin_te('forms.files.title') ?></h2>
        <input type="hidden" name="file_settings" value="1">
        <div class="admin-field" role="group" aria-labelledby="form-field-file-types-label" aria-describedby="form-field-file-types-intro">
          <div class="admin-field__label">
            <span id="form-field-file-types-label"><?= admin_te('forms.files.types_legend') ?></span>
          </div>
          <p class="admin-text-muted" id="form-field-file-types-intro"><?= admin_te('forms.files.types_intro') ?></p>
          <div class="admin-form-file-types">
            <?php foreach (FormFileTypes::keys() as $fileType): ?>
              <label class="admin-checkbox-label">
                <input type="checkbox" class="admin-checkbox" name="file_types[]" value="<?= $h($fileType) ?>"<?= in_array($fileType, $chosenTypes, true) ? ' checked' : '' ?>>
                <?= $h(FormFileTypes::label($fileType)) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="admin-text-muted"><?= admin_te('forms.files.not_offered') ?></p>
        </div>

        <div class="admin-field">
          <?= admin_field_label('form-field-file-max', admin_t('forms.files.max_label')) ?>
          <select id="form-field-file-max" name="file_max_bytes" class="admin-select" aria-describedby="form-field-file-max-intro">
            <?php foreach (FormFileTypes::sizeChoices() as $bytes): ?>
              <option value="<?= $bytes ?>"<?= $bytes === $chosenMax ? ' selected' : '' ?>><?= $h(FormFileTypes::sizeLabel($bytes)) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="admin-text-muted" id="form-field-file-max-intro"><?= admin_te('forms.files.max_intro', ['max' => FormFileTypes::sizeLabel(FormFileTypes::systemMaxBytes())]) ?></p>
        </div>

        <p class="admin-text-muted"><?= admin_te('forms.files.one_file') ?> <?= admin_te('forms.files.where') ?></p>
      </section>
    <?php endif; ?>

    <section class="admin-card">
      <h2><?= admin_te('forms.field_filling_in') ?></h2>
      <?php if ($type !== null && $type->requiredIsFixed()): ?>
        <p><?= admin_te('forms.akkoordvinkje_altijd_verplicht_akkoord') ?></p>
      <?php else: ?>
        <?php /* The hidden 0 is what makes "unticked" arrive at all: the
                 endpoint leaves a setting that was not sent as it is stored. */ ?>
        <input type="hidden" name="is_required" value="0">
        <div class="admin-field admin-field--inline">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-switch" role="switch" name="is_required" value="1"<?= $values['is_required'] ? ' checked' : '' ?>>
            <?= admin_te('forms.verplicht_invullen') ?>
          </label>
          <?= admin_help(admin_t('forms.verplicht_invullen'), admin_t('help.forms.field_required')) ?>
        </div>
      <?php endif; ?>

      <?php if ($type !== null && $type->holdsEmailAddress()): ?>
        <p class="admin-text-muted"><?= admin_t('forms.field_email_reply_to', ['url' => '/admin/form.php?id=' . $formId]) ?></p>
      <?php endif; ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('forms.width.title') ?></h2>
      <?php /* One of the six shares of App\Service\Forms\FormFieldWidth, the
               same for every type and in every language. A select rather
               than a number or a slider: the endpoint accepts these keys and
               nothing else. */ ?>
      <div class="admin-field">
        <?= admin_field_label('form-field-width', admin_t('forms.width.label')) ?>
        <select id="form-field-width" name="layout_width" class="admin-select" aria-describedby="form-field-width-intro">
          <?php foreach (FormFieldWidth::keys() as $widthKey): ?>
            <option value="<?= $h($widthKey) ?>"<?= $values['layout_width'] === $widthKey ? ' selected' : '' ?>><?= admin_te('forms.width.option.' . $widthKey) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="admin-text-muted" id="form-field-width-intro"><?= admin_te('forms.width.intro') ?></p>
    </section>

    <?php /* A plain Opslaan only exists for a browser without the save bar's
             script (data-save-bar-fallback, admin/assets/save-bar.js); with
             it, the bar's Opslaan is the one button. While a type change
             waits for confirmation, the button says what it will do, so it
             stays. */ ?>
    <button type="submit"<?= $changingType ? '' : ' data-save-bar-fallback' ?>><?= admin_te($changingType ? ($losses === [] ? 'forms.type_change.submit' : 'forms.type_change.submit_losing') : 'common.save') ?></button>
  </form>

  <section class="admin-card">
    <details class="admin-collapse admin-collapse--card" data-form-field-technical>
      <summary class="admin-collapse__summary">
        <span class="admin-collapse__caret" aria-hidden="true"></span>
        <h2 class="admin-collapse__title"><?= admin_te('forms.technical') ?></h2>
      </summary>
      <div class="admin-collapse__body">
        <p><?= admin_te('forms.post_name') ?> <code><?= $h((string) $field['field_key']) ?></code></p>
        <p class="admin-text-muted"><?= admin_te('forms.post_name_explained') ?></p>
      </div>
    </details>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('forms.veld_verwijderen') ?></h2>
    <p class="admin-text-muted"><?= admin_te('forms.veld_verdwijnt_uit_formulier') ?></p>
    <form method="post" action="/api/admin/delete-form-field.php"<?= admin_confirm_attributes(
        admin_t('forms.delete_field.title'),
        admin_t('forms.delete_field.message', ['field' => FormLocalization::fieldName($id), 'form' => (string) $form['name']]),
        admin_t('common.delete')
    ) ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="field_id" value="<?= $id ?>">
      <button type="submit"><?= admin_te('forms.definitief_verwijderen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/forms-admin.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
