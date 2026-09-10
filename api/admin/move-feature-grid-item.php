<?php

/**
 * POST /api/admin/move-feature-grid-item.php
 *
 * Simple card ordering: swaps one card with its previous/next neighbour in
 * display order (direction=up|down). Same approach as
 * api/admin/move-product-image.php.
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

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
$direction = $_POST['direction'] ?? '';

if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new FeatureGridRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$gridId = (int) $item['feature_grid_id'];
$grid = $repository->findById($gridId);
if ($grid === null) {
    http_response_code(404);
    exit('Grid not found.');
}

$sectionKey = $grid['page_slug'] . ':' . $grid['section_key'];

$repository->moveItem($gridId, $itemId, $direction);
FeatureGridContent::clearCache();

header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
