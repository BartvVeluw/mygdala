<?php

/**
 * POST /api/admin/create-carousel-card-tag.php
 *
 * Adds a tag to one carousel card.
 *
 * A NEW TAG IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0), like a new
 * page: the label the form sends is stored as the website's default language,
 * where it is required, and every other language is added afterwards on the
 * tag's own row (update-carousel-card-tag.php). The row and its label are one
 * transaction, so a tag never exists without its label or the other way
 * round.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
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

$cardId = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
if ($cardId === false || $cardId === null || $cardId < 1) {
    http_response_code(400);
    exit('Invalid card id.');
}

$repository = new CardCarouselRepository();
$card = $repository->findCardById($cardId);

if ($card === null) {
    http_response_code(404);
    exit('Card not found.');
}

$redirect = '/admin/carousel-card.php?card_id=' . $cardId;

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('carousel_card_tags')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('carousel_card_tags', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_carousel_card_tag_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $tagId = $repository->createTag($cardId);
    BlockLocalization::save('carousel_card_tags', $tagId, $defaultLanguage, $words);

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-carousel-card-tag.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_tag_errors'] = ['Tag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
