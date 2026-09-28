<?php

/**
 * POST /api/admin/update-media-banner.php
 *
 * Saves one Mediabanner block (admin/media-banner.php?section=...): the
 * picture or video, the items after it, the width, the height, how a
 * picture sits in the frame, the playing options and the choices of a
 * sequence. The same
 * `<page>:<key>` gate as api/admin/update-spacer.php: the page must exist by
 * its immutable content_key AND the block's row must already exist (created
 * by App\Service\SectionRegistry::create()), before anything is read or
 * written.
 *
 * WHAT IS CHECKED, and refused at its field rather than stored:
 *   - media_id: empty is "nothing chosen yet"; anything else must name a
 *     picture or a video of the Media Library (MediaBannerContent::usableItem()).
 *     A document, an unknown id, or a file no kind claims is refused. The
 *     path is always the library's; nothing of a file comes from the request.
 *   - sequence[]: the items after the first, as the `media:<id>` tokens of
 *     the shared list (admin/_media_sequence_field.php), when
 *     `sequence_submitted` says the list was on the form (a form without it
 *     keeps the stored list). Each must be a picture or a video of the
 *     library, the first item is not repeated, and there are at most
 *     MediaSequence::MAX_ITEMS in all.
 *   - width, height, slide_transition, slide_duration, slide_controls: words
 *     and numbers of closed lists. A form without the field keeps what is
 *     stored, an unknown value is refused.
 *   - how a picture sits in the frame (Responsive Media 2.0): its focus
 *     point, its fit, a phone's own picture, point, fit and height, read by
 *     App\Service\Media\ResponsiveImage::fromRequest() and refused part by
 *     part (`presentation.<part>`).
 *   - the three video switches: a checkbox posts "1", and an absent one is
 *     off. Anything else ("yes", an array) is refused, never read as on.
 *   - a video that does not play by itself must have controls: otherwise a
 *     visitor could never start it. Autoplay itself is always muted, which is
 *     MediaBannerContent's rule and not a setting.
 *   - a sequence that does not play by itself needs arrows or dots: otherwise
 *     a visitor could never see its second item.
 *   - poster_media_id: empty, or a picture of the library.
 *
 * WHAT IS KEPT. The chosen items decide which settings mean anything. With a
 * picture among them its presentation is stored (App\Repository\ResponsiveImageRepository); with a video, or with more
 * than one item, the playing options are, and the stored ones stay as they
 * are otherwise, so a banner that goes back to a video gets them back and a
 * single picture never gains options it cannot show. The sequence's choices
 * are stored only for a sequence. The poster is cleared unless the first item
 * is a video: a poster nobody sees must not count as a use of a library item
 * and keep it from being deleted. A banner whose first item is cleared while
 * the list still has items takes the first of them as its first; without a
 * first item there are no further ones either.
 *
 * A banner has no words and no language. The row and its further items are
 * one transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\MediaBannerRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Media\ResponsiveImage;
use App\Repository\ResponsiveImageRepository;
use App\Service\Media\MediaSequence;
use App\Service\MediaBannerContent;

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

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new MediaBannerRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$redirect = '/admin/media-banner.php?section=' . urlencode($sectionParam);
$fieldErrors = [];

/** A posted scalar as a string, or null when the request holds something else (an array). */
$posted = static function (string $name): ?string {
    $value = $_POST[$name] ?? '';

    return is_scalar($value) ? trim((string) $value) : null;
};

// ------------------------------------------------------------------ media
$mediaPosted = $posted('media_id');
$media = $mediaPosted !== null && ctype_digit($mediaPosted) ? MediaBannerContent::usableItem((int) $mediaPosted) : null;
if ($mediaPosted === null || ($mediaPosted !== '' && $mediaPosted !== '0' && $media === null)) {
    $fieldErrors['media_id'] = AdminTranslator::trans('block_media_banner.error_media');
}

// --------------------------------------------------------------- sequence
// The items after the first, when the list was on the form; else as stored.
$sequencePosted = isset($_POST['sequence_submitted']);
$sequence = $sequencePosted ? [] : $repository->findItemIds((int) $section['id']);
$sequenceHandback = null;
if ($sequencePosted) {
    $ids = MediaSequence::idsFromTokens($_POST['sequence'] ?? null);
    foreach ($ids ?? [] as $id) {
        if (MediaBannerContent::usableItem($id) === null) {
            $ids = null;
            break;
        }
    }

    if ($ids === null) {
        $fieldErrors['sequence'] = AdminTranslator::trans('block_media_banner.error_slides', ['max' => (string) MediaSequence::MAX_ITEMS]);
        $sequenceHandback = array_values(array_filter(
            is_array($_POST['sequence'] ?? null) ? $_POST['sequence'] : [],
            static fn (mixed $token): bool => is_string($token) && preg_match('/^media:[1-9][0-9]{0,9}$/', $token) === 1
        ));
    } else {
        // A first item that is cleared makes way for the list's first.
        if ($media === null && !isset($fieldErrors['media_id']) && $ids !== []) {
            $media = MediaBannerContent::usableItem(array_shift($ids));
        }
        $sequence = array_values(array_filter($ids, static fn (int $id): bool => $id !== $media?->id));
        if (count($sequence) + 1 > MediaSequence::MAX_ITEMS) {
            $fieldErrors['sequence'] = AdminTranslator::trans('block_media_banner.error_slides', ['max' => (string) MediaSequence::MAX_ITEMS]);
        }
    }
}
// No first item, no further ones.
if ($media === null) {
    $sequence = [];
}

$isImage = $media !== null && $media->isPicture();
$isVideo = $media !== null && $media->isVideo();
$isSequence = $media !== null && $sequence !== [];
$furtherKinds = array_map(
    static fn (int $id): string => MediaBannerContent::usableItem($id)?->isVideo() ? 'video' : 'image',
    $sequence
);
$hasImage = $isImage || in_array('image', $furtherKinds, true);
$hasVideo = $isVideo || in_array('video', $furtherKinds, true);

// ----------------------------------------------------------------- layout
// Each a word (or number) from its closed list. A form without the field
// keeps what is stored; a value that is not on the list is refused at its
// field.
$choices = [
    'width' => [MediaBannerContent::WIDTHS, MediaBannerContent::width($section['width'] ?? null)],
    'height' => [MediaBannerContent::HEIGHTS, MediaBannerContent::height($section['height'] ?? null)],
];
if ($isSequence) {
    $choices['slide_transition'] = [MediaSequence::TRANSITIONS, MediaSequence::transition($section['slide_transition'] ?? null)];
    $choices['slide_duration'] = [array_map('strval', MediaSequence::DURATIONS), (string) MediaSequence::duration($section['slide_duration'] ?? null)];
    $choices['slide_controls'] = [MediaSequence::CONTROLS, MediaSequence::controls($section['slide_controls'] ?? null)];
}

$settings = [];
foreach ($choices as $name => [$list, $current]) {
    $value = array_key_exists($name, $_POST) ? $posted($name) : $current;
    if ($value === null || !in_array($value, $list, true)) {
        $fieldErrors[$name] = AdminTranslator::trans('block_media_banner.error_' . $name);
        $value = $current;
    }
    $settings[$name] = $value;
}
if (isset($settings['slide_duration'])) {
    $settings['slide_duration'] = (int) $settings['slide_duration'];
}

// ------------------------------------------------------------------ video
/** A switch: "1" is on, absent is off, anything else is refused (null). */
$switch = static function (string $name): ?bool {
    if (!array_key_exists($name, $_POST)) {
        return false;
    }

    return $_POST[$name] === '1' ? true : null;
};

$video = [];
foreach (['video_autoplay', 'video_loop', 'video_controls'] as $name) {
    $video[$name] = $switch($name);
    if ($video[$name] === null) {
        $fieldErrors[$name] = AdminTranslator::trans('block_media_banner.error_switch');
    }
}

$posterPosted = $posted('poster_media_id');
$poster = $posterPosted !== null && ctype_digit($posterPosted) ? MediaBannerContent::poster((int) $posterPosted) : null;

if ($isVideo || $isSequence) {
    if ($hasVideo && $video['video_autoplay'] === false && $video['video_controls'] === false) {
        $fieldErrors['video_controls'] = AdminTranslator::trans('block_media_banner.error_controls');
    }
    if ($isSequence && $video['video_autoplay'] === false && ($settings['slide_controls'] ?? '') === 'none') {
        $fieldErrors['slide_controls'] = AdminTranslator::trans('block_media_banner.error_nav');
    }

    $settings += [
        'video_autoplay' => (bool) $video['video_autoplay'],
        'video_loop' => (bool) $video['video_loop'],
        'video_controls' => (bool) $video['video_controls'],
    ];
}

if ($isVideo && ($posterPosted === null || ($posterPosted !== '' && $posterPosted !== '0' && $poster === null))) {
    $fieldErrors['poster_media_id'] = AdminTranslator::trans('block_media_banner.error_poster');
}

$settings['media_id'] = $media?->id;
$settings['poster_media_id'] = $isVideo ? $poster?->id : null;

// How a picture sits in the frame, on a large screen and on a phone
// (Responsive Media 2.0): stored only with a picture among the items, and a
// part the form does not carry keeps what is stored.
$imageSlot = MediaBannerContent::imageSlot();
$presentation = ResponsiveImage::fromRow($section, $imageSlot);
if ($hasImage) {
    [$presentation, $presentationErrors] = ResponsiveImage::fromRequest($_POST, $imageSlot, $presentation);
    foreach ($presentationErrors as $part => $message) {
        $fieldErrors['presentation.' . $part] = $message;
    }
}

// What a refused save hands back: everything as posted, so the editor reopens
// on what was chosen.
$old = [
    'media_id' => $media !== null ? (string) $media->id : (string) $mediaPosted,
    'poster_media_id' => (string) $posterPosted,
    'width' => $settings['width'],
    'height' => $settings['height'],
    'presentation' => $presentation->toRow($imageSlot),
    'video_autoplay' => $video['video_autoplay'] === true,
    'video_loop' => $video['video_loop'] === true,
    'video_controls' => $video['video_controls'] === true,
    'slide_transition' => $settings['slide_transition'] ?? $posted('slide_transition'),
    'slide_duration' => $settings['slide_duration'] ?? $posted('slide_duration'),
    'slide_controls' => $settings['slide_controls'] ?? $posted('slide_controls'),
] + ($sequencePosted ? ['sequence' => $sequenceHandback ?? array_map(static fn (int $id): string => 'media:' . $id, $sequence)] : []);

if ($fieldErrors !== []) {
    $_SESSION['admin_media_banner_errors'] = array_values(array_unique(array_values($fieldErrors)));
    $_SESSION['admin_media_banner_field_errors'] = $fieldErrors;
    $_SESSION['admin_media_banner_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The row and its further items are one save.
    $db->beginTransaction();

    $repository->update((int) $section['id'], $settings);
    (new ResponsiveImageRepository())->save('media_banners', (int) $section['id'], $imageSlot, $presentation);
    if ($sequencePosted || $media === null) {
        $repository->replaceItems((int) $section['id'], $sequence);
    }

    $db->commit();
    MediaBannerContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-media-banner.php] ' . $e->getMessage());

    $_SESSION['admin_media_banner_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_media_banner_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
