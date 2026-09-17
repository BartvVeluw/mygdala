<?php

/**
 * POST /api/admin/update-homepage-hero.php
 *
 * Saves the Homepage Hero's text content: eyebrow, title (+ optional
 * highlight and its display size), lead, both CTAs, and the badge. The image
 * (image_path and its alt text), video (video_path), and media type/layout
 * (media_type/layout) are saved separately by update-homepage-hero-image.php,
 * update-homepage-hero-video.php and update-homepage-hero-media.php — this
 * endpoint always carries those CURRENT values forward unchanged, since
 * HomepageHeroRepository::upsert() always writes the complete row. There is
 * no is_active field here on purpose — the admin editor does not expose a
 * whole-Hero visibility checkbox (see HomepageHeroContent's docblock), so
 * this endpoint always saves with is_active = true. The Hero row itself is
 * created by admin/homepage-hero.php the first time it is opened, so a save
 * without one did not come from that screen and is refused.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the text fields are the words of
 * the language named in `language_code`, which must be an active language of
 * the website registry. Which fields exist, how long they may be and which
 * are required in the default language comes from
 * HomepageHeroBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written.
 * The URLs and the highlight size are the same in every language. The alt
 * text is one of the Hero's words too, but it belongs to the image form:
 * this form neither shows nor requires it, and the words this language
 * already has for it are saved along unchanged, because
 * BlockLocalization::save() writes a whole language at a time.
 *
 * THE SECONDARY BUTTON needs a label and a URL, or neither. The label that
 * counts is the default language's — the one every other language falls back
 * to — so a translation save checks the stored default label, and a
 * translated label without a URL is refused too, since it could never show.
 * THE BADGE follows the same rule: a title and a text in the default
 * language, or neither, and translated badge words only for a badge the
 * default language completes.
 *
 * THE HIGHLIGHT must occur verbatim in the title it is shown with: this
 * language's own title, or the default language's when this language has
 * none, since that is then the title its visitors see.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\HomepageHeroContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
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
$current = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);

if ($current === null) {
    http_response_code(404);
    exit('Hero not found.');
}

$heroId = (int) $current['id'];
$defaultLanguage = BlockLocalization::defaultLanguage();
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$isDefaultLanguage = $languageIsWritable && $languageCode === $defaultLanguage;

// Exactly the fields the block declares, never a name taken from the request
// — except the alt text, which is not on this form (see above).
$words = [];
foreach (array_keys(BlockLocalization::fields('homepage_hero')) as $field) {
    if ($field !== 'image_alt') {
        $words[$field] = trim((string) ($_POST[$field] ?? ''));
    }
}

$settings = [
    'title_highlight_size' => trim((string) ($_POST['title_highlight_size'] ?? '')),
    'primary_url' => trim((string) ($_POST['primary_url'] ?? '')),
    'secondary_url' => trim((string) ($_POST['secondary_url'] ?? '')),
];

/** The words the default language has stored for one field: what a translation falls back to. */
$storedDefault = static fn (string $field): string => BlockLocalization::raw('homepage_hero', $heroId, $field, $defaultLanguage);

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // The image form requires the alt text (update-homepage-hero-image.php);
    // this form cannot fill it in, so it is not this save's problem.
    $problems = BlockLocalization::problems('homepage_hero', $languageCode, $words);
    unset($problems['image_alt']);

    foreach (BlockLocalization::messageKeys($problems) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($settings['primary_url'] === '' && !in_array(AdminTranslator::trans('validation.veld_verplicht'), $errors, true)) {
    $errors[] = AdminTranslator::trans('validation.veld_verplicht');
}

if ($languageIsWritable) {
    // A secondary button needs both a label and a URL, or neither — a
    // half-filled optional button would be broken/dead on the frontend.
    $defaultSecondaryLabel = $isDefaultLanguage ? $words['secondary_label'] : $storedDefault('secondary_label');
    $secondaryUrlSet = $settings['secondary_url'] !== '';

    if (($defaultSecondaryLabel !== '') !== $secondaryUrlSet || ($words['secondary_label'] !== '' && !$secondaryUrlSet)) {
        $errors[] = AdminTranslator::trans('validation.secondary_button_label_and_url');
    }

    // The badge needs both a title and a body text, or neither. A
    // translation's badge words need a badge the default language completes,
    // or they could never show.
    $badgeRefused = $isDefaultLanguage
        ? ($words['badge_title'] !== '') !== ($words['badge_text'] !== '')
        : ($words['badge_title'] !== '' || $words['badge_text'] !== '')
            && ($storedDefault('badge_title') === '' || $storedDefault('badge_text') === '');

    if ($badgeRefused) {
        $errors[] = AdminTranslator::trans($isDefaultLanguage ? 'validation.badge_title_and_text' : 'validation.badge_in_default_language_first');
    }

    // The highlight must occur verbatim in its title — never silently save
    // an impossible highlight.
    $shownTitle = $isDefaultLanguage || $words['title'] !== '' ? $words['title'] : $storedDefault('title');

    if (!HomepageHeroContent::isHighlightValid($shownTitle, $words['title_highlight'])) {
        $errors[] = AdminTranslator::trans($isDefaultLanguage
            ? 'validation.highlight_in_title'
            : 'validation.highlight_in_title_or_default');
    }
}

// The highlight size is a percentage of the headline's own (responsive)
// font size — the slider in admin/homepage-hero.php can only ever produce a
// whole number inside these bounds, so anything else is a tampered POST and
// is rejected rather than silently clamped.
if (!HomepageHeroContent::isHighlightSizeValid($settings['title_highlight_size'])) {
    $errors[] = sprintf(
        'De highlight-grootte moet een heel getal tussen %d%% en %d%% zijn.',
        HomepageHeroContent::HIGHLIGHT_SIZE_MIN,
        HomepageHeroContent::HIGHLIGHT_SIZE_MAX
    );
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_homepage_hero_errors'] = $errors;
    $_SESSION['admin_homepage_hero_old'] = $old;
    header('Location: /admin/homepage-hero.php');
    exit;
}

$db = Database::connection();

try {
    // The Hero's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository->upsert(
        HomepageHeroContent::PAGE_SLUG,
        [
            'title_highlight_size' => (int) $settings['title_highlight_size'],
            'primary_url' => $settings['primary_url'],
            'secondary_url' => $settings['secondary_url'],
        ] + HomepageHeroContent::settingsOf($current) + ['is_active' => true]
    );

    // save() writes a whole language: the alt text this language already
    // has goes along unchanged.
    $words['image_alt'] = BlockLocalization::raw('homepage_hero', $heroId, 'image_alt', $languageCode);
    BlockLocalization::save('homepage_hero', $heroId, $languageCode, $words);

    $db->commit();
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-homepage-hero.php] ' . $e->getMessage());

    $_SESSION['admin_homepage_hero_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_homepage_hero_old'] = $old;
    header('Location: /admin/homepage-hero.php');
    exit;
}

header('Location: /admin/homepage-hero.php?saved=1');
exit;
