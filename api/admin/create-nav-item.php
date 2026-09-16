<?php

/**
 * POST /api/admin/create-nav-item.php
 *
 * Creates a nav_items row from admin/navigation-item.php: a menu link, a
 * submenu item (with parent_id) or a header button (presentation=button).
 * The three are one model (App\Service\NavigationPresentation); the rules
 * that tell them apart live in api/admin/_nav_item_input.php, shared with
 * update-nav-item.php. The new row goes to the end of its own group.
 *
 * Same guard order and PRG/session-flash pattern as
 * api/admin/create-portfolio-item.php; a successful save lands on the new
 * item's own editor, like api/admin/update-form.php, so the save bar there
 * can say it was saved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_nav_item_input.php';

use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
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

$repository = new NavigationRepository();

[$errors, $data, $old] = validateNavItemInput($_POST, null, $repository, new PageRepository());

// Back to the same empty form the editor came from: a submenu item under its
// parent, a button as a button.
$formUrl = '/admin/navigation-item.php';
if ($data['parent_id'] !== null) {
    $formUrl .= '?parent_id=' . (int) $data['parent_id'];
} elseif ($data['presentation'] === NavigationPresentation::BUTTON) {
    $formUrl .= '?presentation=button';
}

if ($errors !== []) {
    $_SESSION['admin_nav_item_errors'] = $errors;
    $_SESSION['admin_nav_item_old'] = $old;
    header('Location: ' . $formUrl);
    exit;
}

try {
    $id = $repository->create($data);
} catch (\Throwable $e) {
    error_log('[api/admin/create-nav-item.php] ' . $e->getMessage());
    $_SESSION['admin_nav_item_errors'] = [AdminTranslator::trans('validation.navigation_item_not_created')];
    $_SESSION['admin_nav_item_old'] = $old;
    header('Location: ' . $formUrl);
    exit;
}

header('Location: /admin/navigation-item.php?id=' . $id . '&saved=1');
exit;
