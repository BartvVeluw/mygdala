<?php

/**
 * POST /api/admin/delete-marquee-item.php
 *
 * Permanently deletes one Marquee item. Distinct from hiding an item via its
 * "Zichtbaar" checkbox (update-marquee-item.php) — see
 * App\Service\MarqueeContent for why that distinction matters for the
 * public-facing fallback logic.
 *
 * The item's words in every website language go first, in the same
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
if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

$repository = new MarqueeRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$section = $repository->findById((int) $item['marquee_section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$db = Database::connection();

try {
    $db->beginTransaction();

    BlockLocalization::deleteOwner('marquee_items', $itemId);
    $repository->deleteItem($itemId);

    $db->commit();
    MarqueeContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/delete-marquee-item.php] ' . $e->getMessage());
    $_SESSION['admin_marquee_item_errors'] = ['Item kon niet worden verwijderd.'];
    header('Location: /admin/marquee.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/marquee.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
