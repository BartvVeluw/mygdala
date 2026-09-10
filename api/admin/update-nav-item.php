<?php

/**
 * POST /api/admin/update-nav-item.php
 *
 * Updates an existing nav_items row. parent_id is deliberately never
 * editable here — moving an item between top-level and submenu (or between
 * parents) is a structural change this feature doesn't expose in the admin
 * UI (see MAIN.MD, "Global Navigation + Footer" — reparenting is out of
 * scope; delete + recreate as a submenu-item under the new parent is the
 * supported path, same as this project's other admin CRUD screens have no
 * "move" action).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\LinkResolver;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;

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
$pageRepository = new PageRepository();
$existing = $repository->findById($idParam);
if ($existing === null) {
    http_response_code(404);
    exit('Menu-item niet gevonden.');
}

$labelNl = trim((string) ($_POST['label_nl'] ?? ''));
$labelEn = trim((string) ($_POST['label_en'] ?? ''));
$linkType = (string) ($_POST['link_type'] ?? '');
$targetPageIdRaw = trim((string) ($_POST['target_page_id'] ?? ''));
$targetPageId = $targetPageIdRaw === '' ? null : (int) $targetPageIdRaw;
$targetRoute = trim((string) ($_POST['target_route'] ?? '')) ?: null;
$externalUrl = trim((string) ($_POST['external_url'] ?? '')) ?: null;
$openInNewTab = isset($_POST['open_in_new_tab']);
$isVisible = isset($_POST['is_visible']);

$isChild = $existing['parent_id'] !== null;

$errors = [];

if ($labelNl === '') {
    $errors[] = 'Label (NL) is verplicht.';
} elseif (mb_strlen($labelNl) > 100) {
    $errors[] = 'Label (NL) mag maximaal 100 tekens zijn.';
}
if ($labelEn === '') {
    $errors[] = 'Label (EN) is verplicht.';
} elseif (mb_strlen($labelEn) > 100) {
    $errors[] = 'Label (EN) mag maximaal 100 tekens zijn.';
}
if ($isChild && $linkType === 'none') {
    $errors[] = 'Een submenu-item moet een eigen link hebben.';
}

$linkError = LinkResolver::validate($linkType, $targetPageId, $targetRoute, $externalUrl, null, LinkResolver::LINK_TYPES_NAV, $pageRepository);
if ($linkError !== null) {
    $errors[] = $linkError;
}

$old = [
    'label_nl' => $labelNl,
    'label_en' => $labelEn,
    'link_type' => $linkType,
    'target_page_id' => $targetPageIdRaw,
    'target_route' => $targetRoute,
    'external_url' => $externalUrl,
    'open_in_new_tab' => $openInNewTab,
    'is_visible' => $isVisible,
];

if ($errors !== []) {
    $_SESSION['admin_nav_item_errors'] = $errors;
    $_SESSION['admin_nav_item_old'] = $old;
    header('Location: /admin/navigation-item.php?id=' . $idParam);
    exit;
}

try {
    $repository->update($idParam, [
        'label_nl' => $labelNl,
        'label_en' => $labelEn,
        'link_type' => $linkType,
        'target_page_id' => $linkType === 'page' ? $targetPageId : null,
        'target_route' => $linkType === 'route' ? $targetRoute : null,
        'external_url' => $linkType === 'external' ? $externalUrl : null,
        'open_in_new_tab' => $openInNewTab,
        'is_visible' => $isVisible,
    ]);
} catch (\Throwable $e) {
    error_log('[api/admin/update-nav-item.php] ' . $e->getMessage());
    $_SESSION['admin_nav_item_errors'] = ['Menu-item kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_nav_item_old'] = $old;
    header('Location: /admin/navigation-item.php?id=' . $idParam);
    exit;
}

header('Location: /admin/navigation.php');
exit;
