<?php

/**
 * POST /api/admin/update-detail-section-main-image.php
 *
 * Sets, replaces or removes the main image of one Detailsectie by CHOOSING a
 * Media Library item (`media_id`, from the picker in
 * admin/_media_picker.php). `remove_image` clears the reference back to NULL,
 * which makes the section render as plain text with its kenmerken beside it.
 *
 * No file is uploaded, replaced or deleted here any more. Uploading happens
 * once, inside the picker (api/admin/media-upload.php); removing a file is
 * the Media Library's own decision, and it refuses while anything still uses
 * it. See MEDIA.md.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the alt text is the word of the
 * language named in `language_code`, which must be an active language of the
 * website registry (DetailSectionBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). It stays in this form, next to the
 * image it describes, but it is one of the section's words in that language,
 * and BlockLocalization::save() writes a whole language at a time: the save
 * carries every other word that language already has along unchanged, and no
 * other language is touched. The image is the same in every language.
 *
 * REMOVING the image removes its alt text too, in every language that has
 * one, in the same transaction: an alt text describes the image it was
 * written for, never the next one chosen. Every other word stays.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Media\BlockImage;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;

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

$sectionParam = (string) ($_POST['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new DetailSectionRepository();

// Never trust an arbitrary page_slug:section_key pair from the request: the
// page must exist (by its immutable pages.content_key) and so must the
// content row App\Service\SectionRegistry::create() made for it.
if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode($sectionParam);

$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$sectionId = (int) $section['id'];

/**
 * Every word one language of this section has, as stored: what a save of
 * that language carries along unchanged.
 *
 * @return array<string, string> field => words
 */
$storedWords = static function (string $languageCode) use ($sectionId): array {
    $words = [];
    foreach (array_keys(BlockLocalization::fields('detail_sections')) as $field) {
        $words[$field] = BlockLocalization::raw('detail_sections', $sectionId, $field, $languageCode);
    }

    return $words;
};

$db = Database::connection();

if (isset($_POST['remove_image'])) {
    try {
        // Clears the REFERENCE. The file stays: it belongs to the Media
        // Library and may well be on three other pages.
        $db->beginTransaction();

        $repository->clearMainImage($sectionId);

        foreach (array_keys(BlockLocalization::translations('detail_sections', $sectionId)) as $languageCode) {
            $words = $storedWords((string) $languageCode);

            if ($words['main_image_alt'] !== '') {
                $words['main_image_alt'] = '';
                BlockLocalization::save('detail_sections', $sectionId, (string) $languageCode, $words);
            }
        }

        $db->commit();
        DetailSectionContent::clearCache();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('[api/admin/update-detail-section-main-image.php] ' . $e->getMessage());
        $_SESSION['admin_detail_section_main_image_errors'] = ['Afbeelding kon niet worden verwijderd.'];
    }

    header('Location: ' . $redirect . '&saved=1');
    exit;
}

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$alt = trim((string) ($_POST['main_image_alt'] ?? ''));

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // Only the alt text is on this form, so only the alt text is this save's
    // to check; the section form answers for the other words.
    $problems = array_intersect_key(
        BlockLocalization::problems('detail_sections', $languageCode, ['main_image_alt' => $alt]),
        ['main_image_alt' => true]
    );

    foreach (BlockLocalization::messageKeys($problems) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_detail_section_main_image_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

$chosen = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($chosen['media_id'] === null) {
    $_SESSION['admin_detail_section_main_image_errors'] = ['Kies een afbeelding uit de mediabibliotheek, of verwijder de hoofdafbeelding.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    // The image and its alt text in this language are one save.
    $db->beginTransaction();

    $repository->updateMainImage($sectionId, [
        'main_media_id' => $chosen['media_id'],
        'main_image_path' => $chosen['image_path'],
    ]);

    // save() writes a whole language: every other word this language
    // already has goes along unchanged.
    $words = $storedWords($languageCode);
    $words['main_image_alt'] = $alt;

    BlockLocalization::save('detail_sections', $sectionId, $languageCode, $words);

    $db->commit();
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-detail-section-main-image.php] ' . $e->getMessage());
    // Nothing to clean up: no file was created here, only a reference.
    $_SESSION['admin_detail_section_main_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
