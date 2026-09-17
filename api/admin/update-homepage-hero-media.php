<?php

/**
 * POST /api/admin/update-homepage-hero-media.php
 *
 * Saves the Homepage Hero's media type (image|video) and layout
 * (media_right|media_left|background) — see App\Service\HomepageHeroContent
 * for the validated constants. Purely two config choices, no file involved
 * (the actual image/video files are uploaded separately via
 * update-homepage-hero-image.php / update-homepage-hero-video.php), so this
 * endpoint carries every other language-neutral field forward unchanged into
 * the upsert() call, same pattern as the sibling Homepage Hero endpoints.
 * Both choices are the same in every language: no words are read or written
 * here (Multilingual 2.0). The Hero row itself is created by
 * admin/homepage-hero.php the first time it is opened, so a save without one
 * is refused.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
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

$mediaType = trim((string) ($_POST['media_type'] ?? ''));
$layout = trim((string) ($_POST['layout'] ?? ''));

$errors = [];

if (!in_array($mediaType, HomepageHeroContent::MEDIA_TYPES, true)) {
    $errors[] = AdminTranslator::trans('validation.ongeldig_media_type');
}

if (!in_array($layout, HomepageHeroContent::LAYOUTS, true)) {
    $errors[] = AdminTranslator::trans('validation.ongeldige_lay_out');
}

if ($errors !== []) {
    $_SESSION['admin_homepage_hero_media_errors'] = $errors;
    header('Location: /admin/homepage-hero.php');
    exit;
}

$repository = new HomepageHeroRepository();

try {
    $current = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
} catch (\Throwable $e) {
    error_log('[api/admin/update-homepage-hero-media.php] ' . $e->getMessage());

    $_SESSION['admin_homepage_hero_media_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

if ($current === null) {
    http_response_code(404);
    exit('Hero not found.');
}

try {
    $repository->upsert(
        HomepageHeroContent::PAGE_SLUG,
        ['media_type' => $mediaType, 'layout' => $layout] + HomepageHeroContent::settingsOf($current) + ['is_active' => true]
    );
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-homepage-hero-media.php] ' . $e->getMessage());

    $_SESSION['admin_homepage_hero_media_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
