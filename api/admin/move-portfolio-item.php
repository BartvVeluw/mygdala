<?php

/**
 * POST /api/admin/move-portfolio-item.php
 *
 * Keyboard-/no-JS-accessible fallback for reordering: swaps one item with
 * its previous/next neighbour (direction=up|down). Drag-and-drop in
 * admin/portfolio.php (reorder-portfolio-items.php) is the primary way to
 * reorder; this is kept as the accessibility fallback per the approved
 * design. Same approach as the old move-gallery-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioGalleryContent;
use App\Repository\PortfolioGalleryRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('portfolio.manage');

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

$repository = new PortfolioGalleryRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$galleryId = (int) $item['portfolio_gallery_id'];

$repository->moveItem($galleryId, $itemId, $direction);
PortfolioGalleryContent::clearCache();

header('Location: /admin/portfolio.php?saved=1');
exit;
