<?php

/**
 * POST /api/admin/reorder-portfolio-items.php
 *
 * Persists a new display order for Portfolio items (drag-and-drop in
 * admin/portfolio.php). Called via fetch(), so — like
 * the old variant-photo reorder endpoint — this responds with JSON instead of a
 * redirect. Only ids belonging to the one Portfolio gallery are honored;
 * see PortfolioGalleryRepository::reorderItems() for how ids missing from
 * the submitted list (e.g. hidden by an active search/category filter in
 * the admin overview) are preserved rather than dropped.
 *
 * Body: item_ids (comma-separated ids in the new order).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioGalleryContent;
use App\Repository\PortfolioGalleryRepository;

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

$repository = new PortfolioGalleryRepository();
$gallery = $repository->findCatalogue();

if ($gallery === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Gallery not found.']);
    exit;
}

$itemIdsRaw = (string) ($_POST['item_ids'] ?? '');
$itemIds = array_values(array_filter(array_map(
    static fn (string $id): int => (int) trim($id),
    explode(',', $itemIdsRaw)
), static fn (int $id): bool => $id > 0));

try {
    $repository->reorderItems((int) $gallery['id'], $itemIds);
    PortfolioGalleryContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/reorder-portfolio-items.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => AdminTranslator::trans('validation.volgorde_kon_opgeslagen')]);
    exit;
}

echo json_encode(['ok' => true]);
