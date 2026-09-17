<?php

/**
 * POST /api/admin/create-homepage-hero-stat.php
 *
 * Adds a new stat to the Homepage Hero. The Hero layout is visually tuned
 * for at most HomepageHeroContent::MAX_STATS (3) stats — enforced here
 * server-side (not just by hiding the "add" form in the UI), so a 4th stat
 * can never be created via a direct POST either.
 *
 * A NEW STAT IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0), like a
 * new page: the words the form sends are stored as the website's default
 * language, where both texts are required, and every other language is added
 * afterwards on the stat's own card (update-homepage-hero-stat.php). The row
 * and its words are one transaction, so a stat never exists without its
 * words or the other way round.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
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

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('homepage_hero_stats')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('homepage_hero_stats', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_homepage_hero_stat_errors'] = $errors;
    header('Location: /admin/homepage-hero.php');
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $statId = $repository->createStat($heroId);
    BlockLocalization::save('homepage_hero_stats', $statId, $defaultLanguage, $words);

    $db->commit();
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-homepage-hero-stat.php] ' . $e->getMessage());
    $_SESSION['admin_homepage_hero_stat_errors'] = ['Statistiek kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
