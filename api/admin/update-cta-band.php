<?php

/**
 * POST /api/admin/update-cta-band.php
 *
 * Saves one CTA band page-builder instance
 * (admin/cta-band.php?section=<page>:<key>). Same guard order and
 * PRG/session-flash pattern as api/admin/update-rich-text-section.php, and
 * the same "a page-builder-attached section is valid only when the page AND
 * its content row already exist" gate — an arbitrary page_slug:section_key
 * pair from the request is never trusted beyond that.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\CtaBandContent;
use App\Repository\PageRepository;
use App\Repository\CtaBandRepository;

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
[$slug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new CtaBandRepository();

if ($slug === null || $slug === '' || $sectionKey === null || $sectionKey === ''
    || (new PageRepository())->findByContentKey($slug) === null
    || $repository->findBySlugAndKey($slug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$fields = [
    'eyebrow_nl' => trim((string) ($_POST['eyebrow_nl'] ?? '')),
    'eyebrow_en' => trim((string) ($_POST['eyebrow_en'] ?? '')),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'lead_nl' => trim((string) ($_POST['lead_nl'] ?? '')),
    'lead_en' => trim((string) ($_POST['lead_en'] ?? '')),
    'primary_label_nl' => trim((string) ($_POST['primary_label_nl'] ?? '')),
    'primary_label_en' => trim((string) ($_POST['primary_label_en'] ?? '')),
    'primary_url' => trim((string) ($_POST['primary_url'] ?? '')),
    'secondary_label_nl' => trim((string) ($_POST['secondary_label_nl'] ?? '')),
    'secondary_label_en' => trim((string) ($_POST['secondary_label_en'] ?? '')),
    'secondary_url' => trim((string) ($_POST['secondary_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$required = ['eyebrow_nl', 'title_nl', 'primary_label_nl', 'primary_url'];
$errors = [];

foreach ($required as $key) {
    if ($fields[$key] === '') {
        $errors[] = AdminTranslator::trans('validation.veld_verplicht');
        break;
    }
}

// A secondary button needs both a label and a URL, or neither — a
// half-filled optional button would be broken/dead on the frontend.
$secondaryLabelSet = $fields['secondary_label_nl'] !== '';
$secondaryUrlSet = $fields['secondary_url'] !== '';
if ($secondaryLabelSet !== $secondaryUrlSet) {
    $errors[] = AdminTranslator::trans('validation.vul_secundaire_knop_zowel_label');
}

if ($errors !== []) {
    $_SESSION['admin_cta_band_errors'] = $errors;
    $_SESSION['admin_cta_band_old'] = $fields;
    header('Location: /admin/cta-band.php?section=' . urlencode($sectionParam));
    exit;
}

try {
    $repository->upsertSection($slug, $sectionKey, $fields);
    CtaBandContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-cta-band.php] ' . $e->getMessage());

    $_SESSION['admin_cta_band_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_cta_band_old'] = $fields;
    header('Location: /admin/cta-band.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: /admin/cta-band.php?section=' . urlencode($sectionParam) . '&saved=1');
exit;
