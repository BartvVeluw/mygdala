<?php

/**
 * POST /api/admin/update-homepage-hero-image.php
 *
 * Edits the Homepage Hero's image (alt text NL/EN, and optionally replaces
 * its file — multipart/form-data, `image`, optional). Uses
 * App\Service\SectionImageUploader for validation/storage, same conventions
 * as api/admin/update-text-image-split-image.php. Note that this image also
 * doubles as the <video> poster/fallback when media_type = 'video' — see
 * HomepageHeroContent. Text content fields, video_path, and media_type/
 * layout are saved separately by update-homepage-hero.php,
 * update-homepage-hero-video.php and update-homepage-hero-media.php — this
 * endpoint always carries them forward unchanged into the upsert() call.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
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

$altNl = trim((string) ($_POST['image_alt_nl'] ?? ''));
$altEn = trim((string) ($_POST['image_alt_en'] ?? ''));

if ($altNl === '') {
    $_SESSION['admin_homepage_hero_image_errors'] = [AdminTranslator::trans('validation.alt_tekst_nl_verplicht')];
    header('Location: /admin/homepage-hero.php');
    exit;
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

$startingValues = HomepageHeroContent::startingValues();

$textFields = $current !== null
    ? [
        'eyebrow_nl' => (string) $current['eyebrow_nl'],
        'eyebrow_en' => (string) ($current['eyebrow_en'] ?? ''),
        'title_nl' => (string) $current['title_nl'],
        'title_en' => (string) ($current['title_en'] ?? ''),
        'title_highlight_nl' => (string) ($current['title_highlight_nl'] ?? ''),
        'title_highlight_en' => (string) ($current['title_highlight_en'] ?? ''),
        'title_highlight_size' => HomepageHeroContent::clampHighlightSize($current['title_highlight_size'] ?? null),
        'lead_nl' => (string) ($current['lead_nl'] ?? ''),
        'lead_en' => (string) ($current['lead_en'] ?? ''),
        'primary_label_nl' => (string) $current['primary_label_nl'],
        'primary_label_en' => (string) ($current['primary_label_en'] ?? ''),
        'primary_url' => (string) $current['primary_url'],
        'secondary_label_nl' => (string) ($current['secondary_label_nl'] ?? ''),
        'secondary_label_en' => (string) ($current['secondary_label_en'] ?? ''),
        'secondary_url' => (string) ($current['secondary_url'] ?? ''),
        'badge_title_nl' => (string) ($current['badge_title_nl'] ?? ''),
        'badge_title_en' => (string) ($current['badge_title_en'] ?? ''),
        'badge_text_nl' => (string) ($current['badge_text_nl'] ?? ''),
        'badge_text_en' => (string) ($current['badge_text_en'] ?? ''),
        'media_type' => (string) ($current['media_type'] ?? $startingValues['media_type']),
        'video_path' => (string) ($current['video_path'] ?? ''),
        'layout' => (string) ($current['layout'] ?? $startingValues['layout']),
    ]
    : array_diff_key($startingValues, ['image_path' => 0, 'image_alt_nl' => 0, 'image_alt_en' => 0]);

$existingImagePath = $current !== null ? (string) $current['image_path'] : $startingValues['image_path'];

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

$imageFields = [
    'image_path' => $newImagePath ?? $existingImagePath,
    'image_alt_nl' => $altNl,
    'image_alt_en' => $altEn,
];

try {
    $repository->upsert(HomepageHeroContent::PAGE_SLUG, $textFields + $imageFields + ['is_active' => true]);
    HomepageHeroContent::clearCache();

    if ($newImagePath !== null) {
        // Only remove the old file after the new one is safely saved, and
        // only if it was itself an admin upload (SectionImageUploader::delete
        // is a no-op for any path outside its upload directory, and for the
        // empty path of a Hero that had no image yet).
        $uploader->delete($existingImagePath);
    }
} catch (\Throwable $e) {
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
