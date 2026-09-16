<?php

/**
 * POST /api/admin/reorder-nav-items.php
 *
 * Persists a new display order within one group — one parent scope (top-level
 * when parent_id is empty) and one presentation, since the menu links and the
 * header buttons are ordered separately — see NavigationRepository::reorder().
 * JSON response, same convention as reorder-page-sections.php. item_ids
 * outside the group actually owned by parent_id and presentation are silently
 * ignored by the repository rather than trusted from the request.
 *
 * Body: parent_id (empty = top-level), presentation (link | button; anything
 * else is a link, which is what every drag list before header buttons sent
 * implicitly), item_ids (comma-separated).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\NavigationRepository;
use App\Service\NavigationPresentation;

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

$parentIdRaw = trim((string) ($_POST['parent_id'] ?? ''));
$parentId = $parentIdRaw === '' ? null : (int) $parentIdRaw;

$idsRaw = (string) ($_POST['item_ids'] ?? '');
$ids = array_values(array_filter(array_map(
    static fn (string $id): int => (int) trim($id),
    explode(',', $idsRaw)
), static fn (int $id): bool => $id > 0));

try {
    (new NavigationRepository())->reorder(
        $parentId,
        $ids,
        NavigationPresentation::of(['presentation' => (string) ($_POST['presentation'] ?? '')])
    );
} catch (\Throwable $e) {
    error_log('[api/admin/reorder-nav-items.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => AdminTranslator::trans('validation.volgorde_kon_opgeslagen')]);
    exit;
}

echo json_encode(['ok' => true]);
