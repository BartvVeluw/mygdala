<?php

/**
 * POST /api/admin/create-form-field.php
 *
 * Adds a field to a form. Only two things are asked for — the kind of field,
 * picked from a described card in the "Veld toevoegen" dialog on
 * admin/form.php, and a Dutch label — because everything else has a sensible
 * empty default and is better filled in on the field's own screen than in a
 * row of eight boxes.
 *
 * A REFUSED ADD GOES BACK INTO THE DIALOG: the form editor is reopened with
 * `add_field=1`, which renders the dialog open, with the errors in it and
 * the chosen type and typed label still there. Nothing is created.
 *
 * THE POST NAME IS GENERATED, never typed. It comes from the label through
 * App\Service\Forms\FormFieldKey, which also makes it unique within this
 * form, so an editor cannot invent a name that collides with the form's own
 * control fields or with another field. It is immutable afterwards: answers
 * are filed under it.
 *
 * The type must be one of the registered ones
 * (App\Service\Forms\FormFieldTypes) — a closed list, so a crafted POST can
 * hit or miss a key and nothing else.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Database;
use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldKey;
use App\Service\Forms\FormLocalization;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormFileTypes;

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

$formId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
if ($formId === false || $formId === null || $formId < 1) {
    http_response_code(400);
    exit('Invalid form id.');
}

$db = Database::connection();
$repository = new FormRepository($db);

if ($repository->find($formId) === null) {
    http_response_code(404);
    exit('Form not found.');
}

$label = is_string($_POST['label'] ?? null) ? mb_substr(trim($_POST['label']), 0, 200) : '';
$typeKey = is_string($_POST['field_type'] ?? null) ? $_POST['field_type'] : '';

$errors = [];
if ($label === '') {
    $errors[] = AdminTranslator::trans('validation.geef_veld_label');
}

$type = FormFieldTypes::get($typeKey);
if ($type === null) {
    $errors[] = AdminTranslator::trans('validation.kies_geldig_veldtype');
}

$dialogUrl = '/admin/form.php?id=' . $formId . '&add_field=1#form-field-add';

if ($errors !== []) {
    $_SESSION['admin_form_field_add_errors'] = $errors;
    // Only a registered key goes back to be pre-selected; anything else was
    // never a choice the dialog offered.
    $_SESSION['admin_form_field_add_old'] = [
        'label' => $label,
        'field_type' => $type === null ? '' : $type->key(),
    ];
    header('Location: ' . $dialogUrl);
    exit;
}

try {
    $taken = array_map(
        static fn (array $row): string => (string) $row['field_key'],
        $repository->fieldsFor($formId)
    );

    $db->beginTransaction();

    $fieldId = $repository->createField($formId, [
        'field_key' => FormFieldKey::fromLabel($label, $taken),
        'field_type' => $type->key(),
        // A consent box is required whatever anybody ticks; every other type
        // starts optional, which is the safer default for a public form.
        'is_required' => $type->requiredIsFixed(),
        // A brand-new field has no options yet, so it can have no
        // default either; the field's own screen offers one once the
        // choices exist.
        'default_value' => null,
        // An upload field starts with the plain, safe kinds and a modest
        // size (App\Service\Forms\FormFileTypes); its own screen changes them.
        'file_types' => $type->acceptsFile() ? FormFileTypes::DEFAULT_TYPES : null,
        'file_max_bytes' => $type->acceptsFile() ? min(FormFileTypes::DEFAULT_MAX_BYTES, FormFileTypes::systemMaxBytes()) : null,
    ]);

    // The label is written in the website's DEFAULT language, whatever
    // language the editor is working in: that is where every other language
    // falls back to, and the field key was made from it.
    FormLocalization::fields()->save($fieldId, FormLocalization::defaultLanguage(), [FormLocalization::LABEL => $label]);

    $db->commit();
    FormCatalog::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/create-form-field.php] ' . $e->getMessage());
    $_SESSION['admin_form_field_add_errors'] = [AdminTranslator::trans('validation.field_not_added')];
    $_SESSION['admin_form_field_add_old'] = ['label' => $label, 'field_type' => $type->key()];
    header('Location: ' . $dialogUrl);
    exit;
}

// Straight into the field's own screen: a choice field is unusable until its
// options are filled in, and every type has text worth translating.
header('Location: /admin/form-field.php?id=' . $fieldId);
exit;
