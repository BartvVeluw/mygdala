<?php

/**
 * POST /api/admin/update-text-image-split-section.php
 *
 * Saves the WHOLE editor of one Tekst met afbeelding block
 * (admin/text-image-split.php?section=...) in one request: whether it shows,
 * its own optional title and lead above all items (the words of the block
 * row, in the language on screen, only their length checked), and its items — each with its words, its picture, its layout and its button's
 * destination (LinkChoice) — their order, new ones and the ones marked for removal. One form,
 * one save (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is no
 * endpoint per item.
 *
 * `editor_action` = items:up|down:<key> is the no-JavaScript path of an
 * item's ↑ and ↓ (App\Service\Blocks\EditorRows), performed on the posted
 * rows after validation and stored with everything else.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing and
 * hands every typed value back, with each message next to its field.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): every stored item's words are the
 * language named in `language_code`, which must be an active language of the
 * website registry; which fields exist and how long they may be comes from
 * TextImageSplitBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written. A
 * NEW item is written in the default language, and a removed one takes its
 * words in every language along, both through
 * App\Service\Blocks\EditorChildList.
 *
 * AN ITEM NEEDS text or a picture — an eyebrow, a title, a body or a whole
 * button (label and destination), exactly what makes TextImageSplitContent show it:
 * a completely empty item is refused with a message on the item, not silently
 * dropped (a new item with nothing typed or chosen is no item at all, as in
 * every list). What counts is the default language, which decides whether an
 * item is there: while a translation is on screen, the stored
 * default-language words are what an item has.
 *
 * THE PICTURE is a Media Library item, resolved here from the posted id
 * (App\Service\Media\BlockImage); it is optional, and "Wissen" in the picker
 * removes it. A stored item whose picture predates the library has a path
 * and no item, which the picker cannot show: an empty field keeps that
 * picture. Removing an item removes the row, never the library's file
 * (MEDIA.md). The alt text follows the library's until it is changed
 * (BlockImage::ownAltInRows()).
 *
 * THE LAYOUT is three closed lists of keys (TextImageSplitContent::layout());
 * anything else becomes the default. How the picture sits in its frame on a
 * large screen and on a phone (Responsive Media 2.0: focus point, fit, a
 * phone's own picture, point, fit and height) is each row's
 * App\Service\Media\ResponsiveImage::fromRequest(), refused part by part
 * next to the item (`items.<key>.presentation.<part>`), and written by
 * App\Repository\ResponsiveImageRepository in the same transaction. A half-filled button is not refused, as
 * it never was: the editor says a label without a URL, or a URL without a
 * label, shows no button, and TextImageSplitContent drops it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
use App\Service\Blocks\TranslatableField;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Media\BlockImage;
use App\Service\Media\ResponsiveImage;
use App\Repository\ResponsiveImageRepository;
use App\Repository\ButtonStyleRepository;
use App\Service\Theme\ButtonStyles;
use App\Service\Routing\LinkChoice;
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

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

$sectionKey = (string) ($_POST['section'] ?? '');
$section = TextImageSplitContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi($dynPageSlug) === null
        || (new TextImageSplitRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
} else {
    // A fixed block of one of the site's own pages.
    \App\Service\ContentOwners\ContentBlockAccess::requirePagesForApi();
}

$repository = new TextImageSplitRepository();
$redirect = '/admin/text-image-split.php?section=' . urlencode($sectionKey);

$isActive = isset($_POST['is_active']);

// The block's own heading: exactly the fields its row declares, never a name
// taken from the request.
$blockWords = [];
foreach (array_keys(BlockLocalization::fields('text_image_splits')) as $field) {
    $blockWords[$field] = is_scalar($_POST[$field] ?? null) ? trim((string) $_POST[$field]) : '';
}

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$defaultLanguage = BlockLocalization::defaultLanguage();

// The items of THIS section; a key naming any other row is dropped.
$stored = $repository->findBySlugAndKey($section['page_slug'], $section['section_key']);
$storedId = $stored === null ? 0 : (int) $stored['id'];
$storedItems = [];
foreach ($storedId > 0 ? $repository->findItemsBySectionId($storedId) : [] as $item) {
    $storedItems[(int) $item['id']] = $item;
}

// The editor shows the library's alt text in each item's alt field; sent
// back unchanged it stays "the library's" (BlockImage::ownAlt(), MEDIA.md).
$post = ['items' => BlockImage::ownAltInRows($_POST['items'] ?? null, $languageCode === $defaultLanguage)] + $_POST;
// The button's destination field (admin/_link_target_field.php) posts one
// list of items per kind, `button_link_target[<kind>]`. Only the chosen kind's
// counts, and a row's fields are plain values, so it becomes one value here.
if (is_array($post['items'])) {
    foreach ($post['items'] as $key => $fields) {
        if (is_array($fields)) {
            // A request without a kind (from before the kind existed) means
            // what it always meant: a button when both its label and its
            // address are filled, and otherwise none, without a message.
            if (!isset($fields['button_link_type'])) {
                $hasBoth = trim((string) (is_string($fields['button_url'] ?? null) ? $fields['button_url'] : '')) !== ''
                    && trim((string) (is_string($fields['button_label'] ?? null) ? $fields['button_label'] : '')) !== '';
                $fields['button_link_type'] = $hasBoth ? LinkChoice::URL : LinkChoice::NONE;
                $post['items'][$key]['button_link_type'] = $fields['button_link_type'];
            }
            $targets = is_array($fields['button_link_target'] ?? null) ? $fields['button_link_target'] : [];
            $post['items'][$key]['button_link_target'] = (string) ($targets[(string) ($fields['button_link_type'] ?? '')] ?? '');
        }
    }
}
// The layout radios and the button's kind arrive already chosen on a new
// item: they alone do not make it an item.
$action = EditorRows::parseAction($_POST['editor_action'] ?? null);
$imageSlot = TextImageSplitContent::imageSlot();
$preset = ['image_side', 'image_column', 'image_height', 'button_link_type', 'button_style_id'];
// The picture's presentation arrives filled in on a new item too.
foreach (['presentation', 'focus_x', 'focus_y', 'zoom', 'mobile_source', 'mobile_focus_x', 'mobile_focus_y', 'mobile_zoom', 'fit', 'mobile_fit', 'mobile_height'] as $part) {
    $preset[] = $imageSlot->column($part);
}
$items = EditorChildList::fromRequest($post, 'items', 'text_image_split_items', array_keys($storedItems), $action, $preset);

/**
 * How an item's picture sits in its frame, and why a part of it was refused
 * (Responsive Media 2.0). Worked out once per row: validation, the refused
 * form and the save all ask.
 *
 * @return array{0: ResponsiveImage, 1: array<string, string>}
 */
$presentations = [];
$presentationOf = static function (array $row) use (&$presentations, $storedItems, $imageSlot): array {
    $storedItem = $storedItems[$row['id']] ?? null;

    return $presentations[$row['key']] ??= ResponsiveImage::fromRequest(
        $row['fields'],
        $imageSlot,
        $storedItem !== null ? ResponsiveImage::fromRow($storedItem, $imageSlot) : new ResponsiveImage()
    );
};

/**
 * The picture an item ends up with: the chosen library item, the stored
 * pre-library path when the field comes back empty, or none.
 *
 * @return array{media_id: int|null, image_path: string}
 */
$pictureOf = static function (array $row) use ($storedItems): array {
    $chosen = BlockImage::fromRequest($row['fields']['media_id'] ?? '');
    if ($chosen['media_id'] !== null) {
        return $chosen;
    }

    $storedItem = $storedItems[$row['id']] ?? null;
    if ($storedItem !== null && (int) ($storedItem['media_id'] ?? 0) === 0 && (string) ($storedItem['image_path'] ?? '') !== '') {
        return ['media_id' => null, 'image_path' => (string) $storedItem['image_path']];
    }

    return ['media_id' => null, 'image_path' => ''];
};

/**
 * The button's destination, by the rule every block button shares
 * (LinkChoice): nothing, an item of the website by id, or a typed address. A
 * kind whose module is off stays as stored when it comes back unchanged.
 * Worked out once per row: validation and the save both ask.
 */
$links = [];
$linkOf = static function (array $row) use (&$links, $storedItems): array {
    $storedItem = $storedItems[$row['id']] ?? [];

    return $links[$row['key']] ??= LinkChoice::fromRequest(
        (string) ($row['fields']['button_link_type'] ?? LinkChoice::NONE),
        $row['fields']['button_link_target'] ?? null,
        (string) ($row['fields']['button_url'] ?? ''),
        (string) ($storedItem['button_link_type'] ?? ''),
        (int) ($storedItem['button_link_target_id'] ?? 0)
    );
};

/**
 * The button's style (Button Styles 2.0): '' = the default, else a style that
 * exists. A forged id comes back with an error and the stored choice.
 *
 * @return array{0: ?int, 1: ?string}
 */
$styleOf = static function (array $row) use ($storedItems): array {
    $storedItem = $storedItems[$row['id']] ?? [];

    return ButtonStyles::choiceFromRequest($row['fields'], 'button_style_id', ButtonStyles::storedChoice($storedItem['button_style_id'] ?? null));
};

/** An item's own checks besides its words: a known picture, its presentation, a valid button, and something to show. */
$itemProblems = static function (array $row) use ($pictureOf, $linkOf, $presentationOf, $styleOf, $languageCode, $defaultLanguage): array {
    $posted = $row['fields']['media_id'] ?? '';
    if ($posted !== '' && $posted !== '0' && BlockImage::fromRequest($posted)['media_id'] === null) {
        return ['media_id' => AdminTranslator::trans('editor_rows.error_media_unknown')];
    }

    $presentationErrors = [];
    foreach ($presentationOf($row)[1] as $part => $message) {
        $presentationErrors['presentation.' . $part] = $message;
    }
    if ($presentationErrors !== []) {
        return $presentationErrors;
    }

    // The default language decides whether the item has words: typed when
    // that language is on screen or the item is new, else as stored.
    $fields = BlockLocalization::fields('text_image_split_items');
    $typed = $row['id'] === 0 || $languageCode === $defaultLanguage;
    $words = static fn (string $field): string => $typed
        ? $fields[$field]->normalise($row['fields'][$field] ?? '')
        : BlockLocalization::raw('text_image_split_items', $row['id'], $field, $defaultLanguage);

    $link = $linkOf($row);
    if ($link['error'] !== null) {
        return ['button_url' => $link['error']];
    }

    $styleError = $styleOf($row)[1];
    if ($styleError !== null) {
        return ['button_style_id' => $styleError];
    }

    // A chosen destination is a button, and a button needs its words in the
    // default language; a translation may stay empty and falls back to them.
    $hasButton = $link['link_type'] !== null;
    if ($hasButton && $typed && $words('button_label') === '') {
        return ['button_label' => AdminTranslator::trans('block_textimage.knoptekst_verplicht')];
    }

    if ($pictureOf($row)['image_path'] !== '') {
        return [];
    }

    if ($words('eyebrow') !== '' || $words('title') !== '' || $words('body') !== ''
        || ($words('button_label') !== '' && $hasButton)
    ) {
        return [];
    }

    return ['title' => AdminTranslator::trans('block_textimage.error_item_leeg')];
};

/**
 * What is the same in every language of an item, as the repository stores it.
 * "Geen knop" stores no address either: a row without a type but with an
 * address reads as an address (LinkChoice::storedType(), for the items from
 * before the type), so a leftover address would bring the button back.
 */
$valuesOf = static function (array $row) use ($pictureOf, $linkOf): array {
    $link = $linkOf($row);

    return $pictureOf($row)
        + TextImageSplitContent::layout($row['fields'])
        + [
            'button_link_type' => $link['link_type'],
            'button_link_target_id' => $link['link_target_id'],
            'button_url' => $link['link_type'] === null ? '' : trim((string) ($row['fields']['button_url'] ?? '')),
        ];
};

$errors = [];
$fieldErrors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    $fieldErrors = EditorChildList::wordErrors('text_image_splits', $languageCode, $blockWords);
    $errors = array_values(array_unique(array_values($fieldErrors)));
    $itemErrors = $items->problems($languageCode, $itemProblems);
    array_push($errors, ...$items->summary($itemErrors, AdminTranslator::trans('block_textimage.item')));
    $fieldErrors += $itemErrors;
}

// A refused save shows each item's presentation as it was understood: the
// phone's picture only when "Eigen afbeelding" was chosen, and so on.
$oldItems = $items->old();
foreach ($oldItems as $index => $oldItem) {
    if (isset($presentations[$oldItem['key']])) {
        $oldItems[$index]['fields'] = $presentations[$oldItem['key']][0]->toRow($imageSlot) + $oldItem['fields'];
    }
}

$old = ['language_code' => $languageCode, 'is_active' => $isActive] + $blockWords + ['items' => $oldItems];

if ($errors !== []) {
    $_SESSION['admin_tis_errors'] = $errors;
    $_SESSION['admin_tis_field_errors'] = $fieldErrors;
    $_SESSION['admin_tis_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The block row and its items are one save.
    $db->beginTransaction();

    $repository->upsertSection($section['page_slug'], $section['section_key'], ['is_active' => $isActive]);
    $sectionId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];
    // A form from before the heading sends neither field: it keeps the stored words.
    if (array_key_exists('title', $_POST) || array_key_exists('lead', $_POST)) {
        BlockLocalization::save('text_image_splits', $sectionId, $languageCode, $blockWords);
    }

    $presentationRepository = new ResponsiveImageRepository();
    $styleRepository = new ButtonStyleRepository();
    $items->save(
        $languageCode,
        static function (array $row) use ($repository, $sectionId, $valuesOf, $presentationRepository, $imageSlot, $presentationOf, $styleRepository, $styleOf): int {
            $id = $repository->createItem($sectionId, $valuesOf($row));
            $presentationRepository->save('text_image_split_items', $id, $imageSlot, $presentationOf($row)[0]);
            $styleRepository->saveChoice('text_image_split_items', 'button_style_id', $id, $styleOf($row)[0]);

            return $id;
        },
        static function (int $id, array $row) use ($repository, $valuesOf, $presentationRepository, $imageSlot, $presentationOf, $styleRepository, $styleOf): void {
            $repository->updateItem($id, $valuesOf($row));
            $presentationRepository->save('text_image_split_items', $id, $imageSlot, $presentationOf($row)[0]);
            $styleRepository->saveChoice('text_image_split_items', 'button_style_id', $id, $styleOf($row)[0]);
        },
        static fn (int $id) => $repository->deleteItem($id),
        static fn (array $order) => $repository->reorderItems($sectionId, $order)
    );

    // A new block joins its page now, in this save's transaction
    // (App\Service\Blocks\ContentBlockDrafts); an existing one is found.
    $placed = \App\Service\Blocks\ContentBlockDrafts::place('text_image_split', $sectionId);
    $db->commit();
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-text-image-split-section.php] ' . $e->getMessage());

    $_SESSION['admin_tis_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_tis_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . \App\Service\ContentOwners\ContentBlockAccess::afterSaveUrl($placed, $redirect));
exit;
