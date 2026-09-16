<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';

use App\Service\Forms\FormFieldTypes;

/**
 * What the CMS calls a form field type, and the cards an editor picks one
 * from.
 *
 * Shared by "Veld toevoegen" on the form editor (admin/form.php) and "Ander
 * soort veld" on the field editor (admin/form-field.php), so both screens
 * show the same words for the same type and neither keeps a list of its own.
 *
 * THE WORDS ARE THE CATALOGUE'S. A type is identified by its registry key
 * (App\Service\Forms\FormFieldTypes), which is what `form_fields.field_type`
 * stores and never changes; its name and one-line description are
 * `formfieldtype.<key>.label` and `.description`, in the reader's CMS
 * language. The key itself is never printed as the name of anything.
 *
 * THE CARDS ARE RADIOS, the radio cards Nieuwe pagina picks a template with
 * (.admin-template-card): the whole card is the hit area, arrow keys move
 * between types, and the choice is sent with the form it sits in. No
 * JavaScript picks anything.
 */

/** The name of a type, or a plain "unknown" for a key nobody registered. */
function form_field_type_label(string $key): string
{
    return FormFieldTypes::has($key)
        ? admin_t('formfieldtype.' . $key . '.label')
        : admin_t('forms.unknown_field_type', ['key' => $key]);
}

/** One sentence on what a registered type is for. */
function form_field_type_description(string $key): string
{
    return admin_t('formfieldtype.' . $key . '.description');
}

/**
 * One radio card per registered type, in registration order. Call it inside
 * a <fieldset> (or an element with role and name) that says what is being
 * chosen.
 *
 * @param string                $name    the name the choice is sent under
 * @param string                $checked the key that starts selected; '' for none
 * @param array<string, string> $notes   key => one plain-text line under that card
 */
function form_field_type_cards(string $name, string $checked, array $notes = []): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="admin-template-grid admin-field-type-grid">
      <?php foreach (FormFieldTypes::keys() as $key): ?>
        <label class="admin-template-card">
          <input type="radio" name="<?= $h($name) ?>" value="<?= $h($key) ?>" required<?= $checked === $key ? ' checked' : '' ?>>
          <span class="admin-template-card__inner">
            <span class="admin-template-card__head">
              <span class="admin-template-card__name"><?= $h(form_field_type_label($key)) ?></span>
            </span>
            <span class="admin-template-card__desc"><?= $h(form_field_type_description($key)) ?></span>
            <?php if (($notes[$key] ?? '') !== ''): ?>
              <span class="admin-template-card__note"><?= $h($notes[$key]) ?></span>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php
}
