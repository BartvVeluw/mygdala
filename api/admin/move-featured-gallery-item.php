<?php

/**
 * POST /api/admin/move-featured-gallery-item.php
 *
 * Reorders one item within the homepage-featured subset only (direction=
 * up|down) — leaves the item's own portfolio-grid `sort_order` untouched.
 * Same approach as api/admin/move-portfolio-item.php, scoped to
 * featured_sort_order via PortfolioGalleryRepository::moveFeaturedItem().
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

$repository->moveFeaturedItem($galleryId, $itemId, $direction);
PortfolioGalleryContent::clearCache();

header('Location: /admin/portfolio.php?saved=1');
exit;
