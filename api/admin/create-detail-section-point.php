<?php

/**
 * POST /api/admin/create-detail-section-point.php
 *
 * Adds a new "kenmerk" (point) card to one Detailsectie.
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

$sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
if ($sectionId === false || $sectionId === null || $sectionId < 1) {
    http_response_code(400);
    exit('Invalid section id.');
}

$repository = new DetailSectionRepository();
$section = $repository->findById($sectionId);

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
];

if ($fields['title_nl'] === '' || $fields['body_nl'] === '') {
    $_SESSION['admin_detail_section_point_errors'] = ['Titel (NL) en tekst (NL) zijn verplicht.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->createPoint($sectionId, $fields);
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-detail-section-point.php] ' . $e->getMessage());
    $_SESSION['admin_detail_section_point_errors'] = ['Kenmerk kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
