<?php

/**
 * POST /api/admin/update-form-field.php
 *
 * Saves one field (admin/form-field.php?id=<id>).
 *
 * POST/REDIRECT/GET, AND BACK TO THE FORM. A save that went through sends
 * the editor to the form the field belongs to (admin/form.php), at that
 * field's row, where the preview already shows it; the address is built from
 * the stored form id and field id, so no request can point it anywhere else.
 * Which field was saved, and whether its default had to go, travels in the
 * session for that one screen. A save that wrote nothing — refused, or a type
 * change waiting for confirmation — goes back to this field's own editor
 * with what was sent, as it always did.
 *
 * THE WIDTH (FORMS.md, "Breedte van een veld") is one key of
 * App\Service\Forms\FormFieldWidth. Any other value is refused with the
 * other errors; it is never stored, and never becomes a class or a style.
 *
 * IT DOES NOT TOUCH `field_key`, and there is no way to make it: the key is
 * what stored answers are filed under, and changing it would orphan every
 * one of them. Renaming a label is free and is what an editor means by
 * renaming a field — a submission keeps the label it was sent with.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0 phase 4, admin/_localized_fields.php).
 * The label, help text and placeholder are written in the active website
 * language named by `language_code` only (App\Service\Forms\FormLocalization);
 * every other language stays exactly as it is. The label is required in the
 * default language only. The type, the required switch, the default and the
 * options as a list (which exist, in which order) are the same in every
 * language and may be changed from any language's screen.
 *
 * WHAT IS WRITTEN IS WHAT THE SCREEN SHOWED. The editor shows only the
 * settings the field's type uses, so a setting that was not on the submitted
 * form is left exactly as it is stored instead of being saved as empty — the
 * rule this endpoint's neighbour api/admin/update-page.php applies to the
 * social image. A field whose type has no placeholder keeps one it once had,
 * unused and untouched. `is_required` has a hidden 0 in front of its switch
 * (admin/form-field.php) so that "unticked" arrives at all; a consent box is
 * required whatever arrives.
 *
 * OPTIONS KEEP THEIR IDENTITY. One row per option: `option_id[i]` (empty for
 * a row added on screen), its label in this language `option_label[i]`, and
 * the default is the ROW that was marked (`default_option` = i). What the
 * rows mean:
 *
 *   - an existing option keeps its id and its VALUE (App\Service\Forms\
 *     FormOption), whatever its label becomes, so renaming or translating it
 *     never changes what a submission stores; its label is written in this
 *     language, and the rows' order becomes the options' order;
 *   - an existing option left out, or emptied on the DEFAULT language's
 *     screen, is no option any more (its labels go with it); emptied on a
 *     translation's screen it only loses that translation;
 *   - a new row with words becomes a new option written in the DEFAULT
 *     language, like a new field: its label there is what was typed, and its
 *     value is that label, made unique within the field. It never changes
 *     afterwards;
 *   - two options with the same label in the default language are one: the
 *     later row is dropped, as it always was.
 *
 * An option added, renamed or marked in this save can be the default of this
 * save. A marked row that is no option any more leaves the field without a
 * default — never with one that points at nothing — and the editor is told.
 *
 * CHANGING THE TYPE NEVER LOSES ANYTHING UNASKED.
 * App\Service\Forms\FormFieldTypeChange says what the new type would throw
 * away. A change that loses something, or that needs a setting the
 * submitted form did not show (the options of a field that becomes a
 * dropdown), stores NOTHING the first time: the input goes back to the
 * editor exactly as a refused save does, and the editor shows the new
 * type's settings with what will disappear above them. Only a save that
 * carries `confirmed_type` for that same type goes through — the flow
 * api/admin/update-page.php uses for a new web address, and enforced here,
 * so a scripted request cannot skip it either. What was confirmed as lost is
 * cleared — a placeholder in every language, every option — and nothing
 * else. A change that loses nothing and needs nothing new is saved at once.
 *
 * The field, its words and its options are one transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\FormFieldOptionRepository;
use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormField;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypeChange;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormFieldWidth;
use App\Service\Forms\FormLocalization;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('forms.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$fieldId = filter_input(INPUT_POST, 'field_id', FILTER_VALIDATE_INT);
if ($fieldId === false || $fieldId === null || $fieldId < 1) {
    http_response_code(400);
    exit('Invalid field id.');
}

$db = Database::connection();
$repository = new FormRepository($db);
$existing = $repository->findField($fieldId);

if ($existing === null) {
    http_response_code(404);
    exit('Field not found.');
}

// The stored words per language and the option rows, which the type change
// and the option rules below read.
[$existing] = FormLocalization::attachFieldWords([$existing]);

$formId = (int) $existing['form_id'];
$form = $repository->find($formId);
$editorUrl = '/admin/form-field.php?id=' . $fieldId;

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$defaultLanguage = FormLocalization::defaultLanguage();
$isDefaultLanguage = $languageCode === $defaultLanguage;

$text = static fn (string $name, int $maxLength): string => is_string($_POST[$name] ?? null)
    ? mb_substr(trim($_POST[$name]), 0, $maxLength)
    : '';

$typeKey = is_string($_POST['field_type'] ?? null) ? $_POST['field_type'] : '';
$type = FormFieldTypes::get($typeKey);

// What the editor sent, and only the groups of settings it sent: this is
// also what goes back to the screen when nothing is written.
$submitted = [
    'language_code' => $languageCode,
    'field_type' => $type === null ? (string) $existing['field_type'] : $type->key(),
    'label' => $text('label', 200),
    'help_text' => $text('help_text', 500),
];

if (array_key_exists('placeholder', $_POST)) {
    $submitted['placeholder'] = $text('placeholder', 200);
}

if (array_key_exists('is_required', $_POST)) {
    $submitted['is_required'] = ($_POST['is_required'] ?? '') === '1';
}

// A key of the closed list and nothing else (App\Service\Forms\FormFieldWidth):
// anything that is not one is refused below, never stored as a style or
// turned into a class.
if (array_key_exists('layout_width', $_POST)) {
    $submitted['layout_width'] = is_string($_POST['layout_width']) ? mb_substr($_POST['layout_width'], 0, 20) : '';
}

if (array_key_exists('option_label', $_POST)) {
    $labels = is_array($_POST['option_label']) ? $_POST['option_label'] : [];
    $ids = is_array($_POST['option_id'] ?? null) ? $_POST['option_id'] : [];
    $rows = [];

    foreach ($labels as $index => $value) {
        $id = is_string($ids[$index] ?? null) && ctype_digit($ids[$index]) ? (int) $ids[$index] : 0;
        $rows[] = [
            'index' => (string) $index,
            'id' => $id,
            'label' => FormFieldOptions::rowText($value),
        ];
    }

    $submitted['option_rows'] = $rows;
    $submitted['default_option'] = is_string($_POST['default_option'] ?? null) ? $_POST['default_option'] : '';
}

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} elseif ($isDefaultLanguage && $submitted['label'] === '') {
    $errors[] = AdminTranslator::trans('validation.label_verplicht_2');
}

if ($type === null) {
    $errors[] = AdminTranslator::trans('validation.kies_geldig_veldtype');
}

if (array_key_exists('layout_width', $submitted) && !FormFieldWidth::isValid($submitted['layout_width'])) {
    $errors[] = AdminTranslator::trans('validation.field_width_unknown');
}

$sendBack = static function (array $submitted, array $errors) use ($editorUrl): never {
    $_SESSION['admin_form_field_errors'] = $errors;
    $_SESSION['admin_form_field_old'] = $submitted;
    header('Location: ' . $editorUrl);
    exit;
};

if ($type === null || !$languageIsWritable) {
    $sendBack($submitted, $errors);
}

$losses = FormFieldTypeChange::losses($existing, $type, $form['reply_to_field_key'] ?? null);

if ($type->key() !== (string) $existing['field_type']) {
    $confirmed = ($_POST['confirmed_type'] ?? null) === $type->key();

    // The settings the new type has that the submitted form did not show:
    // the editor has not seen them yet, so it must before anything is saved.
    $unseen = ($type->usesOptions() && !array_key_exists('option_rows', $submitted))
        || ($type->usesPlaceholder() && !array_key_exists('placeholder', $submitted))
        || (!$type->requiredIsFixed() && !array_key_exists('is_required', $submitted));

    if (!$confirmed && ($losses !== [] || $unseen)) {
        $sendBack($submitted, $errors);
    }
}

// Start from the stored row, so a setting that was not sent stays as it is.
$values = [
    'field_type' => $type->key(),
    'is_required' => (bool) $existing['is_required'],
    'layout_width' => FormFieldWidth::fromStored($existing['layout_width'] ?? null),
    'default_value' => $existing['default_value'],
];

if (array_key_exists('layout_width', $submitted) && FormFieldWidth::isValid($submitted['layout_width'])) {
    $values['layout_width'] = $submitted['layout_width'];
}

$words = [
    FormLocalization::LABEL => $submitted['label'],
    FormLocalization::HELP_TEXT => $submitted['help_text'],
];

if ($type->usesPlaceholder() && array_key_exists('placeholder', $submitted)) {
    $words[FormLocalization::PLACEHOLDER] = $submitted['placeholder'];
}

if ($type->requiredIsFixed()) {
    // A consent box shows no switch, so nothing arrives for it; the rule is
    // enforced here rather than depending on that.
    $values['is_required'] = true;
} elseif (array_key_exists('is_required', $submitted)) {
    $values['is_required'] = $submitted['is_required'];
}

$defaultDropped = false;

/** @var list<array{id: int, value: string, label: string}>|null $plan the options after this save, in order */
$plan = null;

$existingOptions = [];
foreach ($existing['choices'] as $choice) {
    $existingOptions[(int) $choice['id']] = $choice;
}

if ($type->usesOptions() && array_key_exists('option_rows', $submitted)) {
    $plan = [];
    $seenDefaultLabels = [];
    $takenValues = array_map(static fn (array $choice): string => (string) $choice['value'], $existingOptions);
    $markedValue = null;

    foreach ($submitted['option_rows'] as $row) {
        $isExisting = isset($existingOptions[$row['id']]);
        $label = $row['label'];

        if (mb_strlen($label) > FormFieldOptions::MAX_LENGTH) {
            $errors[] = AdminTranslator::trans('validation.a_field_is_too_long');
            break;
        }

        if (!$isExisting && $label === '') {
            continue; // an empty new row is no option
        }
        if ($isExisting && $label === '' && $isDefaultLanguage) {
            continue; // emptied where every language falls back to: no option any more
        }

        // Duplicates are judged on the default language's label, the one
        // every language falls back to.
        $defaultLabel = $isExisting && !$isDefaultLanguage
            ? trim((string) ($existingOptions[$row['id']]['labels'][$defaultLanguage] ?? ''))
            : $label;
        if ($defaultLabel !== '' && isset($seenDefaultLabels[$defaultLabel])) {
            continue;
        }
        if ($defaultLabel !== '') {
            $seenDefaultLabels[$defaultLabel] = true;
        }

        if ($isExisting) {
            $value = (string) $existingOptions[$row['id']]['value'];
        } else {
            // A new option's value is its label, once and for good, made
            // unique within the field.
            $value = mb_substr($label, 0, FormFieldOptions::MAX_LENGTH);
            for ($suffix = 2; in_array($value, $takenValues, true); $suffix++) {
                $tail = ' (' . $suffix . ')';
                $value = mb_substr($label, 0, FormFieldOptions::MAX_LENGTH - mb_strlen($tail)) . $tail;
            }
            $takenValues[] = $value;
        }

        $plan[] = ['id' => $isExisting ? $row['id'] : 0, 'value' => $value, 'label' => $label];

        if ($submitted['default_option'] !== '' && $row['index'] === $submitted['default_option']) {
            $markedValue = $value;
        }

        if (count($plan) >= FormFieldOptions::MAX_OPTIONS) {
            break;
        }
    }

    $values['default_value'] = null;

    // THE one rule about defaults (FormField::isUsableDefault()), applied to
    // the options this save is about to store: a marked row that holds no
    // option any more simply leaves the field without a default.
    $planned = FormFieldOptions::fromRows(array_map(
        static fn (array $item): array => ['value' => $item['value']],
        $plan
    ));

    if ($markedValue !== null && FormField::isUsableDefault($type, $planned, $markedValue)) {
        if (mb_strlen($markedValue) > 200) {
            $errors[] = AdminTranslator::trans('validation.standaardwaarde_opties_veld');
        } else {
            $values['default_value'] = $markedValue;
        }
    } elseif ($submitted['default_option'] !== '') {
        $defaultDropped = true;
    }
}

$optionCount = $plan === null ? count($existingOptions) : count($plan);
if ($type->usesOptions() && $optionCount === 0) {
    $errors[] = AdminTranslator::trans('validation.choice_field_needs_option');
}

if ($errors !== []) {
    $sendBack($submitted, $errors);
}

// Exactly what the editor confirmed losing, and nothing else.
$clearPlaceholder = in_array(FormFieldTypeChange::PLACEHOLDER, $losses, true);
$clearOptions = in_array(FormFieldTypeChange::OPTIONS, $losses, true);

if ($clearPlaceholder) {
    unset($words[FormLocalization::PLACEHOLDER]);
}

if (in_array(FormFieldTypeChange::DEFAULT_VALUE, $losses, true)) {
    $values['default_value'] = null;
}

try {
    $db->beginTransaction();

    $repository->updateField($fieldId, $values);
    FormLocalization::fields()->save($fieldId, $languageCode, $words);

    if ($clearPlaceholder) {
        foreach (array_keys($existing['translations']) as $code) {
            FormLocalization::fields()->save($fieldId, (string) $code, [FormLocalization::PLACEHOLDER => '']);
        }
    }

    $options = new FormFieldOptionRepository($db);

    if ($clearOptions) {
        $options->deleteForField($fieldId);
    } elseif ($plan !== null) {
        $kept = array_filter(array_map(static fn (array $item): int => $item['id'], $plan));
        foreach (array_keys($existingOptions) as $optionId) {
            if (!in_array($optionId, $kept, true)) {
                $options->delete($fieldId, $optionId);
            }
        }

        foreach ($plan as $position => $item) {
            if ($item['id'] > 0) {
                $options->setPosition($fieldId, $item['id'], $position);
                FormLocalization::options()->save($item['id'], $languageCode, [FormLocalization::LABEL => $item['label']]);
            } else {
                $optionId = $options->create($fieldId, $item['value'], $position);
                FormLocalization::options()->save($optionId, $defaultLanguage, [FormLocalization::LABEL => $item['label']]);
            }
        }
    }

    // A field that is no longer an e-mail field cannot go on being the
    // form's Reply-To (FormFieldTypeChange::REPLY_TO, confirmed above).
    if (!$type->holdsEmailAddress()) {
        $repository->clearReplyToField($formId, (string) $existing['field_key']);
    }

    $db->commit();
    FormCatalog::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    FormCatalog::clearCache();
    error_log('[api/admin/update-form-field.php] ' . $e->getMessage());
    $sendBack($submitted, [AdminTranslator::trans('validation.field_not_saved')]);
}

// Saved: back to the form the field belongs to, at this field's row. The
// address is built from the stored form id and the field id alone, never
// from anything the request says about where to go.
$_SESSION['admin_form_field_saved'] = [
    'field_id' => $fieldId,
    'default_dropped' => $defaultDropped,
];

header('Location: /admin/form.php?id=' . $formId . '&saved=1#form-field-' . $fieldId);
exit;
