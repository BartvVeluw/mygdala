<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypeChange;
use App\Service\Forms\FormFieldTypes;

/**
 * What the CMS calls a form field type, the cards an editor picks one from,
 * and what changing a field's type would lose.
 *
 * Shared by "Veld toevoegen" on the form editor (admin/form.php) and the
 * field editor (admin/form-field.php), so both screens show the same words
 * for the same type and neither keeps a list of its own.
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
 *
 * A RADIO IS NAMED BY ITS TYPE ALONE. A <label> around a whole card would
 * make every word on it the radio's name, so a screen reader would read the
 * description and the note as part of "Kort tekstveld" for each of the eight
 * cards. The radio points at the name with aria-labelledby and at the rest
 * with aria-describedby: the same words, heard as a name and a description.
 * The <label> stays, because it is what makes the whole card clickable.
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
        <?php
          $id = admin_ui_id('form-field-type-' . $key);
          $hasNote = ($notes[$key] ?? '') !== '';
          $describedBy = $id . '-desc' . ($hasNote ? ' ' . $id . '-note' : '');
        ?>
        <label class="admin-template-card">
          <input type="radio" name="<?= $h($name) ?>" value="<?= $h($key) ?>" required<?= $checked === $key ? ' checked' : '' ?> aria-labelledby="<?= $h($id) ?>-name" aria-describedby="<?= $h($describedBy) ?>">
          <span class="admin-template-card__inner">
            <span class="admin-template-card__head">
              <span class="admin-template-card__name" id="<?= $h($id) ?>-name"><?= $h(form_field_type_label($key)) ?></span>
            </span>
            <span class="admin-template-card__desc" id="<?= $h($id) ?>-desc"><?= $h(form_field_type_description($key)) ?></span>
            <?php if ($hasNote): ?>
              <span class="admin-template-card__note" id="<?= $h($id) ?>-note"><?= $h($notes[$key]) ?></span>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * What a type change would lose, one sentence per setting, naming what the
 * setting holds now ("de 3 opties: Ja, Nee, Misschien") so an editor can
 * judge it without opening anything else. Plain text.
 *
 * @param list<string>         $losses from App\Service\Forms\FormFieldTypeChange::losses()
 * @param array<string, mixed> $row    the field's stored row with its words and options
 *                                    (App\Service\Forms\FormLocalization::attachFieldWords())
 * @return list<string>
 */
function form_field_loss_sentences(array $losses, array $row): array
{
    $options = FormFieldOptions::fromRows(is_array($row['choices'] ?? null) ? $row['choices'] : []);
    $placeholders = [];
    foreach ((array) ($row['translations'] ?? []) as $words) {
        $placeholders[] = trim((string) ($words['placeholder'] ?? ''));
    }
    $sentences = [];

    foreach ($losses as $loss) {
        $sentences[] = match ($loss) {
            FormFieldTypeChange::PLACEHOLDER => admin_t('forms.loses.placeholder', [
                'text' => (string) (array_values(array_filter($placeholders))[0] ?? ''),
            ]),
            FormFieldTypeChange::OPTIONS => admin_t($options->count() === 1 ? 'forms.loses.options_one' : 'forms.loses.options', [
                'count' => $options->count(),
                'options' => form_field_option_names($options),
            ]),
            FormFieldTypeChange::DEFAULT_VALUE => admin_t('forms.loses.default_value', ['option' => trim((string) ($row['default_value'] ?? ''))]),
            FormFieldTypeChange::REPLY_TO => admin_t('forms.loses.reply_to'),
            default => $loss,
        };
    }

    return $sentences;
}

/**
 * The short line under a type card in the field editor: what choosing that
 * type would lose, or that everything stays. Plain text.
 *
 * @param list<string> $losses
 */
function form_field_loss_note(array $losses): string
{
    if ($losses === []) {
        return admin_t('forms.type_card.keeps_everything');
    }

    return admin_t('forms.type_card.loses', [
        'settings' => implode(', ', array_map(
            static fn (string $loss): string => admin_t('forms.loses_short.' . $loss),
            $losses
        )),
    ]);
}

/** The first few options by the name the CMS gives them, for a sentence. */
function form_field_option_names(FormFieldOptions $options): string
{
    $shown = 5;
    $names = array_map(
        static fn (\App\Service\Forms\FormOption $option): string => $option->label,
        array_slice($options->all(), 0, $shown)
    );

    return implode(', ', $names) . ($options->count() > $shown ? ', …' : '');
}
