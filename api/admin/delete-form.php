<?php

/**
 * POST /api/admin/delete-form.php
 *
 * Permanently deletes a form and its fields — but only one that is safe to
 * delete.
 *
 * THE CHECK IS HERE, not only in the screen that offers the button. The
 * overview and the editor both hide the button for a form that is still in
 * use (App\Service\Forms\FormUsage), and both of those are presentation; a
 * POST that arrives anyway is refused with the same reasons. The same rule
 * the Media Library follows for an image that is still on a page
 * (MEDIA.md), for the same reason: a deletion an editor cannot undo must
 * never depend on a hidden button.
 *
 * Two things block it, and they are different problems:
 *   - the form is still placed on a page, so deleting it would leave that
 *     block rendering nothing with no explanation;
 *   - it still has stored submissions, which are people's personal details
 *     and must be deleted on purpose rather than as a side effect.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormUsage;

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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid form id.');
}

$repository = new FormRepository();
$form = $repository->find($id);

if ($form === null) {
    header('Location: /admin/forms.php');
    exit;
}

$blockers = FormUsage::deletionBlockers($id);

if ($blockers !== []) {
    $_SESSION['admin_forms_errors'] = array_merge(
        ['"' . (string) $form['name'] . '" is niet verwijderd:'],
        $blockers
    );
    header('Location: /admin/forms.php');
    exit;
}

try {
    // form_fields cascades at the database level; there are no files and no
    // submissions left to clean up, because both would have blocked this.
    $repository->delete($id);
    FormCatalog::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-form.php] ' . $e->getMessage());
    $_SESSION['admin_forms_errors'] = ['Het formulier kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/forms.php');
    exit;
}

$_SESSION['admin_forms_flash'] = AdminTranslator::trans('validation.formulier_verwijderd');
header('Location: /admin/forms.php');
exit;
