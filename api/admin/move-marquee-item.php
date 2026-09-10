<?php

/**
 * POST /api/admin/move-marquee-item.php
 *
 * Simple item ordering: swaps one item with its previous/next neighbour in
 * display order (direction=up|down). Same approach as
 * api/admin/move-stat-strip-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\MarqueeContent;
use App\Repository\MarqueeRepository;

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

$repository = new MarqueeRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$sectionId = (int) $item['marquee_section_id'];
$section = $repository->findById($sectionId);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$repository->moveItem($sectionId, $itemId, $direction);
MarqueeContent::clearCache();

header('Location: /admin/marquee.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
