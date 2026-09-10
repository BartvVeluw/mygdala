<?php

/**
 * POST /api/admin/update-homepage-hero-video.php
 *
 * Replaces the Homepage Hero's video file (multipart/form-data, `video`,
 * required — unlike the image form this has nothing else to save, so an
 * empty submission is simply rejected). Uses App\Service\SectionVideoUploader
 * for validation/storage, same conventions as
 * api/admin/update-homepage-hero-image.php. Does NOT change media_type —
 * uploading a video does not by itself switch the Hero to render it; that is
 * a separate, explicit choice via update-homepage-hero-media.php, so an
 * admin can stage a video before switching over, and switching back to
 * "Image" never deletes the previously uploaded video. All other fields are
 * carried forward unchanged into the upsert() call.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionVideoUploader;
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
    error_log('[api/admin/update-homepage-hero-video.php] ' . $e->getMessage());
    $_SESSION['admin_homepage_hero_video_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

$defaults = HomepageHeroContent::defaults();

$carriedFields = $current !== null
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
        'image_path' => (string) $current['image_path'],
        'image_alt_nl' => (string) $current['image_alt_nl'],
        'image_alt_en' => (string) ($current['image_alt_en'] ?? ''),
        'badge_title_nl' => (string) ($current['badge_title_nl'] ?? ''),
        'badge_title_en' => (string) ($current['badge_title_en'] ?? ''),
        'badge_text_nl' => (string) ($current['badge_text_nl'] ?? ''),
        'badge_text_en' => (string) ($current['badge_text_en'] ?? ''),
        'media_type' => (string) ($current['media_type'] ?? $defaults['media_type']),
        'layout' => (string) ($current['layout'] ?? $defaults['layout']),
    ]
    : array_diff_key($defaults, ['video_path' => 0]);

$existingVideoPath = $current !== null ? (string) ($current['video_path'] ?? '') : '';

$uploader = new SectionVideoUploader();

try {
    $newVideoPath = $uploader->store($_FILES['video'] ?? []);
} catch (\RuntimeException $e) {
    $_SESSION['admin_homepage_hero_video_errors'] = [$e->getMessage()];
    header('Location: /admin/homepage-hero.php');
    exit;
}

try {
    $repository->upsert(HomepageHeroContent::PAGE_SLUG, $carriedFields + ['video_path' => $newVideoPath, 'is_active' => true]);
    HomepageHeroContent::clearCache();

    // Only remove the old file after the new one is safely saved.
    $uploader->delete($existingVideoPath);
} catch (\Throwable $e) {
    error_log('[api/admin/update-homepage-hero-video.php] ' . $e->getMessage());
    $uploader->delete($newVideoPath);
    $_SESSION['admin_homepage_hero_video_errors'] = ['Video kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
