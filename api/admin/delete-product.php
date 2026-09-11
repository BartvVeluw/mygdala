<?php

/**
 * POST /api/admin/delete-product.php
 *
 * Permanently deletes a catalog product from the CMS/shop. Same guard order as
 * every other destructive admin endpoint: session login, POST-only, CSRF,
 * server-side id validation — never a GET-triggerable action; the admin UI
 * additionally asks for a JS confirm() first.
 *
 * Deletion is no longer refused for a product that has been ordered. Since
 * db/migrations/20260908120000_relax_order_item_product_foreign_keys.php the
 * order_items.product_id/variant_id foreign keys are ON DELETE SET NULL, and
 * order_items keeps its own authoritative snapshot of product title, variant
 * label, quantity and unit price — so the order line, the order total, the
 * invoice and the confirmation email all stay exactly as they were, with the
 * product row simply detached. order_items rows are never deleted.
 *
 * Deactivating a product remains a separate, non-destructive action
 * (api/admin/update-product-status.php): hidden from the public shop, still
 * editable in the CMS, reversible. This endpoint is the permanent one.
 *
 * The actual rules and cleanup live in App\Service\ProductDeletionService, so
 * they hold regardless of which UI reaches this endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ProductDeletionService;

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

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid product id.');
}

try {
    $deleted = (new ProductDeletionService())->delete($id);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-product.php] ' . $e->getMessage());
    $_SESSION['admin_product_list_error'] = AdminTranslator::trans('validation.product_kon_verwijderd_probeer_opnieuw');
    header('Location: /admin/products.php');
    exit;
}

if (!$deleted) {
    // Unknown or already-deleted id: nothing happened, so say so rather than
    // claiming a success. A double-submitted delete form lands here too.
    $_SESSION['admin_product_list_error'] = AdminTranslator::trans('validation.product_gevonden_mogelijk_al_verwijderd');
    header('Location: /admin/products.php');
    exit;
}

header('Location: /admin/products.php?deleted=1');
exit;
