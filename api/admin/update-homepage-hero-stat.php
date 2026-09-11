<?php

/**
 * POST /api/admin/update-homepage-hero-stat.php
 *
 * Edits one Homepage Hero stat's content and visibility.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\HomepageHeroContent;
use App\Repository\HomepageHeroRepository;

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

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

$repository = new HomepageHeroRepository();
$item = $repository->findStatById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$fields = [
    'primary_text_nl' => trim((string) ($_POST['primary_text_nl'] ?? '')),
    'primary_text_en' => trim((string) ($_POST['primary_text_en'] ?? '')),
    'secondary_text_nl' => trim((string) ($_POST['secondary_text_nl'] ?? '')),
    'secondary_text_en' => trim((string) ($_POST['secondary_text_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];
if ($fields['primary_text_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.primaire_tekst_nl_verplicht');
}
if ($fields['secondary_text_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.secundaire_tekst_nl_verplicht');
}

if ($errors !== []) {
    $_SESSION['admin_homepage_hero_stat_errors'] = $errors;
    header('Location: /admin/homepage-hero.php');
    exit;
}

try {
    $repository->updateStat($itemId, $fields);
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-homepage-hero-stat.php] ' . $e->getMessage());
    $_SESSION['admin_homepage_hero_stat_errors'] = ['Statistiek kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
