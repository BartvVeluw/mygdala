<?php

/**
 * POST /api/admin/create-carousel-card-tag.php
 *
 * Adds a tag to one carousel card.
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

$redirect = '/admin/carousel-card.php?card_id=' . $cardId;

$fields = [
    'label_nl' => trim((string) ($_POST['label_nl'] ?? '')),
    'label_en' => trim((string) ($_POST['label_en'] ?? '')),
];

if ($fields['label_nl'] === '') {
    $_SESSION['admin_carousel_card_tag_errors'] = ['Label (NL) is verplicht.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->createTag($cardId, $fields);
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-carousel-card-tag.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_tag_errors'] = ['Tag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
