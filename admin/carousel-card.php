<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Media\BlockImage;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\LinkTargets;
use App\Repository\CardCarouselRepository;
use App\Repository\PageRepository;

require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_link_target_field.php';

/**
 * Editor for ONE card of a "Kaarten-carrousel" (?card_id=...): whether it is
 * shown, its number, its text, its optional image, its optional button and
 * its tags.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). All of
 * it posts to api/admin/update-carousel-card.php and is stored by the one
 * "Opslaan" (or the save bar), tags included: a tag has no save button of its
 * own, and ↑, ↓ and × on a tag change the list on screen
 * (admin/assets/row-list.js) and are stored with everything else. Without
 * JavaScript those buttons submit the whole form, which stores what was typed
 * and then moves or removes the tag (App\Service\Blocks\EditorRows).
 *
 * A card lives on its own screen rather than inline in
 * admin/card-carousel.php because it carries a repeater of its own (tags),
 * the same reason admin/portfolio-item.php exists next to admin/portfolio.php.
 *
 * THE BUTTON points at nothing, at an address typed here, or at a page, a
 * blog post or a product of this website, chosen by name
 * (App\Service\Routing\LinkTargets) and stored by id, so it follows a new
 * slug and a new language by itself. Only the types whose module is on are
 * offered; a card that points at a type whose module is off keeps that link,
 * and the editor says so. The field that belongs to the chosen kind is the
 * one on screen (admin/assets/navigation-item.js, the header and footer
 * editors' own script).
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the card's words, the image's alt text and every tag show the language
 * chosen in the CMS shell, as stored and without the default language's words
 * in an empty translation; the title and a tag's label are required only in
 * the default language. A save writes that language only. The switch, the
 * image and the button's target are the same in every language. A tag keeps
 * its id however often it is saved or moved, so its other languages stay with
 * it; a NEW tag is written in the default language, like a new page, and the
 * screen says so while another language is on screen. The language bar is
 * printed once, above the words, and not per tag.
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
$storedTags = $repository->findTagsByCardId($cardId);

$errors = $_SESSION['admin_carousel_card_errors'] ?? [];
$fieldErrors = $_SESSION['admin_carousel_card_field_errors'] ?? [];
$old = $_SESSION['admin_carousel_card_old'] ?? null;
unset($_SESSION['admin_carousel_card_errors'], $_SESSION['admin_carousel_card_field_errors'], $_SESSION['admin_carousel_card_old']);

$saved = isset($_GET['saved']);
$created = isset($_GET['created']);

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

$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $card['is_active'];

// The button: its kind, its target and its typed address. A row written
// before the kind existed has an address and no kind, and is an address.
$storedLinkType = LinkChoice::storedType($card['link_type'] ?? null, (string) ($card['link_url'] ?? ''));
$linkType = is_array($old) ? (string) ($old['link_type'] ?? '') : $storedLinkType;
$linkType = $linkType === '' ? LinkChoice::NONE : $linkType;
$linkUrl = is_array($old) ? (string) ($old['link_url'] ?? '') : (string) ($card['link_url'] ?? '');
$linkTargets = is_array($old) ? (array) ($old['link_target'] ?? []) : [];
if (!is_array($old) && LinkTargets::isAvailable($storedLinkType)) {
    $linkTargets[$storedLinkType] = (int) ($card['link_target_id'] ?? 0);
}

// The tags on screen: as a refused save handed them back (in their order,
// new rows included), else as stored.
$tagRows = [];
$storedTagIds = array_map(static fn (array $tag): int => (int) $tag['id'], $storedTags);
if (is_array($old)) {
    foreach ((array) ($old['tags'] ?? []) as $key => $label) {
        $key = (string) $key;
        if (ctype_digit($key) && !in_array((int) $key, $storedTagIds, true)) {
            continue;
        }
        $tagRows[] = ['key' => $key, 'label' => $oldInThisLanguage || !ctype_digit($key)
            ? (string) $label
            : BlockLocalization::raw('carousel_card_tags', (int) $key, 'label', $editLanguage)];
    }
} else {
    foreach ($storedTagIds as $tagId) {
        $tagRows[] = ['key' => (string) $tagId, 'label' => BlockLocalization::raw('carousel_card_tags', $tagId, 'label', $editLanguage)];
    }
}

$imagePath = (string) ($card['image_path'] ?? '');
// "Does this card have a photo at all" — a Media Library reference, or a
// legacy path that predates it. Without one the card renders the fixed icon.
$cardMedia = MediaService::find((int) ($card['media_id'] ?? 0));
$hasLegacyImageOnly = $cardMedia === null && $imagePath !== '';

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
// An optional field says so in the default language; in a translation its
// placeholder says what a visitor sees while it is empty.
$optional = admin_localized_optional_attr($editLanguage);
// An empty number is no number (CardCarouselContent): the field says so in
// the default language; in a translation, what the fallback is.
$numberPlaceholder = $placeholder !== '' ? $placeholder : ' placeholder="' . admin_te('block_carousel.nummer_placeholder') . '"';

// The picture's focus point (ImageFocus): as handed back, else as stored.
$imageFocus = ImageFocus::normalise(is_array($old) ? ($old['image_focus'] ?? null) : ($card['image_focus'] ?? null));

/** aria-invalid + aria-describedby for a field with an error of its own, and the message under it. */
$invalid = static function (string $field) use ($fieldErrors, $h): string {
    return isset($fieldErrors[$field]) ? ' aria-invalid="true" aria-describedby="' . $h('error-' . str_replace('.', '-', $field)) . '"' : '';
};
$fieldError = static function (string $field) use ($fieldErrors, $h): void {
    if (isset($fieldErrors[$field])) {
        echo '<p class="admin-field-error" id="' . $h('error-' . str_replace('.', '-', $field)) . '">' . $h((string) $fieldErrors[$field]) . '</p>';
    }
};

/** One tag row; the template for a new row is the same markup with the key __KEY__. */
$tagRow = static function (string $key, string $label, string $fallback) use ($h, $invalid, $fieldError): void {
    ?>
    <div class="admin-option-row admin-option-row--plain" data-row-list-row>
      <input type="text" name="tags[<?= $h($key) ?>][label]" maxlength="60" value="<?= $h($label) ?>" aria-label="<?= admin_te('block_carousel.tag_label') ?>"<?= $fallback === '' ? '' : ' placeholder="' . $h($fallback) . '"' ?><?= $invalid('tags.' . $key) ?>>
      <span class="admin-option-row__actions">
        <span class="admin-option-row__move">
          <button type="submit" class="admin-btn-ghost admin-option-row__move-button" name="editor_action" value="tags:up:<?= $h($key) ?>" data-row-list-move="up" aria-label="<?= admin_te('block_carousel.tag_omhoog') ?>"><span aria-hidden="true">&uarr;</span></button>
          <button type="submit" class="admin-btn-ghost admin-option-row__move-button" name="editor_action" value="tags:down:<?= $h($key) ?>" data-row-list-move="down" aria-label="<?= admin_te('block_carousel.tag_omlaag') ?>"><span aria-hidden="true">&darr;</span></button>
        </span>
        <button type="submit" class="admin-btn-ghost admin-option-row__move-button admin-btn-text--danger" name="editor_action" value="tags:remove:<?= $h($key) ?>" data-row-list-remove aria-label="<?= admin_te('block_carousel.tag_verwijderen') ?>"><span aria-hidden="true">&times;</span></button>
      </span>
      <?php $fieldError('tags.' . $key); ?>
    </div>
    <?php
};
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
  <?php elseif ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('block_carousel.kaart_aangemaakt') ?></p>
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

  <form method="post" action="/api/admin/update-carousel-card.php" class="admin-product-form" data-nav-item-form<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves or removes a tag. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="card_id" value="<?= $cardId ?>">
    <?= admin_localized_input($editLanguage) ?>

    <section class="admin-card">
      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
          <?= admin_te('block_carousel.kaart_actief') ?>
        </label>
        <?= admin_help(admin_t('block_carousel.kaart_actief'), admin_t('help.block_carousel.kaart_actief')) ?>
      </div>
      <?php if (!$isActive): ?>
        <p class="admin-text-muted"><?= admin_te('block_carousel.kaart_concept') ?></p>
      <?php endif; ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_carousel.inhoud') ?></h2>
      <?php admin_localized_bar($editLanguage); ?>

      <div class="admin-field">
        <?= admin_field_label('card-number', admin_t('block_carousel.nummer'), admin_t('help.block_carousel.nummer')) ?>
        <input type="text" id="card-number" name="number_label" maxlength="40" value="<?= $h($cardWord('number_label')) ?>"<?= $numberPlaceholder ?><?= $invalid('number_label') ?>>
        <?php $fieldError('number_label'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('card-title', admin_t('common.title') . $marker) ?>
        <input type="text" id="card-title" name="title" maxlength="255"<?= $required ?> value="<?= $h($cardWord('title')) ?>"<?= $placeholder ?><?= $invalid('title') ?>>
        <?php $fieldError('title'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('card-body', admin_t('block_carousel.tekst')) ?>
        <textarea id="card-body" name="body" maxlength="500" rows="3"<?= $optional ?><?= $invalid('body') ?>><?= $h($cardWord('body')) ?></textarea>
        <?php $fieldError('body'); ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('common.image') ?></h2>
      <div class="admin-form-row">
        <?php media_picker_field('media_id', $cardMedia, 'Afbeelding', 'Optioneel. Zonder afbeelding toont de kaart het vaste icoon.', false); ?>
      </div>
      <?php if ($hasLegacyImageOnly): ?>
        <div class="admin-field admin-field--inline">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-checkbox" name="remove_legacy_image" value="1">
            <?= admin_te('block_carousel.afbeelding_verwijderen_gebruik_icoon') ?>
          </label>
        </div>
      <?php endif; ?>

      <?php /* The focus point, and a preview in the card's own frame: the same
               object-fit: cover and the same object-position as the website
               (ImageFocus), kept in step by admin/assets/image-focus.js. */ ?>
      <fieldset class="admin-image-focus" data-image-focus>
        <legend><?= admin_te('block_carousel.focus') ?> <?= admin_help(admin_t('block_carousel.focus'), admin_t('help.block_carousel.focus')) ?></legend>
        <div class="admin-image-focus__body">
          <div class="admin-image-focus__grid">
            <?php foreach (ImageFocus::keys() as $focusKey): ?>
              <label class="admin-image-focus__point" title="<?= admin_te('media.focus.' . $focusKey) ?>">
                <input type="radio" name="image_focus" value="<?= $h($focusKey) ?>" data-object-position="<?= $h(ImageFocus::objectPosition($focusKey)) ?>"<?= $imageFocus === $focusKey ? ' checked' : '' ?>>
                <span class="admin-visually-hidden"><?= admin_te('media.focus.' . $focusKey) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <figure class="admin-image-focus__preview">
            <?php $focusSrc = BlockImage::fromOwner($card, null)['image_path']; ?>
            <div class="admin-image-focus__frame" data-image-focus-frame<?= $focusSrc === '' ? ' hidden' : '' ?>>
              <img src="<?= $h($focusSrc) ?>" alt="" style="object-position: <?= $h(ImageFocus::objectPosition($imageFocus)) ?>" data-image-focus-preview>
            </div>
            <figcaption class="admin-text-muted"><?= admin_te('block_carousel.focus_voorbeeld') ?></figcaption>
          </figure>
        </div>
      </fieldset>

      <div class="admin-field">
        <?= admin_field_label('card-alt', admin_t('common.alt_text')) ?>
        <?php $cardAlt = media_alt_field('media_id', $cardWord('image_alt'), $cardMedia, $placeholder); ?>
        <input type="text" id="card-alt" name="image_alt" maxlength="255" value="<?= $h($cardAlt['value']) ?>"<?= $cardAlt['attributes'] ?><?= $invalid('image_alt') ?>>
        <?php $fieldError('image_alt'); ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_carousel.knop') ?></h2>

      <?php link_target_field([
          'id' => 'card-link',
          'type_name' => 'link_type',
          'target_name' => 'link_target',
          'url_name' => 'link_url',
          'type' => $linkType,
          'targets' => $linkTargets,
          'url' => $linkUrl,
          'stored_type' => $storedLinkType,
          'invalid' => $invalid('link'),
          'error' => static fn () => $fieldError('link'),
      ]); ?>

      <div class="admin-field" data-nav-link-field="<?= $h(link_target_shown_kinds($storedLinkType)) ?>">
        <?= admin_field_label('card-link-label', admin_t('block_carousel.knoptekst'), admin_t('help.block_carousel.knoptekst')) ?>
        <input type="text" id="card-link-label" name="link_label" maxlength="150" value="<?= $h($cardWord('link_label')) ?>"<?= $optional ?><?= $invalid('link_label') ?>>
        <?php $fieldError('link_label'); ?>
      </div>
    </section>

    <section class="admin-card" aria-labelledby="card-tags-title">
      <h2 id="card-tags-title"><?= admin_te('block_carousel.tags') ?></h2>
      <input type="hidden" name="tags_present" value="1">

      <div class="admin-option-rows">
        <div class="admin-option-rows__list" data-row-list="card-tags">
          <?php foreach ($tagRows as $row): ?>
            <?php
              // A translation's row shows the tag in the default language,
              // so an untranslated tag can be told from an empty one.
              $fallback = $editLanguage === $defaultLanguage || !ctype_digit($row['key'])
                  ? ''
                  : BlockLocalization::raw('carousel_card_tags', (int) $row['key'], 'label', $defaultLanguage);
              $tagRow($row['key'], $row['label'], $fallback);
            ?>
          <?php endforeach; ?>
          <noscript>
            <?php $tagRow('new0', '', ''); ?>
          </noscript>
        </div>
        <?php if ($tagRows === []): ?>
          <p class="admin-text-muted"><?= admin_te('block_carousel.tags_kaart') ?></p>
        <?php endif; ?>
        <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="card-tags" data-row-list-moved="<?= admin_te('block_carousel.tag_verplaatst') ?>"></p>
        <div class="admin-option-rows__tools">
          <button type="button" class="admin-btn-secondary" data-row-list-add="card-tags" hidden>+ <?= admin_te('block_carousel.tag_toevoegen') ?></button>
        </div>
        <?php admin_localized_new_item_note($editLanguage); ?>
      </div>
      <template data-row-list-template="card-tags"><?php $tagRow('__KEY__', '', ''); ?></template>
    </section>

    <div class="admin-form-actions">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </div>
  </form>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/navigation-item.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/image-focus.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
