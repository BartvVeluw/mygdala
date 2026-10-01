<?php

/**
 * POST /api/admin/update-hover-card-grid.php
 *
 * Saves the WHOLE editor of one Hover kaarten grid
 * (admin/hover-card-grid.php?section=...) in one request: the heading, the
 * choices and the cards — their pictures, words and links, their order, new
 * ones and the ones marked for removal. One form, one save (PAGE-EDITOR.md,
 * "Eén formulier per blok-editor"). The same `<page>:<key>` gate as
 * api/admin/update-media-banner.php: the page must exist by its immutable
 * content_key AND the block's row must already exist
 * (App\Service\SectionRegistry::create()), before anything is read or written.
 *
 * `editor_action` = cards:up|down:<key> is the no-JavaScript path of a card's
 * ↑ and ↓ (App\Service\Blocks\EditorRows), performed on the posted cards
 * after validation and stored with everything else.
 *
 * WHAT IS CHECKED, and refused at its field rather than stored:
 *   - each choice (layout, shape, columns, overlay, effect, header_align):
 *     a word of its closed list (HoverCardGridContent::CHOICES). A form
 *     without the field keeps what is stored; an unknown word is refused.
 *   - a card's picture: required, and a picture of the Media Library
 *     (HoverCardGridContent::picture()); a video, a document or an id that
 *     names nothing is refused. The second picture is optional and follows
 *     the same rule; the same picture twice is stored once.
 *   - how a card's main picture sits in its frame (Responsive Media 2.0):
 *     App\Service\Media\ResponsiveImage::fromRequest(), refused part by
 *     part next to the card (`cards.<key>.presentation.<part>`) and written
 *     by App\Repository\ResponsiveImageRepository in the same transaction.
 *   - a card's link: App\Service\Routing\LinkChoice, the rule every block
 *     button shares. "Geen link" stores no address either (a type-less
 *     address would read as a link again, LinkChoice::storedType()).
 *   - A LINK NEEDS A NAME: a card with a link must have a title or a link
 *     label in the default language, or the one link that covers the card
 *     would have no words for a screen reader. The default language decides:
 *     while a translation is on screen, the stored default-language words
 *     are what a card has.
 *   - the words: none is required; BlockLocalization checks their length.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing and
 * hands every typed value back, with each message next to its field.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the heading's and every stored
 * card's words are the language named in `language_code`, which must be an
 * active language of the website registry. A NEW card is written in the
 * default language, and a removed one takes its words in every language
 * along, both through App\Service\Blocks\EditorChildList.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\HoverCardGridRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
use App\Service\Csrf;
use App\Service\HoverCardGridContent;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Media\ResponsiveImage;
use App\Service\Routing\LinkChoice;
use App\Repository\ResponsiveImageRepository;
use App\Repository\ButtonStyleRepository;
use App\Service\Theme\ButtonStyles;

AdminAuth::requireLoginForApi();
\App\Service\ContentOwners\ContentBlockAccess::requireAnyForApi();

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

$repository = new HoverCardGridRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi($pageSlug) === null
    || ($grid = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$gridId = (int) $grid['id'];
$redirect = '/admin/hover-card-grid.php?section=' . urlencode($sectionParam);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$defaultLanguage = BlockLocalization::defaultLanguage();

$fieldErrors = [];

// ---------------------------------------------------------------- choices
// Each a word from its closed list. A form without the field keeps what is
// stored; a word that is not on the list is refused at its field.
$stored = HoverCardGridContent::settings($grid);
$settings = [];
foreach (HoverCardGridContent::CHOICES as $name => $list) {
    if (!array_key_exists($name, $_POST)) {
        $settings[$name] = $stored[$name];
        continue;
    }

    $value = is_scalar($_POST[$name]) ? trim((string) $_POST[$name]) : null;
    if ($value === null || !in_array($value, $list, true)) {
        $fieldErrors[$name] = AdminTranslator::trans('block_hover_cards.error_choice');
        $value = $stored[$name];
    }
    $settings[$name] = $value;
}

// ------------------------------------------------------------------ words
// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields(HoverCardGridContent::TABLE)) as $field) {
    $words[$field] = is_scalar($_POST[$field] ?? null) ? trim((string) $_POST[$field]) : '';
}

// ------------------------------------------------------------------ cards
$storedCards = [];
foreach ($repository->findItemsByGridId($gridId) as $item) {
    $storedCards[(int) $item['id']] = $item;
}

// The link's destination field (admin/_link_target_field.php) posts one list
// of items per kind, `link_target[<kind>]`. Only the chosen kind's counts,
// and a row's fields are plain values, so it becomes one value here.
$postedCards = is_array($_POST['cards'] ?? null) ? $_POST['cards'] : [];
foreach ($postedCards as $key => $fields) {
    if (is_array($fields)) {
        $type = is_string($fields['link_type'] ?? null) ? $fields['link_type'] : LinkChoice::NONE;
        $targets = is_array($fields['link_target'] ?? null) ? $fields['link_target'] : [];
        $postedCards[$key]['link_type'] = $type;
        $postedCards[$key]['link_target'] = (string) ($targets[$type] ?? '');
    }
}
$post = ['cards' => $postedCards] + $_POST;
// The kind of link and the picture's presentation arrive already chosen on
// a new card: alone they do not make it a card.
$imageSlot = HoverCardGridContent::imageSlot();
$preset = ['link_type', 'button_style_id'];
foreach (['presentation', 'focus_x', 'focus_y', 'zoom', 'mobile_source', 'mobile_focus_x', 'mobile_focus_y', 'mobile_zoom', 'fit', 'mobile_fit'] as $part) {
    $preset[] = $imageSlot->column($part);
}
$cards = EditorChildList::fromRequest($post, 'cards', 'hover_card_grid_items', array_keys($storedCards), EditorRows::parseAction($_POST['editor_action'] ?? null), $preset);

/**
 * How a card's main picture sits in its frame, and why a part of it was
 * refused (Responsive Media 2.0). Worked out once per row: validation, the
 * refused form and the save all ask.
 *
 * @return array{0: ResponsiveImage, 1: array<string, string>}
 */
$presentations = [];
$presentationOf = static function (array $row) use (&$presentations, $storedCards, $imageSlot): array {
    $storedCard = $storedCards[$row['id']] ?? null;

    return $presentations[$row['key']] ??= ResponsiveImage::fromRequest(
        $row['fields'],
        $imageSlot,
        $storedCard !== null ? ResponsiveImage::fromRow($storedCard, $imageSlot) : new ResponsiveImage()
    );
};

/**
 * The link of one card, by the rule every block button shares. Worked out
 * once per row: validation and the save both ask.
 */
$links = [];
$linkOf = static function (array $row) use (&$links, $storedCards): array {
    $storedCard = $storedCards[$row['id']] ?? [];

    return $links[$row['key']] ??= LinkChoice::fromRequest(
        (string) ($row['fields']['link_type'] ?? LinkChoice::NONE),
        $row['fields']['link_target'] ?? null,
        (string) ($row['fields']['link_url'] ?? ''),
        (string) ($storedCard['link_type'] ?? ''),
        (int) ($storedCard['link_target_id'] ?? 0)
    );
};

/** A posted picture id, resolved: the library's picture, or null. */
$pictureId = static function (mixed $posted): ?int {
    $posted = is_scalar($posted) ? trim((string) $posted) : '';

    return ctype_digit($posted) ? HoverCardGridContent::picture((int) $posted)?->id : null;
};

/**
 * The link's button style (Button Styles 2.0): '' = the card's own text link,
 * else a style that exists. A forged id comes back with an error and the
 * stored choice.
 *
 * @return array{0: ?int, 1: ?string}
 */
$styleOf = static function (array $row) use ($storedCards): array {
    $storedCard = $storedCards[$row['id']] ?? [];

    return ButtonStyles::choiceFromRequest($row['fields'], 'button_style_id', ButtonStyles::storedChoice($storedCard['button_style_id'] ?? null));
};

/** A card's own checks besides its words' lengths: its pictures, their presentation and its link. */
$cardProblems = static function (array $row) use ($pictureId, $linkOf, $presentationOf, $styleOf, $languageCode, $defaultLanguage): array {
    $problems = [];

    $styleError = $styleOf($row)[1];
    if ($styleError !== null) {
        $problems['button_style_id'] = $styleError;
    }

    if ($pictureId($row['fields']['media_id'] ?? '') === null) {
        $problems['media_id'] = AdminTranslator::trans('block_hover_cards.error_image');
    }

    foreach ($presentationOf($row)[1] as $part => $message) {
        $problems['presentation.' . $part] = $message;
    }

    $hover = trim((string) ($row['fields']['hover_media_id'] ?? ''));
    if ($hover !== '' && $hover !== '0' && $pictureId($hover) === null) {
        $problems['hover_media_id'] = AdminTranslator::trans('block_hover_cards.error_hover_image');
    }

    $link = $linkOf($row);
    if ($link['error'] !== null) {
        $problems['link_url'] = $link['error'];
    } elseif ($link['link_type'] !== null) {
        // The default language decides whether the card has words: typed
        // when that language is on screen or the card is new, else stored.
        $fields = BlockLocalization::fields(HoverCardGridContent::ITEMS);
        $typed = $row['id'] === 0 || $languageCode === $defaultLanguage;
        $word = static fn (string $field): string => $typed
            ? $fields[$field]->normalise($row['fields'][$field] ?? '')
            : BlockLocalization::raw(HoverCardGridContent::ITEMS, $row['id'], $field, $defaultLanguage);

        if ($word('title') === '' && $word('link_label') === '') {
            $problems['link_label'] = AdminTranslator::trans('block_hover_cards.error_link_name');
        }
    }

    return $problems;
};

/**
 * What is the same in every language of a card, as the repository stores
 * it. "Geen link" stores no address either.
 */
$valuesOf = static function (array $row) use ($pictureId, $linkOf): array {
    $link = $linkOf($row);
    $picture = $pictureId($row['fields']['media_id'] ?? '');
    $hover = $pictureId($row['fields']['hover_media_id'] ?? '');

    return [
        'media_id' => $picture,
        'hover_media_id' => $hover !== $picture ? $hover : null,
        'link_type' => $link['link_type'],
        'link_target_id' => $link['link_target_id'],
        'link_url' => $link['link_type'] === null ? '' : trim((string) ($row['fields']['link_url'] ?? '')),
    ];
};

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // BlockLocalization::problems(), as a message per field.
    $fieldErrors += EditorChildList::wordErrors(HoverCardGridContent::TABLE, $languageCode, $words);
    $cardErrors = $cards->problems($languageCode, $cardProblems);

    // One line per problem at the top, the same line next to its field.
    foreach ($fieldErrors as $message) {
        if (!in_array($message, $errors, true)) {
            $errors[] = $message;
        }
    }
    array_push($errors, ...$cards->summary($cardErrors, AdminTranslator::trans('block_hover_cards.card')));
    $fieldErrors += $cardErrors;
}

// A refused save shows each card's presentation as it was understood: the
// phone's picture only when "Eigen afbeelding" was chosen, and so on.
$oldCards = $cards->old();
foreach ($oldCards as $index => $oldCard) {
    if (isset($presentations[$oldCard['key']])) {
        $oldCards[$index]['fields'] = $presentations[$oldCard['key']][0]->toRow($imageSlot) + $oldCard['fields'];
    }
}

$old = ['language_code' => $languageCode] + $words + $settings + ['cards' => $oldCards];

if ($errors !== []) {
    $_SESSION['admin_hover_cards_errors'] = $errors;
    $_SESSION['admin_hover_cards_field_errors'] = $fieldErrors;
    $_SESSION['admin_hover_cards_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The choices, the heading in this language and every card are one save.
    $db->beginTransaction();

    $repository->updateSettings($gridId, $settings);
    BlockLocalization::save(HoverCardGridContent::TABLE, $gridId, $languageCode, $words);

    $presentationRepository = new ResponsiveImageRepository();
    $styleRepository = new ButtonStyleRepository();
    $cards->save(
        $languageCode,
        static function (array $row) use ($repository, $gridId, $valuesOf, $presentationRepository, $imageSlot, $presentationOf, $styleRepository, $styleOf): int {
            $id = $repository->createItem($gridId, $valuesOf($row));
            $presentationRepository->save(HoverCardGridContent::ITEMS, $id, $imageSlot, $presentationOf($row)[0]);
            $styleRepository->saveChoice(HoverCardGridContent::ITEMS, 'button_style_id', $id, $styleOf($row)[0]);

            return $id;
        },
        static function (int $id, array $row) use ($repository, $valuesOf, $presentationRepository, $imageSlot, $presentationOf, $styleRepository, $styleOf): void {
            $repository->updateItem($id, $valuesOf($row));
            $presentationRepository->save(HoverCardGridContent::ITEMS, $id, $imageSlot, $presentationOf($row)[0]);
            $styleRepository->saveChoice(HoverCardGridContent::ITEMS, 'button_style_id', $id, $styleOf($row)[0]);
        },
        static fn (int $id) => $repository->deleteItem($id),
        static fn (array $order) => $repository->reorderItems($gridId, $order)
    );

    // A new block joins its page now, in this save's transaction
    // (App\Service\Blocks\ContentBlockDrafts); an existing one is found.
    $placed = \App\Service\Blocks\ContentBlockDrafts::place('hover_card_grid', (int) $grid['id']);
    $db->commit();
    HoverCardGridContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-hover-card-grid.php] ' . $e->getMessage());

    $_SESSION['admin_hover_cards_errors'] = [\App\Service\ContentOwners\OwnerContentGuard::messageFor($e) ?? AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_hover_cards_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . \App\Service\ContentOwners\ContentBlockAccess::afterSaveUrl($placed, $redirect));
exit;
