<?php

/**
 * POST /api/admin/update-carousel-card.php
 *
 * Saves the WHOLE editor of one carousel card (admin/carousel-card.php) in
 * one request: whether it is shown, its words (number, title, text, alt text,
 * button text), its image, where its button points, and its tags — their
 * labels, their order, new ones and removed ones. One form, one save
 * (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is no separate
 * image, tag or link save any more.
 *
 * `editor_action` = tags:up|down|remove:<key> is the no-JavaScript path of a
 * tag's ↑, ↓ and × (App\Service\Blocks\EditorRows): it is performed on the
 * posted tags AFTER validation and stored with everything else. With
 * JavaScript the order and the removals arrive as the posted rows.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction. A refused save stores nothing and hands every
 * typed value back, tags included, with each message next to its own field
 * (`admin_carousel_card_field_errors`, keyed by field; a tag's key is
 * `tags.<row key>`).
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): every word is the language named in
 * `language_code`, which must be an active language of the website registry;
 * the card's title and a tag's label are required only in the default
 * language (CardCarouselBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written. In
 * the default language an emptied tag is a removed tag (the no-JavaScript way
 * to remove one); in another language an empty tag is a tag without a
 * translation. A NEW tag is written in the default language whatever the
 * screen shows, like every new item. Removing a tag removes its words in
 * every language, in the same transaction.
 *
 * THE BUTTON. `link_type` is 'none', 'url' (link_url, as typed; a scheme other
 * than http(s), mailto or tel is refused) or a type of
 * App\Service\Routing\LinkTargets with `link_target[<type>]`, which must be
 * one of that type's choices. A card that points at a type whose module is
 * off keeps its stored type and target when the editor leaves it alone.
 * link_url is kept whatever the type, so switching back shows it again.
 *
 * THE IMAGE is the Media Library item in `media_id`, or none: emptying the
 * picker removes the card's image and its alt text in every language, so the
 * card shows the fixed icon. An image that predates the library (a path and
 * no media item) is kept unless `remove_legacy_image` is ticked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorRows;
use App\Service\Blocks\TranslatableField;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\CardCarouselContent;
use App\Service\Media\BlockImage;
use App\Service\Routing\LinkChoice;
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
$defaultLanguage = BlockLocalization::defaultLanguage();
$inDefaultLanguage = $languageCode === $defaultLanguage;

// Every declared word of a card is on this form. Never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('carousel_cards')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$isActive = isset($_POST['is_active']);
$action = EditorRows::parseAction($_POST['editor_action'] ?? null);

// ---------------------------------------------------------------- the button

$linkUrl = trim((string) ($_POST['link_url'] ?? ''));
$postedTargets = is_array($_POST['link_target'] ?? null) ? $_POST['link_target'] : [];
$linkType = (string) ($_POST['link_type'] ?? LinkChoice::NONE);

$fieldErrors = [];

// The same rule every block button follows (App\Service\Routing\LinkChoice).
$link = LinkChoice::fromRequest(
    $linkType,
    $postedTargets[$linkType] ?? null,
    $linkUrl,
    (string) ($card['link_type'] ?? ''),
    (int) ($card['link_target_id'] ?? 0)
);
if ($link['error'] !== null) {
    $fieldErrors['link'] = $link['error'];
}
$settings = ['link_type' => $link['link_type'], 'link_target_id' => $link['link_target_id']];

$settings += ['link_url' => $linkUrl, 'is_active' => $isActive];

// ---------------------------------------------------------------- the image

$mediaPosted = trim((string) ($_POST['media_id'] ?? ''));
$chosen = BlockImage::fromRequest($mediaPosted === '' ? null : $mediaPosted);

// The editor shows the library's alt text in the field; sent back unchanged
// it stays "the library's" (BlockImage::ownAlt(), MEDIA.md).
$words['image_alt'] = BlockImage::ownAlt($words['image_alt'], $chosen['media_id'], $inDefaultLanguage);
$hadMedia = (int) ($card['media_id'] ?? 0) > 0;
$legacyOnly = !$hadMedia && trim((string) ($card['image_path'] ?? '')) !== '';

if ($mediaPosted !== '' && $chosen['media_id'] === null) {
    $fieldErrors['media_id'] = AdminTranslator::trans('block_carousel.error_image');
}

// Only a form that carries the picker can empty it.
$clearImage = $chosen['media_id'] === null && (($hadMedia && array_key_exists('media_id', $_POST) && $mediaPosted === '') || ($legacyOnly && isset($_POST['remove_legacy_image'])));

// ---------------------------------------------------------------- the tags

$storedTagIds = array_map(static fn (array $tag): int => (int) $tag['id'], $repository->findTagsByCardId($cardId));
$tagsPosted = isset($_POST['tags_present']);
$tagRows = array_values(array_filter(
    EditorRows::fromPost($_POST['tags'] ?? []),
    // Only this card's own tags, and new rows.
    static fn (array $row): bool => $row['id'] === 0 || in_array($row['id'], $storedTagIds, true)
));
$tagRows = EditorRows::apply($tagRows, $action, 'tags');

$tagField = BlockLocalization::fields('carousel_card_tags')['label'] ?? null;

foreach ($tagRows as $row) {
    $label = $row['fields']['label'] ?? '';
    if ($tagField instanceof TranslatableField && $tagField->problem($label, false) === TranslatableField::TOO_LONG) {
        $fieldErrors['tags.' . $row['key']] = AdminTranslator::trans('validation.text_too_long');
    }
}

// ---------------------------------------------------------------- the words

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::problems('carousel_cards', $languageCode, $words) as $field => $problem) {
        $fieldErrors[$field] = AdminTranslator::trans($problem === TranslatableField::MISSING ? 'validation.veld_verplicht' : 'validation.text_too_long');
    }
}

// One line per problem at the top, the same line next to its field.
foreach ($fieldErrors as $message) {
    if (!in_array($message, $errors, true)) {
        $errors[] = $message;
    }
}

// As typed: the kind as chosen, not as it would be stored.
$old = ['language_code' => $languageCode, 'link_type' => $linkType] + $words + $settings + [
    'link_target' => array_map('intval', array_filter($postedTargets, 'is_scalar')),
    'tags' => [],
];
foreach ($tagRows as $row) {
    $old['tags'][$row['key']] = $row['fields']['label'] ?? '';
}

if ($errors !== []) {
    $_SESSION['admin_carousel_card_errors'] = $errors;
    $_SESSION['admin_carousel_card_field_errors'] = $fieldErrors;
    $_SESSION['admin_carousel_card_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $repository->updateCard($cardId, $settings);

    if ($chosen['media_id'] !== null) {
        $repository->updateCardImage($cardId, $chosen);
    } elseif ($clearImage) {
        $repository->clearCardImage($cardId);
        // No image, no alt text: in any language.
        $words['image_alt'] = '';
        foreach (array_keys(BlockLocalization::translations('carousel_cards', $cardId)) as $code) {
            $code = (string) $code;
            if ($code !== $languageCode && BlockLocalization::raw('carousel_cards', $cardId, 'image_alt', $code) !== '') {
                $other = [];
                foreach (array_keys(BlockLocalization::fields('carousel_cards')) as $field) {
                    $other[$field] = BlockLocalization::raw('carousel_cards', $cardId, $field, $code);
                }
                BlockLocalization::save('carousel_cards', $cardId, $code, ['image_alt' => ''] + $other);
            }
        }
    }

    BlockLocalization::save('carousel_cards', $cardId, $languageCode, $words);

    if ($tagsPosted) {
        $order = [];
        foreach ($tagRows as $row) {
            $label = $row['fields']['label'] ?? '';

            if ($row['id'] === 0) {
                // A new, empty row is no tag; a new tag is written in the
                // default language.
                if ($label === '') {
                    continue;
                }
                $tagId = $repository->createTag($cardId);
                BlockLocalization::save('carousel_card_tags', $tagId, $defaultLanguage, ['label' => $label]);
                $order[] = $tagId;
                continue;
            }

            if ($inDefaultLanguage && $label === '') {
                continue; // emptied in the default language: removed below
            }

            $repository->updateTag($row['id']);
            BlockLocalization::save('carousel_card_tags', $row['id'], $languageCode, ['label' => $label]);
            $order[] = $row['id'];
        }

        foreach ($storedTagIds as $tagId) {
            if (!in_array($tagId, $order, true)) {
                BlockLocalization::deleteOwner('carousel_card_tags', $tagId);
                $repository->deleteTag($tagId);
            }
        }

        $repository->reorderTags($cardId, $order);
    }

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-carousel-card.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_errors'] = [AdminTranslator::trans('block_carousel.error_card_save_failed')];
    $_SESSION['admin_carousel_card_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
