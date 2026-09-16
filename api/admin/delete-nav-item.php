<?php

/**
 * POST /api/admin/delete-nav-item.php
 *
 * Deletes a nav_items row — but refuses (friendly error, not a raw FK
 * error) when the item still has children: the safest, clearest of the two
 * options considered for "delete an item with children" (see MAIN.MD,
 * "Global Navigation + Footer"), matching this project's existing
 * "check usage first" convention (e.g. delete-portfolio-category.php). An
 * admin must move/delete the children first — nothing is silently
 * destroyed. parent_id's own ON DELETE RESTRICT is the defense-in-depth
 * backstop if this check is ever bypassed.
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

if ($repository->countChildren($idParam) > 0) {
    $_SESSION['admin_nav_error'] = AdminTranslator::trans('validation.menu_item_heeft_submenu_items');
    header('Location: /admin/navigation.php');
    exit;
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
