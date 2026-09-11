<?php

/**
 * POST /api/admin/create-stat-strip-item.php
 *
 * Adds a new stat to a Stat strip. Same guard order / PRG pattern as
 * api/admin/create-feature-grid-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
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

$stripId = filter_input(INPUT_POST, 'strip_id', FILTER_VALIDATE_INT);
if ($stripId === false || $stripId === null || $stripId < 1) {
    http_response_code(400);
    exit('Invalid strip id.');
}

$repository = new StatStripRepository();
$strip = $repository->findById($stripId);

if ($strip === null) {
    http_response_code(404);
    exit('Strip not found.');
}

$sectionKey = $strip['page_slug'] . ':' . $strip['section_key'];

$fields = [
    'primary_text_nl' => trim((string) ($_POST['primary_text_nl'] ?? '')),
    'primary_text_en' => trim((string) ($_POST['primary_text_en'] ?? '')),
    'secondary_text_nl' => trim((string) ($_POST['secondary_text_nl'] ?? '')),
    'secondary_text_en' => trim((string) ($_POST['secondary_text_en'] ?? '')),
];

$errors = [];
if ($fields['primary_text_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.primaire_tekst_nl_verplicht');
}
if ($fields['secondary_text_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.secundaire_tekst_nl_verplicht');
}

if ($errors !== []) {
    $_SESSION['admin_stat_strip_item_errors'] = $errors;
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->createItem($stripId, $fields);
    StatStripContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-stat-strip-item.php] ' . $e->getMessage());
    $_SESSION['admin_stat_strip_item_errors'] = ['Stat kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/stat-strip.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
