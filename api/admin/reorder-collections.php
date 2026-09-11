<?php

/**
 * POST /api/admin/reorder-collections.php
 *
 * Persists a new display order for the collections themselves
 * (drag-and-drop in admin/collections.php). Called via fetch(), so — like
 * reorder-portfolio-items.php — this responds with JSON instead of a
 * redirect.
 *
 * The submitted list is only ever used as an ORDER: the sort_order values
 * are derived from each id's position, never read from the request, and ids
 * that do not belong to an existing collection are ignored. See
 * CollectionRepository::reorderCollections().
 *
 * Body: collection_ids (comma-separated ids in the new order).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\CollectionRepository;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\CollectionService;
use App\Service\Csrf;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('collections.manage');

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

$collectionIds = CollectionService::normalizeIdList($_POST['collection_ids'] ?? '');

try {
    (new CollectionRepository())->reorderCollections($collectionIds);
    CollectionContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/reorder-collections.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => AdminTranslator::trans('validation.volgorde_kon_opgeslagen')]);
    exit;
}

echo json_encode(['ok' => true]);
