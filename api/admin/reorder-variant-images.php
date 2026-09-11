<?php

/**
 * POST /api/admin/reorder-variant-images.php
 *
 * Persists a new display order for a variant's photos (drag-and-drop in
 * admin/product-form.php). Called via fetch(), so unlike the other admin
 * endpoints this responds with JSON instead of a redirect. The first image
 * in the saved order becomes the variant's default/primary photo.
 *
 * Body: variant_id, image_ids (comma-separated ids in the new order).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ProductVariantRepository;
use App\Repository\VariantImageRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('products.manage');

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

$variantId = filter_input(INPUT_POST, 'variant_id', FILTER_VALIDATE_INT);

if ($variantId === false || $variantId === null || $variantId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid variant id.']);
    exit;
}

if ((new ProductVariantRepository())->findById($variantId) === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Variant not found.']);
    exit;
}

$imageIdsRaw = (string) ($_POST['image_ids'] ?? '');
$imageIds = array_values(array_filter(array_map(
    static fn (string $id): int => (int) trim($id),
    explode(',', $imageIdsRaw)
), static fn (int $id): bool => $id > 0));

try {
    (new VariantImageRepository())->reorder($variantId, $imageIds);
} catch (\Throwable $e) {
    error_log('[api/admin/reorder-variant-images.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => AdminTranslator::trans('validation.volgorde_kon_opgeslagen')]);
    exit;
}

echo json_encode(['ok' => true]);
