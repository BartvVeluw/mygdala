<?php

/**
 * POST /api/admin/update-page-hero.php
 *
 * Saves the Page Hero form for one page (admin/page-hero.php?slug=...): its
 * texts, its picture and its presentation choices, together. Same guard
 * order and PRG/session-flash pattern as api/admin/update-site-settings.php.
 *
 * The image arrives as a media id from the picker and is resolved against the
 * library by BlockImage::fromRequest(), like every block that uses it
 * (MEDIA.md): an id that names no item is saved as "no image", never kept as
 * a reference. A choice outside PageHeroContent's closed lists is refused
 * rather than corrected — the form cannot send one, so it can only come from
 * a crafted request, and then nothing is written. The focus point is the
 * exception, as for a carousel card: an unknown point is the middle
 * (ImageFocus::normalise()).
 *
 * WHERE THE PICTURE GOES decides what the rest means. "Geen afbeelding" means
 * no picture, so a picture still chosen in the (hidden) picker is let go
 * rather than kept as an invisible reference that would keep it "in use" in
 * the library, and its alt text goes with it. Between the places of a picture
 * the height and the alt text are kept: a picture behind the text ignores its
 * alt text and one beside it ignores the height, and an editor who switches
 * back finds them as they were. A request without `image_mode`, `hero_height`
 * or `image_focus` (a form from before they existed) leaves that stored value
 * alone (PageHeroRepository::upsert()).
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow, title and lead are the
 * words of the language named in `language_code`, which must be an active
 * language of the website registry. Which fields exist, how long they may be
 * and that the title is required in the default language comes from
 * PageHeroBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written.
 * The image, the choices and is_active are the same in every language, and
 * are saved in the same transaction as the words.
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
use App\Service\Media\BlockImage;
use App\Service\Media\ImageFocus;
use App\Service\PageHeroContent;
use App\Repository\PageHeroRepository;

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

$slug = (string) ($_POST['slug'] ?? '');

// Known keys (PageHeroContent::PAGES) are the originally hardcoded pages;
// any other slug is only valid when the page builder has actually attached
// a Page Hero to it (App\Service\SectionRegistry::create()) — never trust
// an arbitrary slug from the request beyond that.
$isDynamicallyAttached = (new \App\Repository\PageRepository())->findByContentKey($slug) !== null
    && (new PageHeroRepository())->findBySlug($slug) !== null;

if (!array_key_exists($slug, PageHeroContent::PAGES) && !$isDynamicallyAttached) {
    http_response_code(404);
    exit('Unknown page.');
}

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('page_heroes')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = [
    'media_id' => BlockImage::fromRequest($_POST['media_id'] ?? null)['media_id'],
    'content_position' => trim((string) ($_POST['content_position'] ?? '')),
    'title_size' => trim((string) ($_POST['title_size'] ?? '')),
    'text_size' => trim((string) ($_POST['text_size'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

// The later choices only when the form sends them; see the docblock.
foreach (['image_mode', 'hero_height'] as $key) {
    if (array_key_exists($key, $_POST)) {
        $settings[$key] = trim((string) $_POST[$key]);
    }
}

if (array_key_exists('image_focus', $_POST)) {
    $settings['image_focus'] = ImageFocus::normalise($_POST['image_focus']);
}

// Seeing the library's alt text in the field is not choosing it: a text that
// is exactly the library's is stored as "inherit" (MEDIA.md). In a
// translation an empty field falls back to the default language instead.
$words['image_alt'] = BlockImage::ownAlt(
    $words['image_alt'] ?? '',
    $settings['media_id'],
    $languageCode === BlockLocalization::defaultLanguage()
);

// No picture, so no alt text of a picture either: the hidden field still
// carries the library's text of the picture that is let go, and kept as the
// header's own it would stick to the next picture chosen.
if (($settings['image_mode'] ?? null) === PageHeroContent::IMAGE_NONE) {
    $settings['media_id'] = null;
    $words['image_alt'] = '';
}

// The eyebrow is optional: an empty one renders no element at all
// (partials/section-page-hero.php). The breadcrumb label used to be required
// here; the breadcrumb is the page's own navigation now and this form no
// longer carries it (App\Service\Breadcrumbs\PageBreadcrumb). A forged
// request that still sends one is simply ignored — the field is not read.
$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('page_heroes', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$choices = [
    'content_position' => PageHeroContent::POSITIONS,
    'title_size' => PageHeroContent::SIZES,
    'text_size' => PageHeroContent::SIZES,
    'image_mode' => PageHeroContent::IMAGE_MODES,
    'hero_height' => PageHeroContent::HEIGHTS,
];

foreach ($choices as $key => $allowed) {
    if (array_key_exists($key, $settings) && !in_array($settings[$key], $allowed, true)) {
        $errors[] = AdminTranslator::trans('validation.ongeldige_keuze');
        break;
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_page_hero_errors'] = $errors;
    $_SESSION['admin_page_hero_old'] = $old;
    header('Location: /admin/page-hero.php?slug=' . urlencode($slug));
    exit;
}

$db = Database::connection();

try {
    // The header's settings and its words in this language are one save. A
    // page that had no header row yet gets one here, so its id exists before
    // the words are written.
    $db->beginTransaction();

    $repository = new PageHeroRepository();
    $repository->upsert($slug, $settings);
    BlockLocalization::save('page_heroes', (int) $repository->findBySlug($slug)['id'], $languageCode, $words);

    $db->commit();
    PageHeroContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-page-hero.php] ' . $e->getMessage());

    $_SESSION['admin_page_hero_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_page_hero_old'] = $old;
    header('Location: /admin/page-hero.php?slug=' . urlencode($slug));
    exit;
}

header('Location: /admin/page-hero.php?slug=' . urlencode($slug) . '&saved=1');
exit;
