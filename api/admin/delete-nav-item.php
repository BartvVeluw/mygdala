<?php

/**
 * POST /api/admin/delete-nav-item.php
 *
 * Deletes a nav_items row. An item with submenu items may go too: its
 * submenu items are never deleted with it, they take its place in its own
 * list, in their own order, one level up (NavigationRepository::delete(),
 * HEADER-FOOTER.md "Verwijderen"). Nothing but the one item disappears, and
 * no row is ever left pointing at a parent that is gone; parent_id's own ON
 * DELETE RESTRICT stays the backstop if that method is ever bypassed.
 *
 * Asked first in the CMS's own dialog on admin/navigation.php and
 * admin/navigation-item.php (admin_confirm_attributes()); that dialog is a
 * courtesy, never the guard. The overview says afterwards whether a menu item
 * or a header button went (?deleted=link|button).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\NavigationRepository;
use App\Service\NavigationPresentation;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$idParam = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($idParam === false || $idParam === null || $idParam < 1) {
    http_response_code(404);
    exit('Menu-item niet gevonden.');
}

$repository = new NavigationRepository();
$item = $repository->findById($idParam);

if ($item === null) {
    http_response_code(404);
    exit('Menu-item niet gevonden.');
}

try {
    $repository->delete($idParam);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-nav-item.php] ' . $e->getMessage());
    $_SESSION['admin_nav_error'] = AdminTranslator::trans('validation.menu_item_kon_verwijderd');
    header('Location: /admin/navigation.php');
    exit;
}

header('Location: /admin/navigation.php?deleted=' . NavigationPresentation::of($item));
exit;
