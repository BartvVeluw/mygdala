<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\HomepageHeroContent;
use App\Repository\HomepageHeroRepository;

/**
 * Editor for the Homepage Hero: its texts, its media and layout, its image or
 * video, and its stats one card each.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the texts, the image's alt text and every stat show the language chosen in
 * the CMS shell, as stored and without the default language's words in an
 * empty translation, and are required only in the default language; each
 * save writes that language only, for the Hero or for that one stat. The
 * URLs, the highlight size, the media, the layout and a stat's visibility
 * are the same in every language and stay on screen in each. The alt text
 * stays next to the image it describes, in the image form. A stat keeps its
 * id however often it is saved or moved, so the words of the other languages
 * stay with it; a NEW stat is written in the default language, like a new
 * page, and translated afterwards on its own card. Input a refused text save
 * hands back comes back in the language it was typed in, and that form then
 * starts out unsaved in the save bar.
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

$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();

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

/** A language-neutral value of the text form: handed back, else stored. */
$setting = static fn (string $key): string => is_array($old) ? (string) ($old[$key] ?? '') : (string) ($hero[$key] ?? '');

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = $placeholder !== '' ? $placeholder : ' placeholder="Optioneel"';

// Re-clamped rather than echoed raw, because it may come from
// $_SESSION['admin_homepage_hero_old'] — i.e. from a REJECTED save, whose
// highlight size is by definition not guaranteed to be a valid percentage.
$highlightSize = HomepageHeroContent::clampHighlightSize(is_array($old) ? ($old['title_highlight_size'] ?? null) : ($hero['title_highlight_size'] ?? null));
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
    <form method="post" action="/api/admin/update-homepage-hero.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <h2><?= admin_te('block_hero.algemene_inhoud') ?></h2>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_hero.eyebrow') ?><?= $marker ?>
          <input type="text" name="eyebrow" maxlength="150"<?= $required ?> value="<?= $h($word('eyebrow')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_hero.titel_h1') ?><?= $marker ?>
          <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_hero.highlight_titel') ?>
          <input type="text" name="title_highlight" maxlength="255" value="<?= $h($word('title_highlight')) ?>"<?= $optional ?>>
        </label>
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

      <div class="admin-form-row">
        <label><?= admin_te('block_hero.introtekst_lead') ?>
          <textarea name="lead" maxlength="500" rows="3"<?= $placeholder ?>><?= $h($word('lead')) ?></textarea>
        </label>
      </div>

      <h2 style="margin-top:2rem;"><?= admin_te('block_hero.knoppen') ?></h2>

      <h3><?= admin_te('block_hero.primaire_knop') ?>*</h3>
      <div class="admin-form-row">
        <label><?= admin_te('block_hero.label') ?><?= $marker ?>
          <input type="text" name="primary_label" maxlength="150"<?= $required ?> value="<?= $h($word('primary_label')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>*
          <input type="text" name="primary_url" maxlength="255" required value="<?= $h($setting('primary_url')) ?>">
        </label>
      </div>

      <h3 style="margin-top:1.5rem;"><?= admin_te('block_hero.secundaire_knop_optioneel') ?></h3>
      <div class="admin-form-row">
        <label><?= admin_te('block_hero.label_3') ?>
          <input type="text" name="secondary_label" maxlength="150" value="<?= $h($word('secondary_label')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>
          <input type="text" name="secondary_url" maxlength="255" value="<?= $h($setting('secondary_url')) ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_hero.laat_label_url_leeg') ?></p>

      <h2 style="margin-top:2rem;"><?= admin_te('block_hero.badge') ?></h2>
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?>
          <input type="text" name="badge_title" maxlength="150" value="<?= $h($word('badge_title')) ?>"<?= $optional ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_hero.tekst') ?>
          <textarea name="badge_text" maxlength="500" rows="2"<?= $optional ?>><?= $h($word('badge_text')) ?></textarea>
        </label>
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
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

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
      <img src="/<?= $h((string) $hero['image_path']) ?>" alt="">
    </div>

    <form method="post" action="/api/admin/update-homepage-hero-image.php" enctype="multipart/form-data" class="admin-product-form" style="margin-top:0.75rem;">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <div class="admin-form-row">
        <label><?= admin_te('block_hero.vervangen_door_nieuw_bestand') ?>
          <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
        </label>
      </div>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.alt_text') ?><?= $marker ?>
          <input type="text" name="image_alt" maxlength="255"<?= $required ?> value="<?= $h(BlockLocalization::raw('homepage_hero', $heroId, 'image_alt', $editLanguage)) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card" data-media-panel="video" <?= $currentMediaType !== 'video' ? 'hidden' : '' ?>>
    <h2><?= admin_te('block_hero.video_2') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_hero.mp4_webm_max_30') ?></p>

    <?php if ($currentVideoPath !== ''): ?>
      <div class="admin-image-card__media" style="max-width:260px;">
        <video src="/<?= $h($currentVideoPath) ?>" muted loop playsinline controls style="width:100%; height:auto; display:block;"></video>
      </div>
    <?php else: ?>
      <p class="admin-text-muted"><?= admin_te('block_hero.video_ge_pload') ?></p>
    <?php endif; ?>

    <form method="post" action="/api/admin/update-homepage-hero-video.php" enctype="multipart/form-data" class="admin-product-form" style="margin-top:0.75rem;">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

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
        $statWord = static fn (string $field): string => BlockLocalization::raw('homepage_hero_stats', $statId, $field, $editLanguage);
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-homepage-hero-stat.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="item_id" value="<?= $statId ?>">
          <?= admin_localized_input($editLanguage) ?>

          <?php admin_localized_bar($editLanguage); ?>
          <div class="admin-form-row">
            <label><?= admin_te('block_hero.primaire_tekst') ?><?= $marker ?>
              <input type="text" name="primary_text" maxlength="100"<?= $required ?> value="<?= $h($statWord('primary_text')) ?>"<?= $placeholder ?>>
            </label>
          </div>

          <div class="admin-form-row">
            <label><?= admin_te('block_hero.secundaire_tekst') ?><?= $marker ?>
              <input type="text" name="secondary_text" maxlength="150"<?= $required ?> value="<?= $h($statWord('secondary_text')) ?>"<?= $placeholder ?>>
            </label>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= (int) $stat['is_active'] === 1 ? 'checked' : '' ?>>
            <?= admin_te('common.visible') ?>
          </label>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-homepage-hero-stat.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $statId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-homepage-hero-stat.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $statId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-homepage-hero-stat.php" class="admin-inline-form" onsubmit="return confirm('Deze statistiek definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
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
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="hero_id" value="<?= $heroId ?>">

        <?php admin_localized_bar($defaultLanguage); ?>
        <?php admin_localized_new_item_note($editLanguage); ?>
        <div class="admin-form-row">
          <label><?= admin_te('block_hero.primaire_tekst_3') ?>*
            <input type="text" name="primary_text" maxlength="100" required>
          </label>
        </div>

        <div class="admin-form-row">
          <label><?= admin_te('block_hero.secundaire_tekst_3') ?>*
            <input type="text" name="secondary_text" maxlength="150" required>
          </label>
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
</body>
</html>
