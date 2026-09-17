<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
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
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the card's words, the image's alt text and every tag show the language
 * chosen in the CMS shell, as stored and without the default language's words
 * in an empty translation; the title and a tag's label are required only in
 * the default language. Each save writes that language only, for the card or
 * for that one tag. The link URL, the visibility and the image are the same
 * in every language and stay on screen in each; the alt text stays next to
 * the image it describes, in the image form. A tag keeps its id however often
 * it is saved or moved, so the words of the other languages stay with it; a
 * NEW tag is written in the default language, like a new page, and translated
 * afterwards on its own row. Input a refused card save hands back comes back
 * in the language it was typed in, and that form then starts out unsaved in
 * the save bar.
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

$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();

// The words of the carousel, of this card and of every tag, in one query.
BlockLocalization::preloadBlocks(['card_carousels' => [(int) $carousel['id']]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The card's words on screen: typed and handed back in this language, else stored in it. */
$cardWord = static function (string $field) use ($old, $oldInThisLanguage, $cardId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('carousel_cards', $cardId, $field, $editLanguage);
};

$linkUrl = is_array($old) ? (string) ($old['link_url'] ?? '') : (string) ($card['link_url'] ?? '');
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $card['is_active'];

$imagePath = (string) ($card['image_path'] ?? '');
// "Does this card have a photo at all" — a Media Library reference, or a
// legacy path that predates it. Without one the card renders the fixed icon.
$cardMedia = MediaService::find((int) ($card['media_id'] ?? 0));
$hasCardImage = $cardMedia !== null || $imagePath !== '';

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = $placeholder !== '' ? $placeholder : ' placeholder="Optioneel"';
$altPlaceholder = $placeholder !== '' ? $placeholder : ' placeholder="Leeg = alt-tekst uit de mediabibliotheek"';
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
  <h1><?= admin_t('block_carousel.kaart', ['v1' => $h(BlockLocalization::name('carousel_cards', $cardId, 'title'))]) ?></h1>
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
    <form method="post" action="/api/admin/update-carousel-card.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="card_id" value="<?= $cardId ?>">
      <?= admin_localized_input($editLanguage) ?>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?><?= $marker ?>
          <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($cardWord('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_carousel.tekst') ?>
          <textarea name="body" maxlength="500" rows="3"<?= $optional ?>><?= $h($cardWord('body')) ?></textarea>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_carousel.knoptekst') ?>
          <input type="text" name="link_label" maxlength="150" value="<?= $h($cardWord('link_label')) ?>"<?= $optional ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_carousel.knop_url') ?>
          <input type="text" name="link_url" maxlength="255" value="<?= $h($linkUrl) ?>" placeholder="Bijv. diensten.php#hout — leeg = geen knop">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_carousel.knoptekst_url_horen_elkaar') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
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
      <?= admin_localized_input($editLanguage) ?>

      <div class="admin-form-row">
        <?php media_picker_field('media_id', $cardMedia, 'Afbeelding', 'Optioneel. Zonder afbeelding toont de kaart het vaste icoon.', false); ?>
      </div>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.alt_text') ?>
          <input type="text" name="image_alt" maxlength="255" value="<?= $h(BlockLocalization::raw('carousel_cards', $cardId, 'image_alt', $editLanguage)) ?>"<?= $altPlaceholder ?>>
        </label>
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
          <?= admin_localized_input($editLanguage) ?>

          <?php admin_localized_bar($editLanguage); ?>
          <div class="admin-form-row">
            <label><?= admin_te('block_carousel.label') ?><?= $marker ?>
              <input type="text" name="label" maxlength="60"<?= $required ?> value="<?= $h(BlockLocalization::raw('carousel_card_tags', $tagId, 'label', $editLanguage)) ?>"<?= $placeholder ?>>
            </label>
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

      <?php admin_localized_bar($defaultLanguage); ?>
      <?php admin_localized_new_item_note($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_carousel.label_3') ?>*
          <input type="text" name="label" maxlength="60" required>
        </label>
      </div>

      <button type="submit"><?= admin_te('block_carousel.tag_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<?php save_bar_script(); ?>
</body>
</html>
