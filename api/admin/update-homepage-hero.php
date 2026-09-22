<?php

/**
 * POST /api/admin/update-homepage-hero.php
 *
 * Saves the WHOLE Homepage Hero editor (admin/homepage-hero.php) in one
 * request: its texts (eyebrow, title, highlight and its display size, lead,
 * both buttons, the badge), its media type and layout, its image (a new file
 * in `image`, optional, and the alt text), its video (a new file in `video`,
 * optional) and its stats — their words, whether each is shown, their
 * order, new ones and the ones marked for removal. One form, one save
 * (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is no separate
 * image, video, media or stat save any more.
 *
 * There is no is_active field on purpose — the admin editor does not expose
 * a whole-Hero visibility checkbox (see HomepageHeroContent's docblock), so
 * this endpoint always saves with is_active = true. The Hero row itself is
 * created by admin/homepage-hero.php the first time it is opened, so a save
 * without one did not come from that screen and is refused.
 *
 * ALL OR NOTHING. Everything that can be checked without the files is
 * checked first; a refused save stores nothing and hands every typed value
 * back (a file field cannot be handed back, so the screen asks for the file
 * again). Only then are the files stored (App\Service\SectionImageUploader,
 * App\Service\SectionVideoUploader, which refuse a wrong type or size with a
 * message of their own), and the row, its words and its stats are one
 * transaction. If anything fails after a file was stored, the new file is
 * deleted again; a replaced file is deleted only after the commit, and only
 * when it was an admin upload. Choosing a video does not switch the Hero to
 * it: "Media" is its own, explicit choice, so a video can be staged first.
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

$hasImage = isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
$hasVideo = isset($_FILES['video']) && ($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

// The Hero's own stats; a key naming any other row is dropped.
$storedIds = array_map(static fn (array $stat): int => (int) $stat['id'], $repository->findStatsByHeroId($heroId));
$stats = EditorChildList::fromRequest($_POST, 'stats', 'homepage_hero_stats', $storedIds, EditorRows::parseAction($_POST['editor_action'] ?? null));

/** The words the default language has stored for one field: what a translation falls back to. */
$storedDefault = static fn (string $field): string => BlockLocalization::raw('homepage_hero', $heroId, $field, $defaultLanguage);

$fieldErrors = [];

if ($languageIsWritable) {
    // BlockLocalization::problems(), as a message per field.
    $fieldErrors = EditorChildList::wordErrors('homepage_hero', $languageCode, $words);

    // A secondary button needs both a label and a URL, or neither — a
    // half-filled optional button would be broken/dead on the frontend.
    $defaultSecondaryLabel = $isDefaultLanguage ? $words['secondary_label'] : $storedDefault('secondary_label');
    $secondaryUrlSet = $settings['secondary_url'] !== '';
    if (($defaultSecondaryLabel !== '') !== $secondaryUrlSet || ($words['secondary_label'] !== '' && !$secondaryUrlSet)) {
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

if ($settings['primary_url'] === '') {
    $fieldErrors['primary_url'] = AdminTranslator::trans('validation.veld_verplicht');
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

$old = ['language_code' => $languageCode] + $words + $settings + ['stats' => $stats->old()];

/** Back to the screen with everything as typed; a chosen file has to be chosen again. */
$refuse = static function (array $errors, array $fieldErrors) use ($old, $redirect, $hasImage, $hasVideo): never {
    if ($hasImage || $hasVideo) {
        $errors[] = AdminTranslator::trans('block_hero.file_choose_again');
    }
    $_SESSION['admin_homepage_hero_errors'] = $errors;
    $_SESSION['admin_homepage_hero_field_errors'] = $fieldErrors;
    $_SESSION['admin_homepage_hero_old'] = $old;
    header('Location: ' . $redirect);
    exit;
};

if ($errors !== []) {
    $refuse($errors, $fieldErrors);
}

// Only now the files: nothing is written to disk for a save that is refused anyway.
$imageUploader = new SectionImageUploader();
$videoUploader = new SectionVideoUploader();
$newImagePath = null;
$newVideoPath = null;

try {
    if ($hasImage) {
        $newImagePath = $imageUploader->store($_FILES['image']);
    }
} catch (\RuntimeException $e) {
    $refuse([$e->getMessage()], ['image' => $e->getMessage()]);
}

try {
    if ($hasVideo) {
        $newVideoPath = $videoUploader->store($_FILES['video']);
    }
} catch (\RuntimeException $e) {
    if ($newImagePath !== null) {
        $imageUploader->delete($newImagePath);
    }
    $refuse([$e->getMessage()], ['video' => $e->getMessage()]);
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
            'primary_url' => $settings['primary_url'],
            'secondary_url' => $settings['secondary_url'],
            'media_type' => $settings['media_type'],
            'layout' => $settings['layout'],
        ]
        + ($newImagePath !== null ? ['image_path' => $newImagePath] : [])
        + ($newVideoPath !== null ? ['video_path' => $newVideoPath] : [])
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

    if ($newImagePath !== null) {
        $imageUploader->delete($newImagePath);
    }
    if ($newVideoPath !== null) {
        $videoUploader->delete($newVideoPath);
    }

    $refuse([AdminTranslator::trans('editor_rows.error_save_failed')], []);
}

// Only remove a replaced file after the new one is safely saved, and only if
// it was itself an admin upload (both uploaders' delete() is a no-op for any
// path outside their own directory, and for an empty path).
if ($newImagePath !== null) {
    $imageUploader->delete((string) $current['image_path']);
}
if ($newVideoPath !== null) {
    $videoUploader->delete((string) ($current['video_path'] ?? ''));
}

header('Location: ' . $redirect . '?saved=1');
exit;
