<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\HomepageHeroContent;
use App\Repository\HomepageHeroRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$repository = new HomepageHeroRepository();

$hero = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
if ($hero === null) {
    // First time this editor is opened: create the row now (seeded with its
    // known defaults) so stats can be attached to it. is_active is always
    // true — this editor never exposes a whole-Hero visibility checkbox.
    $repository->upsert(HomepageHeroContent::PAGE_SLUG, HomepageHeroContent::defaults() + ['is_active' => true]);
    $hero = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
}

$heroId = (int) $hero['id'];
$stats = $repository->findStatsByHeroId($heroId);

$errors = $_SESSION['admin_homepage_hero_errors'] ?? [];
$old = $_SESSION['admin_homepage_hero_old'] ?? null;
unset($_SESSION['admin_homepage_hero_errors'], $_SESSION['admin_homepage_hero_old']);

$imageErrors = $_SESSION['admin_homepage_hero_image_errors'] ?? [];
unset($_SESSION['admin_homepage_hero_image_errors']);

$videoErrors = $_SESSION['admin_homepage_hero_video_errors'] ?? [];
unset($_SESSION['admin_homepage_hero_video_errors']);

$mediaErrors = $_SESSION['admin_homepage_hero_media_errors'] ?? [];
unset($_SESSION['admin_homepage_hero_media_errors']);

$statErrors = $_SESSION['admin_homepage_hero_stat_errors'] ?? [];
unset($_SESSION['admin_homepage_hero_stat_errors']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $values = $old;
} else {
    $values = [
        'eyebrow_nl' => (string) $hero['eyebrow_nl'],
        'eyebrow_en' => (string) ($hero['eyebrow_en'] ?? ''),
        'title_nl' => (string) $hero['title_nl'],
        'title_en' => (string) ($hero['title_en'] ?? ''),
        'title_highlight_nl' => (string) ($hero['title_highlight_nl'] ?? ''),
        'title_highlight_en' => (string) ($hero['title_highlight_en'] ?? ''),
        'title_highlight_size' => (string) HomepageHeroContent::clampHighlightSize($hero['title_highlight_size'] ?? null),
        'lead_nl' => (string) ($hero['lead_nl'] ?? ''),
        'lead_en' => (string) ($hero['lead_en'] ?? ''),
        'primary_label_nl' => (string) $hero['primary_label_nl'],
        'primary_label_en' => (string) ($hero['primary_label_en'] ?? ''),
        'primary_url' => (string) $hero['primary_url'],
        'secondary_label_nl' => (string) ($hero['secondary_label_nl'] ?? ''),
        'secondary_label_en' => (string) ($hero['secondary_label_en'] ?? ''),
        'secondary_url' => (string) ($hero['secondary_url'] ?? ''),
        'badge_title_nl' => (string) ($hero['badge_title_nl'] ?? ''),
        'badge_title_en' => (string) ($hero['badge_title_en'] ?? ''),
        'badge_text_nl' => (string) ($hero['badge_text_nl'] ?? ''),
        'badge_text_en' => (string) ($hero['badge_text_en'] ?? ''),
    ];
}

$csrfToken = Csrf::token();

// Re-clamped rather than echoed raw, because $values may come from
// $_SESSION['admin_homepage_hero_old'] — i.e. from a REJECTED save, whose
// highlight size is by definition not guaranteed to be a valid percentage.
$highlightSize = HomepageHeroContent::clampHighlightSize($values['title_highlight_size'] ?? null);

/**
 * @param array<string, mixed> $values
 */
function homepageHeroValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
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
  <p class="admin-text-muted"><?= admin_te('block_hero.bovenste_sectie_homepage_eyebrow') ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php foreach ([$errors, $mediaErrors, $imageErrors, $videoErrors, $statErrors] as $errorGroup): ?>
    <?php if ($errorGroup !== []): ?>
      <div class="admin-alert admin-alert--error">
        <ul class="admin-error-list">
          <?php foreach ($errorGroup as $error): ?>
            <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-homepage-hero.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <h2><?= admin_te('block_hero.algemene_inhoud') ?></h2>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_hero.eyebrow') ?>*
          <input type="text" name="eyebrow_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= homepageHeroValue($values, 'eyebrow_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_hero.eyebrow_2') ?>
          <input type="text" name="eyebrow_en" maxlength="150" value="<?= homepageHeroValue($values, 'eyebrow_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_hero.titel_h1') ?>*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= homepageHeroValue($values, 'title_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_hero.titel_h1_2') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= homepageHeroValue($values, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_hero.highlight_titel') ?>
          <input type="text" name="title_highlight_nl" maxlength="255" value="<?= homepageHeroValue($values, 'title_highlight_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_hero.highlight_titel_2') ?>
          <input type="text" name="title_highlight_en" maxlength="255" value="<?= homepageHeroValue($values, 'title_highlight_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_t('block_hero.highlight_woord_highlight_zin') ?></p>

      <div class="admin-form-row">
        <label for="title_highlight_size"><?= admin_te('block_hero.highlight_grootte') ?></label>
        <div class="admin-range" data-range-field>
          <input
            type="range"
            id="title_highlight_size"
            name="title_highlight_size"
            min="<?= HomepageHeroContent::HIGHLIGHT_SIZE_MIN ?>"
            max="<?= HomepageHeroContent::HIGHLIGHT_SIZE_MAX ?>"
            step="<?= HomepageHeroContent::HIGHLIGHT_SIZE_STEP ?>"
            value="<?= $highlightSize ?>"
            data-range-input
            aria-describedby="title_highlight_size_help">
          <output class="admin-range__value" for="title_highlight_size" data-range-output><?= $highlightSize ?>%</output>
        </div>
      </div>
      <p class="admin-text-muted" id="title_highlight_size_help"><?= admin_t('block_hero.hoe_groot_highlight_ten', ['v1' => HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT]) ?></p>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_hero.introtekst_lead') ?>
          <textarea name="lead_nl" maxlength="500" rows="3"><?= homepageHeroValue($values, 'lead_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_hero.introtekst_lead_2') ?>
          <textarea name="lead_en" maxlength="500" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= homepageHeroValue($values, 'lead_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <h2 style="margin-top:2rem;"><?= admin_te('block_hero.knoppen') ?></h2>

      <h3><?= admin_te('block_hero.primaire_knop') ?>*</h3>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_hero.label') ?>*
          <input type="text" name="primary_label_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= homepageHeroValue($values, 'primary_label_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_hero.label_2') ?>
          <input type="text" name="primary_label_en" maxlength="150" value="<?= homepageHeroValue($values, 'primary_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>*
          <input type="text" name="primary_url" maxlength="255" required value="<?= homepageHeroValue($values, 'primary_url') ?>">
        </label>
      </div>

      <h3 style="margin-top:1.5rem;"><?= admin_te('block_hero.secundaire_knop_optioneel') ?></h3>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_hero.label_3') ?>
          <input type="text" name="secondary_label_nl" maxlength="150" value="<?= homepageHeroValue($values, 'secondary_label_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_hero.label_4') ?>
          <input type="text" name="secondary_label_en" maxlength="150" value="<?= homepageHeroValue($values, 'secondary_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>
          <input type="text" name="secondary_url" maxlength="255" value="<?= homepageHeroValue($values, 'secondary_url') ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_hero.laat_label_url_leeg') ?></p>

      <h2 style="margin-top:2rem;"><?= admin_te('block_hero.badge') ?></h2>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="badge_title_nl" maxlength="150" value="<?= homepageHeroValue($values, 'badge_title_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="badge_title_en" maxlength="150" value="<?= homepageHeroValue($values, 'badge_title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_hero.tekst') ?>
          <textarea name="badge_text_nl" maxlength="500" rows="2" placeholder="Optioneel"><?= homepageHeroValue($values, 'badge_text_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_hero.tekst_2') ?>
          <textarea name="badge_text_en" maxlength="500" rows="2"<?= admin_lang_placeholder_attr('en') ?>><?= homepageHeroValue($values, 'badge_text_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_hero.laat_titel_tekst_leeg') ?></p>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <?php
    $currentMediaType = (string) ($hero['media_type'] ?? \App\Service\HomepageHeroContent::MEDIA_TYPE_IMAGE);
    $currentLayout = (string) ($hero['layout'] ?? \App\Service\HomepageHeroContent::LAYOUT_MEDIA_RIGHT);
    $currentVideoPath = (string) ($hero['video_path'] ?? '');
  ?>
  <section class="admin-card">
    <h2><?= admin_t('block_hero.media_lay_out') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_hero.kies_hero_afbeelding_video') ?></p>

    <form method="post" action="/api/admin/update-homepage-hero-media.php" class="admin-product-form" data-homepage-hero-media-form>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row">
        <span class="admin-form-row__label"><?= admin_te('block_hero.media') ?></span>
        <div class="admin-segmented" data-media-type-group>
          <label class="admin-segmented__option">
            <input type="radio" name="media_type" value="image" <?= $currentMediaType === 'image' ? 'checked' : '' ?>>
            <span><?= admin_te('common.image') ?></span>
          </label>
          <label class="admin-segmented__option">
            <input type="radio" name="media_type" value="video" <?= $currentMediaType === 'video' ? 'checked' : '' ?>>
            <span><?= admin_te('block_hero.video') ?></span>
          </label>
        </div>
      </div>

      <div class="admin-form-row">
        <span class="admin-form-row__label"><?= admin_te('block_hero.lay_out') ?></span>
        <div class="admin-segmented">
          <label class="admin-segmented__option">
            <input type="radio" name="layout" value="media_left" <?= $currentLayout === 'media_left' ? 'checked' : '' ?>>
            <span><?= admin_te('block_hero.media_links') ?></span>
          </label>
          <label class="admin-segmented__option">
            <input type="radio" name="layout" value="media_right" <?= $currentLayout === 'media_right' ? 'checked' : '' ?>>
            <span><?= admin_te('block_hero.media_rechts') ?></span>
          </label>
          <label class="admin-segmented__option">
            <input type="radio" name="layout" value="background" <?= $currentLayout === 'background' ? 'checked' : '' ?>>
            <span><?= admin_te('block_hero.volledige_achtergrond') ?></span>
          </label>
        </div>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_hero.media_rechts_oorspronkelijke_hero') ?></p>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card" data-media-panel="image" <?= $currentMediaType !== 'image' ? 'hidden' : '' ?>>
    <h2><?= admin_te('common.image') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_hero.afbeelding_ook_gebruikt_poster') ?></p>

    <div class="admin-image-card__media" style="max-width:260px;">
      <img src="/<?= htmlspecialchars((string) $hero['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="">
    </div>

    <form method="post" action="/api/admin/update-homepage-hero-image.php" enctype="multipart/form-data" class="admin-product-form" style="margin-top:0.75rem;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row">
        <label><?= admin_te('block_hero.vervangen_door_nieuw_bestand') ?>
          <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
        </label>
      </div>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.alt_text') ?>*
          <input type="text" name="image_alt_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= htmlspecialchars((string) $hero['image_alt_nl'], ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="image_alt_en" maxlength="255" value="<?= htmlspecialchars((string) ($hero['image_alt_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card" data-media-panel="video" <?= $currentMediaType !== 'video' ? 'hidden' : '' ?>>
    <h2><?= admin_te('block_hero.video_2') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_hero.mp4_webm_max_30') ?></p>

    <?php if ($currentVideoPath !== ''): ?>
      <div class="admin-image-card__media" style="max-width:260px;">
        <video src="/<?= htmlspecialchars($currentVideoPath, ENT_QUOTES, 'UTF-8') ?>" muted loop playsinline controls style="width:100%; height:auto; display:block;"></video>
      </div>
    <?php else: ?>
      <p class="admin-text-muted"><?= admin_te('block_hero.video_ge_pload') ?></p>
    <?php endif; ?>

    <form method="post" action="/api/admin/update-homepage-hero-video.php" enctype="multipart/form-data" class="admin-product-form" style="margin-top:0.75rem;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row">
        <label><?= admin_t('block_hero.replace_file', ['v1' => $currentVideoPath === '' ? '*' : ' (optioneel)']) ?>
          <input type="file" name="video" accept="video/mp4,video/webm" <?= $currentVideoPath === '' ? 'required' : '' ?>>
        </label>
      </div>

      <button type="submit"><?= admin_te('block_hero.video_uploaden') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_hero.statistieken') ?></h2>
    <p class="admin-text-muted"><?= admin_t('block_hero.maximaal_statistieken_hero_ontwerp', ['v1' => HomepageHeroContent::MAX_STATS]) ?></p>

    <?php if ($stats === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_hero.statistieken_2') ?></p>
    <?php endif; ?>

    <?php foreach ($stats as $index => $stat): ?>
      <?php
        $statId = (int) $stat['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($stats) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-homepage-hero-stat.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="item_id" value="<?= $statId ?>">

          <?php admin_lang_bar(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label><?= admin_te('block_hero.primaire_tekst') ?>*
              <input type="text" name="primary_text_nl" maxlength="100" <?= admin_lang_required('nl') ?> value="<?= htmlspecialchars((string) $stat['primary_text_nl'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label><?= admin_te('block_hero.primaire_tekst_2') ?>
              <input type="text" name="primary_text_en" maxlength="100" value="<?= htmlspecialchars((string) ($stat['primary_text_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label><?= admin_te('block_hero.secundaire_tekst') ?>*
              <input type="text" name="secondary_text_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= htmlspecialchars((string) $stat['secondary_text_nl'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label><?= admin_te('block_hero.secundaire_tekst_2') ?>
              <input type="text" name="secondary_text_en" maxlength="150" value="<?= htmlspecialchars((string) ($stat['secondary_text_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= (int) $stat['is_active'] === 1 ? 'checked' : '' ?>>
            <?= admin_te('common.visible') ?>
          </label>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-homepage-hero-stat.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $statId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-homepage-hero-stat.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $statId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-homepage-hero-stat.php" class="admin-inline-form" onsubmit="return confirm('Deze statistiek definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $statId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <?php if (count($stats) < HomepageHeroContent::MAX_STATS): ?>
    <section class="admin-card">
      <h2><?= admin_te('block_hero.nieuwe_statistiek_toevoegen') ?></h2>
      <form method="post" action="/api/admin/create-homepage-hero-stat.php" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="hero_id" value="<?= $heroId ?>">

        <?php admin_lang_bar(); ?>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('block_hero.primaire_tekst_3') ?>*
            <input type="text" name="primary_text_nl" maxlength="100" <?= admin_lang_required('nl') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('block_hero.primaire_tekst_4') ?>
            <input type="text" name="primary_text_en" maxlength="100"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label><?= admin_te('block_hero.secundaire_tekst_3') ?>*
            <input type="text" name="secondary_text_nl" maxlength="150" <?= admin_lang_required('nl') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label><?= admin_te('block_hero.secundaire_tekst_4') ?>
            <input type="text" name="secondary_text_en" maxlength="150"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>

        <button type="submit"><?= admin_te('block_hero.statistiek_toevoegen') ?></button>
      </form>
    </section>
  <?php else: ?>
    <p class="admin-text-muted"><?= admin_t('block_hero.maximum_statistieken_bereikt_verwijder', ['v1' => HomepageHeroContent::MAX_STATS]) ?></p>
  <?php endif; ?>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
