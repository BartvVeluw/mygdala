<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Repository\CardCarouselRepository;
use App\Repository\PageRepository;

require_once __DIR__ . '/_media_picker.php';

/**
 * Editor for ONE card of a "Kaarten-carrousel" (?card_id=...): its text, its
 * optional image, its optional link button and its tags.
 *
 * A card lives on its own screen rather than inline in
 * admin/card-carousel.php because it carries a repeater of its own (tags),
 * the same reason admin/portfolio-item.php exists next to admin/portfolio.php.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$cardId = filter_input(INPUT_GET, 'card_id', FILTER_VALIDATE_INT);

$repository = new CardCarouselRepository();
$card = ($cardId === false || $cardId === null) ? null : $repository->findCardById($cardId);

if ($card === null) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_kaart'));
}

$cardId = (int) $card['id'];
$carousel = $repository->findById((int) $card['carousel_id']);

if ($carousel === null) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_carrousel'));
}

$sectionParam = (string) $carousel['page_slug'] . ':' . (string) $carousel['section_key'];
$page = (new PageRepository())->findByContentKey((string) $carousel['page_slug']);
$tags = $repository->findTagsByCardId($cardId);

$errors = $_SESSION['admin_carousel_card_errors'] ?? [];
$old = $_SESSION['admin_carousel_card_old'] ?? null;
unset($_SESSION['admin_carousel_card_errors'], $_SESSION['admin_carousel_card_old']);

$imageErrors = $_SESSION['admin_carousel_card_image_errors'] ?? [];
unset($_SESSION['admin_carousel_card_image_errors']);

$tagErrors = $_SESSION['admin_carousel_card_tag_errors'] ?? [];
unset($_SESSION['admin_carousel_card_tag_errors']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $values = $old;
} else {
    $values = [
        'title_nl' => (string) $card['title_nl'],
        'title_en' => (string) ($card['title_en'] ?? ''),
        'body_nl' => (string) ($card['body_nl'] ?? ''),
        'body_en' => (string) ($card['body_en'] ?? ''),
        'link_url' => (string) ($card['link_url'] ?? ''),
        'link_label_nl' => (string) ($card['link_label_nl'] ?? ''),
        'link_label_en' => (string) ($card['link_label_en'] ?? ''),
        'is_active' => (bool) $card['is_active'],
    ];
}

$imagePath = (string) ($card['image_path'] ?? '');
// "Does this card have a photo at all" — a Media Library reference, or a
// legacy path that predates it. Without one the card renders the fixed icon.
$cardMedia = MediaService::find((int) ($card['media_id'] ?? 0));
$hasCardImage = $cardMedia !== null || $imagePath !== '';

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * @param array<string, mixed> $values
 */
function cardValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('block_carousel.kaart_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/card-carousel.php?section=<?= $h(urlencode($sectionParam)) ?>"><?= admin_t('block_carousel.terug_carrousel') ?></a></p>
  <h1><?= admin_t('block_carousel.kaart', ['v1' => $h((string) $card['title_nl'])]) ?></h1>
  <?php if ($page !== null): ?>
    <p class="admin-text-muted"><?= admin_t('block_carousel.kaart_kaarten_carrousel_pagina', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>
  <?php endif; ?>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php foreach ([$errors, $imageErrors, $tagErrors] as $errorList): ?>
    <?php if ($errorList !== []): ?>
      <div class="admin-alert admin-alert--error">
        <ul class="admin-error-list">
          <?php foreach ($errorList as $error): ?>
            <li><?= $h((string) $error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <section class="admin-card">
    <h2><?= admin_te('block_carousel.inhoud') ?></h2>
    <form method="post" action="/api/admin/update-carousel-card.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="card_id" value="<?= $cardId ?>">

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title_nl" maxlength="255" value="<?= cardValue($values, 'title_nl') ?>" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= cardValue($values, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_carousel.tekst') ?>
          <textarea name="body_nl" maxlength="500" rows="3" placeholder="Optioneel"><?= cardValue($values, 'body_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_carousel.tekst_2') ?>
          <textarea name="body_en" maxlength="500" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= cardValue($values, 'body_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_carousel.knoptekst') ?>
          <input type="text" name="link_label_nl" maxlength="150" value="<?= cardValue($values, 'link_label_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_carousel.knoptekst_2') ?>
          <input type="text" name="link_label_en" maxlength="150" value="<?= cardValue($values, 'link_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_carousel.knop_url') ?>
          <input type="text" name="link_url" maxlength="255" value="<?= cardValue($values, 'link_url') ?>" placeholder="Bijv. diensten.php#hout — leeg = geen knop">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_carousel.knoptekst_url_horen_elkaar') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('block_carousel.actief_uitgevinkt_kaart_getoond') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('common.image') ?></h2>

    <?php if (!$hasCardImage): ?>
      <p class="admin-text-muted"><?= admin_t('block_carousel.afbeelding_ingesteld_kaart_toont') ?></p>
    <?php endif; ?>

    <form method="post" action="/api/admin/update-carousel-card-image.php" class="admin-product-form" style="margin-top:0.75rem;">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="card_id" value="<?= $cardId ?>">

      <div class="admin-form-row">
        <?php media_picker_field('media_id', $cardMedia, 'Afbeelding', 'Optioneel. Zonder afbeelding toont de kaart het vaste icoon.', false); ?>
      </div>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="image_alt_nl" maxlength="255" value="<?= $h((string) ($card['image_alt_nl'] ?? '')) ?>" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="image_alt_en" maxlength="255" value="<?= $h((string) ($card['image_alt_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>

    <?php if ($hasCardImage): ?>
      <form method="post" action="/api/admin/update-carousel-card-image.php" class="admin-inline-form" style="margin-top:0.75rem;" onsubmit="return confirm('Afbeelding verwijderen? De kaart toont dan het vaste icoon.');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="card_id" value="<?= $cardId ?>">
        <input type="hidden" name="remove_image" value="1">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('block_carousel.afbeelding_verwijderen_gebruik_icoon') ?></button>
      </form>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_carousel.tags') ?></h2>

    <?php if ($tags === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_carousel.tags_kaart') ?></p>
    <?php endif; ?>

    <?php foreach ($tags as $index => $tag): ?>
      <?php
        $tagId = (int) $tag['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($tags) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-carousel-card-tag.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="tag_id" value="<?= $tagId ?>">

          <?php admin_lang_bar(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label><?= admin_te('block_carousel.label') ?>*
              <input type="text" name="label_nl" maxlength="60" value="<?= $h((string) $tag['label_nl']) ?>" <?= admin_lang_required('nl') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label><?= admin_te('block_carousel.label_2') ?>
              <input type="text" name="label_en" maxlength="60" value="<?= $h((string) ($tag['label_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-carousel-card-tag.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="tag_id" value="<?= $tagId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-carousel-card-tag.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="tag_id" value="<?= $tagId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-carousel-card-tag.php" class="admin-inline-form" onsubmit="return confirm('Deze tag definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="tag_id" value="<?= $tagId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>

    <form method="post" action="/api/admin/create-carousel-card-tag.php" class="admin-product-form" style="margin-top:1rem;">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="card_id" value="<?= $cardId ?>">

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_carousel.label_3') ?>*
          <input type="text" name="label_nl" maxlength="60" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_carousel.label_4') ?>
          <input type="text" name="label_en" maxlength="60"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit"><?= admin_te('block_carousel.tag_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
