<?php

/**
 * POST /api/admin/update-homepage-hero-image.php
 *
 * Edits the Homepage Hero's image: its alt text, and optionally replaces its
 * file (multipart/form-data, `image`, optional). Uses
 * App\Service\SectionImageUploader for validation/storage, same conventions
 * as api/admin/update-text-image-split-image.php. Note that this image also
 * doubles as the <video> poster/fallback when media_type = 'video' — see
 * HomepageHeroContent. Text content fields, video_path, and media_type/
 * layout are saved separately by update-homepage-hero.php,
 * update-homepage-hero-video.php and update-homepage-hero-media.php — this
 * endpoint always carries the language-neutral ones forward unchanged into
 * the upsert() call. The Hero row itself is created by admin/homepage-hero.php
 * the first time it is opened, so a save without one is refused.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the alt text is the word of the
 * language named in `language_code`, which must be an active language of the
 * website registry, and is required only in the default language
 * (HomepageHeroBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). It stays in this form, next to the
 * image it describes, but it is one of the Hero's words in that language, and
 * BlockLocalization::save() writes a whole language at a time: the save
 * carries every other word that language already has along unchanged, and no
 * other language is touched. The file is the same in every language.
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
use App\Service\SectionImageUploader;
use App\Service\HomepageHeroContent;
use App\Repository\HomepageHeroRepository;

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

$repository = new HomepageHeroRepository();

try {
    $current = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
} catch (\Throwable $e) {
    error_log('[api/admin/update-homepage-hero-image.php] ' . $e->getMessage());
    $_SESSION['admin_homepage_hero_image_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

if ($current === null) {
    http_response_code(404);
    exit('Hero not found.');
}

$heroId = (int) $current['id'];
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$alt = trim((string) ($_POST['image_alt'] ?? ''));

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // Only the alt text is on this form, so only the alt text is this save's
    // to check; the text form answers for the other words.
    $problems = array_intersect_key(
        BlockLocalization::problems('homepage_hero', $languageCode, ['image_alt' => $alt]),
        ['image_alt' => true]
    );

    foreach (BlockLocalization::messageKeys($problems) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_homepage_hero_image_errors'] = $errors;
    header('Location: /admin/homepage-hero.php');
    exit;
}

$existingImagePath = (string) $current['image_path'];

$uploader = new SectionImageUploader();
$newImagePath = null;

$hasNewFile = isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasNewFile) {
    try {
        $newImagePath = $uploader->store($_FILES['image']);
    } catch (\RuntimeException $e) {
        $_SESSION['admin_homepage_hero_image_errors'] = [$e->getMessage()];
        header('Location: /admin/homepage-hero.php');
        exit;
    }
}

$db = Database::connection();

try {
    // The image and its alt text in this language are one save.
    $db->beginTransaction();

    $repository->upsert(
        HomepageHeroContent::PAGE_SLUG,
        ['image_path' => $newImagePath ?? $existingImagePath] + HomepageHeroContent::settingsOf($current) + ['is_active' => true]
    );

    // save() writes a whole language: every other word this language
    // already has goes along unchanged.
    $words = [];
    foreach (array_keys(BlockLocalization::fields('homepage_hero')) as $field) {
        $words[$field] = BlockLocalization::raw('homepage_hero', $heroId, $field, $languageCode);
    }
    $words['image_alt'] = $alt;

    BlockLocalization::save('homepage_hero', $heroId, $languageCode, $words);

    $db->commit();
    HomepageHeroContent::clearCache();

    if ($newImagePath !== null) {
        // Only remove the old file after the new one is safely saved, and
        // only if it was itself an admin upload (SectionImageUploader::delete
        // is a no-op for any path outside its upload directory, and for the
        // empty path of a Hero that had no image yet).
        $uploader->delete($existingImagePath);
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-homepage-hero-image.php] ' . $e->getMessage());
    if ($newImagePath !== null) {
        $uploader->delete($newImagePath);
    }
    $_SESSION['admin_homepage_hero_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
