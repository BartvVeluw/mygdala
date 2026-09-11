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
 * Changing the TYPE is allowed, and the two consequences are handled here:
 * a type that no longer holds an e-mail address cannot stay the form's
 * Reply-To, and a consent box is required whatever the checkbox said.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormField;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypes;

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
$typeKey = (string) ($_POST['field_type'] ?? '');
$type = FormFieldTypes::get($typeKey);

$fields = [
    'field_type' => $typeKey,
    'label_nl' => mb_substr(trim((string) ($_POST['label_nl'] ?? '')), 0, 200),
    'label_en' => mb_substr(trim((string) ($_POST['label_en'] ?? '')), 0, 200),
    'placeholder_nl' => mb_substr(trim((string) ($_POST['placeholder_nl'] ?? '')), 0, 200),
    'placeholder_en' => mb_substr(trim((string) ($_POST['placeholder_en'] ?? '')), 0, 200),
    'help_text_nl' => mb_substr(trim((string) ($_POST['help_text_nl'] ?? '')), 0, 500),
    'help_text_en' => mb_substr(trim((string) ($_POST['help_text_en'] ?? '')), 0, 500),
    'is_required' => isset($_POST['is_required']),
    // Re-serialised through the parser, so what is stored is always exactly
    // what will be read back.
    'options' => FormFieldOptions::toStored($_POST['options'] ?? null),
    'default_value' => mb_substr(trim((string) ($_POST['default_value'] ?? '')), 0, 200),
];

$errors = [];

if ($fields['label_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.label_verplicht_2');
}

if ($type === null) {
    $errors[] = AdminTranslator::trans('validation.kies_geldig_veldtype');
} else {
    if ($type->requiredIsFixed()) {
        // The admin renders this checkbox as fixed, so it posts nothing;
        // the rule is enforced here rather than depending on that.
        $fields['is_required'] = true;
    }

    if ($type->usesOptions() && FormFieldOptions::fromStored($fields['options'])->isEmpty()) {
        $errors[] = AdminTranslator::trans('validation.choice_field_needs_option');
    }

    if (!$type->usesOptions()) {
        // Options belonging to a type that has none would sit in the row
        // waiting to reappear if the type were switched back.
        $fields['options'] = '';
    }

    // A DEFAULT MUST BE ONE OF THE OPTIONS BEING SAVED, not one of the
    // options that happened to be there when the screen was rendered.
    // Checking it against the submitted list is what makes "remove the
    // option that was the default" a refusal the editor can see, instead
    // of a row quietly pointing at a choice nobody is offered.
    if (!$type->usesDefaultValue() || $fields['default_value'] === '') {
        $fields['default_value'] = '';
    } elseif (!FormField::isUsableDefault(
        $type,
        FormFieldOptions::fromStored($fields['options']),
        $fields['default_value']
    )) {
        $errors[] = AdminTranslator::trans('validation.standaardwaarde_opties_veld');
    }
}

if ($errors !== []) {
    $_SESSION['admin_form_field_errors'] = $errors;
    $_SESSION['admin_form_field_old'] = $fields;
    header('Location: /admin/form-field.php?id=' . $fieldId);
    exit;
}

try {
    $repository->updateField($fieldId, $fields);

    // A field that is no longer an e-mail field cannot go on being the
    // form's Reply-To.
    if (!$type->holdsEmailAddress()) {
        $repository->clearReplyToField($formId, (string) $existing['field_key']);
    }

    FormCatalog::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-form-field.php] ' . $e->getMessage());
    $_SESSION['admin_form_field_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_form_field_old'] = $fields;
    header('Location: /admin/form-field.php?id=' . $fieldId);
    exit;
}

header('Location: /admin/form-field.php?id=' . $fieldId . '&saved=1');
exit;
