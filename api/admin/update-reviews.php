<?php

/**
 * POST /api/admin/update-reviews.php
 *
 * Saves the WHOLE editor of one Reviews block (admin/reviews.php?section=...)
 * in one request: the heading, the layout, the optional button and the
 * reviews — their words, stars, date, picture and source, their order, new
 * ones and the ones marked for removal. One form, one save (PAGE-EDITOR.md,
 * "Eén formulier per blok-editor"). The same `<page>:<key>` gate as every
 * block endpoint: the page must exist by its immutable content_key and be
 * one this account may manage (ContentBlockAccess::pageForKeyForApi(), a
 * page, a product's or a project's content page) AND the block's row must
 * already exist, before anything is read or written.
 *
 * `editor_action` = reviews:up|down:<key> is the no-JavaScript path of a
 * review's ↑ and ↓ (App\Service\Blocks\EditorRows), performed on the posted
 * reviews after validation and stored with everything else.
 *
 * WHAT IS CHECKED, and refused at its field rather than stored:
 *   - layout and header_align: a word of its closed list
 *     (ReviewsContent::CHOICES). A form without the field keeps what is
 *     stored; an unknown word is refused.
 *   - a review's text: required in the default language, like every
 *     required word (BlockLocalization, EditorChildList); every word's length.
 *   - a review's stars: nothing (no stars) or a whole number 1..5.
 *   - a review's date: nothing or a real calendar date (YYYY-MM-DD).
 *   - a review's source address: nothing or a web address (http, https or a
 *     path of this site; App\Service\Routing\SafeUrl::SCHEMES_WEB): never
 *     javascript:, data:, mailto: or a control character.
 *   - a review's picture: optional, and then a picture of the Media Library
 *     (ReviewsContent::picture()); a video, a document or an id that names
 *     nothing is refused. How it sits in its frame is Responsive Media's
 *     (ResponsiveImage::fromRequest()), refused part by part.
 *   - the featured review: the key of a review of THIS form. Anything else —
 *     a forged id, a review marked for removal — stores no choice, which the
 *     page reads as "the first review".
 *   - the button: App\Service\Routing\LinkChoice, the rule every block button
 *     shares, and a button needs its words in the default language. Its style
 *     is a Button Styles 2.0 choice (ButtonStyles::choiceFromRequest()).
 *   - a review id that is not a review of this block is dropped, never
 *     written (EditorChildList::fromRequest()).
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing and
 * hands every typed value back, with each message next to its field.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the heading's and every stored
 * review's words are the language named in `language_code`, which must be an
 * active language of the website registry. A NEW review is written in the
 * default language, and a removed one takes its words in every language
 * along, both through App\Service\Blocks\EditorChildList. Stars, dates,
 * pictures and addresses are the same in every language.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\ButtonStyleRepository;
use App\Repository\ResponsiveImageRepository;
use App\Repository\ReviewsRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
use App\Service\ContentOwners\ContentBlockAccess;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Media\ResponsiveImage;
use App\Service\ReviewsContent;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\SafeUrl;
use App\Service\Theme\ButtonStyles;

AdminAuth::requireLoginForApi();
ContentBlockAccess::requireAnyForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ReviewsRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || ContentBlockAccess::pageForKeyForApi($pageSlug) === null
    || ($block = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$blockId = (int) $block['id'];
$redirect = '/admin/reviews.php?section=' . urlencode($sectionParam);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$defaultLanguage = BlockLocalization::defaultLanguage();

$fieldErrors = [];

// ---------------------------------------------------------------- choices
$stored = ReviewsContent::settings($block);
$settings = [];
foreach (ReviewsContent::CHOICES as $name => $list) {
    if (!array_key_exists($name, $_POST)) {
        $settings[$name] = $stored[$name];
        continue;
    }

    $value = is_scalar($_POST[$name]) ? trim((string) $_POST[$name]) : null;
    if ($value === null || !in_array($value, $list, true)) {
        $fieldErrors[$name] = AdminTranslator::trans('block_reviews.error_choice');
        $value = $stored[$name];
    }
    $settings[$name] = $value;
}

// ------------------------------------------------------------------ words
// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields(ReviewsContent::TABLE)) as $field) {
    $words[$field] = is_scalar($_POST[$field] ?? null) ? trim((string) $_POST[$field]) : '';
}

// ----------------------------------------------------------------- button
$linkType = is_string($_POST['link_type'] ?? null) ? $_POST['link_type'] : LinkChoice::NONE;
$postedTargets = is_array($_POST['link_target'] ?? null) ? $_POST['link_target'] : [];
$linkUrl = is_scalar($_POST['link_url'] ?? null) ? trim((string) $_POST['link_url']) : '';
$link = LinkChoice::fromRequest(
    $linkType,
    $postedTargets[$linkType] ?? null,
    $linkUrl,
    (string) ($block['link_type'] ?? ''),
    (int) ($block['link_target_id'] ?? 0)
);
if ($link['error'] !== null) {
    $fieldErrors['link_url'] = $link['error'];
} elseif ($link['link_type'] !== null) {
    // A button needs words in the default language: typed when that
    // language is on screen, else as stored.
    $defaultLabel = $languageCode === $defaultLanguage
        ? $words['button_label']
        : BlockLocalization::raw(ReviewsContent::TABLE, $blockId, 'button_label', $defaultLanguage);
    if ($defaultLabel === '') {
        $fieldErrors['button_label'] = AdminTranslator::trans('block_reviews.error_button_label');
    }
}

[$buttonStyle, $buttonStyleError] = ButtonStyles::choiceFromRequest($_POST, 'button_style_id', ButtonStyles::storedChoice($block['button_style_id'] ?? null));
if ($buttonStyleError !== null) {
    $fieldErrors['button_style_id'] = $buttonStyleError;
}

// ---------------------------------------------------------------- reviews
$storedReviews = [];
foreach ($repository->findItemsByBlockId($blockId) as $item) {
    $storedReviews[(int) $item['id']] = $item;
}

// How a review's picture sits in its frame arrives already chosen on a new
// review: alone it does not make it a review.
$imageSlot = ReviewsContent::imageSlot();
$preset = [];
foreach (['presentation', 'focus_x', 'focus_y', 'zoom', 'mobile_source', 'mobile_focus_x', 'mobile_focus_y', 'mobile_zoom'] as $part) {
    $preset[] = $imageSlot->column($part);
}
$reviews = EditorChildList::fromRequest($_POST, 'reviews', 'review_block_items', array_keys($storedReviews), EditorRows::parseAction($_POST['editor_action'] ?? null), $preset);

/**
 * How a review's picture sits in its frame, and why a part of it was refused
 * (Responsive Media). Worked out once per row.
 *
 * @return array{0: ResponsiveImage, 1: array<string, string>}
 */
$presentations = [];
$presentationOf = static function (array $row) use (&$presentations, $storedReviews, $imageSlot): array {
    $storedReview = $storedReviews[$row['id']] ?? null;

    return $presentations[$row['key']] ??= ResponsiveImage::fromRequest(
        $row['fields'],
        $imageSlot,
        $storedReview !== null ? ResponsiveImage::fromRow($storedReview, $imageSlot) : new ResponsiveImage()
    );
};

/** A posted picture id, resolved: the library's picture, or null. */
$pictureId = static function (mixed $posted): ?int {
    $posted = is_scalar($posted) ? trim((string) $posted) : '';

    return ctype_digit($posted) ? ReviewsContent::picture((int) $posted)?->id : null;
};

/** A review's own checks besides its words: stars, date, source address, picture and its presentation. */
$reviewProblems = static function (array $row) use ($pictureId, $presentationOf): array {
    $problems = [];
    $fields = $row['fields'];

    $rating = trim((string) ($fields['rating'] ?? ''));
    if ($rating !== '' && ReviewsContent::rating($rating) === null) {
        $problems['rating'] = AdminTranslator::trans('block_reviews.error_rating');
    }

    $date = trim((string) ($fields['review_date'] ?? ''));
    if ($date !== '' && ReviewsContent::date($date) === null) {
        $problems['review_date'] = AdminTranslator::trans('block_reviews.error_date');
    }

    $sourceProblem = SafeUrl::optionalFieldMessage((string) ($fields['source_url'] ?? ''), SafeUrl::SCHEMES_WEB);
    if ($sourceProblem !== null) {
        $problems['source_url'] = $sourceProblem;
    }

    $media = trim((string) ($fields['media_id'] ?? ''));
    if ($media !== '' && $media !== '0' && $pictureId($media) === null) {
        $problems['media_id'] = AdminTranslator::trans('block_reviews.error_image');
    }

    foreach ($presentationOf($row)[1] as $part => $message) {
        $problems['presentation.' . $part] = $message;
    }

    return $problems;
};

/** What is the same in every language of a review, as the repository stores it. */
$valuesOf = static function (array $row) use ($pictureId): array {
    $fields = $row['fields'];

    return [
        'media_id' => $pictureId($fields['media_id'] ?? ''),
        'rating' => ReviewsContent::rating($fields['rating'] ?? null),
        'review_date' => ReviewsContent::date($fields['review_date'] ?? null)?->format('Y-m-d'),
        'source_url' => SafeUrl::normalise((string) ($fields['source_url'] ?? '')),
    ];
};

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    $fieldErrors += EditorChildList::wordErrors(ReviewsContent::TABLE, $languageCode, $words);
    $reviewErrors = $reviews->problems($languageCode, $reviewProblems);

    foreach ($fieldErrors as $message) {
        if (!in_array($message, $errors, true)) {
            $errors[] = $message;
        }
    }
    array_push($errors, ...$reviews->summary($reviewErrors, AdminTranslator::trans('block_reviews.review')));
    $fieldErrors += $reviewErrors;
}

// The featured review, by the key of its row on this form. Only a row that
// stays counts; anything else is "no choice" (the first review shows).
$featuredKey = is_scalar($_POST['featured'] ?? null) ? trim((string) $_POST['featured']) : '';
$featuredRow = null;
foreach ($reviews->rows() as $row) {
    if ($row['key'] === $featuredKey && !$reviews->isDropped($row)) {
        $featuredRow = $row;
    }
}

// A refused save shows each review's picture presentation as understood.
$oldReviews = $reviews->old();
foreach ($oldReviews as $index => $oldReview) {
    if (isset($presentations[$oldReview['key']])) {
        $oldReviews[$index]['fields'] = $presentations[$oldReview['key']][0]->toRow($imageSlot) + $oldReview['fields'];
    }
}

$old = ['language_code' => $languageCode] + $words + $settings + [
    'featured' => $featuredRow !== null ? $featuredRow['key'] : '',
    'link_type' => $linkType,
    'link_target' => array_map('intval', array_filter($postedTargets, 'is_scalar')),
    'link_url' => $linkUrl,
    'button_style_id' => is_scalar($_POST['button_style_id'] ?? null) ? (string) $_POST['button_style_id'] : '',
    'reviews' => $oldReviews,
];

if ($errors !== []) {
    $_SESSION['admin_reviews_errors'] = $errors;
    $_SESSION['admin_reviews_field_errors'] = $fieldErrors;
    $_SESSION['admin_reviews_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The choices, the button, the heading in this language and every
    // review are one save.
    $db->beginTransaction();

    $repository->updateSettings($blockId, $settings + [
        'link_type' => $link['link_type'],
        'link_target_id' => $link['link_target_id'],
        // "Geen knop" stores no address either (LinkChoice::storedType()).
        'link_url' => $link['link_type'] === null || $linkUrl === '' ? null : $linkUrl,
    ]);
    BlockLocalization::save(ReviewsContent::TABLE, $blockId, $languageCode, $words);
    (new ButtonStyleRepository())->saveChoice(ReviewsContent::TABLE, 'button_style_id', $blockId, $buttonStyle);

    $presentationRepository = new ResponsiveImageRepository();
    $created = $reviews->save(
        $languageCode,
        static function (array $row) use ($repository, $blockId, $valuesOf, $presentationRepository, $imageSlot, $presentationOf): int {
            $id = $repository->createItem($blockId, $valuesOf($row));
            $presentationRepository->save(ReviewsContent::ITEMS, $id, $imageSlot, $presentationOf($row)[0]);

            return $id;
        },
        static function (int $id, array $row) use ($repository, $valuesOf, $presentationRepository, $imageSlot, $presentationOf): void {
            $repository->updateItem($id, $valuesOf($row));
            $presentationRepository->save(ReviewsContent::ITEMS, $id, $imageSlot, $presentationOf($row)[0]);
        },
        static fn (int $id) => $repository->deleteItem($id),
        static fn (array $order) => $repository->reorderItems($blockId, $order)
    );

    // Only now does a new review have an id.
    $featuredId = null;
    if ($featuredRow !== null) {
        $featuredId = $featuredRow['id'] > 0 ? $featuredRow['id'] : ($created[$featuredRow['key']] ?? null);
    }
    $repository->updateSettings($blockId, ['featured_item_id' => $featuredId]);

    // A new block joins its page now, in this save's transaction
    // (App\Service\Blocks\ContentBlockDrafts); an existing one is found.
    $placed = ContentBlockDrafts::place('reviews', $blockId);
    $db->commit();
    ReviewsContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-reviews.php] ' . $e->getMessage());

    $_SESSION['admin_reviews_errors'] = [\App\Service\ContentOwners\OwnerContentGuard::messageFor($e) ?? AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_reviews_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . ContentBlockAccess::afterSaveUrl($placed, $redirect));
exit;
