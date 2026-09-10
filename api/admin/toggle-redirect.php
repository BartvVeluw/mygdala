<?php

/**
 * POST /api/admin/toggle-redirect.php
 *
 * Switches one redirect on or off from the list in admin/redirects.php. Same
 * guard order and PRG pattern as toggle-nav-item.php.
 *
 * Off means the public lookup cannot see the row at all
 * (App\Repository\RedirectRepository::findActiveBySourcePath()), so the URL
 * goes back to answering 404 — while the row, its destination and its status
 * code stay exactly where they were. That is the point: switching a redirect
 * off is how an editor tests whether it is still needed without losing what it
 * said.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

// A closed two-value choice, like every other toggle in this admin: anything
// that is not the literal "1" means off.
$isActive = (string) ($_POST['is_active'] ?? '0') === '1';

$repository = new RedirectRepository();

if ($repository->findById($id) === null) {
    http_response_code(404);
    exit('Redirect not found.');
}

try {
    $repository->setActive($id, $isActive);
} catch (\Throwable $e) {
    error_log('[api/admin/toggle-redirect.php] ' . $e->getMessage());
    $_SESSION['admin_redirects_error'] = 'Redirect kon niet worden aangepast. Probeer het opnieuw.';
}

header('Location: /admin/redirects.php');
exit;
