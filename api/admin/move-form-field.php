<?php

/**
 * POST /api/admin/move-form-field.php
 *
 * Moves one field up or down in its form: one step at a time, two buttons,
 * no JavaScript, no drag-and-drop library, and it works on a phone.
 *
 * The repository renumbers the whole list before swapping, so a list that
 * grew gaps through deletions still moves exactly one place.
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

$direction = (string) ($_POST['direction'] ?? '');
if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new FormRepository();
$field = $repository->findField($fieldId);

if ($field === null) {
    http_response_code(404);
    exit('Field not found.');
}

$formId = (int) $field['form_id'];

try {
    $repository->moveField($formId, $fieldId, $direction);
    FormCatalog::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/move-form-field.php] ' . $e->getMessage());
    $_SESSION['admin_form_errors'] = ['De volgorde kon niet worden aangepast. Probeer het opnieuw.'];
}

header('Location: /admin/form.php?id=' . $formId);
exit;
