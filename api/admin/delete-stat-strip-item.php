<?php

/**
 * POST /api/admin/delete-stat-strip-item.php
 *
 * Permanently deletes one Stat strip item. Distinct from hiding an item via
 * its "Zichtbaar" checkbox (update-stat-strip-item.php) — see
 * App\Service\StatStripContent for why that distinction matters for the
 * public-facing fallback logic.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
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

try {
    $repository->deleteItem($itemId);
    StatStripContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-stat-strip-item.php] ' . $e->getMessage());
    $_SESSION['admin_stat_strip_item_errors'] = ['Stat kon niet worden verwijderd.'];
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
