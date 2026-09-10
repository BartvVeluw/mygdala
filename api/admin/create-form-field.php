<?php

/**
 * POST /api/admin/create-form-field.php
 *
 * Adds a field to a form. Only two things are asked for — a Dutch label and
 * a type — because everything else has a sensible empty default and is
 * better filled in on the field's own screen than in a row of eight boxes.
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

use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldKey;
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

$formId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
if ($formId === false || $formId === null || $formId < 1) {
    http_response_code(400);
    exit('Invalid form id.');
}

$repository = new FormRepository();

if ($repository->find($formId) === null) {
    http_response_code(404);
    exit('Form not found.');
}

$label = mb_substr(trim((string) ($_POST['label_nl'] ?? '')), 0, 200);
$typeKey = (string) ($_POST['field_type'] ?? '');

$errors = [];
if ($label === '') {
    $errors[] = 'Geef het veld een label.';
}

$type = FormFieldTypes::get($typeKey);
if ($type === null) {
    $errors[] = 'Kies een geldig veldtype.';
}

if ($errors !== []) {
    $_SESSION['admin_form_errors'] = $errors;
    header('Location: /admin/form.php?id=' . $formId);
    exit;
}

try {
    $taken = array_map(
        static fn (array $row): string => (string) $row['field_key'],
        $repository->fieldsFor($formId)
    );

    $fieldId = $repository->createField($formId, [
        'field_key' => FormFieldKey::fromLabel($label, $taken),
        'field_type' => $type->key(),
        'label_nl' => $label,
        'label_en' => null,
        'placeholder_nl' => null,
        'placeholder_en' => null,
        'help_text_nl' => null,
        'help_text_en' => null,
        // A consent box is required whatever anybody ticks; every other type
        // starts optional, which is the safer default for a public form.
        'is_required' => $type->requiredIsFixed(),
        'options' => null,
        // A brand-new field has no options yet, so it can have no
        // default either; the field's own screen offers one once the
        // choices exist.
        'default_value' => null,
    ]);

    FormCatalog::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-form-field.php] ' . $e->getMessage());
    $_SESSION['admin_form_errors'] = ['Het veld kon niet worden toegevoegd. Probeer het opnieuw.'];
    header('Location: /admin/form.php?id=' . $formId);
    exit;
}

// Straight into the field's own screen: a choice field is unusable until its
// options are filled in, and every type has bilingual text worth adding.
header('Location: /admin/form-field.php?id=' . $fieldId);
exit;
