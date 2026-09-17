<?php

/**
 * POST /api/admin/update-homepage-hero-stat.php
 *
 * Edits one Homepage Hero stat's content and visibility.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the two texts are the words of the
 * language named in `language_code`, which must be an active language of the
 * website registry, and are required only in the default language
 * (HomepageHeroBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written, so
 * saving the Dutch words never removes an English or German translation. The
 * stat keeps its id; is_active is the same in every language and is saved in
 * the same transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\HomepageHeroContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
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

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('homepage_hero_stats')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('homepage_hero_stats', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_homepage_hero_stat_errors'] = $errors;
    header('Location: /admin/homepage-hero.php');
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $repository->updateStat($itemId, ['is_active' => isset($_POST['is_active'])]);
    BlockLocalization::save('homepage_hero_stats', $itemId, $languageCode, $words);

    $db->commit();
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-homepage-hero-stat.php] ' . $e->getMessage());
    $_SESSION['admin_homepage_hero_stat_errors'] = ['Statistiek kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
