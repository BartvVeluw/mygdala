<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';
require_once __DIR__ . '/_link_target_field.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_responsive_image_field.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\CtaBandContent;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\MediaService;
use App\Service\Routing\LinkChoice;
use App\Repository\PageRepository;
use App\Repository\CtaBandRepository;

/**
 * Editor for one CTA band page-builder instance
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/rich-text.php,
 * admin/faq.php, ...). A page may carry several CTA bands; each is edited
 * here under its own key and never overwrites another.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the five text fields show the language chosen in the CMS shell, as stored
 * and without the default language's words in an empty translation, and the
 * title is required only in the default language; the save writes that
 * language only. Everything else is the same in every language and stays on
 * screen in each. Input a refused save hands back comes back in the language
 * it was typed in, and the form then starts out unsaved in the save bar.
 *
 * FIVE CARDS, one per question (CTA 2.0, CONTENT-BLOCKS.md): Inhoud,
 * Weergave (alignment, lead width, full width), Achtergrond (the picture from
 * the Media Library, its focus point and overlay), Tekstvlak, and Knoppen.
 * Every presentation choice is a segmented choice from a closed list
 * (CtaBandContent), never a free value.
 *
 * WHAT HIDES WITH WHAT. Each button is the shared destination field
 * (admin/_link_target_field.php) with its label in its own
 * [data-nav-link-group], so "Geen knop" hides the label
 * (admin/assets/navigation-item.js). The second button's whole group sits
 * inside a field of the FIRST group, so it hides while the first button is
 * "Geen knop": there is no second button without a first. The focus point and
 * the overlay show only with a picture, the panel's opacity only with the
 * panel on (admin/assets/cta-band.js). The server prints the same `hidden`
 * for what is stored, the form always posts everything, and the endpoint
 * decides what a value means.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$slug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new CtaBandRepository();
$page = ($slug === null || $slug === '') ? null : (new PageRepository())->findByContentKey($slug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || $repository->findBySlugAndKey($slug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$pageId = (int) $page['id'];
$pageLabel = \App\Service\PageLocalization::name((int) $page['id']);
$editLanguage = admin_localized_language();

$errors = $_SESSION['admin_cta_band_errors'] ?? [];
$fieldErrors = $_SESSION['admin_cta_band_field_errors'] ?? [];
$old = $_SESSION['admin_cta_band_old'] ?? null;
unset($_SESSION['admin_cta_band_errors'], $_SESSION['admin_cta_band_field_errors'], $_SESSION['admin_cta_band_old']);

$saved = isset($_GET['saved']);

try {
    $row = $repository->findBySlugAndKey($slug, $sectionKey);
} catch (\Throwable $e) {
    error_log('[admin/cta-band.php] ' . $e->getMessage());
    $row = null;
}

// Unreachable in practice (the gate above already required the row) — an
// empty form is still the safe degradation if the second read fails.
$row ??= [];
$sectionId = (int) ($row['id'] ?? 0);
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The words of one field on screen: typed and handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return $sectionId > 0 ? BlockLocalization::raw('cta_bands', $sectionId, $field, $editLanguage) : '';
};

// The presentation on screen: as handed back, else as stored — each a word
// of its closed list either way.
$source = is_array($old) ? $old : [
    'content_align' => $row['content_align'] ?? null,
    'lead_width' => $row['lead_width'] ?? null,
    'background_overlay' => $row['background_overlay'] ?? null,
    'text_panel_opacity' => $row['text_panel_opacity'] ?? null,
    'full_width' => (bool) ($row['full_width'] ?? false),
    'text_panel' => (bool) ($row['text_panel'] ?? false),
    'background_media_id' => $row['background_media_id'] ?? '',
];
$align = CtaBandContent::choice(CtaBandContent::ALIGNMENTS, $source['content_align'] ?? null);
$leadWidth = CtaBandContent::choice(CtaBandContent::LEAD_WIDTHS, $source['lead_width'] ?? null);
$overlay = CtaBandContent::choice(CtaBandContent::OVERLAYS, $source['background_overlay'] ?? null);
$panelOpacity = CtaBandContent::choice(CtaBandContent::PANEL_OPACITIES, $source['text_panel_opacity'] ?? null);
// How the background sits in the band (Responsive Media 2.0): handed back,
// else stored.
$backgroundSlot = CtaBandContent::backgroundSlot();
$backgroundPresentation = ResponsiveImage::fromRow(
    is_array($old) && is_array($old['background_presentation'] ?? null) ? $old['background_presentation'] : ($row ?? []),
    $backgroundSlot
);
$backgroundErrors = [];
foreach ($fieldErrors as $errorField => $errorMessage) {
    if (str_starts_with((string) $errorField, 'presentation.')) {
        $backgroundErrors[substr((string) $errorField, 13)] = (string) $errorMessage;
    }
}
$fullWidth = !empty($source['full_width']);
$textPanel = !empty($source['text_panel']);
$backgroundId = (string) ($source['background_media_id'] ?? '');
$background = MediaService::findImage(ctype_digit($backgroundId) ? (int) $backgroundId : null);
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) ($row['is_active'] ?? true);

// Each button's destination on screen: as handed back, else as stored.
$buttons = [];
foreach (['primary', 'secondary'] as $button) {
    $storedType = LinkChoice::storedType($row[$button . '_link_type'] ?? null, (string) ($row[$button . '_url'] ?? ''));
    $targets = is_array($old) ? (array) ($old[$button . '_link_target'] ?? []) : [];
    if (!is_array($old) && !in_array($storedType, [LinkChoice::NONE, LinkChoice::URL], true)) {
        $targets[$storedType] = (int) ($row[$button . '_link_target_id'] ?? 0);
    }
    $buttons[$button] = [
        'stored_type' => $storedType,
        'type' => is_array($old) ? (string) ($old[$button . '_link_type'] ?? '') : $storedType,
        'targets' => $targets,
        'url' => is_array($old) ? (string) ($old[$button . '_url'] ?? '') : (string) ($row[$button . '_url'] ?? ''),
    ];
}
$primaryIsButton = !in_array($buttons['primary']['type'], ['', LinkChoice::NONE], true);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);

/**
 * One closed-list choice as a segmented control in a fieldset with a legend
 * (the same markup as editor_row_choice()), its words under block_cta.<name>_<value>.
 *
 * @param list<string> $values
 */
$choice = static function (string $name, string $legendKey, string $helpKey, array $values, string $chosen) use ($h, $fieldErrors): void {
    $legend = admin_t($legendKey);
    ?>
      <fieldset class="admin-segmented-field"<?= editor_field_invalid($fieldErrors, $name) ?>>
        <legend><?= $h($legend) ?> <?= admin_help($legend, admin_t($helpKey)) ?></legend>
        <div class="admin-segmented">
          <?php foreach ($values as $value): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="<?= $h($name) ?>" value="<?= $h($value) ?>"<?= $chosen === $value ? ' checked' : '' ?>>
              <span><?= admin_te('block_cta.' . $name . '_' . $value) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php editor_field_error($fieldErrors, $name); ?>
      </fieldset>
    <?php
};

/** One button: the shared destination field and its label, in its own group. */
$buttonFields = static function (string $button, string $labelKey) use ($buttons, $h, $fieldErrors, $editLanguage, $word): void {
    $state = $buttons[$button];
    link_target_field([
        'id' => 'cta-' . $button . '-link',
        'type_name' => $button . '_link_type',
        'target_name' => $button . '_link_target',
        'url_name' => $button . '_url',
        'type' => $state['type'],
        'targets' => $state['targets'],
        'url' => $state['url'],
        'stored_type' => $state['stored_type'],
        'label' => admin_t($labelKey),
        'invalid' => editor_field_invalid($fieldErrors, $button . '_url'),
        'error' => static fn () => editor_field_error($fieldErrors, $button . '_url'),
    ]);
    ?>
      <div class="admin-field" data-nav-link-field="<?= $h(link_target_shown_kinds($state['stored_type'])) ?>">
        <?= admin_field_label('cta-' . $button . '-label', admin_t('block_cta.knoptekst'), admin_t('help.block_cta.knoptekst')) ?>
        <input type="text" id="cta-<?= $h($button) ?>-label" name="<?= $h($button) ?>_label" maxlength="150" value="<?= $h($word($button . '_label')) ?>"<?= admin_localized_placeholder_attr($editLanguage) ?><?= editor_field_invalid($fieldErrors, $button . '_label') ?>>
        <?php editor_field_error($fieldErrors, $button . '_label'); ?>
      </div>
    <?php
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('block_cta.cta_band_admin', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= $pageId ?>"><?= admin_t('block_cta.text', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= admin_t('block_cta.cta_band', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_cta.oproep_tot_actie_sectie', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/update-cta-band.php" class="admin-product-form" data-nav-item-form data-cta-band-form<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="<?= htmlspecialchars($sectionParam, ENT_QUOTES, 'UTF-8') ?>">
    <?= admin_localized_input($editLanguage) ?>

    <section class="admin-card">
      <h2><?= admin_te('block_cta.group_content') ?></h2>
      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_cta.eyebrow') ?>
          <input type="text" name="eyebrow" maxlength="150" value="<?= $h($word('eyebrow')) ?>"<?= admin_localized_optional_attr($editLanguage) ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_cta.titel_h2') ?><?= $marker ?>
          <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_cta.introtekst_lead') ?>
          <textarea name="lead" maxlength="500" rows="3"<?= $placeholder ?>><?= $h($word('lead')) ?></textarea>
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_cta.leeg_laten_beide_talen') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_cta.actief_uitgevinkt_sectie_getoond') ?>
      </label>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_cta.group_layout') ?></h2>
      <?php $choice('content_align', 'block_cta.content_align', 'help.block_cta.content_align', CtaBandContent::ALIGNMENTS, $align); ?>
      <?php $choice('lead_width', 'block_cta.lead_width', 'help.block_cta.lead_width', CtaBandContent::LEAD_WIDTHS, $leadWidth); ?>
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-switch" role="switch" name="full_width" value="1"<?= $fullWidth ? ' checked' : '' ?>>
        <?= admin_te('block_cta.full_width') ?>
      </label>
      <p class="admin-text-muted"><?= admin_te('block_cta.full_width_uitleg') ?></p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_cta.group_background') ?></h2>
      <?php media_picker_field(
          'background_media_id',
          $background,
          admin_t('block_cta.background_image'),
          admin_t('block_cta.background_image_help')
      ); ?>
      <?php editor_field_error($fieldErrors, 'background_media_id'); ?>

      <div data-cta-needs-image<?= $background !== null ? '' : ' hidden' ?>>
        <?php /* How the background sits in the band, on a large screen and
                 on a phone (Responsive Media 2.0). The frames are a band of
                 about this shape: as tall as its words, so a guide. */ ?>
        <?php responsive_image_field([
            'slot' => $backgroundSlot,
            'value' => $backgroundPresentation,
            'id' => 'cta-background',
            'preview' => $background !== null ? $background->displayPath() : '',
            'picker' => 'background_media_id',
            'mobile_media' => MediaService::find($backgroundPresentation->mobileMediaId),
            'frame' => ['desktop' => '1152 / 400', 'mobile' => '343 / 480'],
            'errors' => $backgroundErrors,
        ]); ?>
        <?php $choice('background_overlay', 'block_cta.background_overlay', 'help.block_cta.background_overlay', CtaBandContent::OVERLAYS, $overlay); ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_cta.group_panel') ?></h2>
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-switch" role="switch" name="text_panel" value="1" data-cta-panel-toggle<?= $textPanel ? ' checked' : '' ?>>
        <?= admin_te('block_cta.text_panel') ?>
      </label>
      <p class="admin-text-muted"><?= admin_te('block_cta.text_panel_uitleg') ?></p>
      <div data-cta-needs-panel<?= $textPanel ? '' : ' hidden' ?>>
        <?php $choice('text_panel_opacity', 'block_cta.text_panel_opacity', 'help.block_cta.text_panel_opacity', CtaBandContent::PANEL_OPACITIES, $panelOpacity); ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_cta.group_buttons') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_cta.buttons_uitleg') ?></p>
      <div data-nav-link-group>
        <?php $buttonFields('primary', 'block_cta.primary_button'); ?>

        <?php /* The second button lives inside a field of the first group:
                 hidden with it while the first is "Geen knop". */ ?>
        <div data-nav-link-field="<?= $h(link_target_shown_kinds($buttons['primary']['stored_type'])) ?>"<?= $primaryIsButton ? '' : ' hidden' ?>>
          <div data-nav-link-group>
            <h3><?= admin_te('block_cta.secondary_button_heading') ?></h3>
            <?php $buttonFields('secondary', 'block_cta.secondary_button'); ?>
          </div>
        </div>
      </div>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php link_target_scripts(); ?>
<?php save_bar_script(); ?>
<?php media_picker_script(); ?>
<?php responsive_image_field_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/cta-band.js') ?>" defer></script>
</body>
</html>
