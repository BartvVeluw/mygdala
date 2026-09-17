<?php

/**
 * POST /api/admin/delete-feature-grid-item.php
 *
 * Permanently deletes one Feature grid card. Distinct from hiding a card via
 * its "Zichtbaar" checkbox (update-feature-grid-item.php) — see
 * App\Service\FeatureGridContent for why that distinction matters for the
 * public-facing fallback logic.
 *
 * The card's words in every website language go first, in the same
 * transaction as the row (BlockLocalization::deleteOwner()): there is no
 * foreign key that could take them along, and once the row is gone nothing
 * would find them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\FeatureGridContent;
use App\Repository\FeatureGridRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

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
if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

$repository = new FeatureGridRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$grid = $repository->findById((int) $item['feature_grid_id']);
if ($grid === null) {
    http_response_code(404);
    exit('Grid not found.');
}

$sectionKey = $grid['page_slug'] . ':' . $grid['section_key'];

$db = Database::connection();

try {
    $db->beginTransaction();

    BlockLocalization::deleteOwner('feature_grid_items', $itemId);
    $repository->deleteItem($itemId);

    $db->commit();
    FeatureGridContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/delete-feature-grid-item.php] ' . $e->getMessage());
    $_SESSION['admin_feature_grid_item_errors'] = ['Kaart kon niet worden verwijderd.'];
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
