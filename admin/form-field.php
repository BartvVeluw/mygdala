<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';
require_once __DIR__ . '/_form_fields.php';

use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormField;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypeChange;
use App\Service\Forms\FormFieldTypes;

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
$notice = $_SESSION['admin_form_field_notice'] ?? null;
unset($_SESSION['admin_form_field_errors'], $_SESSION['admin_form_field_old'], $_SESSION['admin_form_field_notice']);
$saved = isset($_GET['saved']);

$storedKey = (string) $field['field_type'];
$storedType = FormFieldTypes::get($storedKey);

// The stored options as rows, and which row holds the default.
$storedOptions = FormFieldOptions::fromStored(is_string($field['options'] ?? null) ? $field['options'] : null);
$storedRows = [];
$storedDefault = '';

foreach ($storedOptions->all() as $position => $option) {
    $storedRows[] = [
        'index' => (string) $position,
        'nl' => $option->nl,
        // An editor sees an untranslated option as an EMPTY English box,
        // never the Dutch fallback (admin/_language_fields.php).
        'en' => $option->en === $option->nl ? '' : $option->en,
    ];

    if ($storedType !== null
        && FormField::isUsableDefault($storedType, $storedOptions, (string) ($field['default_value'] ?? ''))
        && trim((string) $field['default_value']) === $option->nl
    ) {
        $storedDefault = (string) $position;
    }
}

$values = [
    'field_type' => $storedKey,
    'label_nl' => (string) $field['label_nl'],
    'label_en' => (string) ($field['label_en'] ?? ''),
    'placeholder_nl' => (string) ($field['placeholder_nl'] ?? ''),
    'placeholder_en' => (string) ($field['placeholder_en'] ?? ''),
    'help_text_nl' => (string) ($field['help_text_nl'] ?? ''),
    'help_text_en' => (string) ($field['help_text_en'] ?? ''),
    'is_required' => (bool) $field['is_required'],
    'option_rows' => $storedRows,
    'default_option' => $storedDefault,
];

// What came back from the endpoint wins, group by group; what it did not
// carry is still the stored value.
if (is_array($old)) {
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
    static fn (array $row): bool => ($row['nl'] ?? '') !== '' || ($row['en'] ?? '') !== ''
));
$nextIndex = 0;
foreach ($optionRows as $row) {
    $nextIndex = max($nextIndex, (int) $row['index'] + 1);
}
for ($blank = 0; $blank < 3 && count($optionRows) < FormFieldOptions::MAX_OPTIONS; $blank++) {
    $optionRows[] = ['index' => (string) $nextIndex++, 'nl' => '', 'en' => ''];
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
<title><?= $h((string) $field['label_nl']) ?> <?= admin_te('forms.veld_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/form.php?id=<?= $formId ?>"><?= admin_t('forms.text', ['v1' => $h((string) $form['name'])]) ?></a></p>
  <h1><?= $h((string) $field['label_nl']) ?></h1>
  <p class="admin-text-muted"><?= admin_t('forms.field_in_form', ['form' => $h((string) $form['name'])]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($notice === 'default_dropped'): ?>
    <p class="admin-alert admin-alert--warning" role="status"><?= admin_te('forms.default_dropped') ?></p>
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

  <form method="post" action="/api/admin/update-form-field.php" class="admin-product-form" data-form-field-editor>
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
          <a href="/admin/form-field.php?id=<?= $id ?>" class="admin-btn-secondary"><?= admin_te('forms.type_change.cancel') ?></a>
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

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <div class="admin-field">
          <?= admin_field_label('form-field-label-nl', admin_t('forms.label'), admin_t('help.forms.field_label'), true) ?>
          <input type="text" id="form-field-label-nl" name="label_nl" maxlength="200"<?= admin_lang_required('nl') ?> value="<?= $v($values, 'label_nl') ?>">
        </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <div class="admin-field">
          <?= admin_field_label('form-field-label-en', admin_t('forms.label'), admin_t('help.forms.field_label')) ?>
          <input type="text" id="form-field-label-en" name="label_en" maxlength="200" value="<?= $v($values, 'label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </div>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <div class="admin-field">
          <?= admin_field_label('form-field-help-nl', admin_t('forms.field_help'), admin_t('help.forms.field_help')) ?>
          <input type="text" id="form-field-help-nl" name="help_text_nl" maxlength="500" value="<?= $v($values, 'help_text_nl') ?>">
        </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <div class="admin-field">
          <?= admin_field_label('form-field-help-en', admin_t('forms.field_help'), admin_t('help.forms.field_help')) ?>
          <input type="text" id="form-field-help-en" name="help_text_en" maxlength="500" value="<?= $v($values, 'help_text_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </div>
        <?php admin_lang_pane_end(); ?>
      </div>

      <?php if ($type !== null && $type->usesPlaceholder()): ?>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-field">
            <?= admin_field_label('form-field-placeholder-nl', admin_t('forms.field_placeholder'), admin_t('help.forms.field_placeholder')) ?>
            <input type="text" id="form-field-placeholder-nl" name="placeholder_nl" maxlength="200" value="<?= $v($values, 'placeholder_nl') ?>">
          </div>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <div class="admin-field">
            <?= admin_field_label('form-field-placeholder-en', admin_t('forms.field_placeholder'), admin_t('help.forms.field_placeholder')) ?>
            <input type="text" id="form-field-placeholder-en" name="placeholder_en" maxlength="200" value="<?= $v($values, 'placeholder_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </div>
          <?php admin_lang_pane_end(); ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($type !== null && $type->usesOptions()): ?>
      <section class="admin-card">
        <h2 id="form-field-options-title"><?= admin_te('forms.options.title') ?></h2>
        <p class="admin-text-muted"><?= admin_te($type->usesDefaultValue() ? 'forms.options.intro_with_default' : 'forms.options.intro', ['max' => FormFieldOptions::MAX_OPTIONS]) ?></p>

        <fieldset class="admin-option-rows" aria-labelledby="form-field-options-title" data-form-options data-form-options-max="<?= FormFieldOptions::MAX_OPTIONS ?>">
          <div class="admin-option-rows__list" data-form-option-list>
            <?php /* One row per option: its number, the option in each language
                     pane, the radio that makes it the default, and a remove
                     button forms-admin.js reveals. The row's INDEX ties the two
                     languages and the radio together (`option_nl[i]`,
                     `option_en[i]`, `default_option` = i). It is not a
                     position, so a row added or removed in the browser needs
                     no renumbering on the server. */ ?>
            <?php foreach ($optionRows as $position => $row): ?>
              <?php $number = $position + 1; $index = (string) $row['index']; ?>
              <div class="admin-option-row" data-form-option-row>
                <span class="admin-option-row__number" aria-hidden="true" data-form-option-number><?= $number ?></span>
                <?php admin_lang_pane_start('nl'); ?>
                  <input type="text" name="option_nl[<?= $h($index) ?>]" maxlength="200" value="<?= $h((string) $row['nl']) ?>" aria-label="<?= admin_te('forms.option.label_nl', ['n' => $number]) ?>">
                <?php admin_lang_pane_end(); ?>
                <?php admin_lang_pane_start('en'); ?>
                  <input type="text" name="option_en[<?= $h($index) ?>]" maxlength="200" value="<?= $h((string) $row['en']) ?>" aria-label="<?= admin_te('forms.option.label_en', ['n' => $number]) ?>"<?= admin_lang_placeholder_attr('en') ?>>
                <?php admin_lang_pane_end(); ?>
                <span class="admin-option-row__actions">
                  <?php if ($type->usesDefaultValue()): ?>
                    <label class="admin-option-row__default">
                      <input type="radio" name="default_option" value="<?= $h($index) ?>"<?= (string) $values['default_option'] === $index ? ' checked' : '' ?> aria-label="<?= admin_te('forms.option.default_label', ['n' => $number]) ?>">
                      <span aria-hidden="true"><?= admin_te('forms.option.default') ?></span>
                    </label>
                  <?php endif; ?>
                  <button type="button" class="admin-btn-text admin-btn-text--danger" data-form-option-remove hidden aria-label="<?= admin_te('forms.option.remove_label', ['n' => $number]) ?>"><?= admin_te('common.delete') ?></button>
                </span>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="admin-option-rows__tools">
            <button type="button" class="admin-btn-secondary" data-form-option-add hidden><?= admin_te('forms.option.add') ?></button>
            <?php if ($type->usesDefaultValue()): ?>
              <label class="admin-option-row__default admin-option-rows__none">
                <input type="radio" name="default_option" value=""<?= (string) $values['default_option'] === '' ? ' checked' : '' ?>>
                <span><?= admin_te('forms.option.no_default') ?></span>
              </label>
            <?php endif; ?>
          </div>
        </fieldset>
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

    <button type="submit"><?= admin_te($changingType ? ($losses === [] ? 'forms.type_change.submit' : 'forms.type_change.submit_losing') : 'common.save') ?></button>
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
    <form method="post" action="/api/admin/delete-form-field.php" onsubmit="return confirm('Dit veld verwijderen?');">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="field_id" value="<?= $id ?>">
      <button type="submit"><?= admin_te('forms.definitief_verwijderen') ?></button>
    </form>
  </section>
</main>
<?php admin_lang_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/forms-admin.js') ?>" defer></script>
</body>
</html>
