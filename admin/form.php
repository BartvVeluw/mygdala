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
 * Then the fields, with no drag-and-drop page builder anywhere. Reordering
 * is the same pair of up/down buttons every repeater in this CMS uses
 * (admin/faq.php, admin/card-carousel.php), which works without JavaScript
 * and needs no library.
 *
 * Editing ONE field happens on its own screen (admin/form-field.php), like a
 * carousel card: a field has nine settings, and nine of them per row inline
 * would make a five-field form unreadable. The list says what each field is
 * in words (its label, its kind, how many options) and not the name it is
 * posted under: nobody editing a form needs that name, and the field's own
 * screen keeps it under "Technische gegevens" for whoever does.
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
unset(
    $_SESSION['admin_form_errors'],
    $_SESSION['admin_form_old'],
    $_SESSION['admin_form_field_add_errors'],
    $_SESSION['admin_form_field_add_old']
);
$saved = isset($_GET['saved']);

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

    <button type="submit"><?= admin_te('common.save') ?></button>
  </form>

  <section class="admin-card">
    <h2><?= admin_te('forms.velden') ?></h2>

    <?php if ($fieldRows === []): ?>
      <p class="admin-text-muted"><?= admin_te('forms.formulier_heeft_velden_zolang') ?></p>
    <?php endif; ?>

    <?php foreach ($fieldRows as $index => $field): ?>
      <?php
        $fieldId = (int) $field['id'];
        $type = FormFieldTypes::get((string) $field['field_type']);
        $isFirst = $index === 0;
        $isLast = $index === count($fieldRows) - 1;
        $optionCount = $type !== null && $type->usesOptions() ? count($field['choices'] ?? []) : 0;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <div class="admin-main__heading">
          <h3 style="margin:0;"><?= $h(FormLocalization::fieldName($fieldId)) ?></h3>
          <?php if ((int) $field['is_required'] === 1): ?>
            <span class="admin-badge admin-badge--info"><?= admin_te('common.required_badge') ?></span>
          <?php endif; ?>
        </div>
        <p class="admin-text-muted">
          <?= $h(form_field_type_label((string) $field['field_type'])) ?>
          <?php if ($type !== null && $type->usesOptions()): ?>
            &middot; <?= admin_te($optionCount === 1 ? 'forms.option_count_one' : 'forms.option_count', ['count' => $optionCount]) ?>
          <?php endif; ?>
        </p>
        <?php if ($type === null): ?>
          <p class="admin-alert admin-alert--error"><?= admin_te('forms.veldtype_bestaat_meer_veld') ?></p>
        <?php elseif ($type->usesOptions() && $optionCount === 0): ?>
          <p class="admin-alert admin-alert--error"><?= admin_te('forms.keuzeveld_heeft_opties_dus') ?></p>
        <?php endif; ?>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <a class="admin-btn-text" href="/admin/form-field.php?id=<?= $fieldId ?>"><?= admin_te('common.edit') ?></a>
          <form method="post" action="/api/admin/move-form-field.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-form-field.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-form-field.php" class="admin-inline-form"<?= admin_confirm_attributes(
              admin_t('forms.delete_field.title'),
              admin_t('forms.delete_field.message', ['field' => FormLocalization::fieldName($fieldId), 'form' => (string) $row['name']]),
              admin_t('common.delete')
          ) ?>>
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>

    <p class="admin-field-add">
      <a class="admin-btn-primary" href="/admin/form.php?id=<?= $id ?>&amp;add_field=1#form-field-add" data-form-field-add-open aria-haspopup="dialog"><?= admin_te('forms.veld_toevoegen') ?></a>
    </p>
  </section>

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
