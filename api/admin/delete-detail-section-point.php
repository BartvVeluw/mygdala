<?php

/**
 * POST /api/admin/delete-detail-section-point.php
 *
 * Permanently deletes one "kenmerk" (point) card of a Detailsectie.
 *
 * The point's words in every website language go first, in the same
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
use App\Service\DetailSectionContent;
use App\Repository\DetailSectionRepository;

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

$pointId = filter_input(INPUT_POST, 'point_id', FILTER_VALIDATE_INT);
if ($pointId === false || $pointId === null || $pointId < 1) {
    http_response_code(400);
    exit('Invalid point id.');
}

$repository = new DetailSectionRepository();
$point = $repository->findPointById($pointId);

if ($point === null) {
    http_response_code(404);
    exit('Point not found.');
}

$section = $repository->findById((int) $point['section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode((string) $section['page_slug'] . ':' . (string) $section['section_key']);

$db = Database::connection();

try {
    $db->beginTransaction();

    BlockLocalization::deleteOwner('detail_section_points', $pointId);
    $repository->deletePoint($pointId);

    $db->commit();
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/delete-detail-section-point.php] ' . $e->getMessage());
    $_SESSION['admin_detail_section_point_errors'] = ['Kenmerk kon niet worden verwijderd.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
