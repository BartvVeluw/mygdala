<?php

/**
 * POST /api/admin/update-carousel-card-tag.php
 *
 * Saves one tag of a carousel card.
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
    $repository->updateTag($tagId, $fields);
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-carousel-card-tag.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_tag_errors'] = ['Tag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
