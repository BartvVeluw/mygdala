<?php

/**
 * POST /api/admin/update-detail-section.php
 *
 * Saves the section-level fields of one Detailsectie block
 * (admin/detail-section.php?section=...): anchor + nav label, title, lead,
 * rich body, image position, closing note, CTA and visibility. The main
 * image, the "kenmerken" and the gallery are saved by their own endpoints,
 * so this form can never drop content it does not show.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
use App\Service\RichTextSanitizer;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;

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

$sectionParam = (string) ($_POST['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new DetailSectionRepository();

// Never trust an arbitrary page_slug:section_key pair from the request: the
// page must exist (by its immutable pages.content_key) and so must the
// content row App\Service\SectionRegistry::create() made for it.
if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode($sectionParam);

$imagePosition = (string) ($_POST['image_position'] ?? 'image_right');
if (!in_array($imagePosition, DetailSectionContent::IMAGE_POSITIONS, true)) {
    $imagePosition = 'image_right';
}

$fields = [
    'anchor' => trim((string) ($_POST['anchor'] ?? '')),
    'nav_label_nl' => trim((string) ($_POST['nav_label_nl'] ?? '')),
    'nav_label_en' => trim((string) ($_POST['nav_label_en'] ?? '')),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'lead_nl' => trim((string) ($_POST['lead_nl'] ?? '')),
    'lead_en' => trim((string) ($_POST['lead_en'] ?? '')),
    'content_html' => (string) (RichTextSanitizer::sanitize($_POST['content_html'] ?? null) ?? ''),
    'content_html_en' => (string) (RichTextSanitizer::sanitize($_POST['content_html_en'] ?? null) ?? ''),
    'image_position' => $imagePosition,
    'closing_note_nl' => trim((string) ($_POST['closing_note_nl'] ?? '')),
    'closing_note_en' => trim((string) ($_POST['closing_note_en'] ?? '')),
    'cta_label_nl' => trim((string) ($_POST['cta_label_nl'] ?? '')),
    'cta_label_en' => trim((string) ($_POST['cta_label_en'] ?? '')),
    'cta_url' => trim((string) ($_POST['cta_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

if ($fields['title_nl'] === '') {
    $_SESSION['admin_detail_section_errors'] = [AdminTranslator::trans('validation.titel_nl_verplicht')];
    $_SESSION['admin_detail_section_old'] = $fields;
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->upsertSection($pageSlug, $sectionKey, $fields);
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-detail-section.php] ' . $e->getMessage());

    $_SESSION['admin_detail_section_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_detail_section_old'] = $fields;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
