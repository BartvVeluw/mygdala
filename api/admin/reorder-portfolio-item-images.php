<?php

/**
 * POST /api/admin/reorder-portfolio-item-images.php
 *
 * Persists a new display order for a Portfolio item's additional
 * Projectafbeeldingen (drag-and-drop in admin/portfolio-item.php). Same
 * fetch()/JSON shape as reorder-variant-images.php.
 *
 * Body: portfolio_item_id, image_ids (comma-separated ids in the new order).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('portfolio.manage');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

$portfolioItemId = filter_input(INPUT_POST, 'portfolio_item_id', FILTER_VALIDATE_INT);

if ($portfolioItemId === false || $portfolioItemId === null || $portfolioItemId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid portfolio item id.']);
    exit;
}

if ((new PortfolioGalleryRepository())->findItemById($portfolioItemId) === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Portfolio item not found.']);
    exit;
}

$imageIdsRaw = (string) ($_POST['image_ids'] ?? '');
$imageIds = array_values(array_filter(array_map(
    static fn (string $id): int => (int) trim($id),
    explode(',', $imageIdsRaw)
), static fn (int $id): bool => $id > 0));

try {
    (new PortfolioItemImageRepository())->reorder($portfolioItemId, $imageIds);
} catch (\Throwable $e) {
    error_log('[api/admin/reorder-portfolio-item-images.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Volgorde kon niet worden opgeslagen.']);
    exit;
}

echo json_encode(['ok' => true]);
