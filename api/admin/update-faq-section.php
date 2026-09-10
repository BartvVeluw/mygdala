<?php

/**
 * POST /api/admin/update-faq-section.php
 *
 * Saves the section-level fields (heading + visibility) for one FAQ list
 * (admin/faq.php?section=...). Same guard order and PRG/session-flash
 * pattern as api/admin/update-feature-grid.php. Item content is saved
 * separately by create/update/delete/move-faq-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\FaqContent;
use App\Repository\FaqRepository;

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
$section = FaqContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new FaqRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
}

$fields = [
    'eyebrow_nl' => trim((string) ($_POST['eyebrow_nl'] ?? '')),
    'eyebrow_en' => trim((string) ($_POST['eyebrow_en'] ?? '')),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];
foreach (['eyebrow_nl', 'title_nl'] as $key) {
    if ($fields[$key] === '') {
        $errors[] = 'Dit veld is verplicht.';
        break;
    }
}

if ($errors !== []) {
    $_SESSION['admin_faq_errors'] = $errors;
    $_SESSION['admin_faq_old'] = $fields;
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    (new FaqRepository())->upsertSection($section['page_slug'], $section['section_key'], $fields);
    FaqContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-faq-section.php] ' . $e->getMessage());

    $_SESSION['admin_faq_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_faq_old'] = $fields;
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/faq.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
