<?php

/**
 * POST /api/admin/delete-collection.php
 *
 * Permanently removes a collection. POST-only and CSRF-protected, exactly
 * like delete-product.php / delete-page.php: no amount of link prefetching,
 * crawling or a pasted URL may destroy CMS data.
 *
 * The rules live in App\Service\CollectionService::delete() so they hold no
 * matter which UI (or forged request) triggers a deletion. In particular:
 * this removes the collection row, its membership rows (pivot FK cascade)
 * and its own uploaded image — and can never remove a product or a product
 * image.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\CollectionRepository;
use App\Service\AdminAuth;
use App\Service\CollectionService;
use App\Service\Csrf;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('collections.manage');

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
    exit('Invalid collection id.');
}

$repository = new CollectionRepository();

if ($repository->findById($id) === null) {
    http_response_code(404);
    exit('Collection not found.');
}

try {
    CollectionService::delete($id, $repository);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-collection.php] ' . $e->getMessage());

    $_SESSION['admin_collection_list_error'] = AdminTranslator::trans('validation.collectie_kon_verwijderd_probeer_opnieuw');
    header('Location: /admin/collections.php');
    exit;
}

header('Location: /admin/collections.php?deleted=1');
exit;
