<?php

/**
 * POST /api/admin/create-carousel-card.php
 *
 * Adds a new card to a Kaarten-carrousel and opens it, so the editor lands
 * straight in the screen where its text, image and tags are filled in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
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

$fields = [
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
];

if ($fields['title_nl'] === '') {
    $_SESSION['admin_carousel_card_errors'] = [AdminTranslator::trans('validation.titel_nl_verplicht')];
    header('Location: ' . $listRedirect);
    exit;
}

try {
    $cardId = $repository->createCard($carouselId, $fields + ['is_active' => true]);
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-carousel-card.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_errors'] = ['Kaart kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $listRedirect);
    exit;
}

header('Location: /admin/carousel-card.php?card_id=' . $cardId . '&saved=1');
exit;
