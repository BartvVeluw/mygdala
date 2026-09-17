<?php

/**
 * POST /api/admin/update-carousel-card.php
 *
 * Saves one carousel card's text fields, its optional link button and its
 * visibility. The image is saved separately, so a text save can never drop
 * a photo the editor did not touch.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the title, body and link label are
 * the words of the language named in `language_code`, which must be an active
 * language of the website registry, and the title is required only in the
 * default language (CardCarouselBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). BlockLocalization::save() writes a
 * whole language at a time and the alt text is not on this form, so the save
 * carries the alt text that language already has along unchanged; no other
 * language is touched. The card keeps its id; the link URL and is_active are
 * the same in every language and are saved in the same transaction.
 *
 * THE LINK is not refused when only its label or only its URL is filled in:
 * the editor says such a card simply shows no button, and
 * App\Service\CardCarouselContent decides that, on the default language's
 * label, the one every other language falls back to.
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

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// The alt text belongs to the image form (update-carousel-card-image.php);
// every other declared field is on this one. Never a name taken from the
// request.
$words = [];
foreach (array_keys(BlockLocalization::fields('carousel_cards')) as $field) {
    if ($field !== 'image_alt') {
        $words[$field] = trim((string) ($_POST[$field] ?? ''));
    }
}

$settings = [
    'link_url' => trim((string) ($_POST['link_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('carousel_cards', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_carousel_card_errors'] = $errors;
    $_SESSION['admin_carousel_card_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The card's link URL, its visibility and its words in this language are
    // one save.
    $db->beginTransaction();

    $repository->updateCard($cardId, $settings);

    // save() writes a whole language: the alt text this language already
    // has goes along unchanged.
    BlockLocalization::save('carousel_cards', $cardId, $languageCode, $words + [
        'image_alt' => BlockLocalization::raw('carousel_cards', $cardId, 'image_alt', $languageCode),
    ]);

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-carousel-card.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_errors'] = ['Kaart kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_carousel_card_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
