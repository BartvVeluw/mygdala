<?php

/**
 * POST /api/admin/update-text-image-split-section.php
 *
 * Saves the WHOLE editor of one Text + image split block
 * (admin/text-image-split.php?section=...) in one request: its layout,
 * optional eyebrow/title, optional button and visibility, and both of its
 * lists — the paragraphs, and the images with their media item and alt
 * text — their order, new ones and the ones marked for removal. One form,
 * one save (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is no
 * endpoint per paragraph or image any more.
 *
 * `editor_action` = paragraphs|images:up|down:<key> is the no-JavaScript
 * path of a row's ↑ and ↓ (App\Service\Blocks\EditorRows), performed on the
 * posted rows after validation and stored with everything else.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing and
 * hands every typed value back, with each message next to its field.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow, title and button
 * label and every stored row's words are the language named in
 * `language_code`, which must be an active language of the website
 * registry; which fields exist and how long they may be comes from
 * TextImageSplitBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written. A
 * NEW paragraph or image is written in the default language, and a removed
 * one takes its words in every language along, both through
 * App\Service\Blocks\EditorChildList.
 *
 * AN IMAGE is a Media Library item, resolved here from the posted id
 * (App\Service\Media\BlockImage). A new image needs one. A stored image
 * whose field comes back empty keeps what it has — an image that predates
 * the library has a path and no item, and must not stop every other save of
 * the block. Removing an image removes the row that shows it, never the
 * library's file (MEDIA.md).
 *
 * A half-filled button is not refused, as it never was: the editor says that
 * a label without a URL, or a URL without a label, shows no button, and
 * TextImageSplitContent drops it. The label that counts there is the default
 * language's.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
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

$layout = (string) ($_POST['layout'] ?? 'image_right');
if (!in_array($layout, ['image_left', 'image_right'], true)) {
    $layout = 'image_right';
}

$settings = [
    'layout' => $layout,
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('text_image_splits')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

// The rows of THIS section; a key naming any other row is dropped.
$stored = $repository->findBySlugAndKey($section['page_slug'], $section['section_key']);
$storedId = $stored === null ? 0 : (int) $stored['id'];
$idsOf = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['id'], $rows);
$action = EditorRows::parseAction($_POST['editor_action'] ?? null);
$paragraphs = EditorChildList::fromRequest($_POST, 'paragraphs', 'text_image_split_paragraphs', $storedId > 0 ? $idsOf($repository->findParagraphsBySectionId($storedId)) : [], $action);
// The editor shows the library's alt text in each image's alt field; sent
// back unchanged it stays "the library's" (BlockImage::ownAlt(), MEDIA.md).
$post = ['images' => BlockImage::ownAltInRows($_POST['images'] ?? null, $languageCode === BlockLocalization::defaultLanguage())] + $_POST;
$images = EditorChildList::fromRequest($post, 'images', 'text_image_split_images', $storedId > 0 ? $idsOf($repository->findImagesBySectionId($storedId)) : [], $action);

/** An image row's media item: a new row needs one, a posted id must be the library's. */
$imageProblems = static function (array $row): array {
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
    $fieldErrors = EditorChildList::wordErrors('text_image_splits', $languageCode, $words);
    $paragraphErrors = $paragraphs->problems($languageCode);
    $imageErrors = $images->problems($languageCode, $imageProblems);

    // One line per problem at the top, the same line next to its field.
    foreach ($fieldErrors as $message) {
        if (!in_array($message, $errors, true)) {
            $errors[] = $message;
        }
    }
    array_push(
        $errors,
        ...$paragraphs->summary($paragraphErrors, AdminTranslator::trans('block_textimage.alinea')),
        ...$images->summary($imageErrors, AdminTranslator::trans('block_textimage.afbeelding'))
    );
    $fieldErrors += $paragraphErrors + $imageErrors;
}

$old = ['language_code' => $languageCode] + $words + $settings + ['paragraphs' => $paragraphs->old(), 'images' => $images->old()];

if ($errors !== []) {
    $_SESSION['admin_tis_errors'] = $errors;
    $_SESSION['admin_tis_field_errors'] = $fieldErrors;
    $_SESSION['admin_tis_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The section, its words in this language and both lists are one save.
    $db->beginTransaction();

    $repository->upsertSection($section['page_slug'], $section['section_key'], $settings);
    $sectionId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];
    BlockLocalization::save('text_image_splits', $sectionId, $languageCode, $words);

    $paragraphs->save(
        $languageCode,
        static fn (array $row): int => $repository->createParagraph($sectionId),
        static fn (int $id, array $row) => $repository->updateParagraph($id),
        static fn (int $id) => $repository->deleteParagraph($id),
        static fn (array $order) => $repository->reorderParagraphs($sectionId, $order)
    );

    $images->save(
        $languageCode,
        static fn (array $row): int => $repository->createImage($sectionId, BlockImage::fromRequest($row['fields']['media_id'] ?? '')),
        static function (int $id, array $row) use ($repository): void {
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
