<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_image_focus.php';

use App\Repository\MediaBannerRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaType;
use App\Service\MediaBannerContent;
use App\Service\SectionRegistry;

/**
 * Editor for one Mediabanner block (?section=<page content_key>:<section_key>):
 * the picture or video, the width, the height, a picture's focus point and a
 * video's options. The same "valid only when the page and its content row
 * really exist" gate as admin/spacer.php.
 *
 * THREE CARDS (CONTENT-BLOCKS.md, "Mediabanner"): Media (one field that takes
 * a picture or a video from the Media Library, MediaType::VISUAL; the chosen
 * item decides which it is, so there is no image/video switch), Weergave
 * (width, height, and the focus point for a picture) and Video (autoplay,
 * loop, controls and an optional poster, for a video). What only one kind
 * needs shows only for that kind (admin/assets/media-banner.js); the server
 * prints the same `hidden` for what is stored, the form always posts every
 * field, and the endpoint decides what a value means.
 *
 * No website language on this screen: a banner has no words. The picture's
 * alt text is the library's (Media Library → the item). Whether the block
 * shows is the page builder's eye, like for every block; this screen has no
 * second switch for it.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new MediaBannerRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$errors = $_SESSION['admin_media_banner_errors'] ?? [];
$fieldErrors = $_SESSION['admin_media_banner_field_errors'] ?? [];
$old = $_SESSION['admin_media_banner_old'] ?? null;
unset($_SESSION['admin_media_banner_errors'], $_SESSION['admin_media_banner_field_errors'], $_SESSION['admin_media_banner_old']);
$saved = isset($_GET['saved']);

// What is on screen: as a refused save handed it back, else as stored — each
// value checked against its list or the library either way.
$source = is_array($old) ? $old : [
    'media_id' => (string) ($section['media_id'] ?? ''),
    'width' => $section['width'] ?? null,
    'height' => $section['height'] ?? null,
    'image_focus' => $section['image_focus'] ?? null,
    'video_autoplay' => (bool) ($section['video_autoplay'] ?? false),
    'video_loop' => (bool) ($section['video_loop'] ?? false),
    'video_controls' => (bool) ($section['video_controls'] ?? true),
    'poster_media_id' => (string) ($section['poster_media_id'] ?? ''),
];
$mediaId = (string) ($source['media_id'] ?? '');
$media = MediaBannerContent::usableItem(ctype_digit($mediaId) ? (int) $mediaId : null);
$posterId = (string) ($source['poster_media_id'] ?? '');
$poster = MediaBannerContent::poster(ctype_digit($posterId) ? (int) $posterId : null);
$width = MediaBannerContent::width($source['width'] ?? null);
$height = MediaBannerContent::height($source['height'] ?? null);
$focus = ImageFocus::normalise($source['image_focus'] ?? null);
$autoplay = !empty($source['video_autoplay']);
$loop = !empty($source['video_loop']);
$controls = !empty($source['video_controls']);
$isImage = $media !== null && $media->isPicture();
$isVideo = $media !== null && $media->isVideo();

$pageLabel = \App\Service\PageLocalization::name((int) $page['id']);
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * One closed-list choice as a segmented control in a fieldset with a legend
 * (the same markup as admin/cta-band.php), its words under
 * block_media_banner.<name>_<value>.
 *
 * @param list<string> $values
 */
$choice = static function (string $name, array $values, string $chosen) use ($h, $fieldErrors): void {
    $legend = admin_t('block_media_banner.' . $name);
    ?>
      <fieldset class="admin-segmented-field"<?= editor_field_invalid($fieldErrors, $name) ?>>
        <legend><?= $h($legend) ?> <?= admin_help($legend, admin_t('help.block_media_banner.' . $name)) ?></legend>
        <div class="admin-segmented">
          <?php foreach ($values as $value): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="<?= $h($name) ?>" value="<?= $h($value) ?>"<?= $chosen === $value ? ' checked' : '' ?>>
              <span><?= admin_te('block_media_banner.' . $name . '_' . $value) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php editor_field_error($fieldErrors, $name); ?>
      </fieldset>
    <?php
};

/** One video option as a switch with its help button. */
$switch = static function (string $name, string $wordKey, bool $checked) use ($h, $fieldErrors): void {
    ?>
      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="<?= $h($name) ?>" value="1"<?= $checked ? ' checked' : '' ?><?= editor_field_invalid($fieldErrors, $name) ?>>
          <?= admin_te('block_media_banner.' . $wordKey) ?>
        </label>
        <?= admin_help(admin_t('block_media_banner.' . $wordKey), admin_t('help.block_media_banner.' . $wordKey)) ?>
      </div>
      <?php editor_field_error($fieldErrors, $name); ?>
    <?php
};
?>
<!doctype html>
<html lang="<?= $h(\App\Service\Language\AdminLocale::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('media_banner')) ?> — <?= $h($pageLabel) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_media_banner.terug', ['v1' => $h($pageLabel)]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('media_banner')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_media_banner.uitleg', ['v1' => $h($pageLabel)]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error" role="alert">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/update-media-banner.php" class="admin-product-form" data-media-banner-form<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

    <section class="admin-card">
      <h2><?= admin_te('block_media_banner.group_media') ?></h2>
      <div data-media-banner-main>
        <?php media_picker_field(
            'media_id',
            $media,
            admin_t('block_media_banner.media'),
            admin_t('block_media_banner.media_help'),
            true,
            MediaType::VISUAL
        ); ?>
      </div>
      <?php editor_field_error($fieldErrors, 'media_id'); ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_media_banner.group_layout') ?></h2>
      <?php $choice('width', MediaBannerContent::WIDTHS, $width); ?>
      <?php $choice('height', MediaBannerContent::HEIGHTS, $height); ?>

      <div data-media-banner-needs="image"<?= $isImage ? '' : ' hidden' ?>>
        <?php media_focus_field(
            'image_focus',
            $focus,
            $isImage ? $media->displayPath() : '',
            admin_t('block_media_banner.focus'),
            admin_t('help.block_media_banner.focus'),
            admin_t('block_media_banner.focus_voorbeeld')
        ); ?>
        <?php editor_field_error($fieldErrors, 'image_focus'); ?>
      </div>
    </section>

    <section class="admin-card" data-media-banner-needs="video"<?= $isVideo ? '' : ' hidden' ?>>
      <h2><?= admin_te('block_media_banner.group_video') ?></h2>
      <?php $switch('video_autoplay', 'autoplay', $autoplay); ?>
      <?php $switch('video_loop', 'loop', $loop); ?>
      <?php $switch('video_controls', 'controls', $controls); ?>

      <?php media_picker_field(
          'poster_media_id',
          $poster,
          admin_t('block_media_banner.poster'),
          admin_t('block_media_banner.poster_help')
      ); ?>
      <?php editor_field_error($fieldErrors, 'poster_media_id'); ?>

      <p class="admin-text-muted"><?= admin_te('block_media_banner.video_uitleg') ?></p>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php save_bar_script(); ?>
<?php media_picker_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/image-focus.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/media-banner.js') ?>" defer></script>
</body>
</html>
