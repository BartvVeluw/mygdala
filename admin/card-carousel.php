<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\CardCarouselContent;
use App\Service\Csrf;
use App\Service\Media\BlockImage;
use App\Service\SectionRegistry;
use App\Repository\CardCarouselRepository;
use App\Repository\PageRepository;

/**
 * Editor for one "Kaarten-carrousel" block instance
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page and its content row
 * really exist" gate as every other repeater section editor.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). The
 * heading, whether the carousel is shown, its layout, and the cards — their
 * order, which of them are shown and which are to be removed — are one form
 * posting to api/admin/update-card-carousel.php, and its one "Opslaan" (and
 * the save bar) stores all of it. ↑, ↓, "Bewerken" and "Kaart toevoegen" are
 * buttons of that same form: they store what was typed first and never throw
 * it away (App\Service\Blocks\EditorRows, admin/assets/row-list.js).
 *
 * A card's own content (text, number, image, button, tags) has its own screen
 * (admin/carousel-card.php): it carries a repeater of its own, which does not
 * fit one tile of the grid. The grid shows what an editor needs to recognise
 * a card and to decide on it: its image, its title, whether it is shown.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the heading shows the language chosen in the CMS shell, as stored and
 * without the default language's words in an empty translation, and a save
 * writes that language only. Everything else on this screen is the same in
 * every language. A card is named by its title in the default language, and
 * a NEW card is written in the default language, like a new page. Input a
 * refused save hands back comes back as it was typed, and the form then
 * starts out unsaved in the save bar.
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
$storedCards = [];
foreach ($repository->findCardsByCarouselId($carouselId) as $card) {
    $storedCards[(int) $card['id']] = $card;
}

$errors = $_SESSION['admin_card_carousel_errors'] ?? [];
$old = $_SESSION['admin_card_carousel_old'] ?? null;
unset($_SESSION['admin_card_carousel_errors'], $_SESSION['admin_card_carousel_old']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();

// The words of the carousel, of every card and of every tag, in one query.
BlockLocalization::preloadBlocks(['card_carousels' => [$carouselId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $carousel['is_active'];
$layout = CardCarouselContent::layout(is_array($old) ? (string) ($old['desktop_layout'] ?? '') : (string) ($carousel['desktop_layout'] ?? ''));

/** The heading's words on screen: typed and handed back in this language, else stored in it. */
$carouselWord = static function (string $field) use ($old, $oldInThisLanguage, $carouselId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('card_carousels', $carouselId, $field, $editLanguage);
};

// The cards on screen: in the order a refused save handed back (with its
// switches and removal marks), else as stored. A card that no longer exists
// is not shown; a card added since goes at the end.
$cards = [];
foreach (is_array($old) ? (array) ($old['cards'] ?? []) : [] as $id => $state) {
    if (isset($storedCards[(int) $id])) {
        $cards[(int) $id] = ['active' => !empty($state['active']), 'remove' => !empty($state['remove'])];
    }
}
foreach ($storedCards as $id => $card) {
    $cards[$id] ??= ['active' => (bool) $card['is_active'], 'remove' => false];
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = $placeholder !== '' ? $placeholder : ' placeholder="Optioneel"';
$pageName = \App\Service\PageLocalization::name((int) $page['id']);
$activeCount = count(array_filter($cards, static fn (array $state): bool => $state['active']));
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('card_carousel')) ?> <?= admin_t('block_carousel.admin', ['v1' => $h($pageName)]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_carousel.terug', ['v1' => $h($pageName)]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('card_carousel')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_carousel.sectie_pagina_bepaalt_zelf', ['v1' => $h($pageName)]) ?></p>

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

  <form method="post" action="/api/admin/update-card-carousel.php" class="admin-product-form" data-save-name="<?= $h(SectionRegistry::label('card_carousel')) ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves or edits a card. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
    <?= admin_localized_input($editLanguage) ?>

    <section class="admin-card">
      <h2><?= admin_te('block_carousel.kop_boven_carrousel') ?></h2>
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
    </section>

    <section class="admin-card" aria-labelledby="carousel-cards-title">
      <h2 id="carousel-cards-title"><?= admin_te('block_carousel.kaarten', ['v1' => count($cards)]) ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_carousel.kaarten_uitleg', ['active' => $activeCount, 'total' => count($cards)]) ?></p>

      <?php if ($cards === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_carousel.kaarten_carrousel_zonder_kaarten') ?></p>
      <?php endif; ?>

      <input type="hidden" name="cards_present" value="1">
      <div class="admin-portfolio-grid admin-carousel-cards" data-row-list="carousel-cards">
        <?php foreach (array_keys($cards) as $position => $cardId): ?>
          <?php
            $card = $storedCards[$cardId];
            $state = $cards[$cardId];
            $name = BlockLocalization::name('carousel_cards', $cardId, 'title');
            $image = BlockImage::fromOwner($card, '');
            $switchId = 'carousel-card-' . $cardId . '-active';
          ?>
          <article class="admin-portfolio-card admin-carousel-card" data-row-list-row>
            <input type="hidden" name="cards[<?= $cardId ?>][present]" value="1">
            <div class="admin-portfolio-card__media">
              <?php if ($image['image_path'] !== ''): ?>
                <img src="<?= $h($image['image_path']) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="admin-carousel-card__no-image"><?= admin_te('block_carousel.geen_afbeelding') ?></span>
              <?php endif; ?>
            </div>
            <div class="admin-portfolio-card__body">
              <p class="admin-portfolio-card__name"><?= $h($name) ?></p>
              <div class="admin-field admin-field--inline admin-carousel-card__switch">
                <label class="admin-checkbox-label" for="<?= $h($switchId) ?>">
                  <input type="checkbox" class="admin-switch" role="switch" id="<?= $h($switchId) ?>" name="cards[<?= $cardId ?>][active]" value="1"<?= $state['active'] ? ' checked' : '' ?>>
                  <?= admin_te('block_carousel.kaart_actief') ?>
                </label>
              </div>
              <p class="admin-carousel-card__removing"><?= admin_te('block_carousel.wordt_verwijderd') ?></p>
            </div>
            <div class="admin-carousel-card__actions">
              <button type="submit" class="admin-btn-secondary admin-carousel-card__edit" name="editor_action" value="cards:edit:<?= $cardId ?>" aria-label="<?= admin_te('block_carousel.kaart_bewerken_label', ['name' => $name]) ?>"><?= admin_te('common.edit') ?></button>
              <span class="admin-carousel-card__move-group">
                <button type="submit" class="admin-btn-ghost admin-carousel-card__move" name="editor_action" value="cards:up:<?= $cardId ?>" data-row-list-move="up" aria-label="<?= admin_te('block_carousel.kaart_omhoog_label', ['name' => $name]) ?>"<?= $position === 0 ? ' disabled' : '' ?>><span aria-hidden="true">&larr;</span></button>
                <button type="submit" class="admin-btn-ghost admin-carousel-card__move" name="editor_action" value="cards:down:<?= $cardId ?>" data-row-list-move="down" aria-label="<?= admin_te('block_carousel.kaart_omlaag_label', ['name' => $name]) ?>"<?= $position === count($cards) - 1 ? ' disabled' : '' ?>><span aria-hidden="true">&rarr;</span></button>
              </span>
              <label class="admin-btn-text admin-btn-text--danger admin-carousel-card__remove">
                <input type="checkbox" class="admin-visually-hidden admin-carousel-card__remove-input" name="cards[<?= $cardId ?>][remove]" value="1"<?= $state['remove'] ? ' checked' : '' ?> aria-label="<?= admin_te('block_carousel.kaart_verwijderen_label', ['name' => $name]) ?>">
                <span class="admin-carousel-card__remove-on" aria-hidden="true"><?= admin_te('common.delete') ?></span>
                <span class="admin-carousel-card__remove-off" aria-hidden="true"><?= admin_te('block_carousel.niet_verwijderen') ?></span>
              </label>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="carousel-cards" data-row-list-moved="<?= admin_te('block_carousel.kaart_verplaatst') ?>"></p>

      <div class="admin-carousel-add">
        <?php admin_localized_new_item_note($editLanguage); ?>
        <div class="admin-carousel-add__row">
          <label class="admin-carousel-add__field"><?= admin_te('block_carousel.titel_nieuwe_kaart') ?>
            <input type="text" name="new_card_title" maxlength="255" value="<?= $h(is_array($old) ? (string) ($old['new_card_title'] ?? '') : '') ?>" placeholder="<?= admin_te('block_carousel.nieuwe_kaart_placeholder') ?>">
          </label>
          <button type="submit" class="admin-btn-secondary" name="editor_action" value="cards:add"><?= admin_te('block_carousel.kaart_toevoegen') ?></button>
        </div>
        <p class="admin-text-muted"><?= admin_te('block_carousel.komt_daarna_direct_kaart') ?></p>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_carousel.weergave') ?></h2>

      <fieldset class="admin-carousel-layout">
        <legend><?= admin_te('block_carousel.weergave_groot_scherm') ?></legend>
        <div class="admin-template-grid">
          <?php foreach ([CardCarouselContent::LAYOUT_ORBIT => 'orbit', CardCarouselContent::LAYOUT_ROW => 'row'] as $value => $key): ?>
            <label class="admin-template-card">
              <input type="radio" name="desktop_layout" value="<?= $h($value) ?>"<?= $layout === $value ? ' checked' : '' ?>>
              <span class="admin-template-card__inner">
                <strong><?= admin_te('block_carousel.layout_' . $key) ?></strong>
                <span class="admin-text-muted"><?= admin_te('block_carousel.layout_' . $key . '_uitleg') ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
          <?= admin_te('block_carousel.carrousel_tonen') ?>
        </label>
        <?= admin_help(admin_t('block_carousel.carrousel_tonen'), admin_t('help.block_carousel.carrousel_tonen')) ?>
      </div>
    </section>

    <div class="admin-form-actions">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </div>
  </form>
</main>
<?php save_bar(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
