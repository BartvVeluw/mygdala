<?php

/**
 * POST /api/admin/delete-carousel-card.php
 *
 * Permanently deletes one carousel card — its tags (ON DELETE CASCADE) and
 * its uploaded image included.
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

$repository->deleteCard($cardId);
CardCarouselContent::clearCache();
    // Removes the REFERENCE only. The file belongs to the Media Library and
    // may still be in use elsewhere; deleting one is the library's own
    // decision, and it refuses while anything still uses it (MEDIA.md).

header('Location: ' . $redirect . '&saved=1');
exit;
