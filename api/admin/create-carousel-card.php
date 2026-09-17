<?php

/**
 * POST /api/admin/create-carousel-card.php
 *
 * Adds a new card to a Kaarten-carrousel and opens it, so the editor lands
 * straight in the screen where its text, image and tags are filled in.
 *
 * A NEW CARD IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0), like a
 * new page: the title the form sends is stored as the website's default
 * language, where it is required, and every other language is added
 * afterwards on the card's own screen (update-carousel-card.php). The row and
 * its words are one transaction, so a card never exists without its title or
 * the other way round.
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

$carouselId = filter_input(INPUT_POST, 'carousel_id', FILTER_VALIDATE_INT);
if ($carouselId === false || $carouselId === null || $carouselId < 1) {
    http_response_code(400);
    exit('Invalid carousel id.');
}

$repository = new CardCarouselRepository();
$carousel = $repository->findById($carouselId);

if ($carousel === null) {
    http_response_code(404);
    exit('Carousel not found.');
}

$listRedirect = '/admin/card-carousel.php?section=' . urlencode((string) $carousel['page_slug'] . ':' . (string) $carousel['section_key']);

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('carousel_cards')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('carousel_cards', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_carousel_card_errors'] = $errors;
    header('Location: ' . $listRedirect);
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $cardId = $repository->createCard($carouselId);
    BlockLocalization::save('carousel_cards', $cardId, $defaultLanguage, $words);

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-carousel-card.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_errors'] = ['Kaart kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $listRedirect);
    exit;
}

header('Location: /admin/carousel-card.php?card_id=' . $cardId . '&saved=1');
exit;
