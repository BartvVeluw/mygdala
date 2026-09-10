<?php

/**
 * POST /api/admin/update-page-hero.php
 *
 * Saves the Page Hero form for one page (admin/page-hero.php?slug=...).
 * Same guard order and PRG/session-flash pattern as
 * api/admin/update-site-settings.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageHeroContent;
use App\Repository\PageHeroRepository;

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

$slug = (string) ($_POST['slug'] ?? '');

// Known keys (PageHeroContent::PAGES) are the originally hardcoded pages;
// any other slug is only valid when the page builder has actually attached
// a Page Hero to it (App\Service\SectionRegistry::create()) — never trust
// an arbitrary slug from the request beyond that.
$isDynamicallyAttached = (new \App\Repository\PageRepository())->findByContentKey($slug) !== null
    && (new PageHeroRepository())->findBySlug($slug) !== null;

if (!array_key_exists($slug, PageHeroContent::PAGES) && !$isDynamicallyAttached) {
    http_response_code(404);
    exit('Unknown page.');
}

$fields = [
    'eyebrow_nl' => trim((string) ($_POST['eyebrow_nl'] ?? '')),
    'eyebrow_en' => trim((string) ($_POST['eyebrow_en'] ?? '')),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'lead_nl' => trim((string) ($_POST['lead_nl'] ?? '')),
    'lead_en' => trim((string) ($_POST['lead_en'] ?? '')),
    'breadcrumb_label_nl' => trim((string) ($_POST['breadcrumb_label_nl'] ?? '')),
    'breadcrumb_label_en' => trim((string) ($_POST['breadcrumb_label_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$required = ['eyebrow_nl', 'title_nl', 'breadcrumb_label_nl'];
$errors = [];

foreach ($required as $key) {
    if ($fields[$key] === '') {
        $errors[] = 'Dit veld is verplicht.';
        break;
    }
}

if ($errors !== []) {
    $_SESSION['admin_page_hero_errors'] = $errors;
    $_SESSION['admin_page_hero_old'] = $fields;
    header('Location: /admin/page-hero.php?slug=' . urlencode($slug));
    exit;
}

try {
    (new PageHeroRepository())->upsert($slug, $fields);
    PageHeroContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-page-hero.php] ' . $e->getMessage());

    $_SESSION['admin_page_hero_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_page_hero_old'] = $fields;
    header('Location: /admin/page-hero.php?slug=' . urlencode($slug));
    exit;
}

header('Location: /admin/page-hero.php?slug=' . urlencode($slug) . '&saved=1');
exit;
