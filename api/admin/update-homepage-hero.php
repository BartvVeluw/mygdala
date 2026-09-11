<?php

/**
 * POST /api/admin/update-homepage-hero.php
 *
 * Saves the Homepage Hero's text content: eyebrow, title (+ optional
 * highlight and its display size), lead, both CTAs, and the badge. Image fields
 * (image_path/image_alt_*), video (video_path), and media
 * type/layout (media_type/layout) are saved separately by
 * update-homepage-hero-image.php, update-homepage-hero-video.php and
 * update-homepage-hero-media.php — this endpoint always carries those
 * CURRENT fields forward unchanged into the upsert() call, since
 * HomepageHeroRepository::upsert() always writes the complete row. There is
 * no is_active field here on purpose — the admin editor does not expose a
 * whole-Hero visibility checkbox (see HomepageHeroContent's docblock), so
 * this endpoint always saves with is_active = true.
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

$fields = [
    'eyebrow_nl' => trim((string) ($_POST['eyebrow_nl'] ?? '')),
    'eyebrow_en' => trim((string) ($_POST['eyebrow_en'] ?? '')),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'title_highlight_nl' => trim((string) ($_POST['title_highlight_nl'] ?? '')),
    'title_highlight_en' => trim((string) ($_POST['title_highlight_en'] ?? '')),
    'title_highlight_size' => trim((string) ($_POST['title_highlight_size'] ?? '')),
    'lead_nl' => trim((string) ($_POST['lead_nl'] ?? '')),
    'lead_en' => trim((string) ($_POST['lead_en'] ?? '')),
    'primary_label_nl' => trim((string) ($_POST['primary_label_nl'] ?? '')),
    'primary_label_en' => trim((string) ($_POST['primary_label_en'] ?? '')),
    'primary_url' => trim((string) ($_POST['primary_url'] ?? '')),
    'secondary_label_nl' => trim((string) ($_POST['secondary_label_nl'] ?? '')),
    'secondary_label_en' => trim((string) ($_POST['secondary_label_en'] ?? '')),
    'secondary_url' => trim((string) ($_POST['secondary_url'] ?? '')),
    'badge_title_nl' => trim((string) ($_POST['badge_title_nl'] ?? '')),
    'badge_title_en' => trim((string) ($_POST['badge_title_en'] ?? '')),
    'badge_text_nl' => trim((string) ($_POST['badge_text_nl'] ?? '')),
    'badge_text_en' => trim((string) ($_POST['badge_text_en'] ?? '')),
];

$errors = [];

$required = ['eyebrow_nl', 'title_nl', 'primary_label_nl', 'primary_url'];
foreach ($required as $key) {
    if ($fields[$key] === '') {
        $errors[] = AdminTranslator::trans('validation.veld_verplicht');
        break;
    }
}

// A secondary button needs both a label and a URL, or neither — a
// half-filled optional button would be broken/dead on the frontend.
$secondaryLabelSet = $fields['secondary_label_nl'] !== '';
$secondaryUrlSet = $fields['secondary_url'] !== '';
if ($secondaryLabelSet !== $secondaryUrlSet) {
    $errors[] = AdminTranslator::trans('validation.vul_secundaire_knop_zowel_label');
}

// The badge needs both a title and a body text, or neither.
$badgeTitleSet = $fields['badge_title_nl'] !== '';
$badgeTextSet = $fields['badge_text_nl'] !== '';
if ($badgeTitleSet !== $badgeTextSet) {
    $errors[] = AdminTranslator::trans('validation.vul_badge_zowel_titel_nl');
}

// The highlight must occur verbatim in its title — never silently save an
// impossible highlight. EN falls back to the NL title when EN is empty (the
// site-wide bilingual convention), so the EN highlight is checked against
// whichever title will actually render for EN.
if (!HomepageHeroContent::isHighlightValid($fields['title_nl'], $fields['title_highlight_nl'])) {
    $errors[] = AdminTranslator::trans('validation.highlight_nl_exact_voorkomen_titel');
}

$effectiveTitleEn = $fields['title_en'] !== '' ? $fields['title_en'] : $fields['title_nl'];
if (!HomepageHeroContent::isHighlightValid($effectiveTitleEn, $fields['title_highlight_en'])) {
    $errors[] = AdminTranslator::trans('validation.highlight_exact_voorkomen_titel_titel');
}

// The highlight size is a percentage of the headline's own (responsive)
// font size — the slider in admin/homepage-hero.php can only ever produce a
// whole number inside these bounds, so anything else is a tampered POST and
// is rejected rather than silently clamped.
if (!HomepageHeroContent::isHighlightSizeValid($fields['title_highlight_size'])) {
    $errors[] = sprintf(
        'De highlight-grootte moet een heel getal tussen %d%% en %d%% zijn.',
        HomepageHeroContent::HIGHLIGHT_SIZE_MIN,
        HomepageHeroContent::HIGHLIGHT_SIZE_MAX
    );
}

if ($errors !== []) {
    $_SESSION['admin_homepage_hero_errors'] = $errors;
    $_SESSION['admin_homepage_hero_old'] = $fields;
    header('Location: /admin/homepage-hero.php');
    exit;
}

$repository = new HomepageHeroRepository();

try {
    $current = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
    $carriedFields = $current !== null
        ? [
            'image_path' => (string) $current['image_path'],
            'image_alt_nl' => (string) $current['image_alt_nl'],
            'image_alt_en' => (string) ($current['image_alt_en'] ?? ''),
            'media_type' => (string) ($current['media_type'] ?? HomepageHeroContent::defaults()['media_type']),
            'video_path' => (string) ($current['video_path'] ?? ''),
            'layout' => (string) ($current['layout'] ?? HomepageHeroContent::defaults()['layout']),
        ]
        : [
            'image_path' => HomepageHeroContent::defaults()['image_path'],
            'image_alt_nl' => HomepageHeroContent::defaults()['image_alt_nl'],
            'image_alt_en' => HomepageHeroContent::defaults()['image_alt_en'],
            'media_type' => HomepageHeroContent::defaults()['media_type'],
            'video_path' => HomepageHeroContent::defaults()['video_path'],
            'layout' => HomepageHeroContent::defaults()['layout'],
        ];

    $repository->upsert(HomepageHeroContent::PAGE_SLUG, $fields + $carriedFields + ['is_active' => true]);
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-homepage-hero.php] ' . $e->getMessage());

    $_SESSION['admin_homepage_hero_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_homepage_hero_old'] = $fields;
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
