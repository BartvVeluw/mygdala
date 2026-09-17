<?php

/**
 * POST /api/admin/update-carousel-card-tag.php
 *
 * Saves one tag of a carousel card.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the label is the word of the
 * language named in `language_code`, which must be an active language of the
 * website registry, and is required only in the default language
 * (CardCarouselBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written, so
 * saving the Dutch label never removes an English or German one. The tag
 * keeps its id.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\CardCarouselContent;
use App\Repository\CardCarouselRepository;

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

$tagId = filter_input(INPUT_POST, 'tag_id', FILTER_VALIDATE_INT);
if ($tagId === false || $tagId === null || $tagId < 1) {
    http_response_code(400);
    exit('Invalid tag id.');
}

$repository = new CardCarouselRepository();
$tag = $repository->findTagById($tagId);

if ($tag === null) {
    http_response_code(404);
    exit('Tag not found.');
}

$redirect = '/admin/carousel-card.php?card_id=' . (int) $tag['card_id'];

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('carousel_card_tags')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('carousel_card_tags', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_carousel_card_tag_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $repository->updateTag($tagId);
    BlockLocalization::save('carousel_card_tags', $tagId, $languageCode, $words);

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-carousel-card-tag.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_tag_errors'] = ['Tag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
