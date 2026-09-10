<?php

/**
 * POST /api/admin/update-redirect.php
 *
 * Saves one redirect from admin/redirect.php. Identical guards and identical
 * validation to create-redirect.php (both call redirect_input_from_post(),
 * see _redirect_input.php) — the only difference is that this one tells the
 * validator which row to ignore, so a redirect is never reported as a
 * duplicate or a loop of itself.
 *
 * `origin` is deliberately not writable. A redirect a page rename created may
 * be edited freely — its source, destination, status and on/off switch are all
 * the editor's — but it stays labelled as automatic, because that label is
 * what explains to the next person why the row is there, and it is what stops
 * a later rename from silently overwriting a destination somebody chose by
 * hand.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_redirect_input.php';

use App\Repository\RedirectRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('settings.manage');

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
    exit('Invalid redirect id.');
}

$repository = new RedirectRepository();

if ($repository->findById($id) === null) {
    http_response_code(404);
    exit('Redirect not found.');
}

$input = redirect_input_from_post($id);

if ($input['errors'] !== []) {
    $_SESSION['admin_redirect_errors'] = $input['errors'];
    $_SESSION['admin_redirect_old'] = $input['old'];
    header('Location: /admin/redirect.php?id=' . $id);
    exit;
}

try {
    $repository->update($id, $input['data']);
} catch (\Throwable $e) {
    error_log('[api/admin/update-redirect.php] ' . $e->getMessage());
    $_SESSION['admin_redirect_errors'] = ['Redirect kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_redirect_old'] = $input['old'];
    header('Location: /admin/redirect.php?id=' . $id);
    exit;
}

header('Location: /admin/redirects.php?updated=1');
exit;
