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
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the alt text is the word of the
 * language named in `language_code`, which must be an active language of the
 * website registry, and is optional in every language
 * (CardCarouselBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). It stays in this form, next to the
 * image it describes, but it is one of the card's words in that language, and
 * BlockLocalization::save() writes a whole language at a time: the save
 * carries every other word that language already has along unchanged, and no
 * other language is touched. The image is the same in every language.
 *
 * Removing the image removes its alt text too, in every language, in the
 * same transaction: an alt text describes the image it was written for, and
 * the next image an editor picks should not inherit it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
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

/**
 * Every declared word one language of this card already has, with the alt
 * text replaced: what save() needs to change the alt text alone.
 *
 * @return array<string, string>
 */
$wordsWithAlt = static function (string $languageCode, string $alt) use ($cardId): array {
    $words = [];
    foreach (array_keys(BlockLocalization::fields('carousel_cards')) as $field) {
        $words[$field] = BlockLocalization::raw('carousel_cards', $cardId, $field, $languageCode);
    }
    $words['image_alt'] = $alt;

    return $words;
};

$db = Database::connection();

if (isset($_POST['remove_image'])) {
    try {
        $db->beginTransaction();

        // Clears the reference; the file stays in the library.
        $repository->clearCardImage($cardId);

        foreach (array_keys(BlockLocalization::translations('carousel_cards', $cardId)) as $languageCode) {
            if (BlockLocalization::raw('carousel_cards', $cardId, 'image_alt', (string) $languageCode) !== '') {
                BlockLocalization::save('carousel_cards', $cardId, (string) $languageCode, $wordsWithAlt((string) $languageCode, ''));
            }
        }

        $db->commit();
        CardCarouselContent::clearCache();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

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

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$alt = trim((string) ($_POST['image_alt'] ?? ''));

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // Only the alt text is on this form, so only the alt text is this save's
    // to check; the card's text form answers for the other words.
    $problems = array_intersect_key(
        BlockLocalization::problems('carousel_cards', $languageCode, ['image_alt' => $alt]),
        ['image_alt' => true]
    );

    foreach (BlockLocalization::messageKeys($problems) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_carousel_card_image_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

try {
    // The image and its alt text in this language are one save.
    $db->beginTransaction();

    $repository->updateCardImage($cardId, $chosen);
    BlockLocalization::save('carousel_cards', $cardId, $languageCode, $wordsWithAlt($languageCode, $alt));

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-carousel-card-image.php] ' . $e->getMessage());
    // Nothing to clean up: this endpoint created no file, only a reference.
    $_SESSION['admin_carousel_card_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
