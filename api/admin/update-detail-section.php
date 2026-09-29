<?php

/**
 * POST /api/admin/update-detail-section.php
 *
 * Saves the WHOLE editor of one Detailsectie block
 * (admin/detail-section.php?section=...) in one request: anchor + nav label,
 * title, lead, rich body, image position, closing note, CTA and visibility,
 * the main image with its alt text, the "kenmerken" and the gallery — their
 * words, order, new ones and the ones marked for removal. One form, one save
 * (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is no separate
 * main-image, point or image save any more.
 *
 * `editor_action` = points|images:up|down:<key> is the no-JavaScript path
 * of a row's ↑ and ↓ (App\Service\Blocks\EditorRows), performed on the
 * posted rows after validation and stored with everything else.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing and
 * hands every typed value back, with each message next to its field.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the section's words (the main
 * image's alt text among them) and every stored row's words are the
 * language named in `language_code`, which must be an active language of
 * the website registry. Which fields exist, how long they may be and which
 * are required in the default language comes from
 * DetailSectionBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written.
 * The body is rich text, and BlockLocalization sanitizes it through
 * RichTextSanitizer before it reaches the database because the block
 * declares it rich (the Quill toolbar is convenience, never the security
 * boundary). A NEW point or image is written in the default language, and a
 * removed one takes its words in every language along, both through
 * App\Service\Blocks\EditorChildList. The anchor, the image position, the
 * CTA URL, the media and is_active are the same in every language.
 *
 * THE MAIN IMAGE is the Media Library item in `main_media_id`, or none.
 * Only a form that carries the picker can empty it: an empty field on a
 * section that had an item clears the reference, and an image that
 * predates the library (a path and no item) goes only when
 * `remove_legacy_main_image` is ticked. Clearing it removes its alt text in
 * every language, in the same transaction: an alt text describes the image
 * it was written for, never the next one chosen. The file stays; it belongs
 * to the Media Library (MEDIA.md). A gallery image is an item too; a new one
 * needs one, and a stored one whose field comes back empty keeps what it
 * shows.
 *
 * THE ANCHOR (Detailsectie 2.0) is stored in its one shape
 * (App\Service\Blocks\AnchorName: "#Hout " becomes "hout"); something that
 * leaves no anchor is refused, and so is an anchor another Detailsectie on
 * the same page already has — two sections cannot both be "#hout".
 *
 * A GALLERY ITEM is a library picture or an item of the site
 * (App\Service\Media\LinkedImages): `source` is 'media' or a kind, and
 * `source_<kind>` the chosen id. A kind must be one the picker offers and the
 * id one of its choices — except the choice the row already has, which is
 * kept as it is even when it can no longer be offered (gone, not public, a
 * module that is off), as the Destination Picker keeps one (LinkChoice). Only
 * the kind and the id are stored; the item's picture, name and address are
 * read live. A row that switches to a library picture needs one.
 *
 * THE CTA shows only with a label in the default language and a URL
 * (DetailSectionContent). A half-filled CTA is not refused: the editor says
 * that one of the two alone shows no button, as it always did.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\AnchorName;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Media\BlockImage;
use App\Service\Media\LinkedImages;
use App\Service\Media\ResponsiveImage;
use App\Repository\ResponsiveImageRepository;
use App\Service\Routing\LinkTargets;
use App\Repository\DetailSectionRepository;

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

$repository = new DetailSectionRepository();

// Never trust an arbitrary page_slug:section_key pair from the request: the
// page must exist (by its immutable pages.content_key) and so must the
// content row App\Service\SectionRegistry::create() made for it.
if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi($pageSlug) === null
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode($sectionParam);
$sectionId = (int) $section['id'];

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('detail_sections')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

// Only a form that carries the main image's alt text can change it: the
// words this language already has for it go along unchanged otherwise,
// because BlockLocalization::save() writes a whole language at a time.
if (!array_key_exists('main_image_alt', $_POST) && $languageCode !== '') {
    $words['main_image_alt'] = BlockLocalization::raw('detail_sections', $sectionId, 'main_image_alt', $languageCode);
}

$imagePosition = (string) ($_POST['image_position'] ?? 'image_right');
if (!in_array($imagePosition, DetailSectionContent::IMAGE_POSITIONS, true)) {
    $imagePosition = 'image_right';
}

// What stands above the title (App\Service\Blocks\LabelMode): refused when
// it is not one of the section's modes; a form without it keeps the stored
// one. The own words are the translated field `label`, among $words.
$labelMode = DetailSectionContent::labelMode($section['label_mode'] ?? null);
$labelModeRefused = false;
if (array_key_exists('label_mode', $_POST)) {
    $labelModeRefused = !\App\Service\Blocks\LabelMode::isValid($_POST['label_mode'], \App\Service\Blocks\LabelMode::SECTION_MODES);
    $labelMode = $labelModeRefused ? $labelMode : (string) $_POST['label_mode'];
}

$typedAnchor = (string) ($_POST['anchor'] ?? '');
$settings = [
    'anchor' => AnchorName::normalise($typedAnchor),
    'image_position' => $imagePosition,
    'label_mode' => $labelMode,
    'cta_url' => trim((string) ($_POST['cta_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

// ---------------------------------------------------------------- the main image

$mainPosted = trim((string) ($_POST['main_media_id'] ?? ''));
$mainChosen = BlockImage::fromRequest($mainPosted === '' ? null : $mainPosted);
$inDefaultLanguage = $languageCode === BlockLocalization::defaultLanguage();

// The editor shows the library's alt text in the alt fields; sent back
// unchanged it stays "the library's" (BlockImage::ownAlt(), MEDIA.md).
if (array_key_exists('main_image_alt', $_POST)) {
    $words['main_image_alt'] = BlockImage::ownAlt($words['main_image_alt'], $mainChosen['media_id'], $inDefaultLanguage);
}
$post = ['images' => BlockImage::ownAltInRows($_POST['images'] ?? null, $inDefaultLanguage)] + $_POST;
$hadMainMedia = (int) ($section['main_media_id'] ?? 0) > 0;
$legacyMainOnly = !$hadMainMedia && trim((string) ($section['main_image_path'] ?? '')) !== '';
$clearMainImage = $mainChosen['media_id'] === null
    && (($hadMainMedia && array_key_exists('main_media_id', $_POST) && $mainPosted === '')
        || ($legacyMainOnly && isset($_POST['remove_legacy_main_image'])));

// ---------------------------------------------------------------- the lists

$idsOf = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['id'], $rows);
$action = EditorRows::parseAction($_POST['editor_action'] ?? null);
$points = EditorChildList::fromRequest($_POST, 'points', 'detail_section_points', $idsOf($repository->findPointsBySectionId($sectionId)), $action);
// A gallery item's focus point arrives already set (the middle) on a new
// row: it does not make an empty new row a row.
$imageSlot = DetailSectionContent::imageSlot();
$imagePreset = array_map(static fn (string $part): string => $imageSlot->column($part), ['presentation', 'focus_x', 'focus_y']);
$images = EditorChildList::fromRequest($post, 'images', 'detail_section_images', $idsOf($repository->findImagesBySectionId($sectionId)), $action, $imagePreset);

// What each stored gallery row shows now, so a kept choice is recognised.
$storedSources = [];
$storedImages = [];
foreach ($repository->findImagesBySectionId($sectionId) as $storedImage) {
    $storedSources[(int) $storedImage['id']] = [(string) ($storedImage['source_type'] ?? ''), (int) ($storedImage['source_id'] ?? 0)];
    $storedImages[(int) $storedImage['id']] = $storedImage;
}

/**
 * Where a gallery item's picture sits in its square (Responsive Media 2.0,
 * DetailSectionContent::imageSlot()): the focus point, clamped to 0–100,
 * refused when it is no number; a row without the field keeps what it has.
 * Worked out once per row: validation, the refused form and the save ask.
 *
 * @return array{0: ResponsiveImage, 1: array<string, string>}
 */
$presentations = [];
$presentationOf = static function (array $row) use (&$presentations, $storedImages, $imageSlot): array {
    $stored = $storedImages[$row['id']] ?? null;

    return $presentations[$row['key']] ??= ResponsiveImage::fromRequest(
        $row['fields'],
        $imageSlot,
        $stored !== null ? ResponsiveImage::fromRow($stored, $imageSlot) : new ResponsiveImage()
    );
};

/**
 * A gallery row's source: 'media', or [kind, id] of an item of the site.
 *
 * @return array{0: string, 1: int}
 */
$sourceOf = static function (array $row): array {
    $kind = (string) ($row['fields']['source'] ?? 'media');
    if ($kind === '' || $kind === 'media') {
        return ['media', 0];
    }

    return [$kind, (int) ($row['fields']['source_' . $kind] ?? 0)];
};

/** A gallery row's item: a known kind and one of its choices, or the row's own stored one. */
$sourceProblems = static function (array $row) use ($sourceOf, $storedSources): array {
    [$kind, $id] = $sourceOf($row);
    $stored = $storedSources[$row['id']] ?? ['', 0];

    if ($row['id'] > 0 && $stored[0] === $kind && $stored[1] === $id && $id > 0) {
        return [];
    }
    if (!LinkedImages::isAvailable($kind)) {
        return ['source' => AdminTranslator::trans('gallery_source.error_kind')];
    }
    if ($id < 1 || !LinkTargets::exists($kind, $id)) {
        return ['source_' . $kind => AdminTranslator::trans('gallery_source.error_item'), 'source' => AdminTranslator::trans('gallery_source.error_item')];
    }

    return [];
};

/** A gallery row's media item: a new row needs one, a posted id must be the library's. */
$imageProblems = static function (array $row) use ($sourceOf, $sourceProblems, $storedSources): array {
    if ($sourceOf($row)[0] !== 'media') {
        return $sourceProblems($row);
    }

    // A row that showed an item and now shows a library picture needs one.
    if ($row['id'] > 0 && ($storedSources[$row['id']][0] ?? '') !== '' && BlockImage::fromRequest($row['fields']['media_id'] ?? '')['media_id'] === null) {
        return ['media_id' => AdminTranslator::trans('editor_rows.error_media_required')];
    }

    $posted = $row['fields']['media_id'] ?? '';
    if ($posted !== '' && $posted !== '0' && BlockImage::fromRequest($posted)['media_id'] === null) {
        return ['media_id' => AdminTranslator::trans('editor_rows.error_media_unknown')];
    }
    if ($row['id'] === 0 && BlockImage::fromRequest($posted)['media_id'] === null) {
        return ['media_id' => AdminTranslator::trans('editor_rows.error_media_required')];
    }

    return [];
};

$errors = [];
$fieldErrors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // BlockLocalization::problems(), as a message per field.
    $fieldErrors = EditorChildList::wordErrors('detail_sections', $languageCode, $words);
    if ($mainPosted !== '' && $mainChosen['media_id'] === null) {
        $fieldErrors['main_media_id'] = AdminTranslator::trans('editor_rows.error_media_unknown');
    }
    $pointErrors = $points->problems($languageCode);
    $imageErrors = $images->problems($languageCode, static function (array $row) use ($imageProblems, $presentationOf): array {
        $problems = $imageProblems($row);
        foreach ($presentationOf($row)[1] as $part => $message) {
            $problems['presentation.' . $part] = $message;
        }

        return $problems;
    });

    // One line per problem at the top, the same line next to its field.
    foreach ($fieldErrors as $message) {
        if (!in_array($message, $errors, true)) {
            $errors[] = $message;
        }
    }
    array_push(
        $errors,
        ...$points->summary($pointErrors, AdminTranslator::trans('block_detail.kenmerk')),
        ...$images->summary($imageErrors, AdminTranslator::trans('block_detail.afbeelding'))
    );
    $fieldErrors += $pointErrors + $imageErrors;
}

if ($labelModeRefused) {
    $errors[] = $fieldErrors['label_mode'] = AdminTranslator::trans('label_mode.error_mode');
}

// The anchor: something that leaves nothing is no anchor, and two sections
// on one page cannot share one.
if (AnchorName::isUnusable($typedAnchor)) {
    $errors[] = $fieldErrors['anchor'] = AdminTranslator::trans('block_detail.error_anker');
} elseif ($settings['anchor'] !== '') {
    foreach ($repository->anchorsOnPage($pageSlug) as $otherId => $otherAnchor) {
        if ($otherId !== $sectionId && AnchorName::normalise($otherAnchor) === $settings['anchor']) {
            $errors[] = $fieldErrors['anchor'] = AdminTranslator::trans('block_detail.error_anker_dubbel', ['anchor' => $settings['anchor']]);
            break;
        }
    }
}

// The one rule for a typed address (App\Service\Routing\SafeUrl): never a
// javascript: or a hidden control character on the website.
$urlProblem = \App\Service\Routing\SafeUrl::optionalFieldMessage($settings['cta_url']);
if ($urlProblem !== null) {
    $errors[] = $urlProblem;
    $fieldErrors['cta_url'] = $urlProblem;
}

$old = ['language_code' => $languageCode] + $words + [
    'label_mode' => is_string($_POST['label_mode'] ?? null) ? $_POST['label_mode'] : $labelMode,
] + $settings + [
    'main_media_id' => $mainPosted,
    'remove_legacy_main_image' => isset($_POST['remove_legacy_main_image']),
    'points' => $points->old(),
    'images' => $images->old(),
];
if (!array_key_exists('main_media_id', $_POST)) {
    // A form without the picker changes nothing about the image; the screen
    // shows the stored one.
    $old['main_media_id'] = (string) (int) ($section['main_media_id'] ?? 0);
}

if ($errors !== []) {
    $_SESSION['admin_detail_section_errors'] = $errors;
    $_SESSION['admin_detail_section_field_errors'] = $fieldErrors;
    $_SESSION['admin_detail_section_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The section, its main image, its words in this language and both
    // lists are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, $settings);

    if ($mainChosen['media_id'] !== null) {
        $repository->updateMainImage($sectionId, [
            'main_media_id' => $mainChosen['media_id'],
            'main_image_path' => $mainChosen['image_path'],
        ]);
    } elseif ($clearMainImage) {
        // Clears the REFERENCE. The file stays: it belongs to the Media
        // Library and may well be on three other pages. No image, no alt
        // text, in any language.
        $repository->clearMainImage($sectionId);
        $words['main_image_alt'] = '';
        foreach (array_keys(BlockLocalization::translations('detail_sections', $sectionId)) as $code) {
            $code = (string) $code;
            if ($code !== $languageCode && BlockLocalization::raw('detail_sections', $sectionId, 'main_image_alt', $code) !== '') {
                $other = [];
                foreach (array_keys(BlockLocalization::fields('detail_sections')) as $field) {
                    $other[$field] = BlockLocalization::raw('detail_sections', $sectionId, $field, $code);
                }
                BlockLocalization::save('detail_sections', $sectionId, $code, ['main_image_alt' => ''] + $other);
            }
        }
    }

    BlockLocalization::save('detail_sections', $sectionId, $languageCode, $words);

    $points->save(
        $languageCode,
        static function (array $row) use ($repository, $sectionId): int {
            $id = $repository->createPoint($sectionId);
            $repository->updatePoint($id, ['is_active' => EditorChildList::flag($row, 'active')]);

            return $id;
        },
        static fn (int $id, array $row) => $repository->updatePoint($id, ['is_active' => EditorChildList::flag($row, 'active')]),
        static fn (int $id) => $repository->deletePoint($id),
        static fn (array $order) => $repository->reorderPoints($sectionId, $order)
    );

    // The focus point of each item is stored on the gallery row, beside its
    // source, never on the library item or the product, project or post.
    $presentationRepository = new ResponsiveImageRepository();
    $images->save(
        $languageCode,
        static function (array $row) use ($repository, $sectionId, $sourceOf, $presentationRepository, $imageSlot, $presentationOf): int {
            [$kind, $itemId] = $sourceOf($row);

            $id = $repository->createImage($sectionId, $kind === 'media'
                ? BlockImage::fromRequest($row['fields']['media_id'] ?? '')
                : ['source_type' => $kind, 'source_id' => $itemId]);
            $presentationRepository->save('detail_section_images', $id, $imageSlot, $presentationOf($row)[0]);

            return $id;
        },
        static function (int $id, array $row) use ($repository, $sourceOf, $presentationRepository, $imageSlot, $presentationOf): void {
            [$kind, $itemId] = $sourceOf($row);
            $presentationRepository->save('detail_section_images', $id, $imageSlot, $presentationOf($row)[0]);
            if ($kind !== 'media') {
                // Only the kind and the id; the picture is the item's own.
                $repository->updateImage($id, ['source_type' => $kind, 'source_id' => $itemId]);

                return;
            }

            // An empty field keeps the stored image (see above).
            $chosen = BlockImage::fromRequest($row['fields']['media_id'] ?? '');
            if ($chosen['media_id'] !== null) {
                $repository->updateImage($id, $chosen);
            }
        },
        static fn (int $id) => $repository->deleteImage($id),
        static fn (array $order) => $repository->reorderImages($sectionId, $order)
    );

    $db->commit();
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-detail-section.php] ' . $e->getMessage());

    $_SESSION['admin_detail_section_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_detail_section_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
