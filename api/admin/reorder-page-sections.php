<?php

/**
 * POST /api/admin/reorder-page-sections.php
 *
 * Persists a new display order for one page's block list (drag-and-drop in
 * admin/page.php). Called via fetch(), so — like
 * reorder-portfolio-items.php — this responds with JSON instead of a
 * redirect. A page is ONE ordered list, so every block on it may be moved
 * anywhere in that list — including the fixed blocks a page template used
 * to hardcode. section_ids that do not belong to this exact page are
 * silently ignored by PageSectionRepository::reorder() rather than trusted
 * from the request.
 *
 * Body: page_id, section_ids (comma-separated ids in the new order).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

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

$pageId = (int) ($_POST['page_id'] ?? 0);

$page = $pageId > 0 ? (new PageRepository())->findById($pageId) : null;

if ($page === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Unknown page.']);
    exit;
}

$idsRaw = (string) ($_POST['section_ids'] ?? '');
$ids = array_values(array_filter(array_map(
    static fn (string $id): int => (int) trim($id),
    explode(',', $idsRaw)
), static fn (int $id): bool => $id > 0));

try {
    (new PageSectionRepository())->reorder((int) $page['id'], $ids);
} catch (\Throwable $e) {
    error_log('[api/admin/reorder-page-sections.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Volgorde kon niet worden opgeslagen.']);
    exit;
}

echo json_encode(['ok' => true]);
