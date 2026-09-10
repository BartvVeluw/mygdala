<?php

/**
 * POST /api/admin/create-feature-grid-item.php
 *
 * Adds a new card to a Feature grid. Same guard order / PRG pattern as
 * api/admin/create-product-option.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\FeatureGridContent;
use App\Repository\FeatureGridRepository;

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

$gridId = filter_input(INPUT_POST, 'grid_id', FILTER_VALIDATE_INT);
if ($gridId === false || $gridId === null || $gridId < 1) {
    http_response_code(400);
    exit('Invalid grid id.');
}

$repository = new FeatureGridRepository();
$grid = $repository->findById($gridId);

if ($grid === null) {
    http_response_code(404);
    exit('Grid not found.');
}

$sectionKey = $grid['page_slug'] . ':' . $grid['section_key'];

$iconKey = is_string($_POST['icon_key'] ?? null) ? $_POST['icon_key'] : '';
$fields = [
    'icon_key' => array_key_exists($iconKey, FeatureGridContent::ICON_KEYS) ? $iconKey : array_key_first(FeatureGridContent::ICON_KEYS),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'body_nl' => trim((string) ($_POST['body_nl'] ?? '')),
    'body_en' => trim((string) ($_POST['body_en'] ?? '')),
];

$errors = [];
if ($fields['title_nl'] === '') {
    $errors[] = 'Titel (NL) is verplicht.';
}
if ($fields['body_nl'] === '') {
    $errors[] = 'Tekst (NL) is verplicht.';
}

if ($errors !== []) {
    $_SESSION['admin_feature_grid_item_errors'] = $errors;
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->createItem($gridId, $fields);
    FeatureGridContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-feature-grid-item.php] ' . $e->getMessage());
    $_SESSION['admin_feature_grid_item_errors'] = ['Kaart kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
