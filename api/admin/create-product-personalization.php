<?php

/**
 * POST /api/admin/create-product-personalization.php
 *
 * ENROLS an existing shop product into the Personalisatie module.
 *
 * It creates NOTHING in `products` and copies nothing out of one: the only
 * row written is `product_personalization_settings`, disabled and with
 * personalization optional, so enrolling a product changes precisely nothing
 * about how it behaves until the administrator configures and enables it.
 * `products` stays the single source of truth for the name, description,
 * price, variants, photos and visibility of everything in the shop.
 *
 * A product that already has a configuration comes back with a message rather
 * than an error: "already added" is an ordinary thing to click twice, and the
 * UNIQUE index on product_id guarantees the outcome regardless.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\ProductPersonalizationContent;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('personalization.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    $_SESSION['admin_personalization_list_errors'] = ['Kies een product om toe te voegen.'];
    header('Location: /admin/personalization.php');
    exit;
}

if ((new ProductRepository())->findByIdForAdmin($productId) === null) {
    http_response_code(404);
    exit('Product not found.');
}

try {
    $settingsId = (new ProductPersonalizationRepository())->createForProduct($productId);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-product-personalization.php] ' . $e->getMessage());
    $_SESSION['admin_personalization_list_errors'] = ['Het product kon niet worden toegevoegd. Probeer het opnieuw.'];
    header('Location: /admin/personalization.php');
    exit;
}

if ($settingsId === null) {
    header('Location: /admin/personalization.php?duplicate=1');
    exit;
}

// Straight into the editor: the next thing to do is always to add a preview
// image, and there is nothing to see on the overview until that exists.
header('Location: /admin/personalization-product.php?product_id=' . $productId . '&added=1');
exit;
