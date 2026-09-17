<?php

/**
 * POST /api/admin/delete-faq-item.php
 *
 * Permanently deletes one FAQ item. Distinct from hiding an item via its
 * "Zichtbaar" checkbox (update-faq-item.php) — see App\Service\FaqContent
 * for why that distinction matters for the public-facing fallback logic.
 *
 * The question's words in every website language go first, in the same
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
use App\Service\FaqContent;
use App\Repository\FaqRepository;

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

$repository = new FaqRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$section = $repository->findById((int) $item['faq_section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$db = Database::connection();

try {
    $db->beginTransaction();

    BlockLocalization::deleteOwner('faq_items', $itemId);
    $repository->deleteItem($itemId);

    $db->commit();
    FaqContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/delete-faq-item.php] ' . $e->getMessage());
    $_SESSION['admin_faq_item_errors'] = ['Vraag kon niet worden verwijderd.'];
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/faq.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
