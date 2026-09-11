<?php

/**
 * POST /api/admin/delete-redirect.php
 *
 * Removes one redirect, from the list in admin/redirects.php or from the
 * editor in admin/redirect.php. Same guard order and PRG pattern as
 * delete-nav-item.php.
 *
 * A redirect a page rename created may be deleted like any other, and it is
 * NOT recreated by the next ordinary save of that page: only a genuine slug
 * change writes one (see App\Service\Redirects\SlugChangeRedirects and
 * api/admin/update-page.php). An editor who decides an old URL should simply
 * 404 gets to have that.
 *
 * Nothing else in the CMS points at a `redirects` row, so there is no
 * reference check here of the kind App\Service\PageService::delete() performs
 * — the only thing that disappears is the redirect itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
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

try {
    $repository->delete($id);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-redirect.php] ' . $e->getMessage());
    $_SESSION['admin_redirects_error'] = AdminTranslator::trans('validation.redirect_kon_verwijderd_probeer_opnieuw');
    header('Location: /admin/redirects.php');
    exit;
}

header('Location: /admin/redirects.php?deleted=1');
exit;
