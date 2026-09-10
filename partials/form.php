<?php

/**
 * THE public form renderer: one `<form>`, built from a form definition and
 * the state of one rendered instance.
 *
 * Everything that is the same for every field — the `.form-field` wrapper,
 * the `<label>`, the required marker, the hint, the error message and the
 * `aria-describedby` that ties them together — is here, once. The control
 * itself is echoed by the field type
 * (App\Service\Forms\FieldTypes\FormFieldType::renderControl()), so this
 * file contains no `switch` over field types and never asks what type it is
 * rendering. Adding a type changes nothing here.
 *
 * IT WORKS WITHOUT JAVASCRIPT. The form is an ordinary POST to
 * /api/form-submit.php, which answers a browser with a 303 back to the page
 * (assets/js/blocks/form.js only upgrades that to a fetch so the page does
 * not reload). Validation errors come back rendered next to their fields,
 * with everything the visitor typed still in place.
 *
 * ACCESSIBILITY is not an afterthought here: every control has a real
 * `<label for>`, a radio group is a `<fieldset>` with a `<legend>`, required
 * fields carry `required` and `aria-required`, an invalid one carries
 * `aria-invalid` and points at its message through `aria-describedby`, and
 * the error summary is a focusable `role="alert"` with a link to each field.
 *
 * @param \App\Service\Forms\FormDefinition  $form
 * @param \App\Service\Forms\FormRenderState $state
 */

use App\Service\Forms\FieldTypes\FormFieldControl;
use App\Service\Forms\FormDefinition;
use App\Service\Forms\FormRenderState;
use App\Service\Forms\FormSourcePath;
use App\Service\Forms\FormSpamGuard;

/**
 * The success message, shown INSTEAD of the form after a successful
 * submission. Its own function because the `contact_form` block shows it in
 * the same place its old confirmation banner appeared.
 */
function render_form_success(FormDefinition $form, FormRenderState $state): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
  <div class="form-status is-visible form-status--ok" role="status" aria-live="polite" tabindex="-1"
       id="<?= $h($state->id('status')) ?>"
       data-nl="<?= $h($form->successMessage->nl) ?>"
       data-en="<?= $h($form->successMessage->en) ?>"><?= $h($form->successMessage->nl) ?></div>
    <?php
}

/**
 * The form itself, or the success message when the visitor has just sent it.
 *
 * @param array<int, array{html: string}> $extraControls markup appended after the
 *        fields, used by the `contact_form` block for the attachment control it
 *        has always had. Never editor input — see partials/section-contact-form.php.
 */
function render_form(FormDefinition $form, FormRenderState $state, array $extraControls = []): void
{
    if ($state->showSuccess) {
        render_form_success($form, $state);

        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $summaryId = $state->id('summary');
    ?>
  <form class="vvl-form" method="post" action="/api/form-submit.php" novalidate
        enctype="multipart/form-data"
        id="<?= $h($state->token) ?>"
        data-form-block
        data-form-token="<?= $h($state->token) ?>">

    <?php // Which form, and which placement of it. Both are checked server-side. ?>
    <input type="hidden" name="form-key" value="<?= $h($form->internalKey) ?>">
    <input type="hidden" name="form-instance" value="<?= $h($state->token) ?>">
    <?php
    // Where to send the visitor back to. Validated as a same-site path
    // before it is ever used in a Location header — App\Service\Forms\FormSourcePath.
    ?>
    <input type="hidden" name="form-source" value="<?= $h(FormSourcePath::current()) ?>">
    <?php // The render time; a POST that arrives within seconds was not typed by a person. ?>
    <input type="hidden" name="<?= $h(FormSpamGuard::TIMESTAMP_FIELD) ?>" value="<?= time() ?>">

    <?php
    // The honeypot. Off-screen rather than display:none and with
    // tabindex="-1" plus aria-hidden, so a screen reader skips it exactly
    // like a sighted visitor does — a hidden field that traps assistive
    // technology users would be worse than no honeypot at all.
    ?>
    <div class="form-field visually-hidden" aria-hidden="true">
      <label for="<?= $h($state->id('hp')) ?>">Laat dit veld leeg</label>
      <input type="text" id="<?= $h($state->id('hp')) ?>" name="<?= $h(FormSpamGuard::HONEYPOT_FIELD) ?>" tabindex="-1" autocomplete="off">
    </div>

    <div class="form-error-summary<?= $state->hasErrors() ? ' is-visible' : '' ?>" role="alert" id="<?= $h($summaryId) ?>"<?= $state->hasErrors() ? ' tabindex="-1"' : '' ?>>
      <?php if ($state->hasErrors()): ?>
        <p data-nl="Controleer het volgende:" data-en="Please check the following:">Controleer het volgende:</p>
        <ul>
          <?php foreach ($form->fields as $field): ?>
            <?php $error = $state->errorFor($field->key); ?>
            <?php if ($error !== null): ?>
              <li><a href="#<?= $h($state->id($field->key)) ?>" data-nl="<?= $h($error->nl) ?>" data-en="<?= $h($error->en) ?>"><?= $h($error->nl) ?></a></li>
            <?php endif; ?>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="form-grid">
      <?php foreach ($form->fields as $field): ?>
        <?php render_form_field($field, $state); ?>
      <?php endforeach; ?>

      <?php foreach ($extraControls as $extra): ?>
        <?= $extra['html'] ?>
      <?php endforeach; ?>
    </div>

    <button type="submit" class="btn btn--block"
            data-nl="<?= $h($form->submitLabel->nl) ?>"
            data-en="<?= $h($form->submitLabel->en) ?>"><?= $h($form->submitLabel->nl) ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
    </button>
  </form>
  <div class="form-status" role="status" aria-live="polite" id="<?= $h($state->id('status')) ?>"
       data-form-success-nl="<?= $h($form->successMessage->nl) ?>"
       data-form-success-en="<?= $h($form->successMessage->en) ?>"></div>
    <?php
}

/**
 * One field: its wrapper, its label, its hint, its control and its error.
 *
 * @param \App\Service\Forms\FormField $field
 */
function render_form_field(\App\Service\Forms\FormField $field, FormRenderState $state): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $type = $field->type;
    $id = $state->id($field->key);
    $hintId = $id . '-hint';
    $errorId = $id . '-error';
    $error = $state->errorFor($field->key);

    $describedBy = [];
    if (!$field->helpText->isEmpty()) {
        $describedBy[] = $hintId;
    }
    // The error element is always present (empty when there is nothing to
    // say) and always referenced, so a message announced after a failed
    // submit lands in a region the control already points at.
    $describedBy[] = $errorId;

    $control = new FormFieldControl(
        $field,
        $id,
        $field->key,
        // Submitted value, else the field's configured default, else empty —
        // see FormRenderState::valueFor().
        $state->valueFor($field),
        implode(' ', $describedBy),
        $error !== null
    );

    $position = $type->labelPosition();
    // A textarea and a group of radios want the full width of the grid; a
    // name and an e-mail address sit comfortably side by side.
    $fullWidth = $position !== 'before' || in_array($type->key(), ['textarea', 'select'], true);

    $classes = 'form-field' . ($fullWidth ? ' form-field--full' : '') . ($error !== null ? ' has-error' : '');
    ?>
  <div class="<?= $h($classes) ?>">
    <?php if ($position === 'legend'): ?>
      <?php
      // The GROUP carries the field's id, not any one radio inside it — the
      // radios are numbered from it. That is what the error summary's link
      // and assets/js/blocks/form.js both address, so "jump to the field
      // that failed" lands on the whole question rather than on one option
      // (or, worse, on nothing at all). tabindex="-1" lets that anchor
      // actually take focus.
      ?>
      <fieldset id="<?= $h($id) ?>" tabindex="-1"<?= $describedBy !== [] ? ' aria-describedby="' . $h(implode(' ', $describedBy)) . '"' : '' ?>>
        <?php
        // The label text sits in a span of its own INSIDE the legend, for the
        // same reason it does inside a <label>: assets/js/core.js swaps a
        // data-nl element's innerHTML wholesale, so anything that must
        // survive a language switch — here the required marker — has to be
        // its sibling rather than its content.
        ?>
        <legend><span data-nl="<?= $h($field->label->nl) ?>" data-en="<?= $h($field->label->en) ?>"><?= $h($field->label->nl) ?></span><?php
        if ($field->isRequired) {
            echo ' <span class="req" aria-hidden="true">*</span>';
        }
        ?></legend><?php
        render_form_hint($field, $hintId);
        $type->renderControl($control);
        ?>
      </fieldset>
    <?php elseif ($position === 'wrap'): ?>
      <?php render_form_hint($field, $hintId); ?>
      <label class="form-check" for="<?= $h($id) ?>"><?php
        $type->renderControl($control);
        ?><span data-nl="<?= $h($field->label->nl) ?>" data-en="<?= $h($field->label->en) ?>"><?= $h($field->label->nl) ?></span><?php
        if ($field->isRequired) {
            echo ' <span class="req" aria-hidden="true">*</span>';
        }
        ?></label>
    <?php else: ?>
      <label for="<?= $h($id) ?>"><span data-nl="<?= $h($field->label->nl) ?>" data-en="<?= $h($field->label->en) ?>"><?= $h($field->label->nl) ?></span><?php
        if ($field->isRequired) {
            echo ' <span class="req" aria-hidden="true">*</span>';
        }
        ?></label>
      <?php render_form_hint($field, $hintId); ?>
      <?php $type->renderControl($control); ?>
    <?php endif; ?>

    <span class="form-error<?= $error !== null ? ' is-visible' : '' ?>" id="<?= $h($errorId) ?>"<?= $error !== null ? ' data-nl="' . $h($error->nl) . '" data-en="' . $h($error->en) . '"' : '' ?>><?= $error !== null ? $h($error->nl) : '' ?></span>
  </div>
    <?php
}

/**
 * The optional hint under a label. Its id is what the control references, so
 * it is only printed when there is something to reference.
 *
 * @param \App\Service\Forms\FormField $field
 */
function render_form_hint(\App\Service\Forms\FormField $field, string $hintId): void
{
    if ($field->helpText->isEmpty()) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <span class="hint" id="<?= $h($hintId) ?>" data-nl="<?= $h($field->helpText->nl) ?>" data-en="<?= $h($field->helpText->en) ?>"><?= $h($field->helpText->nl) ?></span>
    <?php
}
