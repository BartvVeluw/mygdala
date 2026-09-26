<?php

/**
 * POST /api/admin/update-media-banner.php
 *
 * Saves one Mediabanner block (admin/media-banner.php?section=...): the
 * picture or video, the width, the height, a picture's focus point and a
 * video's options. The same `<page>:<key>` gate as
 * api/admin/update-spacer.php: the page must exist by its immutable
 * content_key AND the block's row must already exist (created by
 * App\Service\SectionRegistry::create()), before anything is read or written.
 *
 * WHAT IS CHECKED, and refused at its field rather than stored:
 *   - media_id: empty is "nothing chosen yet"; anything else must name a
 *     picture or a video of the Media Library (MediaBannerContent::usableItem()).
 *     A document, an unknown id, or a file no kind claims is refused. The
 *     path is always the library's; nothing of a file comes from the request.
 *   - width, height, image_focus: words of closed lists. A form without the
 *     field keeps what is stored, an unknown word is refused.
 *   - the three video switches: a checkbox posts "1", and an absent one is
 *     off. Anything else ("yes", an array) is refused, never read as on.
 *   - a video that does not play by itself must have controls: otherwise a
 *     visitor could never start it. Autoplay itself is always muted, which is
 *     MediaBannerContent's rule and not a setting.
 *   - poster_media_id: empty, or a picture of the library.
 *
 * WHAT IS KEPT. The chosen item decides which settings mean anything. With a
 * picture the focus point is stored and the posted video options are not:
 * the stored ones stay as they are, so a banner that goes back to a video
 * gets them back, and a picture never gains video settings it cannot show.
 * With a video it is the other way round. The poster is cleared unless the
 * item is a video: a poster nobody sees must not count as a use of a library
 * item and keep it from being deleted.
 *
 * A banner has no words and no language, so there is no language here and no
 * transaction: one row, one UPDATE.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\MediaBannerRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Media\ImageFocus;
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
$isImage = $media !== null && $media->isPicture();
$isVideo = $media !== null && $media->isVideo();

// ----------------------------------------------------------------- layout
// Each a word from its closed list. A form without the field keeps what is
// stored; a word that is not on the list is refused at its field.
$choices = [
    'width' => [MediaBannerContent::WIDTHS, MediaBannerContent::width($section['width'] ?? null)],
    'height' => [MediaBannerContent::HEIGHTS, MediaBannerContent::height($section['height'] ?? null)],
];
if ($isImage) {
    $choices['image_focus'] = [ImageFocus::keys(), ImageFocus::normalise($section['image_focus'] ?? null)];
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

if ($isVideo) {
    if ($video['video_autoplay'] === false && $video['video_controls'] === false) {
        $fieldErrors['video_controls'] = AdminTranslator::trans('block_media_banner.error_controls');
    }
    if ($posterPosted === null || ($posterPosted !== '' && $posterPosted !== '0' && $poster === null)) {
        $fieldErrors['poster_media_id'] = AdminTranslator::trans('block_media_banner.error_poster');
    }

    $settings += [
        'video_autoplay' => (bool) $video['video_autoplay'],
        'video_loop' => (bool) $video['video_loop'],
        'video_controls' => (bool) $video['video_controls'],
    ];
}

$settings['media_id'] = $media?->id;
$settings['poster_media_id'] = $isVideo ? $poster?->id : null;

// What a refused save hands back: everything as posted, so the editor reopens
// on what was chosen.
$old = [
    'media_id' => (string) $mediaPosted,
    'poster_media_id' => (string) $posterPosted,
    'width' => $settings['width'],
    'height' => $settings['height'],
    'image_focus' => $settings['image_focus'] ?? ImageFocus::normalise($posted('image_focus')),
    'video_autoplay' => $video['video_autoplay'] === true,
    'video_loop' => $video['video_loop'] === true,
    'video_controls' => $video['video_controls'] === true,
];

if ($fieldErrors !== []) {
    $_SESSION['admin_media_banner_errors'] = array_values(array_unique(array_values($fieldErrors)));
    $_SESSION['admin_media_banner_field_errors'] = $fieldErrors;
    $_SESSION['admin_media_banner_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->update((int) $section['id'], $settings);
    MediaBannerContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-media-banner.php] ' . $e->getMessage());

    $_SESSION['admin_media_banner_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_media_banner_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
