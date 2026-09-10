<?php

/**
 * POST /api/admin/move-carousel-card-tag.php
 *
 * Simple tag ordering: swaps one tag with its previous/next neighbour on
 * the card (direction=up|down).
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

$repository->moveTag((int) $tag['card_id'], $tagId, $direction);
CardCarouselContent::clearCache();

header('Location: ' . $redirect . '&saved=1');
exit;
