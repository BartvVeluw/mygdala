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
 * "Image" never deletes the previously uploaded video. All other
 * language-neutral fields are carried forward unchanged into the upsert()
 * call. The video is the same in every language: no words are read or
 * written here (Multilingual 2.0). The Hero row itself is created by
 * admin/homepage-hero.php the first time it is opened, so a save without one
 * is refused.
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

if ($current === null) {
    http_response_code(404);
    exit('Hero not found.');
}

$existingVideoPath = (string) ($current['video_path'] ?? '');

$uploader = new SectionVideoUploader();

try {
    $newVideoPath = $uploader->store($_FILES['video'] ?? []);
} catch (\RuntimeException $e) {
    $_SESSION['admin_homepage_hero_video_errors'] = [$e->getMessage()];
    header('Location: /admin/homepage-hero.php');
    exit;
}

try {
    $repository->upsert(
        HomepageHeroContent::PAGE_SLUG,
        ['video_path' => $newVideoPath] + HomepageHeroContent::settingsOf($current) + ['is_active' => true]
    );
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
