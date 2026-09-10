<?php

/**
 * POST /api/admin/update-detail-section-point.php
 *
 * Saves one "kenmerk" (point) card of a Detailsectie.
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

$fields = [
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'body_nl' => trim((string) ($_POST['body_nl'] ?? '')),
    'body_en' => trim((string) ($_POST['body_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

if ($fields['title_nl'] === '' || $fields['body_nl'] === '') {
    $_SESSION['admin_detail_section_point_errors'] = ['Titel (NL) en tekst (NL) zijn verplicht.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->updatePoint($pointId, $fields);
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-detail-section-point.php] ' . $e->getMessage());
    $_SESSION['admin_detail_section_point_errors'] = ['Kenmerk kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
