<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_form_fields.php';
require_once __DIR__ . '/_save_bar.php';

use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormLocalization;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormFieldWidth;
use App\Service\Forms\FormRecipient;
use App\Service\Forms\FormUsage;

/**
 * The form editor: whether the form is on, what it is called, what happens
 * when somebody sends it, and its fields in order.
 *
 * The settings are ONE form in three cards, in the order an editor thinks
 * about them: Algemeen (on or off, the name, the button), Na het versturen
 * (the thank-you message and who is notified) and Geavanceerd (storing
 * submissions and the reply address — chosen once, rarely touched). The
 * last card is a native <details> in the collapse styling, the way
 * admin/page-new.php folds its SEO card: shut by default, open after a
 * refused save or while the form would lose submissions, because then what
 * needs changing may be inside it. A closed <details> still submits its
 * controls, so the endpoint receives exactly the same fields either way.
 *
 * Then the fields, one compact row each (Forms 2.0 phase 1), with no
 * drag-and-drop page builder anywhere. Reordering is the square ↑/↓ pair of
 * the navigation and footer rows (.admin-section-row), which works without
 * JavaScript and needs no library.
 *
 * Editing ONE field happens on its own screen (admin/form-field.php), like a
 * carousel card: a field has nine settings, and nine of them per row inline
 * would make a five-field form unreadable. A row says what each field is in
 * one line of words (its kind, required or not, its width, how many options)
 * and not the name it is posted under: nobody editing a form needs that
 * name, and the field's own screen keeps it under "Technische gegevens" for
 * whoever does. Each row is the anchor a saved field comes back to
 * (#form-field-<id>, api/admin/update-form-field.php), and that one save is
 * named above the cards.
 *
 * THE PREVIEW beside the fields is the stored form drawn by the public
 * renderer, in a sandboxed frame of its own (admin/form-preview.php), so it
 * cannot drift from what a visitor sees. It shows what is saved: every save
 * on this screen or a field's screen reloads it.
 *
 * ONE OPSLAAN. The save bar's button is the screen's save; the settings
 * form's own button is only there for a browser without the bar's script
 * (`data-save-bar-fallback`), hidden once the bar shows, and still the
 * form's default button, so Enter in a field still saves.
 *
 * ADDING A FIELD STARTS WITH WHAT KIND OF FIELD. "Veld toevoegen" opens a
 * dialog with a described card per type (admin/_form_fields.php) and the
 * label, and one POST creates the field and opens its editor. The opener is
 * a link to this screen with `add_field=1`, which renders the same dialog
 * already open, so without JavaScript the link simply shows it in the page;
 * admin/assets/forms-admin.js opens it as a modal instead. A refused add
 * comes back the same way, with what was chosen and typed still in it.
 *
 * UNSAVED CHANGES are the save bar's (admin/_save_bar.php), as on the field
 * editor: it watches the settings form, and every setting above is a control
 * in that one form, the ones folded under Geavanceerd included. Opening or
 * closing that card, or a help mark, changes no control, so it is no edit.
 * A refused save comes back with what was sent and not written, so the form
 * then starts out unsaved (`data-save-bar-unsaved`). The field list's
 * move and delete buttons are one-button forms the bar skips, and
 * "Formulier verwijderen" carries only hidden fields. The "Veld toevoegen"
 * dialog opts out (`data-no-dirty-track`), as the block picker does: choosing
 * a type and a label is not an edit to save later, and the bar's Opslaan must
 * never create a field.
 *
 * DELETING ASKS FIRST, in the CMS's own dialog (admin_confirm_dialog(),
 * ADMIN-UI.md): a field from the list and the form itself each name what
 * goes. The form still does the work, so without JavaScript it is sent
 * straight away, and api/admin/delete-form-field.php and delete-form.php keep
 * every guard and the check whether a form may go at all.
 *
 * What "on" and "off" mean everywhere is FORMS.md, "Actief en uit"; this
 * screen only sets the column.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit(admin_t('screen.ongeldig_formulier_id'));
}

try {
    $repository = new FormRepository();
    $row = $repository->find($id);
    $fieldRows = $row === null ? [] : FormLocalization::attachFieldWords($repository->fieldsFor($id));
    // Only a number, never a submission: counting is what this permission
    // may see (admin/forms.php says why).
    $submissionCount = $row === null ? 0 : (new FormSubmissionRepository())->countForForm($id);
} catch (\Throwable $e) {
    error_log('[admin/form.php] ' . $e->getMessage());
    http_response_code(500);
    exit(admin_t('screen.formulier_kon_geladen'));
}

if ($row === null) {
    http_response_code(404);
    exit(admin_t('screen.formulier_gevonden'));
}

$definition = FormCatalog::find($id);
$placements = FormUsage::placements($id);
$blockers = FormUsage::deletionBlockers($id);

$errors = $_SESSION['admin_form_errors'] ?? [];
$old = $_SESSION['admin_form_old'] ?? null;
$addErrors = $_SESSION['admin_form_field_add_errors'] ?? [];
$addOld = $_SESSION['admin_form_field_add_old'] ?? [];
$fieldSaved = $_SESSION['admin_form_field_saved'] ?? null;
unset(
    $_SESSION['admin_form_errors'],
    $_SESSION['admin_form_old'],
    $_SESSION['admin_form_field_add_errors'],
    $_SESSION['admin_form_field_add_old'],
    $_SESSION['admin_form_field_saved']
);
$saved = isset($_GET['saved']);

// A field saved on its own screen comes back here
// (api/admin/update-form-field.php): named in the message, and only when it
// is a field of THIS form — the session says which, the address only where.
$savedFieldId = is_array($fieldSaved) ? (int) ($fieldSaved['field_id'] ?? 0) : 0;
$savedFieldIsHere = $savedFieldId > 0 && in_array($savedFieldId, array_map('intval', array_column($fieldRows, 'id')), true);
$savedDefaultDropped = $savedFieldIsHere && !empty($fieldSaved['default_dropped']);

// Open as the page renders: the no-JavaScript route to "Veld toevoegen", or
// an add the endpoint sent back.
$addOpen = isset($_GET['add_field']) || $addErrors !== [];
$addLabel = (string) ($addOld['label'] ?? '');
$addType = (string) ($addOld['field_type'] ?? '');

// The button text and the thank-you message are website text, one language
// at a time (admin/_localized_fields.php); everything else on this screen is
// the same in every language.
$editLanguage = admin_localized_language();
$words = FormLocalization::forms()->words($id);

$values = [
    'name' => (string) $row['name'],
    'is_active' => (bool) $row['is_active'],
    'submit_label' => (string) ($words[$editLanguage][FormLocalization::SUBMIT_LABEL] ?? ''),
    'success_message' => (string) ($words[$editLanguage][FormLocalization::SUCCESS_MESSAGE] ?? ''),
    'notification_email' => (string) ($row['notification_email'] ?? ''),
    'reply_to_field_key' => (string) ($row['reply_to_field_key'] ?? ''),
    'store_submissions' => (bool) $row['store_submissions'],
];

// What a refused save sent comes back, but its words only in the language
// they were typed in.
if (is_array($old)) {
    $values = array_replace($values, array_intersect_key($old, $values));

    if (($old['language_code'] ?? null) !== $editLanguage) {
        $values['submit_label'] = (string) ($words[$editLanguage][FormLocalization::SUBMIT_LABEL] ?? '');
        $values['success_message'] = (string) ($words[$editLanguage][FormLocalization::SUCCESS_MESSAGE] ?? '');
    }
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$v = static fn (array $values, string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');

$siteFallback = FormRecipient::siteFallback();
$replyToCandidates = $definition === null ? [] : $definition->replyToCandidates();

// The heading and the placement line describe the form as it is STORED, so
// they stay true while the switch below is being changed but not yet saved.
$isActive = (bool) $row['is_active'];

$losesSubmissions = FormRecipient::losesSubmissions($values, $siteFallback);
$advancedOpen = $errors !== [] || $losesSubmissions;
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $row['name']) ?> <?= admin_te('forms.formulier_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/forms.php"><?= admin_t('forms.formulieren') ?></a></p>
  <div class="admin-main__heading">
    <h1><?= $h((string) $row['name']) ?></h1>
    <?php /* Green and amber with the word, as admin/forms.php shows it. */ ?>
    <span class="admin-badge admin-badge--<?= $isActive ? 'paid' : 'draft' ?>"><?= admin_te($isActive ? 'common.active' : 'common.inactive') ?></span>
  </div>

  <?php if ($placements === []): ?>
    <p class="admin-text-muted"><?= admin_t('forms.formulier_staat_enkele_pagina') ?></p>
  <?php else: ?>
    <?php /* Switched off, the same list reads as what visitors are missing:
             those pages carry the block, but show no form. */ ?>
    <p class="admin-text-muted"><?= admin_te($isActive ? 'forms.placed_on' : 'forms.placed_on_while_inactive') ?>
      <?php foreach ($placements as $index => $placement): ?><?= $index > 0 ? ', ' : '' ?><?php if ($placement['edit_url'] !== ''): ?><a href="<?= $h($placement['edit_url']) ?>"><?= $h($placement['page_title']) ?></a><?php else: ?><?= $h($placement['page_title']) ?><?php endif; ?><?php endforeach; ?>.
    </p>
  <?php endif; ?>

  <?php if ($savedFieldIsHere): ?>
    <p class="admin-alert admin-alert--success" role="status"><?= admin_te('forms.field_saved', ['field' => FormLocalization::fieldName($savedFieldId)]) ?></p>
    <?php if ($savedDefaultDropped): ?>
      <p class="admin-alert admin-alert--warning" role="status"><?= admin_te('forms.default_dropped') ?></p>
    <?php endif; ?>
  <?php elseif ($saved): ?>
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

  <?php /* Two columns where the screen is wide enough for both: the
           settings and the fields on the left, the preview beside them.
           Where it is not, the preview follows the fields. A flex row that
           wraps, so this needs no breakpoint of its own. */ ?>
  <div class="admin-form-builder">
    <div class="admin-form-builder__editor">
  <form method="post" action="/api/admin/update-form.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <section class="admin-card">
      <h2><?= admin_te('forms.algemeen') ?></h2>

      <?php /* The status first: whether visitors can use this form at all is
               the first thing to know about it. A switch (ADMIN-UI.md), and
               still one checkbox underneath: api/admin/update-form.php reads
               isset($_POST['is_active']) exactly as it did. */ ?>
      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
          <?= admin_te('common.active') ?>
        </label>
        <?= admin_help(admin_t('common.active'), admin_t('help.forms.active')) ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('form-name', admin_t('common.name'), admin_t('help.forms.name'), true) ?>
        <input type="text" id="form-name" name="name" maxlength="150" required value="<?= $v($values, 'name') ?>">
      </div>

      <?php admin_localized_bar($editLanguage); ?>
      <?= admin_localized_input($editLanguage) ?>
      <div class="admin-field">
        <?= admin_field_label('form-submit-label', admin_t('forms.tekst_verstuurknop')) ?>
        <input type="text" id="form-submit-label" name="submit_label" maxlength="150" value="<?= $v($values, 'submit_label') ?>" placeholder="Versturen">
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('forms.after_sending') ?></h2>

      <div class="admin-field">
        <?= admin_field_label('form-success-message', admin_t('forms.thank_you_message'), admin_t('help.forms.thank_you_message')) ?>
        <textarea id="form-success-message" name="success_message" maxlength="1000" rows="3" placeholder="Bedankt — je bericht is verstuurd."><?= $v($values, 'success_message') ?></textarea>
      </div>

      <div class="admin-field">
        <?= admin_field_label('form-notification-email', admin_t('forms.e_mailadres_melding_krijgt'), admin_t('help.forms.notification_email')) ?>
        <input type="email" id="form-notification-email" name="notification_email" maxlength="254" value="<?= $v($values, 'notification_email') ?>" placeholder="<?= $h($siteFallback ?? admin_t('forms.notification_email_placeholder')) ?>">
      </div>
      <p class="admin-text-muted">
        <?php if ($siteFallback !== null): ?>
          <?= admin_t('forms.fallback_recipient', ['v1' => $h($siteFallback)]) ?>
        <?php else: ?>
          <?= admin_t('forms.no_fallback_recipient') ?>
        <?php endif; ?>
      </p>
      <?php /* The state api/admin/update-form.php refuses to save, shown on
               the stored form too: the site address it relied on may have
               been emptied since. Here rather than under Geavanceerd, next
               to the address that is one of its two ways out. */ ?>
      <?php if ($losesSubmissions): ?>
        <p class="admin-alert admin-alert--warning" role="status"><?= admin_t('forms.submissions_go_nowhere') ?></p>
      <?php endif; ?>
    </section>

    <section class="admin-card">
      <details class="admin-collapse admin-collapse--card" data-form-advanced<?= $advancedOpen ? ' open' : '' ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <h2 class="admin-collapse__title"><?= admin_te('forms.advanced') ?></h2>
          <?php /* What is folded away stays visible: whether this form keeps
                   personal data is not something to find out by opening a
                   card. "Not stored" is the plain badge rather than
                   --muted, whose faint text is too light to read on the
                   dark dashboard themes. */ ?>
          <span class="admin-collapse__badges">
            <span class="admin-badge<?= ($values['store_submissions'] ?? false) ? ' admin-badge--info' : '' ?>"><?= admin_te(($values['store_submissions'] ?? false) ? 'forms.storing_on_badge' : 'forms.storing_off_badge') ?></span>
          </span>
        </summary>
        <div class="admin-collapse__body">
          <p class="admin-text-muted"><?= admin_te('forms.advanced_intro') ?></p>

          <div class="admin-field admin-field--inline">
            <label class="admin-checkbox-label">
              <input type="checkbox" class="admin-switch" role="switch" name="store_submissions" value="1" <?= ($values['store_submissions'] ?? false) ? 'checked' : '' ?>>
              <?= admin_te('forms.inzendingen_bewaren_cms') ?>
            </label>
            <?= admin_help(admin_t('forms.inzendingen_bewaren_cms'), admin_t('help.forms.store_submissions')) ?>
          </div>
          <?php /* Switching storing off deletes nothing (FORMS.md, "Privacy"),
                   so say so where the switch is: otherwise "niet bewaren"
                   reads as "and the old ones are gone". Only the count, which
                   is all forms.manage may see. */ ?>
          <?php if ($submissionCount > 0): ?>
            <p class="admin-text-muted"><?= admin_te($submissionCount === 1 ? 'forms.stored_submissions_remain_one' : 'forms.stored_submissions_remain', ['count' => $submissionCount]) ?></p>
          <?php endif; ?>

          <div class="admin-field">
            <?= admin_field_label('form-reply-to', admin_t('forms.reply_to_label'), admin_t('help.forms.reply_to')) ?>
            <select name="reply_to_field_key" id="form-reply-to" class="admin-select">
              <option value=""><?= admin_te('forms.reply_to_none') ?></option>
              <?php foreach ($replyToCandidates as $candidate): ?>
                <option value="<?= $h($candidate->key) ?>" <?= ($values['reply_to_field_key'] ?? '') === $candidate->key ? 'selected' : '' ?>><?= $h($candidate->recordedLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($replyToCandidates === []): ?>
            <p class="admin-text-muted"><?= admin_te('forms.reply_to_needs_email_field') ?></p>
          <?php endif; ?>
        </div>
      </details>
    </section>

    <?php /* The form's own Opslaan, for a browser without the save bar's
             script. With it, the bar's Opslaan is the one button and this
             one is hidden (data-save-bar-fallback, admin/assets/save-bar.js);
             it stays in the form, so Enter in a field still saves. */ ?>
    <button type="submit" data-save-bar-fallback><?= admin_te('common.save') ?></button>
  </form>

  <section class="admin-card" aria-labelledby="form-fields-title" id="form-fields">
    <h2 id="form-fields-title"><?= admin_te('forms.velden') ?></h2>

    <?php if ($fieldRows === []): ?>
      <p class="admin-text-muted"><?= admin_te('forms.formulier_heeft_velden_zolang') ?></p>
    <?php else: ?>
      <?php /* One compact row per field, in form order: ↑/↓ where a drag
               handle would be, the label, one line saying what it is (its
               kind, whether it is required, its width, how many options),
               and Bewerken and Verwijderen on the right. Every other setting
               is on the field's own screen. The rows are the navigation and
               footer rows of this CMS (.admin-section-row), with the same
               square arrows. */ ?>
      <ol class="admin-form-field-list">
        <?php foreach ($fieldRows as $index => $field): ?>
          <?php
            $fieldId = (int) $field['id'];
            $fieldName = FormLocalization::fieldName($fieldId);
            $type = FormFieldTypes::get((string) $field['field_type']);
            $optionCount = $type !== null && $type->usesOptions() ? count($field['choices'] ?? []) : 0;
            $isRequired = $type !== null && $type->requiredIsFixed() ? true : (int) $field['is_required'] === 1;
            $width = FormFieldWidth::fromStored($field['layout_width'] ?? null);

            $facts = [form_field_type_label((string) $field['field_type'])];
            $facts[] = admin_t($isRequired ? 'forms.field_row.required' : 'forms.field_row.optional');
            $facts[] = admin_t('forms.width.short.' . $width);
            if ($type !== null && $type->usesOptions()) {
                $facts[] = admin_t($optionCount === 1 ? 'forms.option_count_one' : 'forms.option_count', ['count' => $optionCount]);
            }
          ?>
          <li class="admin-section-row admin-form-field-row" id="form-field-<?= $fieldId ?>">
            <div class="admin-form-field-row__move">
              <?php foreach (['up' => ['forms.field_row.move_up_label', '&uarr;', $index === 0], 'down' => ['forms.field_row.move_down_label', '&darr;', $index === count($fieldRows) - 1]] as $direction => [$labelKey, $arrow, $disabled]): ?>
                <form method="post" action="/api/admin/move-form-field.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="field_id" value="<?= $fieldId ?>">
                  <input type="hidden" name="direction" value="<?= $direction ?>">
                  <button type="submit" class="admin-btn-ghost admin-row-move" aria-label="<?= admin_te($labelKey, ['field' => $fieldName]) ?>"<?= $disabled ? ' disabled' : '' ?>><span aria-hidden="true"><?= $arrow ?></span></button>
                </form>
              <?php endforeach; ?>
            </div>
            <div class="admin-section-row__body">
              <p class="admin-section-row__name"><?= $h($fieldName) ?></p>
              <p class="admin-section-row__note"><?= $h(implode(' · ', $facts)) ?></p>
              <?php if ($type === null): ?>
                <p class="admin-section-row__note admin-form-field-row__problem"><?= admin_te('forms.veldtype_bestaat_meer_veld') ?></p>
              <?php elseif ($type->usesOptions() && $optionCount === 0): ?>
                <p class="admin-section-row__note admin-form-field-row__problem"><?= admin_te('forms.keuzeveld_heeft_opties_dus') ?></p>
              <?php endif; ?>
            </div>
            <div class="admin-section-row__actions">
              <a href="/admin/form-field.php?id=<?= $fieldId ?>" class="admin-section-row__edit" aria-label="<?= admin_te('forms.field_row.edit_label', ['field' => $fieldName]) ?>"><?= admin_te('common.edit') ?></a>
              <form method="post" action="/api/admin/delete-form-field.php" class="admin-inline-form"<?= admin_confirm_attributes(
                  admin_t('forms.delete_field.title'),
                  admin_t('forms.delete_field.message', ['field' => $fieldName, 'form' => (string) $row['name']]),
                  admin_t('common.delete')
              ) ?>>
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="field_id" value="<?= $fieldId ?>">
                <button type="submit" class="admin-btn-danger admin-section-row__button" aria-label="<?= admin_te('forms.field_row.delete_label', ['field' => $fieldName]) ?>"><?= admin_te('common.delete') ?></button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <p class="admin-field-add">
      <a class="admin-btn-primary" href="/admin/form.php?id=<?= $id ?>&amp;add_field=1#form-field-add" data-form-field-add-open aria-haspopup="dialog"><?= admin_te('forms.veld_toevoegen') ?></a>
    </p>
  </section>
    </div>

    <?php /* The preview: the stored form, rendered by the public renderer in
             a document of its own (admin/form-preview.php). The frame is
             sandboxed with nothing but allow-same-origin: no script runs in
             it and nothing in it can be sent, and it lets forms-admin.js
             read how tall the form is, to size the frame. Without that
             script the frame simply has a fixed height and its own scroll
             bar, at the width of this column. With it, Desktop draws the
             form at a desktop width scaled into the column, and Mobiel at a
             phone's, because the site's media query answers to the width of
             the frame. */ ?>
    <aside class="admin-card admin-form-builder__preview" aria-labelledby="form-preview-title" data-form-preview>
      <div class="admin-form-preview__head">
        <h2 id="form-preview-title"><?= admin_te('forms.preview.title') ?></h2>
        <div class="admin-block-preview__viewports" role="group" aria-label="<?= admin_te('forms.preview.viewports') ?>" data-form-preview-viewports hidden>
          <button type="button" class="admin-block-preview__viewport" data-form-preview-viewport="desktop" aria-pressed="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg>
            <span><?= admin_te('forms.preview.desktop') ?></span>
          </button>
          <button type="button" class="admin-block-preview__viewport" data-form-preview-viewport="mobile" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2"/></svg>
            <span><?= admin_te('forms.preview.mobile') ?></span>
          </button>
        </div>
      </div>
      <p class="admin-text-muted"><?= admin_te('forms.preview.intro') ?></p>
      <?php if (!$isActive): ?>
        <p class="admin-alert admin-alert--warning"><?= admin_te('forms.preview.inactive') ?></p>
      <?php endif; ?>
      <?php if ($definition === null || !$definition->hasFields()): ?>
        <p class="admin-text-muted"><?= admin_te('forms.preview.empty') ?></p>
      <?php else: ?>
        <div class="admin-form-preview__stage" data-form-preview-stage data-viewport="desktop">
          <iframe class="admin-form-preview__frame" data-form-preview-frame
                  src="/admin/form-preview.php?id=<?= $id ?>"
                  title="<?= admin_te('forms.preview.frame_title', ['form' => (string) $row['name']]) ?>"
                  sandbox="allow-same-origin" referrerpolicy="same-origin"></iframe>
        </div>
      <?php endif; ?>
    </aside>
  </div>

  <?php /* "Veld toevoegen": a native <dialog>, modal once forms-admin.js
           opens it (the page behind it inert, Escape closes it, focus goes
           back to the opener). Rendered `open` for the no-JavaScript link and
           for a refused add, and then not modal: admin.css lets it sit in the
           page like a card. Choosing a type is a radio, so nothing is created
           until the form is sent, and Annuleren is a link back to this
           screen that the script turns into "close". */ ?>
  <dialog class="admin-field-picker" id="form-field-add" aria-labelledby="form-field-add-title" data-form-field-add<?= $addOpen ? ' open' : '' ?>>
    <form method="post" action="/api/admin/create-form-field.php" class="admin-field-picker__panel" data-no-dirty-track>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="form_id" value="<?= $id ?>">

      <h2 class="admin-field-picker__title" id="form-field-add-title"><?= admin_te('forms.veld_toevoegen') ?></h2>

      <?php if ($addErrors !== []): ?>
        <div class="admin-alert admin-alert--error" role="alert">
          <ul class="admin-error-list">
            <?php foreach ($addErrors as $error): ?>
              <li><?= $h((string) $error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <fieldset class="admin-field-picker__types">
        <legend class="admin-field-picker__legend"><?= admin_te('forms.add_field_type_question') ?></legend>
        <?php form_field_type_cards('field_type', $addType); ?>
      </fieldset>

      <div class="admin-field">
        <?= admin_field_label('form-field-add-label', admin_t('forms.label'), admin_t('help.forms.field_label'), true) ?>
        <input type="text" id="form-field-add-label" name="label" maxlength="200" required value="<?= $h($addLabel) ?>">
      </div>
      <p class="admin-text-muted"><?= admin_te('forms.add_field_after') ?></p>
      <?php admin_localized_new_item_note($editLanguage); ?>

      <div class="admin-field-picker__actions">
        <a class="admin-btn-secondary" href="/admin/form.php?id=<?= $id ?>" data-form-field-add-close><?= admin_te('common.cancel') ?></a>
        <button type="submit" class="admin-btn-primary"><?= admin_te('forms.veld_toevoegen_2') ?></button>
      </div>
    </form>
  </dialog>

  <section class="admin-card">
    <h2><?= admin_te('forms.formulier_verwijderen') ?></h2>
    <?php if ($blockers === []): ?>
      <p class="admin-text-muted"><?= admin_te('forms.formulier_staat_nergens_heeft') ?></p>
      <form method="post" action="/api/admin/delete-form.php"<?= admin_confirm_attributes(
          admin_t('forms.delete_form.title'),
          admin_t('forms.delete_form.message', ['form' => (string) $row['name']]),
          admin_t('common.delete')
      ) ?>>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit"><?= admin_te('forms.definitief_verwijderen') ?></button>
      </form>
    <?php else: ?>
      <p class="admin-text-muted"><?= admin_te('forms.formulier_nu_verwijderd') ?></p>
      <ul class="admin-error-list">
        <?php foreach ($blockers as $blocker): ?>
          <li><?= $h($blocker) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>
<?php save_bar(); ?>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/forms-admin.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
