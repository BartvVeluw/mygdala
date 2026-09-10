<?php

/**
 * POST /api/admin/create-homepage-hero-stat.php
 *
 * Adds a new stat to the Homepage Hero. The Hero layout is visually tuned
 * for at most HomepageHeroContent::MAX_STATS (3) stats — enforced here
 * server-side (not just by hiding the "add" form in the UI), so a 4th stat
 * can never be created via a direct POST either.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

$heroId = filter_input(INPUT_POST, 'hero_id', FILTER_VALIDATE_INT);
if ($heroId === false || $heroId === null || $heroId < 1) {
    http_response_code(400);
    exit('Invalid hero id.');
}

$repository = new HomepageHeroRepository();
$hero = $repository->findById($heroId);

if ($hero === null) {
    http_response_code(404);
    exit('Hero not found.');
}

if ($repository->countStatsByHeroId($heroId) >= HomepageHeroContent::MAX_STATS) {
    $_SESSION['admin_homepage_hero_stat_errors'] = ['Er kunnen maximaal ' . HomepageHeroContent::MAX_STATS . ' statistieken zijn. Verwijder er eerst een.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

$fields = [
    'primary_text_nl' => trim((string) ($_POST['primary_text_nl'] ?? '')),
    'primary_text_en' => trim((string) ($_POST['primary_text_en'] ?? '')),
    'secondary_text_nl' => trim((string) ($_POST['secondary_text_nl'] ?? '')),
    'secondary_text_en' => trim((string) ($_POST['secondary_text_en'] ?? '')),
];

$errors = [];
if ($fields['primary_text_nl'] === '') {
    $errors[] = 'Primaire tekst (NL) is verplicht.';
}
if ($fields['secondary_text_nl'] === '') {
    $errors[] = 'Secundaire tekst (NL) is verplicht.';
}

if ($errors !== []) {
    $_SESSION['admin_homepage_hero_stat_errors'] = $errors;
    header('Location: /admin/homepage-hero.php');
    exit;
}

try {
    $repository->createStat($heroId, $fields);
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-homepage-hero-stat.php] ' . $e->getMessage());
    $_SESSION['admin_homepage_hero_stat_errors'] = ['Statistiek kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
