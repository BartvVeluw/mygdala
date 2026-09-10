<?php

/**
 * POST /api/admin/update-variant-image.php
 *
 * Edits a variant photo's metadata only (image_name, alt_text) — never
 * touches or renames the underlying uploaded file.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\VariantImageRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);

if ($imageId === false || $imageId === null || $imageId < 1) {
    http_response_code(400);
    exit('Invalid image id.');
}

$imageRepository = new VariantImageRepository();
$image = $imageRepository->findById($imageId);

if ($image === null) {
    http_response_code(404);
    exit('Image not found.');
}

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

$imageName = trim((string) ($_POST['image_name'] ?? ''));
$altText = trim((string) ($_POST['alt_text'] ?? ''));

if (mb_strlen($imageName) > 255 || mb_strlen($altText) > 255) {
    $_SESSION['admin_variant_errors'] = ['Naam/alt-tekst mag maximaal 255 tekens zijn.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

$imageRepository->updateMeta($imageId, $imageName !== '' ? $imageName : null, $altText !== '' ? $altText : null);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
