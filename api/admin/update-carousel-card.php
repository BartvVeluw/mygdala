<?php

/**
 * POST /api/admin/update-carousel-card.php
 *
 * Saves one carousel card's text fields, its optional link button and its
 * visibility. The image is saved separately, so a text save can never drop
 * a photo the editor did not touch.
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
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'body_nl' => trim((string) ($_POST['body_nl'] ?? '')),
    'body_en' => trim((string) ($_POST['body_en'] ?? '')),
    'link_url' => trim((string) ($_POST['link_url'] ?? '')),
    'link_label_nl' => trim((string) ($_POST['link_label_nl'] ?? '')),
    'link_label_en' => trim((string) ($_POST['link_label_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

if ($fields['title_nl'] === '') {
    $_SESSION['admin_carousel_card_errors'] = [AdminTranslator::trans('validation.titel_nl_verplicht')];
    $_SESSION['admin_carousel_card_old'] = $fields;
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->updateCard($cardId, $fields);
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-carousel-card.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_errors'] = ['Kaart kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_carousel_card_old'] = $fields;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
