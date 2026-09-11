<?php

/**
 * POST /api/admin/update-homepage-hero-media.php
 *
 * Saves the Homepage Hero's media type (image|video) and layout
 * (media_right|media_left|background) — see App\Service\HomepageHeroContent
 * for the validated constants. Purely two config choices, no file involved
 * (the actual image/video files are uploaded separately via
 * update-homepage-hero-image.php / update-homepage-hero-video.php), so this
 * endpoint carries every other field forward unchanged into the upsert()
 * call, same pattern as the sibling Homepage Hero endpoints.
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
            'video_path' => (string) ($current['video_path'] ?? ''),
        ]
        : array_diff_key($defaults, ['media_type' => 0, 'layout' => 0]);

    $repository->upsert(
        HomepageHeroContent::PAGE_SLUG,
        $carriedFields + ['media_type' => $mediaType, 'layout' => $layout, 'is_active' => true]
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
