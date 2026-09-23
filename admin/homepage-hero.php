<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_link_target_field.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\HomepageHeroContent;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Service\Routing\LinkChoice;
use App\Repository\HomepageHeroRepository;

/**
 * Editor for the Homepage Hero: its texts, its buttons and badge, its media
 * and layout, its image or video, and its stats.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor").
 * Everything on this screen posts to api/admin/update-homepage-hero.php
 * together, and its one "Opslaan" (or the save bar) stores all of it in one
 * transaction. The image and the video are chosen from the Media Library
 * (admin/_media_picker.php; the video field lists only video), so choosing
 * one is part of that save and nothing typed elsewhere is lost by it. A Hero
 * from before the library keeps showing the file it had until another is
 * chosen. Each button points at a page, a blog post, a product or an own
 * address (admin/_link_target_field.php, the Kaarten-carrousel's field).
 * Longer explanations sit behind the "?" beside a label (ADMIN-UI.md).
 * The stats are a list of rows, at most
 * HomepageHeroContent::MAX_STATS: ↑, ↓ and "Statistiek toevoegen" work on
 * screen (admin/assets/row-list.js), Verwijderen marks a stat until the save
 * (App\Service\Blocks\EditorChildList, admin/_editor_rows.php).
 *
 * The image is always on screen: it is the Hero's picture, and with "Video"
 * chosen it is the video's poster. The video card shows only while "Video"
 * is chosen (admin/assets/admin.js).
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the texts, the image's alt text and every stat show the language chosen in
 * the CMS shell, as stored and without the default language's words in an
 * empty translation, and are required only in the default language; a save
 * writes that language only. The URLs, the highlight size, the media, the
 * layout and a stat's visibility are the same in every language and stay on
 * screen in each. A stat keeps its id however often it is saved or moved,
 * so the words of the other languages stay with it; a NEW stat is written
 * in the default language, like a new page. Input a refused save hands back
 * comes back as it was typed (stats, order and marks included), with each
 * message next to its field, and the form then starts out unsaved in the
 * save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$repository = new HomepageHeroRepository();

$hero = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
if ($hero === null) {
    // First time this editor is opened: create the Hero now, exactly as the
    // page builder does (HomepageHeroBlock::create(): the generic starting
    // values, and the starting words in the website's default language), so
    // stats can be attached to it. is_active is always true — this editor
    // never exposes a whole-Hero visibility checkbox.
    BlockDefinitions::get('homepage_hero')?->create(HomepageHeroContent::PAGE_SLUG);
    $hero = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
}

$heroId = (int) $hero['id'];
$stats = $repository->findStatsByHeroId($heroId);

$errors = $_SESSION['admin_homepage_hero_errors'] ?? [];
$fieldErrors = $_SESSION['admin_homepage_hero_field_errors'] ?? [];
$old = $_SESSION['admin_homepage_hero_old'] ?? null;
unset($_SESSION['admin_homepage_hero_errors'], $_SESSION['admin_homepage_hero_field_errors'], $_SESSION['admin_homepage_hero_old']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();

// The words of the Hero and of every stat, in one query.
BlockLocalization::preloadBlocks(['homepage_hero' => [$heroId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The Hero's words on screen: typed and handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $heroId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('homepage_hero', $heroId, $field, $editLanguage);
};

/** A language-neutral value: handed back, else stored. */
$setting = static fn (string $key): string => is_array($old) ? (string) ($old[$key] ?? '') : (string) ($hero[$key] ?? '');

// The stats on screen: as a refused save handed them back, else as stored.
$rows = editor_rows_on_screen(
    $stats,
    $oldInThisLanguage ? (array) ($old['stats'] ?? []) : null,
    static fn (array $stat): array => [
        'primary_text' => BlockLocalization::raw('homepage_hero_stats', (int) $stat['id'], 'primary_text', $editLanguage),
        'secondary_text' => BlockLocalization::raw('homepage_hero_stats', (int) $stat['id'], 'secondary_text', $editLanguage),
        'active' => (int) $stat['is_active'] === 1 ? '1' : '',
    ]
);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = admin_localized_optional_attr($editLanguage);

// Re-clamped rather than echoed raw, because it may come from
// $_SESSION['admin_homepage_hero_old'] — i.e. from a REJECTED save, whose
// highlight size is by definition not guaranteed to be a valid percentage.
$highlightSize = HomepageHeroContent::clampHighlightSize(is_array($old) ? ($old['title_highlight_size'] ?? null) : ($hero['title_highlight_size'] ?? null));

$mediaType = $setting('media_type');
$mediaType = in_array($mediaType, HomepageHeroContent::MEDIA_TYPES, true) ? $mediaType : HomepageHeroContent::MEDIA_TYPE_IMAGE;
$layout = $setting('layout');
$layout = in_array($layout, HomepageHeroContent::LAYOUTS, true) ? $layout : HomepageHeroContent::LAYOUT_MEDIA_RIGHT;
// The image and the video: a library item as handed back or stored, and the
// file a Hero from before the library still shows while none is chosen.
$heroMedia = MediaService::findImage((int) (is_array($old) ? ($old['media_id'] ?? 0) : ($hero['media_id'] ?? 0)));
$heroVideo = MediaService::findVideo((int) (is_array($old) ? ($old['video_media_id'] ?? 0) : ($hero['video_media_id'] ?? 0)));
$legacyImage = (int) ($hero['media_id'] ?? 0) === 0 ? trim((string) ($hero['image_path'] ?? '')) : '';
$legacyVideo = (int) ($hero['video_media_id'] ?? 0) === 0 ? trim((string) ($hero['video_path'] ?? '')) : '';

/** One button's destination on screen: as handed back, else as stored. */
$button = static function (string $button) use ($old, $hero): array {
    $storedType = LinkChoice::storedType($hero[$button . '_link_type'] ?? null, (string) ($hero[$button . '_url'] ?? ''));
    $targets = is_array($old) ? (array) ($old[$button . '_link_target'] ?? []) : [];
    if (!is_array($old) && !in_array($storedType, [LinkChoice::NONE, LinkChoice::URL], true)) {
        $targets[$storedType] = (int) ($hero[$button . '_link_target_id'] ?? 0);
    }

    return [
        'stored_type' => $storedType,
        'type' => is_array($old) ? (string) ($old[$button . '_link_type'] ?? '') : $storedType,
        'targets' => $targets,
        'url' => is_array($old) ? (string) ($old[$button . '_url'] ?? '') : (string) ($hero[$button . '_url'] ?? ''),
    ];
};
$primary = $button('primary');
$secondary = $button('secondary');

/**
 * One text field of the Hero, with its own message and, when it needs more
 * than its label, the "?" with the longer explanation.
 */
$field = static function (string $name, string $label, int $maxLength, string $attributes, int $lines = 0, string $help = '', ?string $value = null) use ($h, $word, $fieldErrors): void {
    $id = 'hero-' . str_replace('_', '-', $name);
    $value ??= $word($name);
    echo '<div class="admin-field">' . admin_field_label($id, $label, $help);
    if ($lines > 0) {
        echo '<textarea id="' . $h($id) . '" name="' . $h($name) . '" maxlength="' . $maxLength . '" rows="' . $lines . '"' . $attributes
            . editor_field_invalid($fieldErrors, $name) . '>' . $h($value) . '</textarea>';
    } else {
        echo '<input type="text" id="' . $h($id) . '" name="' . $h($name) . '" maxlength="' . $maxLength . '" value="' . $h($value) . '"' . $attributes
            . editor_field_invalid($fieldErrors, $name) . '>';
    }
    editor_field_error($fieldErrors, $name);
    echo '</div>';
};

/** One stat; the template for a new one is the same markup with the key __KEY__. */
$statRow = static function (string $key, array $fields, int $position, int $count) use ($marker, $placeholder, $fieldErrors): void {
    [$star, $hint] = editor_row_word_hints($key, $marker, $placeholder);
    editor_row_open('stats', $key, admin_t('block_hero.statistiek'), $position, $count, ($fields['remove'] ?? '') !== '');
    echo '<div class="admin-row-card__pair">';
    editor_row_text('stats', $key, 'primary_text', admin_t('block_hero.primaire_tekst') . $star, 100, $fields, $fieldErrors, $hint);
    editor_row_text('stats', $key, 'secondary_text', admin_t('block_hero.secundaire_tekst') . $star, 150, $fields, $fieldErrors, $hint);
    echo '</div>';
    editor_row_switch('stats', $key, $fields, admin_t('common.visible'));
    editor_row_close();
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('block_hero.homepage_hero_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl('index'), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_hero.homepage') ?></a></p>
  <h1><?= admin_te('block_hero.homepage_hero') ?></h1>

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

  <form method="post" action="/api/admin/update-homepage-hero.php" class="admin-product-form" data-nav-item-form data-save-name="<?= admin_te('block_hero.homepage_hero') ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves a stat. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?= admin_localized_input($editLanguage) ?>
    <?php admin_localized_bar($editLanguage); ?>

    <section class="admin-card">
      <h2><?= admin_te('block_hero.algemene_inhoud') ?></h2>
      <?php $field('eyebrow', admin_t('block_hero.eyebrow'), 150, $optional, 0, admin_t('help.block_hero.eyebrow')); ?>
      <?php $field('title', admin_t('block_hero.titel_h1') . $marker, 255, $required . $placeholder); ?>
      <?php $field('title_highlight', admin_t('block_hero.highlight_titel'), 255, $optional, 0, admin_t('help.block_hero.title_highlight')); ?>

      <div class="admin-form-row">
        <?= admin_field_label('title_highlight_size', admin_t('block_hero.highlight_grootte'), admin_t('help.block_hero.highlight_size', ['v1' => HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT])) ?>
        <div class="admin-range" data-range-field>
          <input
            type="range"
            id="title_highlight_size"
            name="title_highlight_size"
            min="<?= HomepageHeroContent::HIGHLIGHT_SIZE_MIN ?>"
            max="<?= HomepageHeroContent::HIGHLIGHT_SIZE_MAX ?>"
            step="<?= HomepageHeroContent::HIGHLIGHT_SIZE_STEP ?>"
            value="<?= $highlightSize ?>"
            data-range-input>
          <output class="admin-range__value" for="title_highlight_size" data-range-output><?= $highlightSize ?>%</output>
        </div>
      </div>

      <?php $field('lead', admin_t('block_hero.introtekst_lead'), 500, $placeholder, 3); ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_hero.knoppen') ?></h2>

      <h3><?= admin_te('block_hero.primaire_knop') ?></h3>
      <?php $field('primary_label', admin_t('block_hero.label') . $marker, 150, $required . $placeholder); ?>
      <?php link_target_field([
          'id' => 'hero-primary-link',
          'type_name' => 'primary_link_type',
          'target_name' => 'primary_link_target',
          'url_name' => 'primary_url',
          'type' => $primary['type'],
          'targets' => $primary['targets'],
          'url' => $primary['url'],
          'stored_type' => $primary['stored_type'],
          // The primary button is always there: no "Geen knop".
          'allow_none' => false,
          'invalid' => editor_field_invalid($fieldErrors, 'primary_url'),
          'error' => static fn () => editor_field_error($fieldErrors, 'primary_url'),
      ]); ?>

      <?php /* The secondary button's label only matters while it is a button,
               so it sits in the button's own group and hides with "Geen knop"
               (admin/assets/navigation-item.js). */ ?>
      <div data-nav-link-group>
        <h3><?= admin_te('block_hero.secundaire_knop_optioneel') ?></h3>
        <?php link_target_field([
            'id' => 'hero-secondary-link',
            'type_name' => 'secondary_link_type',
            'target_name' => 'secondary_link_target',
            'url_name' => 'secondary_url',
            'type' => $secondary['type'],
            'targets' => $secondary['targets'],
            'url' => $secondary['url'],
            'stored_type' => $secondary['stored_type'],
            'invalid' => editor_field_invalid($fieldErrors, 'secondary_url'),
            'error' => static fn () => editor_field_error($fieldErrors, 'secondary_url'),
        ]); ?>
        <div data-nav-link-field="<?= $h(link_target_shown_kinds($secondary['stored_type'])) ?>">
          <?php $field('secondary_label', admin_t('block_hero.label_3'), 150, $placeholder); ?>
        </div>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_hero.badge') ?></h2>
      <?php $field('badge_title', admin_t('common.title'), 150, $optional, 0, admin_t('help.block_hero.badge')); ?>
      <?php $field('badge_text', admin_t('block_hero.tekst'), 500, $optional, 2); ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_t('block_hero.media_lay_out') ?></h2>

      <div class="admin-form-row">
        <span class="admin-form-row__label"><?= admin_te('block_hero.media') ?> <?= admin_help(admin_t('block_hero.media'), admin_t('help.block_hero.media')) ?></span>
        <div class="admin-segmented" data-media-type-group>
          <label class="admin-segmented__option">
            <input type="radio" name="media_type" value="image"<?= $mediaType === 'image' ? ' checked' : '' ?>>
            <span><?= admin_te('common.image') ?></span>
          </label>
          <label class="admin-segmented__option">
            <input type="radio" name="media_type" value="video"<?= $mediaType === 'video' ? ' checked' : '' ?>>
            <span><?= admin_te('block_hero.video') ?></span>
          </label>
        </div>
      </div>

      <div class="admin-form-row">
        <span class="admin-form-row__label"><?= admin_te('block_hero.lay_out') ?> <?= admin_help(admin_t('block_hero.lay_out'), admin_t('help.block_hero.layout')) ?></span>
        <div class="admin-segmented">
          <?php foreach (['media_left' => 'block_hero.media_links', 'media_right' => 'block_hero.media_rechts', 'background' => 'block_hero.volledige_achtergrond'] as $value => $label): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="layout" value="<?= $h($value) ?>"<?= $layout === $value ? ' checked' : '' ?>>
              <span><?= admin_te($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <h3><?= admin_te('common.image') ?> <?= admin_help(admin_t('common.image'), admin_t('help.block_hero.image')) ?></h3>
      <div class="admin-field">
        <?php media_picker_field('media_id', $heroMedia, admin_t('common.image'), '', true); ?>
        <?php editor_field_error($fieldErrors, 'media_id'); ?>
      </div>
      <?php if ($legacyImage !== '' && $heroMedia === null): ?>
        <div class="admin-field">
          <p class="admin-text-muted"><?= admin_te('block_hero.legacy_image') ?></p>
          <div class="admin-image-card__media" style="max-width:260px;">
            <img src="/<?= $h(ltrim($legacyImage, '/')) ?>" alt="">
          </div>
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-checkbox" name="remove_legacy_image" value="1"<?= is_array($old) && !empty($old['remove_legacy_image']) ? ' checked' : '' ?>>
            <?= admin_te('block_hero.remove_legacy_image') ?>
          </label>
        </div>
      <?php endif; ?>
      <?php
      // The alt text this image really gets: its own, else the library's,
      // filled in and linked to the picker (media_alt_field()). A Hero image
      // from before the library has no library alt text to show.
      $heroAlt = media_alt_field('media_id', $word('image_alt'), $heroMedia, $placeholder);
      $field('image_alt', admin_t('common.alt_text') . $marker, 255, $heroAlt['attributes'], 0, '', $heroAlt['value']);
      ?>

      <div data-media-panel="video"<?= $mediaType !== 'video' ? ' hidden' : '' ?>>
        <h3><?= admin_te('block_hero.video_2') ?> <?= admin_help(admin_t('block_hero.video_2'), admin_t('help.block_hero.video')) ?></h3>
        <div class="admin-field">
          <?php media_picker_field('video_media_id', $heroVideo, admin_t('block_hero.video_2'), '', true, MediaType::VIDEO); ?>
          <?php editor_field_error($fieldErrors, 'video_media_id'); ?>
        </div>
        <?php if ($legacyVideo !== '' && $heroVideo === null): ?>
          <div class="admin-field">
            <p class="admin-text-muted"><?= admin_te('block_hero.legacy_video') ?></p>
            <div class="admin-image-card__media" style="max-width:260px;">
              <video src="/<?= $h(ltrim($legacyVideo, '/')) ?>" muted loop playsinline controls preload="metadata" style="width:100%; height:auto; display:block;"></video>
            </div>
            <label class="admin-checkbox-label">
              <input type="checkbox" class="admin-checkbox" name="remove_legacy_video" value="1"<?= is_array($old) && !empty($old['remove_legacy_video']) ? ' checked' : '' ?>>
              <?= admin_te('block_hero.remove_legacy_video') ?>
            </label>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="admin-card" aria-labelledby="homepage-hero-stats-title">
      <h2 id="homepage-hero-stats-title"><?= admin_te('block_hero.statistieken') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_hero.maximaal_statistieken_hero_ontwerp', ['v1' => HomepageHeroContent::MAX_STATS]) ?></p>

      <?php if ($rows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_hero.statistieken_2') ?></p>
      <?php endif; ?>

      <input type="hidden" name="stats_present" value="1">
      <div class="admin-row-cards" data-row-list="homepage-hero-stats" data-row-list-max="<?= HomepageHeroContent::MAX_STATS ?>">
        <?php foreach ($rows as $position => $row): ?>
          <?php $statRow($row['key'], $row['fields'], $position, count($rows)); ?>
        <?php endforeach; ?>
        <?php if (count($rows) < HomepageHeroContent::MAX_STATS): ?>
          <noscript>
            <?php $statRow(editor_rows_free_key($rows), ['active' => '1'], count($rows), count($rows) + 1); ?>
          </noscript>
        <?php endif; ?>
      </div>
      <?php editor_rows_status('homepage-hero-stats'); ?>
      <?php editor_rows_add('homepage-hero-stats', admin_t('block_hero.statistiek_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="homepage-hero-stats"><?php $statRow('__KEY__', ['active' => '1'], 0, 1); ?></template>
    </section>

    <div class="admin-form-actions">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </div>
  </form>
</main>
<?php media_picker_modal(); ?>
<?php save_bar(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/navigation-item.js') ?>" defer></script>
<?php media_picker_script(); ?>
<?php save_bar_script(); ?>
</body>
</html>
