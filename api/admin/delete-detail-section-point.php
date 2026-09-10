<?php

/**
 * POST /api/admin/delete-detail-section-point.php
 *
 * Permanently deletes one "kenmerk" (point) card of a Detailsectie.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
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

$repository->deletePoint($pointId);
DetailSectionContent::clearCache();

header('Location: ' . $redirect . '&saved=1');
exit;
