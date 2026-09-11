<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
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

if ($old !== null) {
    $values = $old;
} else {
    $values = [
        'eyebrow_nl' => (string) ($carousel['eyebrow_nl'] ?? ''),
        'eyebrow_en' => (string) ($carousel['eyebrow_en'] ?? ''),
        'title_nl' => (string) ($carousel['title_nl'] ?? ''),
        'title_en' => (string) ($carousel['title_en'] ?? ''),
        'lead_nl' => (string) ($carousel['lead_nl'] ?? ''),
        'lead_en' => (string) ($carousel['lead_en'] ?? ''),
        'is_active' => (bool) $carousel['is_active'],
    ];
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * @param array<string, mixed> $values
 */
function carouselValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('card_carousel')) ?> <?= admin_t('block_carousel.admin', ['v1' => $h((string) $page['title'])]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_carousel.terug', ['v1' => $h((string) $page['title'])]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('card_carousel')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_carousel.sectie_pagina_bepaalt_zelf', ['v1' => $h((string) $page['title'])]) ?></p>

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
    <form method="post" action="/api/admin/update-card-carousel.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_carousel.eyebrow') ?>
          <input type="text" name="eyebrow_nl" maxlength="255" value="<?= carouselValue($values, 'eyebrow_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_carousel.eyebrow_2') ?>
          <input type="text" name="eyebrow_en" maxlength="255" value="<?= carouselValue($values, 'eyebrow_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_carousel.titel_h2') ?>
          <input type="text" name="title_nl" maxlength="255" value="<?= carouselValue($values, 'title_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_carousel.titel_h2_2') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= carouselValue($values, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_carousel.lead') ?>
          <textarea name="lead_nl" maxlength="1000" rows="3" placeholder="Optioneel"><?= carouselValue($values, 'lead_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_carousel.lead_2') ?>
          <textarea name="lead_en" maxlength="1000" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= carouselValue($values, 'lead_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_carousel.alles_hierboven_optioneel_laat') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
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
          <strong><?= $h((string) $card['title_nl']) ?></strong>
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

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title_en" maxlength="255"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit"><?= admin_te('block_carousel.kaart_toevoegen') ?></button>
    </form>
    <p class="admin-text-muted"><?= admin_te('block_carousel.komt_daarna_direct_kaart') ?></p>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
