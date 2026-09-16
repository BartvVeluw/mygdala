<?php

/**
 * POST /api/admin/update-form-field.php
 *
 * Saves one field (admin/form-field.php?id=<id>).
 *
 * IT DOES NOT TOUCH `field_key`, and there is no way to make it: the key is
 * what stored answers are filed under, and changing it would orphan every
 * one of them. Renaming a label is free and is what an editor means by
 * renaming a field — a submission keeps the label it was sent with.
 *
 * WHAT IS WRITTEN IS WHAT THE SCREEN SHOWED. The editor shows only the
 * settings the field's type uses, so a setting that was not on the submitted
 * form is left exactly as it is stored instead of being saved as empty — the
 * rule this endpoint's neighbour api/admin/update-page.php applies to the
 * social image. A field whose type has no placeholder keeps one it once had,
 * unused and untouched, and the editor sent back unchanged writes back what
 * was there. `is_required` has a hidden 0 in front of its switch
 * (admin/form-field.php) so that "unticked" arrives at all; a consent box is
 * required whatever arrives.
 *
 * OPTIONS AND THEIR DEFAULT ARRIVE TOGETHER. One row per option
 * (`option_nl[i]`, `option_en[i]`), and the default is the ROW that was
 * marked (`default_option` = i), not a label typed in or picked from the
 * stored list. An option added, renamed or marked in this save can be the
 * default of this save. A marked row that was emptied is no option any
 * more, so the field is left without a default — never with one that
 * points at nothing — and the editor is told. The stored text is built by
 * App\Service\Forms\FormFieldOptions, so its format and its rules are the
 * ones the read model parses.
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
 * cleared; nothing else is. A change that loses nothing and needs nothing
 * new is saved at once.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormField;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypeChange;
use App\Service\Forms\FormFieldTypes;
use App\Service\Language\AdminTranslator;

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

$repository = new FormRepository();
$existing = $repository->findField($fieldId);

if ($existing === null) {
    http_response_code(404);
    exit('Field not found.');
}

$formId = (int) $existing['form_id'];
$form = $repository->find($formId);
$editorUrl = '/admin/form-field.php?id=' . $fieldId;

$text = static fn (string $name, int $maxLength): string => is_string($_POST[$name] ?? null)
    ? mb_substr(trim($_POST[$name]), 0, $maxLength)
    : '';

$typeKey = is_string($_POST['field_type'] ?? null) ? $_POST['field_type'] : '';
$type = FormFieldTypes::get($typeKey);

// What the editor sent, and only the groups of settings it sent: this is
// also what goes back to the screen when nothing is written.
$submitted = [
    'field_type' => $type === null ? (string) $existing['field_type'] : $type->key(),
    'label_nl' => $text('label_nl', 200),
    'label_en' => $text('label_en', 200),
    'help_text_nl' => $text('help_text_nl', 500),
    'help_text_en' => $text('help_text_en', 500),
];

if (array_key_exists('placeholder_nl', $_POST) || array_key_exists('placeholder_en', $_POST)) {
    $submitted['placeholder_nl'] = $text('placeholder_nl', 200);
    $submitted['placeholder_en'] = $text('placeholder_en', 200);
}

if (array_key_exists('is_required', $_POST)) {
    $submitted['is_required'] = ($_POST['is_required'] ?? '') === '1';
}

if (array_key_exists('option_nl', $_POST)) {
    $dutch = is_array($_POST['option_nl']) ? $_POST['option_nl'] : [];
    $english = is_array($_POST['option_en'] ?? null) ? $_POST['option_en'] : [];
    $rows = [];

    foreach ($dutch as $index => $value) {
        $rows[] = [
            'index' => (string) $index,
            'nl' => FormFieldOptions::rowText($value),
            'en' => FormFieldOptions::rowText($english[$index] ?? ''),
        ];
    }

    $submitted['option_rows'] = $rows;
    $submitted['default_option'] = is_string($_POST['default_option'] ?? null) ? $_POST['default_option'] : '';
}

$errors = [];

if ($submitted['label_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.label_verplicht_2');
}

if ($type === null) {
    $errors[] = AdminTranslator::trans('validation.kies_geldig_veldtype');
}

$sendBack = static function (array $submitted, array $errors) use ($editorUrl): never {
    $_SESSION['admin_form_field_errors'] = $errors;
    $_SESSION['admin_form_field_old'] = $submitted;
    header('Location: ' . $editorUrl);
    exit;
};

if ($type === null) {
    $sendBack($submitted, $errors);
}

$losses = FormFieldTypeChange::losses($existing, $type, $form['reply_to_field_key'] ?? null);

if ($type->key() !== (string) $existing['field_type']) {
    $confirmed = ($_POST['confirmed_type'] ?? null) === $type->key();

    // The settings the new type has that the submitted form did not show:
    // the editor has not seen them yet, so it must before anything is saved.
    $unseen = ($type->usesOptions() && !array_key_exists('option_rows', $submitted))
        || ($type->usesPlaceholder() && !array_key_exists('placeholder_nl', $submitted))
        || (!$type->requiredIsFixed() && !array_key_exists('is_required', $submitted));

    if (!$confirmed && ($losses !== [] || $unseen)) {
        $sendBack($submitted, $errors);
    }
}

// Start from the stored row, so a setting that was not sent stays as it is.
$values = [
    'field_type' => $type->key(),
    'label_nl' => $submitted['label_nl'],
    'label_en' => $submitted['label_en'],
    'help_text_nl' => $submitted['help_text_nl'],
    'help_text_en' => $submitted['help_text_en'],
    'placeholder_nl' => $existing['placeholder_nl'],
    'placeholder_en' => $existing['placeholder_en'],
    'is_required' => (bool) $existing['is_required'],
    'options' => $existing['options'],
    'default_value' => $existing['default_value'],
];

if ($type->usesPlaceholder() && array_key_exists('placeholder_nl', $submitted)) {
    $values['placeholder_nl'] = $submitted['placeholder_nl'];
    $values['placeholder_en'] = $submitted['placeholder_en'];
}

if ($type->requiredIsFixed()) {
    // A consent box shows no switch, so nothing arrives for it; the rule is
    // enforced here rather than depending on that.
    $values['is_required'] = true;
} elseif (array_key_exists('is_required', $submitted)) {
    $values['is_required'] = $submitted['is_required'];
}

$defaultDropped = false;

if ($type->usesOptions() && array_key_exists('option_rows', $submitted)) {
    foreach ($submitted['option_rows'] as $row) {
        if (FormFieldOptions::holdsSeparator($row['nl'])) {
            $errors[] = AdminTranslator::trans('validation.form_option_separator');
            break;
        }
    }

    $values['options'] = FormFieldOptions::rowsToStored($submitted['option_rows']);
    $values['default_value'] = null;

    $marked = null;
    foreach ($submitted['option_rows'] as $row) {
        if ($submitted['default_option'] !== '' && $row['index'] === $submitted['default_option']) {
            $marked = $row['nl'];
            break;
        }
    }

    // The ONE rule about defaults (FormField::isUsableDefault()), applied to
    // the options being saved. A marked row that holds no option any more
    // simply leaves the field without a default.
    if ($marked !== null && FormField::isUsableDefault($type, FormFieldOptions::fromStored($values['options']), $marked)) {
        if (mb_strlen($marked) > 200) {
            $errors[] = AdminTranslator::trans('validation.standaardwaarde_opties_veld');
        } else {
            $values['default_value'] = $marked;
        }
    } elseif ($submitted['default_option'] !== '') {
        $defaultDropped = true;
    }
}

if ($type->usesOptions() && FormFieldOptions::fromStored(is_string($values['options']) ? $values['options'] : null)->isEmpty()) {
    $errors[] = AdminTranslator::trans('validation.choice_field_needs_option');
}

if ($errors !== []) {
    $sendBack($submitted, $errors);
}

// Exactly what the editor confirmed losing, and nothing else.
if (in_array(FormFieldTypeChange::PLACEHOLDER, $losses, true)) {
    $values['placeholder_nl'] = null;
    $values['placeholder_en'] = null;
}

if (in_array(FormFieldTypeChange::OPTIONS, $losses, true)) {
    $values['options'] = null;
}

if (in_array(FormFieldTypeChange::DEFAULT_VALUE, $losses, true)) {
    $values['default_value'] = null;
}

try {
    $repository->updateField($fieldId, $values);

    // A field that is no longer an e-mail field cannot go on being the
    // form's Reply-To (FormFieldTypeChange::REPLY_TO, confirmed above).
    if (!$type->holdsEmailAddress()) {
        $repository->clearReplyToField($formId, (string) $existing['field_key']);
    }

    FormCatalog::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-form-field.php] ' . $e->getMessage());
    $sendBack($submitted, [AdminTranslator::trans('validation.field_not_saved')]);
}

if ($defaultDropped) {
    $_SESSION['admin_form_field_notice'] = 'default_dropped';
}

header('Location: ' . $editorUrl . '&saved=1');
exit;
