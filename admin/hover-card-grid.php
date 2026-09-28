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
require_once __DIR__ . '/_admin_collapse.php';

use App\Repository\HoverCardGridRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\HoverCardGridContent;
use App\Service\Media\MediaService;
use App\Service\Routing\LinkChoice;
use App\Service\SectionRegistry;

/**
 * Editor for one Hover kaarten grid (?section=<page content_key>:<section_key>):
 * the heading above the grid, how the cards look, and the cards themselves.
 * The same "valid only when the page and its content row really exist" gate
 * as admin/media-banner.php.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). The
 * heading, the choices and every card — its two pictures, its words, its
 * link, its place, a removal mark, new cards — post to
 * api/admin/update-hover-card-grid.php together, and its one "Opslaan" (or
 * the save bar) stores all of it. ↑, ↓ and "Kaart toevoegen" work on screen
 * (admin/assets/row-list.js); without JavaScript ↑ and ↓ submit the whole
 * form and one empty card waits at the end of the list.
 *
 * EVERY CARD FOLDS ON ITS OWN, like the items of Tekst met afbeelding
 * (editor_row_open() with $collapse): "Kaart 2 — Onze service" as its button.
 * A lone card, a new one and one with a message are open; the others of a
 * longer list start folded and are remembered as the editor left them.
 *
 * THE LINK is the shared destination field of every block button
 * (admin/_link_target_field.php, App\Service\Routing\LinkChoice), one
 * [data-nav-link-group] per card (admin/assets/navigation-item.js), with the
 * card's own link label beside it. The pictures come from the Media picker;
 * there is no upload field and no alt-text field (the library's alt text is
 * used, MEDIA.md).
 *
 * ONE WEBSITE LANGUAGE AT A TIME (admin/_localized_fields.php): the heading
 * and each card's badge, title, text and link label show the language chosen
 * in the CMS shell; all of them are optional. A NEW card is written in the
 * default language, like a new page. The choices, the pictures and the link's
 * destination are the same in every language and stay on screen in each.
 * Whether the block shows is the page builder's eye, like for every newer
 * block; this screen has no second switch for it.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new HoverCardGridRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || ($grid = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$errors = $_SESSION['admin_hover_cards_errors'] ?? [];
$fieldErrors = $_SESSION['admin_hover_cards_field_errors'] ?? [];
$old = $_SESSION['admin_hover_cards_old'] ?? null;
unset($_SESSION['admin_hover_cards_errors'], $_SESSION['admin_hover_cards_field_errors'], $_SESSION['admin_hover_cards_old']);
$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();
$gridId = (int) $grid['id'];

// The words of the grid and of every card, in one query.
BlockLocalization::preloadBlocks([HoverCardGridContent::TABLE => [$gridId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The heading's words on screen: typed and handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $gridId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw(HoverCardGridContent::TABLE, $gridId, $field, $editLanguage);
};

// The choices on screen: as a refused save handed them back, else as
// stored — each checked against its list either way.
$settings = HoverCardGridContent::settings(is_array($old) ? $old : $grid);

// The cards on screen: as a refused save handed them back, else as stored.
$cardRows = editor_rows_on_screen(
    $repository->findItemsByGridId($gridId),
    $oldInThisLanguage ? (array) ($old['cards'] ?? []) : null,
    static function (array $item) use ($editLanguage): array {
        $fields = [
            'media_id' => (string) (int) ($item['media_id'] ?? 0),
            'hover_media_id' => isset($item['hover_media_id']) ? (string) (int) $item['hover_media_id'] : '',
            'link_type' => LinkChoice::storedType($item['link_type'] ?? null, (string) ($item['link_url'] ?? '')),
            'link_target' => (string) (int) ($item['link_target_id'] ?? 0),
            'link_url' => (string) ($item['link_url'] ?? ''),
        ];

        foreach (array_keys(BlockLocalization::fields(HoverCardGridContent::ITEMS)) as $field) {
            $fields[$field] = BlockLocalization::raw(HoverCardGridContent::ITEMS, (int) $item['id'], $field, $editLanguage);
        }

        return $fields;
    }
);

$pageLabel = \App\Service\PageLocalization::name((int) $page['id']);
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$optional = admin_localized_optional_attr($editLanguage);

/**
 * One closed-list choice as a segmented control in a fieldset with a legend
 * (the same markup as admin/media-banner.php), its words under
 * block_hover_cards.<name>_<value>. $needs names the layout the choice only
 * matters for (admin/assets/hover-card-grid.js shows it for that layout only;
 * the server prints the same `hidden`).
 *
 * @param list<string> $values
 */
$choice = static function (string $name, array $values, string $chosen, string $needs = '') use ($h, $fieldErrors, $settings): void {
    $legend = admin_t('block_hover_cards.' . $name);
    $hidden = $needs !== '' && $settings['layout'] !== $needs;
    ?>
      <fieldset class="admin-segmented-field"<?= editor_field_invalid($fieldErrors, $name) ?><?= $needs !== '' ? ' data-hover-cards-needs="' . $h($needs) . '"' : '' ?><?= $hidden ? ' hidden' : '' ?>>
        <legend><?= $h($legend) ?> <?= admin_help($legend, admin_t('help.block_hover_cards.' . $name)) ?></legend>
        <div class="admin-segmented">
          <?php foreach ($values as $value): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="<?= $h($name) ?>" value="<?= $h($value) ?>"<?= $chosen === $value ? ' checked' : '' ?><?= $name === 'layout' ? ' data-hover-cards-layout' : '' ?>>
              <span><?= admin_te('block_hover_cards.' . $name . '_' . $value) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php editor_field_error($fieldErrors, $name); ?>
      </fieldset>
    <?php
};

/**
 * One card; the template for a new one is the same markup with the key __KEY__.
 *
 * @param array<string, mixed>|null $stored the stored row, null for a new card
 */
$cardRow = static function (string $key, array $fields, int $position, int $count, ?array $stored) use ($h, $fieldErrors, $editLanguage): void {
    // A stored card follows the language on screen; a new one is written in
    // the default language (editor_row_word_hints()).
    $optional = admin_localized_optional_attr(ctype_digit($key) ? $editLanguage : admin_localized_default());

    // Folded or open: a new card and one with a message are open (the
    // message's card opens whatever was remembered), a lone card too.
    $hasMessage = false;
    foreach (array_keys($fieldErrors) as $errorKey) {
        if (str_starts_with((string) $errorKey, 'cards.' . $key . '.')) {
            $hasMessage = true;
        }
    }
    $collapse = [
        'title' => (string) ($fields['title'] ?? ''),
        'open' => !ctype_digit($key) || $hasMessage || $count === 1,
        'force' => $hasMessage,
    ];

    // The link's destination on screen: its kind, the chosen item of that
    // kind, and the typed address (LinkChoice, admin/_link_target_field.php).
    $storedType = $stored === null ? '' : LinkChoice::storedType($stored['link_type'] ?? null, (string) ($stored['link_url'] ?? ''));
    $linkType = (string) ($fields['link_type'] ?? LinkChoice::NONE);
    $linkTargets = $linkType !== '' && (int) ($fields['link_target'] ?? 0) > 0 ? [$linkType => (int) $fields['link_target']] : [];
    $hoverId = (int) ($fields['hover_media_id'] ?? 0);

    editor_row_open('cards', $key, admin_t('block_hover_cards.card'), $position, $count, ($fields['remove'] ?? '') !== '', 'admin-hover-card', $collapse);

    echo '<div class="admin-hover-card__pictures">';
    editor_row_media('cards', $key, $fields, $fieldErrors, admin_t('block_hover_cards.image'), admin_t('block_hover_cards.image_help'));
    echo '<div class="admin-field">';
    media_picker_field(
        editor_row_name('cards', $key, 'hover_media_id'),
        $hoverId > 0 ? MediaService::find($hoverId) : null,
        admin_t('block_hover_cards.hover_image'),
        admin_t('block_hover_cards.hover_image_help')
    );
    editor_field_error($fieldErrors, 'cards.' . $key . '.hover_media_id');
    echo '</div>';
    echo '</div>';

    editor_row_text('cards', $key, 'badge', admin_t('block_hover_cards.badge'), 60, $fields, $fieldErrors, $optional);
    editor_row_text('cards', $key, 'title', admin_t('block_hover_cards.card_title'), 255, $fields, $fieldErrors, $optional . ' data-row-list-title-source');
    editor_row_text('cards', $key, 'body', admin_t('block_hover_cards.body'), 500, $fields, $fieldErrors, $optional, 3);

    // The link: the shared destination field every block button has, in a
    // group of its own so each card's label follows its own kind.
    echo '<div class="admin-hover-card__link" data-nav-link-group>';
    echo '<h3>' . admin_te('block_hover_cards.link_heading') . '</h3>';
    $linkErrorKey = 'cards.' . $key . '.link_url';
    link_target_field([
        'id' => editor_row_id('cards', $key, 'link'),
        'type_name' => editor_row_name('cards', $key, 'link_type'),
        'target_name' => editor_row_name('cards', $key, 'link_target'),
        'url_name' => editor_row_name('cards', $key, 'link_url'),
        'type' => $linkType,
        'targets' => $linkTargets,
        'url' => (string) ($fields['link_url'] ?? ''),
        'stored_type' => $storedType,
        'invalid' => editor_field_invalid($fieldErrors, $linkErrorKey),
        'error' => static fn () => editor_field_error($fieldErrors, $linkErrorKey),
        'label' => admin_t('block_hover_cards.link_type'),
    ]);
    echo '<div data-nav-link-field="' . $h(link_target_shown_kinds($storedType)) . '">';
    editor_row_text('cards', $key, 'link_label', admin_t('block_hover_cards.link_label'), 150, $fields, $fieldErrors, $optional);
    echo '<p class="admin-text-muted">' . admin_te('block_hover_cards.link_label_help') . '</p>';
    echo '</div>';
    echo '</div>';
    editor_row_close(true);
};
?>
<!doctype html>
<html lang="<?= $h(\App\Service\Language\AdminLocale::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('hover_card_grid')) ?> — <?= $h($pageLabel) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_hover_cards.terug', ['v1' => $h($pageLabel)]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('hover_card_grid')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_hover_cards.uitleg', ['v1' => $h($pageLabel)]) ?></p>

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

  <form method="post" action="/api/admin/update-hover-card-grid.php" class="admin-product-form" data-nav-item-form data-hover-cards-form data-save-name="<?= $h(SectionRegistry::label('hover_card_grid')) ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves a card. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
    <?= admin_localized_input($editLanguage) ?>
    <?php admin_localized_bar($editLanguage); ?>

    <section class="admin-card">
      <h2><?= admin_te('block_hover_cards.group_heading') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_hover_cards.heading_uitleg') ?></p>

      <div class="admin-field">
        <?= admin_field_label('hover-cards-eyebrow', admin_t('block_hover_cards.eyebrow')) ?>
        <input type="text" id="hover-cards-eyebrow" name="eyebrow" maxlength="150" value="<?= $h($word('eyebrow')) ?>"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'eyebrow') ?>>
        <?php editor_field_error($fieldErrors, 'eyebrow'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('hover-cards-title', admin_t('block_hover_cards.title')) ?>
        <input type="text" id="hover-cards-title" name="title" maxlength="255" value="<?= $h($word('title')) ?>"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'title') ?>>
        <?php editor_field_error($fieldErrors, 'title'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('hover-cards-lead', admin_t('block_hover_cards.lead')) ?>
        <textarea id="hover-cards-lead" name="lead" maxlength="500" rows="3"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'lead') ?>><?= $h($word('lead')) ?></textarea>
        <?php editor_field_error($fieldErrors, 'lead'); ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_hover_cards.group_layout') ?></h2>
      <?php $choice('layout', HoverCardGridContent::LAYOUTS, $settings['layout']); ?>
      <?php $choice('shape', HoverCardGridContent::SHAPES, $settings['shape']); ?>
      <?php $choice('columns', HoverCardGridContent::COLUMNS, $settings['columns']); ?>
      <?php $choice('overlay', HoverCardGridContent::OVERLAYS, $settings['overlay'], 'overlay'); ?>
      <?php $choice('effect', HoverCardGridContent::EFFECTS, $settings['effect']); ?>
      <?php $choice('header_align', HoverCardGridContent::HEADER_ALIGNMENTS, $settings['header_align']); ?>
    </section>

    <section class="admin-card" aria-labelledby="hover-cards-list-title">
      <h2 id="hover-cards-list-title"><?= admin_te('block_hover_cards.cards') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_hover_cards.cards_uitleg') ?></p>

      <?php if ($cardRows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_hover_cards.cards_leeg') ?></p>
      <?php endif; ?>

      <input type="hidden" name="cards_present" value="1">
      <div class="admin-row-cards" data-row-list="hover-card-grid-cards" data-admin-collapse-group="hover-card-grid-cards" data-admin-collapse-scope="<?= $gridId ?>" data-admin-collapse-no-return>
        <?php foreach ($cardRows as $position => $row): ?>
          <?php $cardRow($row['key'], $row['fields'], $position, count($cardRows), $row['stored']); ?>
        <?php endforeach; ?>
        <noscript>
          <?php $cardRow(editor_rows_free_key($cardRows), [], count($cardRows), count($cardRows) + 1, null); ?>
        </noscript>
      </div>
      <?php editor_rows_status('hover-card-grid-cards'); ?>
      <?php editor_rows_add('hover-card-grid-cards', admin_t('block_hover_cards.card_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="hover-card-grid-cards"><?php $cardRow('__KEY__', [], 0, 1, null); ?></template>
    </section>

    <div class="admin-form-actions">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </div>
  </form>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
<?php link_target_scripts(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/hover-card-grid.js') ?>" defer></script>
<?php admin_collapse_script(); ?>
<?php save_bar_script(); ?>
</body>
</html>
