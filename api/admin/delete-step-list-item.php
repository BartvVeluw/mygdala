<?php

/**
 * POST /api/admin/delete-step-list-item.php
 *
 * Permanently deletes one step. Distinct from hiding a step via its
 * "Zichtbaar" checkbox (update-step-list-item.php) — see
 * App\Service\StepListContent for why that distinction matters for the
 * public-facing fallback logic.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\StepListContent;
use App\Repository\StepListRepository;

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

$repository = new StepListRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$section = $repository->findById((int) $item['step_list_section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

try {
    $repository->deleteItem($itemId);
    StepListContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-step-list-item.php] ' . $e->getMessage());
    $_SESSION['admin_step_list_item_errors'] = ['Stap kon niet worden verwijderd.'];
    header('Location: /admin/step-list.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/step-list.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
