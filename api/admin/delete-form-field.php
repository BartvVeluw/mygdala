<?php

/**
 * POST /api/admin/delete-form-field.php
 *
 * Removes one field from a form.
 *
 * STORED SUBMISSIONS ARE NOT TOUCHED, and that is the point of how they are
 * stored: `form_submission_values` carries its own copy of every label and
 * answer, so an enquiry from last spring still reads correctly after the
 * field it was typed into is gone (FORMS.md, "Wat een inzending bewaart").
 * Nothing here deletes anybody's data.
 *
 * The form's Reply-To setting is cleared first when it names this field, so
 * it can never outlive the field it points at.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;

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
$field = $repository->findField($fieldId);

if ($field === null) {
    header('Location: /admin/forms.php');
    exit;
}

$formId = (int) $field['form_id'];

try {
    $repository->clearReplyToField($formId, (string) $field['field_key']);
    $repository->deleteField($fieldId);
    $repository->renumberFields($formId);
    FormCatalog::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-form-field.php] ' . $e->getMessage());
    $_SESSION['admin_form_errors'] = ['Het veld kon niet worden verwijderd. Probeer het opnieuw.'];
}

header('Location: /admin/form.php?id=' . $formId);
exit;
