<?php

/**
 * POST /api/admin/update-homepage-hero.php
 *
 * Saves the WHOLE Homepage Hero editor (admin/homepage-hero.php) in one
 * request: its texts (eyebrow, title, highlight and its display size, lead,
 * both buttons with where each goes, the badge), its media type and layout,
 * its image (`media_id` from the Media Library, and the alt text), its video
 * (`video_media_id`, a library video) and its stats — their words, whether
 * each is shown, their order, new ones and the ones marked for removal. One form, one save
 * (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is no separate
 * image, video, media or stat save any more.
 *
 * There is no is_active field on purpose — the admin editor does not expose
 * a whole-Hero visibility checkbox (see HomepageHeroContent's docblock), so
 * this endpoint always saves with is_active = true. The Hero row itself is
 * created by admin/homepage-hero.php the first time it is opened, so a save
 * without one did not come from that screen and is refused.
 *
 * ALL OR NOTHING. Everything is checked first; a refused save stores nothing
 * and hands every typed and chosen value back. The row, its words and its
 * stats are then one transaction. Choosing a video does not switch the Hero
 * to it: "Media" is its own, explicit choice, so a video can be staged first.
 *
 * THE MEDIA come from the library by id and are checked against it: an id
 * that names nothing, or a video sent as the image (or the other way round),
 * is refused (BlockImage::fromRequest(), MediaService::findVideo()). A Hero
 * from before the library keeps the file it had (image_path, video_path)
 * until an item is chosen or `remove_legacy_image` / `remove_legacy_video`
 * is ticked; only then, after the commit, is that old file deleted, and only
 * when it was an admin upload (SectionImageUploader / SectionVideoUploader
 * delete nothing outside their own folders). A library item is never
 * deleted here: other places may use it.
 *
 * THE ALT TEXT follows the library (MEDIA.md, "Alt-tekst"): the screen shows
 * the library's alt text in the field, and a text that only repeats it is
 * stored empty, so it keeps following the library (BlockImage::ownAlt()). It
 * is required the way it was before: with a newly chosen image, or when the
 * editor changes it, the alt text that would be USED (own, else the
 * library's) may not be empty. A save of the other fields leaves a Hero
 * whose image has no alt text alone.
 *
 * THE BUTTONS point at a page, a blog post, a product or an own address, by
 * the rule every block button follows (App\Service\Routing\LinkChoice). The
 * primary button is required; the secondary one may be "Geen knop".
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the words, the alt text and every
 * stored stat's words are the language named in `language_code`, which must
 * be an active language of the website registry. Which fields exist, how
 * long they may be and which are required in the default language comes
 * from HomepageHeroBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written. A
 * NEW stat is written in the default language, and a removed stat takes its
 * words in every language along (App\Service\Blocks\EditorChildList). The
 * URLs, the highlight size, the media, the layout and the files are the same
 * in every language.
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
 *
 * THE STATS are at most HomepageHeroContent::MAX_STATS after the save.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
use App\Service\Csrf;
use App\Service\HomepageHeroContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Media\BlockImage;
use App\Service\Media\MediaService;
use App\Service\Routing\LinkChoice;
use App\Service\SectionImageUploader;
use App\Service\SectionVideoUploader;
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
$redirect = '/admin/homepage-hero.php';
$defaultLanguage = BlockLocalization::defaultLanguage();
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$isDefaultLanguage = $languageIsWritable && $languageCode === $defaultLanguage;

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('homepage_hero')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = [
    'title_highlight_size' => trim((string) ($_POST['title_highlight_size'] ?? '')),
    'primary_url' => trim((string) ($_POST['primary_url'] ?? '')),
    'secondary_url' => trim((string) ($_POST['secondary_url'] ?? '')),
    'media_type' => trim((string) ($_POST['media_type'] ?? '')),
    'layout' => trim((string) ($_POST['layout'] ?? '')),
];

$fieldErrors = [];

// ---------------------------------------------------------------- the buttons

$linkTypes = [];
$postedTargets = [];
foreach (['primary', 'secondary'] as $button) {
    $linkTypes[$button] = (string) ($_POST[$button . '_link_type'] ?? LinkChoice::NONE);
    $postedTargets[$button] = is_array($_POST[$button . '_link_target'] ?? null) ? $_POST[$button . '_link_target'] : [];

    $link = LinkChoice::fromRequest(
        $linkTypes[$button],
        $postedTargets[$button][$linkTypes[$button]] ?? null,
        $settings[$button . '_url'],
        (string) ($current[$button . '_link_type'] ?? ''),
        (int) ($current[$button . '_link_target_id'] ?? 0),
        // The primary button is always there.
        $button === 'secondary'
    );

    if ($link['error'] !== null) {
        $fieldErrors[$button . '_url'] = $link['error'];
    }

    $settings[$button . '_link_type'] = $link['link_type'];
    $settings[$button . '_link_target_id'] = $link['link_target_id'];
}

// ---------------------------------------------------------------- the media

$imagePosted = trim((string) ($_POST['media_id'] ?? ''));
$imageChosen = BlockImage::fromRequest($imagePosted === '' ? null : $imagePosted);
if ($imagePosted !== '' && $imagePosted !== '0' && $imageChosen['media_id'] === null) {
    $fieldErrors['media_id'] = AdminTranslator::trans('editor_rows.error_media_unknown');
}

$videoPosted = trim((string) ($_POST['video_media_id'] ?? ''));
$videoChosen = MediaService::findVideo(is_numeric($videoPosted) ? (int) $videoPosted : null);
if ($videoPosted !== '' && $videoPosted !== '0' && $videoChosen === null) {
    $fieldErrors['video_media_id'] = AdminTranslator::trans('editor_rows.error_media_unknown');
}

$storedMediaId = (int) ($current['media_id'] ?? 0);
$storedVideoId = (int) ($current['video_media_id'] ?? 0);
$legacyImage = $storedMediaId === 0 ? trim((string) ($current['image_path'] ?? '')) : '';
$legacyVideo = $storedVideoId === 0 ? trim((string) ($current['video_path'] ?? '')) : '';

// What the row stores. A chosen item writes its own path beside its id, so
// the old column stays truthful (MEDIA.md); no item keeps a pre-library file
// unless it is ticked away.
if ($imageChosen['media_id'] !== null) {
    $image = ['media_id' => $imageChosen['media_id'], 'image_path' => $imageChosen['image_path']];
} elseif ($legacyImage !== '' && !isset($_POST['remove_legacy_image'])) {
    $image = ['media_id' => null, 'image_path' => $legacyImage];
} else {
    $image = ['media_id' => null, 'image_path' => ''];
}

if ($videoChosen !== null) {
    $video = ['video_media_id' => $videoChosen->id, 'video_path' => $videoChosen->path];
} elseif ($legacyVideo !== '' && !isset($_POST['remove_legacy_video'])) {
    $video = ['video_media_id' => null, 'video_path' => $legacyVideo];
} else {
    $video = ['video_media_id' => null, 'video_path' => ''];
}

$imageIsNew = $image['media_id'] !== null && $image['media_id'] !== $storedMediaId;
$libraryAlt = $imageChosen['media_id'] !== null ? trim((string) (MediaService::find($imageChosen['media_id'])?->altText ?? '')) : '';
$submittedAlt = $words['image_alt'];

// The Hero's own stats; a key naming any other row is dropped.
$storedIds = array_map(static fn (array $stat): int => (int) $stat['id'], $repository->findStatsByHeroId($heroId));
$stats = EditorChildList::fromRequest($_POST, 'stats', 'homepage_hero_stats', $storedIds, EditorRows::parseAction($_POST['editor_action'] ?? null));

/** The words the default language has stored for one field: what a translation falls back to. */
$storedDefault = static fn (string $field): string => BlockLocalization::raw('homepage_hero', $heroId, $field, $defaultLanguage);

if ($languageIsWritable) {
    // The alt text the screen showed: this language's own, else (in the
    // default language) the library's. Changing it is what makes it checked.
    $storedOwnAlt = BlockLocalization::raw('homepage_hero', $heroId, 'image_alt', $languageCode);
    $shownAlt = $storedOwnAlt !== '' || !$isDefaultLanguage
        ? $storedOwnAlt
        : trim((string) (MediaService::find($storedMediaId)?->altText ?? ''));

    // A text that only repeats the library's stays the library's.
    $words['image_alt'] = BlockImage::ownAlt($words['image_alt'], $imageChosen['media_id'], $isDefaultLanguage);

    // BlockLocalization::problems(), as a message per field.
    $fieldErrors += EditorChildList::wordErrors('homepage_hero', $languageCode, $words);

    // THE ALT TEXT is required the way it was before the library: with a
    // newly chosen image, or when the editor changes it. What must not be
    // empty is the text that will be used — own, else the library's.
    $usedAlt = $submittedAlt !== '' ? $submittedAlt : $libraryAlt;
    $hasAnImage = $image['media_id'] !== null || $image['image_path'] !== '';
    if ($isDefaultLanguage && $hasAnImage && $usedAlt === '' && ($imageIsNew || $submittedAlt !== $shownAlt)) {
        $fieldErrors['image_alt'] ??= AdminTranslator::trans('block_hero.error_alt_required');
    }

    // A secondary button needs both a label and a destination, or neither —
    // a half-filled optional button would be broken/dead on the frontend.
    $defaultSecondaryLabel = $isDefaultLanguage ? $words['secondary_label'] : $storedDefault('secondary_label');
    $secondaryIsButton = $settings['secondary_link_type'] !== null;
    if (($defaultSecondaryLabel !== '') !== $secondaryIsButton || ($words['secondary_label'] !== '' && !$secondaryIsButton)) {
        $fieldErrors['secondary_url'] ??= AdminTranslator::trans('validation.secondary_button_label_and_url');
    }

    // The badge needs both a title and a body text, or neither. A
    // translation's badge words need a badge the default language completes,
    // or they could never show.
    $badgeRefused = $isDefaultLanguage
        ? ($words['badge_title'] !== '') !== ($words['badge_text'] !== '')
        : ($words['badge_title'] !== '' || $words['badge_text'] !== '')
            && ($storedDefault('badge_title') === '' || $storedDefault('badge_text') === '');
    if ($badgeRefused) {
        $fieldErrors['badge_text'] ??= AdminTranslator::trans($isDefaultLanguage ? 'validation.badge_title_and_text' : 'validation.badge_in_default_language_first');
    }

    // The highlight must occur verbatim in its title — never silently save
    // an impossible highlight.
    $shownTitle = $isDefaultLanguage || $words['title'] !== '' ? $words['title'] : $storedDefault('title');
    if (!HomepageHeroContent::isHighlightValid($shownTitle, $words['title_highlight'])) {
        $fieldErrors['title_highlight'] ??= AdminTranslator::trans($isDefaultLanguage
            ? 'validation.highlight_in_title'
            : 'validation.highlight_in_title_or_default');
    }
}

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
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

if (!in_array($settings['media_type'], HomepageHeroContent::MEDIA_TYPES, true)) {
    $errors[] = AdminTranslator::trans('validation.ongeldig_media_type');
}

if (!in_array($settings['layout'], HomepageHeroContent::LAYOUTS, true)) {
    $errors[] = AdminTranslator::trans('validation.ongeldige_lay_out');
}

if ($stats->keptCount() > HomepageHeroContent::MAX_STATS) {
    $errors[] = AdminTranslator::trans('block_hero.error_max_stats', ['max' => HomepageHeroContent::MAX_STATS]);
}

// One line per problem at the top, the same line next to its field.
foreach ($fieldErrors as $message) {
    if (!in_array($message, $errors, true)) {
        $errors[] = $message;
    }
}

if ($languageIsWritable) {
    $statErrors = $stats->problems($languageCode);
    array_push($errors, ...$stats->summary($statErrors, AdminTranslator::trans('block_hero.statistiek')));
    $fieldErrors += $statErrors;
}

// As typed and chosen: the kinds as chosen, not as they would be stored.
$old = ['language_code' => $languageCode, 'image_alt' => $submittedAlt] + $words + $settings + [
    'primary_link_type' => $linkTypes['primary'],
    'secondary_link_type' => $linkTypes['secondary'],
    'primary_link_target' => array_map('intval', array_filter($postedTargets['primary'], 'is_scalar')),
    'secondary_link_target' => array_map('intval', array_filter($postedTargets['secondary'], 'is_scalar')),
    'media_id' => (string) (int) $imagePosted,
    'video_media_id' => (string) (int) $videoPosted,
    'remove_legacy_image' => isset($_POST['remove_legacy_image']) ? '1' : '',
    'remove_legacy_video' => isset($_POST['remove_legacy_video']) ? '1' : '',
    'stats' => $stats->old(),
];

/** Back to the screen with everything as typed. */
$refuse = static function (array $errors, array $fieldErrors) use ($old, $redirect): never {
    $_SESSION['admin_homepage_hero_errors'] = $errors;
    $_SESSION['admin_homepage_hero_field_errors'] = $fieldErrors;
    $_SESSION['admin_homepage_hero_old'] = $old;
    header('Location: ' . $redirect);
    exit;
};

if ($errors !== []) {
    $refuse($errors, $fieldErrors);
}

$db = Database::connection();

try {
    // The Hero's settings, its files, its words in this language and every
    // stat are one save.
    $db->beginTransaction();

    $repository->upsert(
        HomepageHeroContent::PAGE_SLUG,
        [
            'title_highlight_size' => (int) $settings['title_highlight_size'],
            // "Geen knop" stores no address either: a row without a type
            // but with an address reads as an address
            // (LinkChoice::storedType()). $old keeps it as typed.
            'primary_url' => $settings['primary_link_type'] === null ? '' : $settings['primary_url'],
            'primary_link_type' => $settings['primary_link_type'],
            'primary_link_target_id' => $settings['primary_link_target_id'],
            'secondary_url' => $settings['secondary_link_type'] === null ? '' : $settings['secondary_url'],
            'secondary_link_type' => $settings['secondary_link_type'],
            'secondary_link_target_id' => $settings['secondary_link_target_id'],
            'media_type' => $settings['media_type'],
            'layout' => $settings['layout'],
        ]
        + $image
        + $video
        + HomepageHeroContent::settingsOf($current)
        + ['is_active' => true]
    );
    BlockLocalization::save('homepage_hero', $heroId, $languageCode, $words);

    $stats->save(
        $languageCode,
        static function (array $row) use ($repository, $heroId): int {
            $id = $repository->createStat($heroId);
            $repository->updateStat($id, ['is_active' => EditorChildList::flag($row, 'active')]);

            return $id;
        },
        static fn (int $id, array $row) => $repository->updateStat($id, ['is_active' => EditorChildList::flag($row, 'active')]),
        static fn (int $id) => $repository->deleteStat($id),
        static fn (array $order) => $repository->reorderStats($heroId, $order)
    );

    $db->commit();
    HomepageHeroContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-homepage-hero.php] ' . $e->getMessage());

    $refuse([AdminTranslator::trans('editor_rows.error_save_failed')], []);
}

// A pre-library file that is no longer used goes only after the commit, and
// only when it was itself an admin upload (both uploaders' delete() is a
// no-op for any path outside their own directory, and for an empty path).
if ($legacyImage !== '' && $image['image_path'] !== $legacyImage) {
    (new SectionImageUploader())->delete($legacyImage);
}
if ($legacyVideo !== '' && $video['video_path'] !== $legacyVideo) {
    (new SectionVideoUploader())->delete($legacyVideo);
}

header('Location: ' . $redirect . '?saved=1');
exit;
