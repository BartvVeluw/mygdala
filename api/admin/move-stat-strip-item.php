<?php

/**
 * POST /api/admin/move-stat-strip-item.php
 *
 * Simple stat ordering: swaps one stat with its previous/next neighbour in
 * display order (direction=up|down). Same approach as
 * api/admin/move-feature-grid-item.php.
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
$direction = $_POST['direction'] ?? '';

if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new StatStripRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$stripId = (int) $item['stat_strip_id'];
$strip = $repository->findById($stripId);
if ($strip === null) {
    http_response_code(404);
    exit('Strip not found.');
}

$sectionKey = $strip['page_slug'] . ':' . $strip['section_key'];

$repository->moveItem($stripId, $itemId, $direction);
StatStripContent::clearCache();

header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
