<?php

/**
 * POST /api/admin/move-carousel-card.php
 *
 * Simple card ordering: swaps one card with its previous/next neighbour in
 * the carousel (direction=up|down).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

$direction = $_POST['direction'] ?? '';
if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
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

$carousel = $repository->findById((int) $card['carousel_id']);
if ($carousel === null) {
    http_response_code(404);
    exit('Carousel not found.');
}

$redirect = '/admin/card-carousel.php?section=' . urlencode((string) $carousel['page_slug'] . ':' . (string) $carousel['section_key']);

$repository->moveCard((int) $card['carousel_id'], $cardId, $direction);
CardCarouselContent::clearCache();

header('Location: ' . $redirect . '&saved=1');
exit;
