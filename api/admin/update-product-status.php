<?php

/**
 * POST /api/admin/update-product-status.php
 *
 * Activates or deactivates a product (products.active). This is the only
 * field this endpoint ever touches — it never deletes anything, so it's the
 * safe way to hide a product from the shop without risking existing orders.
 * Plain HTML form post from admin/products.php, PRG redirect back.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ProductRepository;

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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$active = $_POST['active'] ?? null;

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid product id.');
}

if ($active !== '0' && $active !== '1') {
    http_response_code(400);
    exit('Invalid status value.');
}

try {
    (new ProductRepository())->setActive($id, $active === '1');
} catch (\Throwable $e) {
    error_log('[api/admin/update-product-status.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Status kon niet worden bijgewerkt.');
}

// The success marker every write endpoint appends (admin/assets/save-bar.js
// tells a saved redirect from a refused one by it); a failure never gets here.
header('Location: /admin/products.php?updated=1');
exit;
