<?php

/**
 * POST /api/admin/delete-stat-strip-item.php
 *
 * Permanently deletes one Stat strip item. Distinct from hiding an item via
 * its "Zichtbaar" checkbox (update-stat-strip-item.php) — see
 * App\Service\StatStripContent for why that distinction matters for the
 * public-facing fallback logic.
 *
 * The stat's words in every website language go first, in the same
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
use App\Service\StatStripContent;
use App\Repository\StatStripRepository;

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

$repository = new StatStripRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$strip = $repository->findById((int) $item['stat_strip_id']);
if ($strip === null) {
    http_response_code(404);
    exit('Strip not found.');
}

$sectionKey = $strip['page_slug'] . ':' . $strip['section_key'];

$db = Database::connection();

try {
    $db->beginTransaction();

    BlockLocalization::deleteOwner('stat_strip_items', $itemId);
    $repository->deleteItem($itemId);

    $db->commit();
    StatStripContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/delete-stat-strip-item.php] ' . $e->getMessage());
    $_SESSION['admin_stat_strip_item_errors'] = ['Stat kon niet worden verwijderd.'];
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
