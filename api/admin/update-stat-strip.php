<?php

/**
 * POST /api/admin/update-stat-strip.php
 *
 * Saves the section-level visibility for one Stat strip
 * (admin/stat-strip.php?section=...). This section type has no
 * section-level content fields (see App\Service\StatStripContent), only
 * the "Actief" checkbox. Same guard order and PRG/session-flash pattern as
 * api/admin/update-feature-grid.php. Stat content is saved separately by
 * create/update/delete/move-stat-strip-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\StatStripContent;
use App\Repository\StatStripRepository;

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
$section = StatStripContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StatStripRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
}

$fields = [
    'is_active' => isset($_POST['is_active']),
];

try {
    (new StatStripRepository())->upsertStrip($section['page_slug'], $section['section_key'], $fields);
    StatStripContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-stat-strip.php] ' . $e->getMessage());

    $_SESSION['admin_stat_strip_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
