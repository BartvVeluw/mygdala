<?php

/**
 * POST /api/admin/update-text-image-split-section.php
 *
 * Saves the section-level fields (layout, optional eyebrow/title, optional
 * button, visibility) for one Text + image split block
 * (admin/text-image-split.php?section=...). Same guard order and
 * PRG/session-flash pattern as api/admin/update-faq-section.php.
 * Paragraph/image content is saved separately by the paragraph/image
 * endpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

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

$sectionKey = (string) ($_POST['section'] ?? '');
$section = TextImageSplitContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new TextImageSplitRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
}

$layout = (string) ($_POST['layout'] ?? 'image_right');
if (!in_array($layout, ['image_left', 'image_right'], true)) {
    $layout = 'image_right';
}

$fields = [
    'layout' => $layout,
    'eyebrow_nl' => trim((string) ($_POST['eyebrow_nl'] ?? '')),
    'eyebrow_en' => trim((string) ($_POST['eyebrow_en'] ?? '')),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'button_label_nl' => trim((string) ($_POST['button_label_nl'] ?? '')),
    'button_label_en' => trim((string) ($_POST['button_label_en'] ?? '')),
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

try {
    (new TextImageSplitRepository())->upsertSection($section['page_slug'], $section['section_key'], $fields);
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-text-image-split-section.php] ' . $e->getMessage());

    $_SESSION['admin_tis_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_tis_old'] = $fields;
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
