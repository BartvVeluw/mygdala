<?php

/**
 * POST /api/admin/reorder-footer-links.php — JSON response. Reordering is
 * always scoped to one column_id; link_ids outside that exact column are
 * silently ignored by FooterRepository::reorderLinks() rather than trusted
 * from the request (same convention as reorder-page-sections.php).
 * Body: column_id, link_ids (comma-separated).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\FooterRepository;

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

$columnIdParam = filter_input(INPUT_POST, 'column_id', FILTER_VALIDATE_INT);
if ($columnIdParam === false || $columnIdParam === null || $columnIdParam < 1) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Unknown column.']);
    exit;
}

$idsRaw = (string) ($_POST['link_ids'] ?? '');
$ids = array_values(array_filter(array_map(
    static fn (string $id): int => (int) trim($id),
    explode(',', $idsRaw)
), static fn (int $id): bool => $id > 0));

try {
    (new FooterRepository())->reorderLinks($columnIdParam, $ids);
} catch (\Throwable $e) {
    error_log('[api/admin/reorder-footer-links.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Volgorde kon niet worden opgeslagen.']);
    exit;
}

echo json_encode(['ok' => true]);
