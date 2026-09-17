<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Repository\CardCarouselRepository;
use App\Repository\PageRepository;

/**
 * Editor for one "Kaarten-carrousel" block instance
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page and its content row
 * really exist" gate as every other repeater section editor.
 *
 * The block's own heading and the ORDER of its cards live here; a card's
 * own content (text, image, tags) has its own screen
 * (admin/carousel-card.php), the same "list screen + item screen" split
 * admin/portfolio.php and admin/portfolio-item.php use — a card carries a
 * repeater of its own, which does not fit one flat form.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the heading shows the language chosen in the CMS shell, as stored and
 * without the default language's words in an empty translation, and a save
 * writes that language only; the heading is optional in every language, and
 * is_active is the same in all of them. The card list names each card by its
 * title in the default language. A NEW card is written in the default
 * language, like a new page, and translated afterwards on its own screen.
 * Input a refused heading save hands back comes back in the language it was
 * typed in, and that form then starts out unsaved in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new CardCarouselRepository();
$pages = new PageRepository();

if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || $pages->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$page = $pages->findByContentKey($pageSlug);
$carousel = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$carouselId = (int) $carousel['id'];
$cards = $repository->findCardsByCarouselId($carouselId);

$errors = $_SESSION['admin_card_carousel_errors'] ?? [];
$old = $_SESSION['admin_card_carousel_old'] ?? null;
unset($_SESSION['admin_card_carousel_errors'], $_SESSION['admin_card_carousel_old']);

$cardErrors = $_SESSION['admin_carousel_card_errors'] ?? [];
unset($_SESSION['admin_carousel_card_errors']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();

// The words of the carousel, of every card and of every tag, in one query.
BlockLocalization::preloadBlocks(['card_carousels' => [$carouselId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $carousel['is_active'];

/** The heading's words on screen: typed and handed back in this language, else stored in it. */
$carouselWord = static function (string $field) use ($old, $oldInThisLanguage, $carouselId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('card_carousels', $carouselId, $field, $editLanguage);
};

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = $placeholder !== '' ? $placeholder : ' placeholder="Optioneel"';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('card_carousel')) ?> <?= admin_t('block_carousel.admin', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_carousel.terug', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('card_carousel')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_carousel.sectie_pagina_bepaalt_zelf', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php foreach ([$errors, $cardErrors] as $errorList): ?>
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
    <h2><?= admin_te('block_carousel.kop_boven_carrousel') ?></h2>
    <form method="post" action="/api/admin/update-card-carousel.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_carousel.eyebrow') ?>
          <input type="text" name="eyebrow" maxlength="255" value="<?= $h($carouselWord('eyebrow')) ?>"<?= $optional ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_carousel.titel_h2') ?>
          <input type="text" name="title" maxlength="255" value="<?= $h($carouselWord('title')) ?>"<?= $optional ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_carousel.lead') ?>
          <textarea name="lead" maxlength="1000" rows="3"<?= $optional ?>><?= $h($carouselWord('lead')) ?></textarea>
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_carousel.alles_hierboven_optioneel_laat') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_carousel.actief_uitgevinkt_hele_carrousel') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_t('block_carousel.kaarten', ['v1' => count($cards)]) ?></h2>

    <?php if ($cards === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_carousel.kaarten_carrousel_zonder_kaarten') ?></p>
    <?php endif; ?>

    <?php foreach ($cards as $index => $card): ?>
      <?php
        $cardId = (int) $card['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($cards) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <?php if ((string) ($card['image_path'] ?? '') !== ''): ?>
          <div class="admin-image-card__media" style="max-width:180px;">
            <img src="/<?= $h((string) $card['image_path']) ?>" alt="">
          </div>
        <?php endif; ?>

        <p>
          <strong><?= $h(BlockLocalization::name('carousel_cards', $cardId, 'title')) ?></strong>
          <?php if (!(bool) $card['is_active']): ?>
            <span class="admin-text-muted">— verborgen</span>
          <?php endif; ?>
        </p>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <a class="admin-btn-text" href="/admin/carousel-card.php?card_id=<?= $cardId ?>"><?= admin_te('common.edit') ?></a>
          <form method="post" action="/api/admin/move-carousel-card.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="card_id" value="<?= $cardId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-carousel-card.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="card_id" value="<?= $cardId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-carousel-card.php" class="admin-inline-form" onsubmit="return confirm('Deze kaart definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="card_id" value="<?= $cardId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_carousel.nieuwe_kaart_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-carousel-card.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="carousel_id" value="<?= $carouselId ?>">

      <?php admin_localized_bar($defaultLanguage); ?>
      <?php admin_localized_new_item_note($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title" maxlength="255" required>
        </label>
      </div>

      <button type="submit"><?= admin_te('block_carousel.kaart_toevoegen') ?></button>
    </form>
    <p class="admin-text-muted"><?= admin_te('block_carousel.komt_daarna_direct_kaart') ?></p>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
