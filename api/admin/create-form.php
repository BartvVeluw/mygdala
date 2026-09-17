<?php

/**
 * POST /api/admin/create-form.php
 *
 * Creates an empty form and sends the editor straight into it to add fields.
 * Same guard order and PRG/session-flash pattern as every other admin write
 * endpoint in this directory.
 *
 * The new form is deliberately CONSERVATIVE: switched on so it works the
 * moment it is placed, but storing nothing and naming no recipient — the
 * notification then follows the site's own contact address
 * (App\Service\Forms\FormRecipient) and no personal data is retained until
 * somebody decides it should be (FORMS.md, "Privacy").
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

$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '') {
    $_SESSION['admin_forms_errors'] = ['Geef het formulier een naam.'];
    header('Location: /admin/forms.php');
    exit;
}

$name = mb_substr($name, 0, 150);

try {
    $repository = new FormRepository();

    // A new form stores no words at all: its button and its thank-you
    // message start on the generic texts of
    // App\Service\Forms\FormDefinition, in whatever language a visitor
    // reads, until an editor writes their own on the form's own screen.
    $formId = $repository->create([
        'name' => $name,
        'internal_key' => FormCatalog::internalKeyFor($name, $repository),
        'is_active' => true,
        'notification_email' => null,
        'reply_to_field_key' => null,
        'store_submissions' => false,
    ]);

    FormCatalog::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-form.php] ' . $e->getMessage());
    $_SESSION['admin_forms_errors'] = ['Het formulier kon niet worden aangemaakt. Probeer het opnieuw.'];
    header('Location: /admin/forms.php');
    exit;
}

header('Location: /admin/form.php?id=' . $formId . '&saved=1');
exit;
