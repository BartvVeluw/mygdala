<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_block_editor.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_link_target_field.php';
require_once __DIR__ . '/_admin_collapse.php';
require_once __DIR__ . '/_responsive_image_field.php';
require_once __DIR__ . '/_button_style_field.php';

use App\Repository\ReviewsRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentBlockAccess;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\Media\ResponsiveImage;
use App\Service\ReviewsContent;
use App\Service\Routing\LinkChoice;
use App\Service\SectionRegistry;
use App\Service\Theme\ButtonStyles;

/**
 * Editor for one Reviews block (?section=<page content_key>:<section_key>):
 * the heading, the layout, the reviews and an optional button. The same
 * "valid only when the page and its content row really exist" gate as every
 * block editor, owner-aware (ContentBlockAccess): a page, a product's or a
 * project's content page.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). The
 * heading, the layout, the button and every review post to
 * api/admin/update-reviews.php together. ↑, ↓ and "Review toevoegen" work on
 * screen (admin/assets/row-list.js); without JavaScript ↑ and ↓ submit the
 * whole form and one empty review waits at the end of the list.
 *
 * EVERY REVIEW FOLDS ON ITS OWN (editor_row_open() with $collapse):
 * "Review 2 — Peter" as its button, "Review 3 — Anoniem" without a name. A
 * lone review, a new one and one with a message are open; the others of a
 * longer list start folded and are remembered as the editor left them.
 *
 * THE LAYOUT is one select, "Weergave", with a small sketch of each of the
 * four next to it and one sentence about the chosen one
 * (admin/assets/reviews.js follows the select; the server prints the same
 * state). "Deze review uitlichten" only shows for "Uitgelichte review".
 * There is no background, border or colour here: that is "Extra vormgeving"
 * in the page's block list, the one place for every block.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (admin/_localized_fields.php): the heading,
 * the button label and each review's text, name, description and source
 * label show the language chosen in the CMS shell. A NEW review is written in
 * the default language. Stars, dates, pictures, source addresses, the layout
 * and the button's destination are the same in every language.
 */

AdminAuth::requireLogin();
ContentBlockAccess::requireAny();

$sectionParam = (string) ($_GET['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ReviewsRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : ContentBlockAccess::pageForKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || ($block = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$errors = $_SESSION['admin_reviews_errors'] ?? [];
$fieldErrors = $_SESSION['admin_reviews_field_errors'] ?? [];
$old = $_SESSION['admin_reviews_old'] ?? null;
unset($_SESSION['admin_reviews_errors'], $_SESSION['admin_reviews_field_errors'], $_SESSION['admin_reviews_old']);
$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();
$blockId = (int) $block['id'];

BlockLocalization::preloadBlocks([ReviewsContent::TABLE => [$blockId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The block's words on screen: typed and handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $blockId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw(ReviewsContent::TABLE, $blockId, $field, $editLanguage);
};

$settings = ReviewsContent::settings(is_array($old) ? $old : $block);
$storedItems = $repository->findItemsByBlockId($blockId);

// The featured review on screen: its row key. Stored, that is the id; a
// stored id that is no longer a review of this block is no choice.
$featured = is_array($old) ? (string) ($old['featured'] ?? '') : (string) (int) ($block['featured_item_id'] ?? 0);

$imageSlot = ReviewsContent::imageSlot();
$reviewRows = editor_rows_on_screen(
    $storedItems,
    $oldInThisLanguage ? (array) ($old['reviews'] ?? []) : null,
    static function (array $item) use ($editLanguage, $imageSlot): array {
        $fields = [
            'media_id' => isset($item['media_id']) ? (string) (int) $item['media_id'] : '',
            'rating' => isset($item['rating']) ? (string) (int) $item['rating'] : '',
            'review_date' => (string) ($item['review_date'] ?? ''),
            'source_url' => (string) ($item['source_url'] ?? ''),
        ] + ResponsiveImage::fromRow($item, $imageSlot)->toRow($imageSlot);

        foreach (array_keys(BlockLocalization::fields(ReviewsContent::ITEMS)) as $field) {
            $fields[$field] = BlockLocalization::raw(ReviewsContent::ITEMS, (int) $item['id'], $field, $editLanguage);
        }

        return $fields;
    }
);
$keysOnScreen = array_column($reviewRows, 'key');
if (!in_array($featured, $keysOnScreen, true)) {
    $featured = '';
}

// The button on screen: as a refused save handed it back, else as stored.
$storedLinkType = LinkChoice::storedType($block['link_type'] ?? null, (string) ($block['link_url'] ?? ''));
if (is_array($old)) {
    $button = [
        'type' => (string) ($old['link_type'] ?? LinkChoice::NONE),
        'targets' => (array) ($old['link_target'] ?? []),
        'url' => (string) ($old['link_url'] ?? ''),
        'style' => ButtonStyles::storedChoice($old['button_style_id'] ?? null),
    ];
} else {
    $button = [
        'type' => $storedLinkType,
        'targets' => !in_array($storedLinkType, [LinkChoice::NONE, LinkChoice::URL], true) ? [$storedLinkType => (int) ($block['link_target_id'] ?? 0)] : [],
        'url' => (string) ($block['link_url'] ?? ''),
        'style' => ButtonStyles::storedChoice($block['button_style_id'] ?? null),
    ];
}

$pageLabel = \App\Service\PageLocalization::name((int) $page['id']);
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$optional = admin_localized_optional_attr($editLanguage);
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
$isFeaturedLayout = $settings['layout'] === 'featured';

/**
 * One review; the template for a new one is the same markup with the key __KEY__.
 *
 * @param array<string, string> $fields the row's values on screen
 */
$reviewRow = static function (string $key, array $fields, int $position, int $count) use ($h, $fieldErrors, $editLanguage, $imageSlot, $marker, $placeholder, $featured, $isFeaturedLayout): void {
    $optional = admin_localized_optional_attr(ctype_digit($key) ? $editLanguage : admin_localized_default());
    [$star, $hint] = editor_row_word_hints($key, $marker, $placeholder);

    $hasMessage = false;
    foreach (array_keys($fieldErrors) as $errorKey) {
        if (str_starts_with((string) $errorKey, 'reviews.' . $key . '.')) {
            $hasMessage = true;
        }
    }
    $anonymous = admin_t('block_reviews.anoniem');
    $name = trim((string) ($fields['name'] ?? ''));
    $collapse = [
        // A review without a name stays recognisable: "Review 3 — Anoniem".
        'title' => $name !== '' ? $name : $anonymous,
        'open' => !ctype_digit($key) || $hasMessage || $count === 1,
        'force' => $hasMessage,
    ];

    editor_row_open('reviews', $key, admin_t('block_reviews.review'), $position, $count, ($fields['remove'] ?? '') !== '', 'admin-review', $collapse);

    editor_row_text('reviews', $key, 'body', admin_t('block_reviews.body') . $star, 1500, $fields, $fieldErrors, $hint, 5);

    echo '<div class="admin-review__grid">';
    editor_row_text('reviews', $key, 'name', admin_t('block_reviews.name'), 150, $fields, $fieldErrors, $optional . ' data-row-list-title-source data-row-list-title-fallback="' . $h($anonymous) . '"');
    editor_row_text('reviews', $key, 'role', admin_t('block_reviews.role'), 150, $fields, $fieldErrors, $optional);

    // Stars: none (the start) or 1..5. A select, so there is never a
    // half-chosen value.
    $ratingId = editor_row_id('reviews', $key, 'rating');
    $ratingKey = 'reviews.' . $key . '.rating';
    $rating = (string) ($fields['rating'] ?? '');
    echo '<div class="admin-field">' . admin_field_label($ratingId, admin_t('block_reviews.rating'));
    echo '<select id="' . $h($ratingId) . '" name="' . $h(editor_row_name('reviews', $key, 'rating')) . '" class="admin-select"' . editor_field_invalid($fieldErrors, $ratingKey) . '>';
    echo '<option value=""' . ($rating === '' ? ' selected' : '') . '>' . admin_te('block_reviews.rating_none') . '</option>';
    for ($stars = 1; $stars <= ReviewsContent::MAX_RATING; $stars++) {
        echo '<option value="' . $stars . '"' . ($rating === (string) $stars ? ' selected' : '') . '>' . admin_te($stars === 1 ? 'block_reviews.rating_one' : 'block_reviews.rating_many', ['n' => (string) $stars]) . '</option>';
    }
    echo '</select>';
    editor_field_error($fieldErrors, $ratingKey);
    echo '</div>';

    $dateId = editor_row_id('reviews', $key, 'review_date');
    $dateKey = 'reviews.' . $key . '.review_date';
    echo '<div class="admin-field">' . admin_field_label($dateId, admin_t('block_reviews.date'));
    echo '<input type="date" id="' . $h($dateId) . '" name="' . $h(editor_row_name('reviews', $key, 'review_date')) . '" value="' . $h((string) ($fields['review_date'] ?? '')) . '"' . editor_field_invalid($fieldErrors, $dateKey) . '>';
    editor_field_error($fieldErrors, $dateKey);
    echo '</div>';
    echo '</div>';

    // The picture: optional, from the Media Library, and how it sits in its
    // round frame (Responsive Media).
    editor_row_media('reviews', $key, $fields, $fieldErrors, admin_t('block_reviews.image'), admin_t('block_reviews.image_help'), true);
    $media = (int) ($fields['media_id'] ?? 0) > 0 ? MediaService::find((int) $fields['media_id']) : null;
    $presentation = ResponsiveImage::fromRow($fields, $imageSlot);
    $presentationErrors = [];
    foreach ($fieldErrors as $errorKey => $message) {
        if (str_starts_with((string) $errorKey, 'reviews.' . $key . '.presentation.')) {
            $presentationErrors[substr((string) $errorKey, strlen('reviews.' . $key . '.presentation.'))] = (string) $message;
        }
    }
    responsive_image_field([
        'slot' => $imageSlot,
        'value' => $presentation,
        'id' => editor_row_id('reviews', $key, 'picture'),
        'name' => static fn (string $column): string => editor_row_name('reviews', $key, $column),
        'preview' => $media !== null ? $media->displayPath() : '',
        'picker' => editor_row_name('reviews', $key, 'media_id'),
        'mobile_media' => MediaService::find($presentation->mobileMediaId),
        // The same shape on every screen (admin.css); a tablet shows it too.
        'views' => \App\Service\Media\ImagePresentation::VIEWS,
        'errors' => $presentationErrors,
        'note' => admin_t('block_reviews.presentation_note'),
    ]);

    // Where the review comes from: words and, optionally, a web address.
    echo '<div class="admin-review__grid">';
    editor_row_text('reviews', $key, 'source_label', admin_t('block_reviews.source_label'), 150, $fields, $fieldErrors, $optional);
    $sourceId = editor_row_id('reviews', $key, 'source_url');
    $sourceKey = 'reviews.' . $key . '.source_url';
    echo '<div class="admin-field">' . admin_field_label($sourceId, admin_t('block_reviews.source_url'), admin_t('help.block_reviews.source_url'));
    echo '<input type="url" id="' . $h($sourceId) . '" name="' . $h(editor_row_name('reviews', $key, 'source_url')) . '" maxlength="500" value="' . $h((string) ($fields['source_url'] ?? '')) . '" placeholder="https://"' . editor_field_invalid($fieldErrors, $sourceKey) . '>';
    editor_field_error($fieldErrors, $sourceKey);
    echo '</div>';
    echo '</div>';

    // "Uitgelichte review" shows one review: this one, when chosen.
    echo '<p class="admin-review__featured" data-reviews-needs="featured"' . ($isFeaturedLayout ? '' : ' hidden') . '>'
        . '<label><input type="radio" name="featured" value="' . $h($key) . '"' . ($featured !== '' && $featured === $key ? ' checked' : '') . '> '
        . admin_te('block_reviews.featured_choice') . '</label></p>';

    editor_row_close(true);
};

/** The four layouts as small sketches, so the choice is visible before it is saved. */
$sketches = [
    'cards' => '<rect x="3" y="6" width="17" height="24" rx="3"/><rect x="23" y="6" width="17" height="24" rx="3"/><rect x="43" y="6" width="17" height="24" rx="3"/><path d="M7 11h6M27 11h6M47 11h6M7 16h9M27 16h9M47 16h9M7 25h5M27 25h5M47 25h5"/>',
    'minimal' => '<path d="M28 7l-2.5 7M35 7l-2.5 7"/><path d="M12 20h39M17 25h29M27 31h9"/>',
    'featured' => '<rect x="3" y="4" width="57" height="30" rx="4"/><circle cx="16" cy="19" r="7"/><path d="M29 12h24M29 17h20M29 22h24M29 28h9"/>',
    'carousel' => '<rect x="9" y="5" width="14" height="22" rx="3"/><rect x="25" y="5" width="14" height="22" rx="3"/><rect x="41" y="5" width="14" height="22" rx="3"/><path d="M5 13l-3 3 3 3M58 13l3 3-3 3"/><circle cx="26" cy="33" r="2"/><circle cx="37" cy="33" r="2"/>',
];
?>
<!doctype html>
<html lang="<?= $h(\App\Service\Language\AdminLocale::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('reviews')) ?> — <?= $h($pageLabel) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <?php if (!block_editor_draft_notice('reviews', $csrfToken)): ?>
    <p class="admin-text-muted"><a href="<?= $h(ContentBlockAccess::listUrl($page)) ?>"><?= admin_t('block_reviews.terug', ['v1' => $h($pageLabel)]) ?></a></p>
  <?php endif; ?>
  <h1><?= $h(SectionRegistry::label('reviews')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_reviews.uitleg', ['v1' => $h($pageLabel)]) ?></p>

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

  <form method="post" action="/api/admin/update-reviews.php" class="admin-product-form" data-nav-item-form data-reviews-form data-reviews-layout="<?= $h($settings['layout']) ?>" data-save-name="<?= $h(SectionRegistry::label('reviews')) ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves a review. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
    <?= admin_localized_input($editLanguage) ?>
    <?php admin_localized_bar($editLanguage); ?>

    <section class="admin-card">
      <h2><?= admin_te('block_reviews.group_layout') ?></h2>
      <div class="admin-field">
        <?= admin_field_label('reviews-layout', admin_t('block_reviews.layout'), admin_t('help.block_reviews.layout')) ?>
        <select id="reviews-layout" name="layout" class="admin-select" data-reviews-layout-select aria-describedby="reviews-layout-note"<?= editor_field_invalid($fieldErrors, 'layout') ?>>
          <?php foreach (ReviewsContent::LAYOUTS as $layout): ?>
            <option value="<?= $h($layout) ?>"<?= $settings['layout'] === $layout ? ' selected' : '' ?>><?= admin_te('block_reviews.layout_' . $layout) ?></option>
          <?php endforeach; ?>
        </select>
        <?php editor_field_error($fieldErrors, 'layout'); ?>
      </div>
      <ul class="admin-reviews-sketches" aria-hidden="true">
        <?php foreach ($sketches as $layout => $shapes): ?>
          <li class="admin-reviews-sketch" data-reviews-sketch="<?= $h($layout) ?>">
            <svg viewBox="0 0 63 38" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><?= $shapes ?></svg>
            <span><?= admin_te('block_reviews.layout_' . $layout) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="admin-text-muted" id="reviews-layout-note" data-reviews-layout-note>
        <?php foreach (ReviewsContent::LAYOUTS as $layout): ?>
          <span data-reviews-note="<?= $h($layout) ?>"<?= $settings['layout'] === $layout ? '' : ' hidden' ?>><?= admin_te('block_reviews.layout_' . $layout . '_uitleg') ?></span>
        <?php endforeach; ?>
      </p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_reviews.group_heading') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_reviews.heading_uitleg') ?></p>

      <div class="admin-field">
        <?= admin_field_label('reviews-eyebrow', admin_t('block_reviews.eyebrow')) ?>
        <input type="text" id="reviews-eyebrow" name="eyebrow" maxlength="150" value="<?= $h($word('eyebrow')) ?>"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'eyebrow') ?>>
        <?php editor_field_error($fieldErrors, 'eyebrow'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('reviews-title', admin_t('block_reviews.title')) ?>
        <input type="text" id="reviews-title" name="title" maxlength="255" value="<?= $h($word('title')) ?>"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'title') ?>>
        <?php editor_field_error($fieldErrors, 'title'); ?>
      </div>

      <div class="admin-field">
        <?= admin_field_label('reviews-lead', admin_t('block_reviews.lead')) ?>
        <textarea id="reviews-lead" name="lead" maxlength="500" rows="3"<?= $optional ?><?= editor_field_invalid($fieldErrors, 'lead') ?>><?= $h($word('lead')) ?></textarea>
        <?php editor_field_error($fieldErrors, 'lead'); ?>
      </div>

      <fieldset class="admin-segmented-field"<?= editor_field_invalid($fieldErrors, 'header_align') ?>>
        <legend><?= admin_te('block_reviews.header_align') ?></legend>
        <div class="admin-segmented">
          <?php foreach (ReviewsContent::HEADER_ALIGNMENTS as $align): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="header_align" value="<?= $h($align) ?>"<?= $settings['header_align'] === $align ? ' checked' : '' ?>>
              <span><?= admin_te('block_reviews.header_align_' . $align) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php editor_field_error($fieldErrors, 'header_align'); ?>
      </fieldset>
    </section>

    <section class="admin-card" aria-labelledby="reviews-list-title">
      <h2 id="reviews-list-title"><?= admin_te('block_reviews.reviews') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_reviews.reviews_uitleg') ?></p>

      <?php if ($reviewRows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_reviews.reviews_leeg') ?></p>
      <?php endif; ?>

      <input type="hidden" name="reviews_present" value="1">
      <div class="admin-row-cards" data-row-list="reviews-items" data-admin-collapse-group="reviews-items" data-admin-collapse-scope="<?= $blockId ?>" data-admin-collapse-no-return>
        <?php foreach ($reviewRows as $position => $row): ?>
          <?php $reviewRow($row['key'], $row['fields'], $position, count($reviewRows)); ?>
        <?php endforeach; ?>
        <noscript>
          <?php $reviewRow(editor_rows_free_key($reviewRows), [], count($reviewRows), count($reviewRows) + 1); ?>
        </noscript>
      </div>
      <?php editor_rows_status('reviews-items'); ?>
      <?php editor_rows_add('reviews-items', admin_t('block_reviews.review_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="reviews-items"><?php $reviewRow('__KEY__', [], 0, 1); ?></template>
    </section>

    <section class="admin-card" data-nav-link-group>
      <h2><?= admin_te('block_reviews.group_button') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_reviews.button_uitleg') ?></p>
      <?php link_target_field([
          'id' => 'reviews-link',
          'type_name' => 'link_type',
          'target_name' => 'link_target',
          'url_name' => 'link_url',
          'type' => $button['type'],
          'targets' => $button['targets'],
          'url' => $button['url'],
          'stored_type' => $storedLinkType,
          'label' => admin_t('block_reviews.link_type'),
          'invalid' => editor_field_invalid($fieldErrors, 'link_url'),
          'error' => static fn () => editor_field_error($fieldErrors, 'link_url'),
      ]); ?>
      <div class="admin-field" data-nav-link-field="<?= $h(link_target_shown_kinds($storedLinkType)) ?>">
        <?= admin_field_label('reviews-button-label', admin_t('block_reviews.button_label')) ?>
        <input type="text" id="reviews-button-label" name="button_label" maxlength="150" value="<?= $h($word('button_label')) ?>"<?= $placeholder ?><?= editor_field_invalid($fieldErrors, 'button_label') ?>>
        <?php editor_field_error($fieldErrors, 'button_label'); ?>
      </div>
      <div data-nav-link-field="<?= $h(link_target_shown_kinds($storedLinkType)) ?>">
        <?= admin_button_style_field('reviews-button-style', 'button_style_id', $button['style'], 'secondary', '', $fieldErrors['button_style_id'] ?? null) ?>
      </div>
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
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/reviews.js') ?>" defer></script>
<?php admin_collapse_script(); ?>
<?php responsive_image_field_script(); ?>
<?php save_bar_script(); ?>
</body>
</html>
