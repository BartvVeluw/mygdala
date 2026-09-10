<?php

/**
 * POST /api/admin/update-card-carousel.php
 *
 * Saves the block-level fields of one Kaarten-carrousel
 * (admin/card-carousel.php?section=...): the optional eyebrow/title/lead
 * above the cards, plus visibility. The cards themselves are saved by the
 * card endpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\CardCarouselContent;
use App\Repository\CardCarouselRepository;
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

$repository = new CardCarouselRepository();

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

$redirect = '/admin/card-carousel.php?section=' . urlencode($sectionParam);

$fields = [
    'eyebrow_nl' => trim((string) ($_POST['eyebrow_nl'] ?? '')),
    'eyebrow_en' => trim((string) ($_POST['eyebrow_en'] ?? '')),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'lead_nl' => trim((string) ($_POST['lead_nl'] ?? '')),
    'lead_en' => trim((string) ($_POST['lead_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

try {
    $repository->upsertCarousel($pageSlug, $sectionKey, $fields);
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-card-carousel.php] ' . $e->getMessage());

    $_SESSION['admin_card_carousel_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_card_carousel_old'] = $fields;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
