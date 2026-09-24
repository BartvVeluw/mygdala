<?php

/**
 * POST /api/admin/update-text-image-split-section.php
 *
 * Saves the WHOLE editor of one Tekst met afbeelding block
 * (admin/text-image-split.php?section=...) in one request: whether it shows,
 * and its items — each with its words, its picture, its layout and its button
 * address — their order, new ones and the ones marked for removal. One form,
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
 * button (label and URL), exactly what makes TextImageSplitContent show it:
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
 * THE LAYOUT is four closed lists of keys (TextImageSplitContent::layout());
 * anything else becomes the default. A half-filled button is not refused, as
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
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

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
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new TextImageSplitRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
}

$repository = new TextImageSplitRepository();
$redirect = '/admin/text-image-split.php?section=' . urlencode($sectionKey);

$isActive = isset($_POST['is_active']);

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
// The layout radios arrive already chosen on a new item: they alone do not
// make it an item.
$action = EditorRows::parseAction($_POST['editor_action'] ?? null);
$preset = ['image_side', 'image_column', 'image_height', 'image_focus'];
$items = EditorChildList::fromRequest($post, 'items', 'text_image_split_items', array_keys($storedItems), $action, $preset);

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

/** An item's own checks besides its words: a known picture, and something to show. */
$itemProblems = static function (array $row) use ($pictureOf, $languageCode, $defaultLanguage): array {
    $posted = $row['fields']['media_id'] ?? '';
    if ($posted !== '' && $posted !== '0' && BlockImage::fromRequest($posted)['media_id'] === null) {
        return ['media_id' => AdminTranslator::trans('editor_rows.error_media_unknown')];
    }

    if ($pictureOf($row)['image_path'] !== '') {
        return [];
    }

    // The default language decides whether the item has words: typed when
    // that language is on screen or the item is new, else as stored.
    $fields = BlockLocalization::fields('text_image_split_items');
    $typed = $row['id'] === 0 || $languageCode === $defaultLanguage;
    $words = static fn (string $field): string => $typed
        ? $fields[$field]->normalise($row['fields'][$field] ?? '')
        : BlockLocalization::raw('text_image_split_items', $row['id'], $field, $defaultLanguage);

    if ($words('eyebrow') !== '' || $words('title') !== '' || $words('body') !== ''
        || ($words('button_label') !== '' && trim((string) ($row['fields']['button_url'] ?? '')) !== '')
    ) {
        return [];
    }

    return ['title' => AdminTranslator::trans('block_textimage.error_item_leeg')];
};

/** What is the same in every language of an item, as the repository stores it. */
$valuesOf = static fn (array $row): array => $pictureOf($row)
    + TextImageSplitContent::layout($row['fields'])
    + ['button_url' => trim((string) ($row['fields']['button_url'] ?? ''))];

$errors = [];
$fieldErrors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    $fieldErrors = $items->problems($languageCode, $itemProblems);
    $errors = $items->summary($fieldErrors, AdminTranslator::trans('block_textimage.item'));
}

$old = ['language_code' => $languageCode, 'is_active' => $isActive, 'items' => $items->old()];

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

    $items->save(
        $languageCode,
        static fn (array $row): int => $repository->createItem($sectionId, $valuesOf($row)),
        static fn (int $id, array $row) => $repository->updateItem($id, $valuesOf($row)),
        static fn (int $id) => $repository->deleteItem($id),
        static fn (array $order) => $repository->reorderItems($sectionId, $order)
    );

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

header('Location: ' . $redirect . '&saved=1');
exit;
