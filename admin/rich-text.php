<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require __DIR__ . '/_richtext_field.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';
require_once __DIR__ . '/_link_target_field.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\RichTextContent;
use App\Service\Routing\LinkChoice;
use App\Service\SectionRegistry;
use App\Repository\PageRepository;
use App\Repository\RichTextRepository;

/**
 * Editor for one Rich text page-builder section
 * (?section=<page content_key>:<section_key>) — the same
 * `<page>:<key>` addressing and the same "valid only when the page and its
 * content row really exist" gate as the other repeater section editors
 * (admin/faq.php, admin/feature-grid.php, ...), and the same shared Quill
 * field (admin/_richtext_field.php) every other rich-text field in this
 * project uses.
 *
 * This is where the body of the three migrated information pages is now
 * edited. It is deliberately the ONLY rich-text page-content editor: the old
 * admin/information-page.php combined page settings and body in one
 * bespoke screen, which is exactly the "second page-content editor" the
 * unified page model removes — settings now live on admin/page.php, body
 * content is a section like any other.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the body field shows the language chosen in the CMS shell, as stored,
 * without the default language's words in an empty translation; the save
 * writes that language only. "Actief" is the same in every language.
 * Input a refused save hands back is shown again only in the language it was
 * typed in, and the form then starts out unsaved in the save bar.
 *
 * ALIGNMENT, WIDTH AND BUTTON. The alignment (RichTextContent::ALIGNMENTS),
 * the width (RichTextContent::WIDTHS: the narrow reading column or the site's
 * normal content width) and where
 * the optional button goes are the same in every language; the button's label
 * is a word of the language on screen. The destination is the shared field
 * every block button uses (admin/_link_target_field.php, LinkChoice), and its
 * label hides with "Geen knop" (admin/assets/navigation-item.js).
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new RichTextRepository();

if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$page = (new PageRepository())->findByContentKey($pageSlug);
$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$sectionId = (int) $section['id'];
$editLanguage = admin_localized_language();

$errors = $_SESSION['admin_rich_text_errors'] ?? [];
$fieldErrors = $_SESSION['admin_rich_text_field_errors'] ?? [];
$old = $_SESSION['admin_rich_text_old'] ?? null;
unset($_SESSION['admin_rich_text_errors'], $_SESSION['admin_rich_text_field_errors'], $_SESSION['admin_rich_text_old']);

$saved = isset($_GET['saved']);

$body = is_array($old) && ($old['language_code'] ?? null) === $editLanguage
    ? (string) ($old[RichTextContent::BODY] ?? '')
    : BlockLocalization::raw('rich_text_sections', $sectionId, RichTextContent::BODY, $editLanguage);
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $section['is_active'];
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$buttonLabel = $oldInThisLanguage
    ? (string) ($old[RichTextContent::BUTTON_LABEL] ?? '')
    : BlockLocalization::raw('rich_text_sections', $sectionId, RichTextContent::BUTTON_LABEL, $editLanguage);
$align = is_array($old) ? (string) ($old['text_align'] ?? '') : (string) ($section['text_align'] ?? '');
$align = array_key_exists($align, RichTextContent::ALIGNMENTS) ? $align : (string) array_key_first(RichTextContent::ALIGNMENTS);
$width = RichTextContent::width(is_array($old) ? (string) ($old['content_width'] ?? '') : (string) ($section['content_width'] ?? ''));

// The button's destination on screen: as handed back, else as stored.
$buttonStoredType = LinkChoice::storedType($section['button_link_type'] ?? null, (string) ($section['button_url'] ?? ''));
$buttonTargets = is_array($old) ? (array) ($old['button_link_target'] ?? []) : [];
if (!is_array($old) && !in_array($buttonStoredType, [LinkChoice::NONE, LinkChoice::URL], true)) {
    $buttonTargets[$buttonStoredType] = (int) ($section['button_link_target_id'] ?? 0);
}
$buttonType = is_array($old) ? (string) ($old['button_link_type'] ?? '') : $buttonStoredType;
$buttonUrl = is_array($old) ? (string) ($old['button_url'] ?? '') : (string) ($section['button_url'] ?? '');

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('block_richtext.tekstblok_admin', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_richtext.terug', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('rich_text')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_richtext.sectie_pagina', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>
  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/update-rich-text-section.php" class="admin-product-form" data-nav-item-form<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
    <?= admin_localized_input($editLanguage) ?>

    <section class="admin-card">
      <h2><?= admin_te('block_richtext.inhoud') ?></h2>
      <?php admin_localized_bar($editLanguage); ?>
      <?php renderRichTextField(RichTextContent::BODY, 'Tekst', $body, 'full', 'admin-richtext-editor--lg'); ?>
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_richtext.actief_zichtbaar_pagina') ?>
      </label>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('block_richtext.weergave') ?></h2>
      <div class="admin-form-row">
        <span class="admin-form-row__label" id="rich-text-align-label"><?= admin_te('block_richtext.uitlijning') ?> <?= admin_help(admin_t('block_richtext.uitlijning'), admin_t('help.block_richtext.uitlijning')) ?></span>
        <div class="admin-segmented" role="radiogroup" aria-labelledby="rich-text-align-label">
          <?php foreach (array_keys(RichTextContent::ALIGNMENTS) as $value): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="text_align" value="<?= $h($value) ?>"<?= $align === $value ? ' checked' : '' ?>>
              <span><?= admin_te('block_richtext.uitlijning_' . $value) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="admin-form-row">
        <span class="admin-form-row__label" id="rich-text-width-label"><?= admin_te('block_richtext.breedte') ?> <?= admin_help(admin_t('block_richtext.breedte'), admin_t('help.block_richtext.breedte')) ?></span>
        <div class="admin-segmented" role="radiogroup" aria-labelledby="rich-text-width-label"<?= editor_field_invalid($fieldErrors, 'content_width') ?>>
          <?php foreach (array_keys(RichTextContent::WIDTHS) as $value): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="content_width" value="<?= $h($value) ?>"<?= $width === $value ? ' checked' : '' ?>>
              <span><?= admin_te('block_richtext.breedte_' . $value) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php editor_field_error($fieldErrors, 'content_width'); ?>
      </div>

      <div data-nav-link-group>
        <h3><?= admin_te('block_richtext.knop_optioneel') ?></h3>
        <?php link_target_field([
            'id' => 'rich-text-button-link',
            'type_name' => 'button_link_type',
            'target_name' => 'button_link_target',
            'url_name' => 'button_url',
            'type' => $buttonType,
            'targets' => $buttonTargets,
            'url' => $buttonUrl,
            'stored_type' => $buttonStoredType,
            'invalid' => editor_field_invalid($fieldErrors, 'button_url'),
            'error' => static fn () => editor_field_error($fieldErrors, 'button_url'),
        ]); ?>
        <div class="admin-field" data-nav-link-field="<?= $h(link_target_shown_kinds($buttonStoredType)) ?>">
          <?= admin_field_label('rich-text-button-label', admin_t('block_richtext.knoptekst')) ?>
          <input type="text" id="rich-text-button-label" name="<?= $h(RichTextContent::BUTTON_LABEL) ?>" maxlength="150" value="<?= $h($buttonLabel) ?>"<?= admin_localized_placeholder_attr($editLanguage) ?><?= editor_field_invalid($fieldErrors, RichTextContent::BUTTON_LABEL) ?>>
          <?php editor_field_error($fieldErrors, RichTextContent::BUTTON_LABEL); ?>
        </div>
      </div>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php save_bar(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/navigation-item.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
