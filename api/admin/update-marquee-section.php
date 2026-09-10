<?php

/**
 * POST /api/admin/update-marquee-section.php
 *
 * Saves the section-level visibility for one Marquee
 * (admin/marquee.php?section=...). This section type has no section-level
 * content fields (see App\Service\MarqueeContent), only the "Actief"
 * checkbox. Same guard order and PRG/session-flash pattern as
 * api/admin/update-stat-strip.php. Item content is saved separately by
 * create/update/delete/move-marquee-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\MarqueeContent;
use App\Repository\PageRepository;
use App\Repository\MarqueeRepository;

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

// A page-builder-attached instance is valid only when the page really
// exists (by its immutable pages.content_key) AND its content row already
// does too (created by App\Service\SectionRegistry::create()) — never trust
// an arbitrary page_slug:section_key pair from the request beyond that.
[$pageSlug, $sectionKeyPart] = array_pad(explode(':', $sectionKey, 2), 2, null);

if ($pageSlug === null || $pageSlug === '' || $sectionKeyPart === null || $sectionKeyPart === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || (new MarqueeRepository())->findBySlugAndKey($pageSlug, $sectionKeyPart) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$section = ['page_slug' => $pageSlug, 'section_key' => $sectionKeyPart];

$fields = [
    'is_active' => isset($_POST['is_active']),
];

try {
    (new MarqueeRepository())->upsertSection($section['page_slug'], $section['section_key'], $fields);
    MarqueeContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-marquee-section.php] ' . $e->getMessage());

    $_SESSION['admin_marquee_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/marquee.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/marquee.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
