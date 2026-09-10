<?php

/**
 * POST /api/admin/create-nav-item.php
 *
 * Creates a nav_items row from admin/navigation-item.php's "+ Menu-item
 * toevoegen" / "+ Submenu-item" forms. Same guard order and PRG/
 * session-flash pattern as create-information-page.php.
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

$repository = new NavigationRepository();
$pageRepository = new PageRepository();

$labelNl = trim((string) ($_POST['label_nl'] ?? ''));
$labelEn = trim((string) ($_POST['label_en'] ?? ''));
$linkType = (string) ($_POST['link_type'] ?? '');
$targetPageIdRaw = trim((string) ($_POST['target_page_id'] ?? ''));
$targetPageId = $targetPageIdRaw === '' ? null : (int) $targetPageIdRaw;
$targetRoute = trim((string) ($_POST['target_route'] ?? '')) ?: null;
$externalUrl = trim((string) ($_POST['external_url'] ?? '')) ?: null;
$openInNewTab = isset($_POST['open_in_new_tab']);
$isVisible = isset($_POST['is_visible']);

$parentIdRaw = trim((string) ($_POST['parent_id'] ?? ''));
$parentId = $parentIdRaw === '' ? null : (int) $parentIdRaw;

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

if ($parentId !== null) {
    // A submenu item's own link may never be 'none' (a dropdown heading
    // only makes sense as a top-level item) and depth is capped at 2 —
    // the parent itself must be a top-level item, never already a child.
    if ($linkType === 'none') {
        $errors[] = 'Een submenu-item moet een eigen link hebben.';
    }
    if (!$repository->canBeParent($parentId)) {
        $errors[] = 'Ongeldig hoofditem: navigatie ondersteunt maximaal 2 niveaus.';
    }
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
    header('Location: /admin/navigation-item.php' . ($parentId !== null ? '?parent_id=' . $parentId : ''));
    exit;
}

try {
    $repository->create([
        'label_nl' => $labelNl,
        'label_en' => $labelEn,
        'link_type' => $linkType,
        'target_page_id' => $linkType === 'page' ? $targetPageId : null,
        'target_route' => $linkType === 'route' ? $targetRoute : null,
        'external_url' => $linkType === 'external' ? $externalUrl : null,
        'open_in_new_tab' => $openInNewTab,
        'parent_id' => $parentId,
        'is_visible' => $isVisible,
    ]);
} catch (\Throwable $e) {
    error_log('[api/admin/create-nav-item.php] ' . $e->getMessage());
    $_SESSION['admin_nav_item_errors'] = ['Menu-item kon niet worden aangemaakt. Probeer het opnieuw.'];
    $_SESSION['admin_nav_item_old'] = $old;
    header('Location: /admin/navigation-item.php' . ($parentId !== null ? '?parent_id=' . $parentId : ''));
    exit;
}

header('Location: /admin/navigation.php');
exit;
