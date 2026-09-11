<?php

/**
 * POST /api/admin/update-carousel-card-image.php
 *
 * Sets, replaces or removes one carousel card's image by CHOOSING a Media
 * Library item (`media_id`, from the picker in admin/_media_picker.php).
 * `remove_image` clears the reference back to NULL, which makes the card
 * render the theme's fixed icon instead of a photo.
 *
 * No file is uploaded, replaced or deleted here any more: uploading happens
 * once, inside the picker, and removing a file is the Media Library's own
 * decision. See MEDIA.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\CardCarouselContent;
use App\Service\Media\BlockImage;
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

if (isset($_POST['remove_image'])) {
    try {
        // Clears the reference; the file stays in the library.
        $repository->clearCardImage($cardId);
        CardCarouselContent::clearCache();
    } catch (\Throwable $e) {
        error_log('[api/admin/update-carousel-card-image.php] ' . $e->getMessage());
        $_SESSION['admin_carousel_card_image_errors'] = ['Afbeelding kon niet worden verwijderd.'];
    }

    header('Location: ' . $redirect . '&saved=1');
    exit;
}

$chosen = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($chosen['media_id'] === null) {
    $_SESSION['admin_carousel_card_image_errors'] = ['Kies een afbeelding uit de mediabibliotheek, of verwijder de afbeelding van deze kaart.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->updateCardImage($cardId, $chosen + [
        'image_alt_nl' => trim((string) ($_POST['image_alt_nl'] ?? '')),
        'image_alt_en' => trim((string) ($_POST['image_alt_en'] ?? '')),
    ]);
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-carousel-card-image.php] ' . $e->getMessage());
    // Nothing to clean up: this endpoint created no file, only a reference.
    $_SESSION['admin_carousel_card_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
