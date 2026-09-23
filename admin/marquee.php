<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_editor_rows.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Repository\PageRepository;
use App\Repository\MarqueeRepository;

/**
 * Editor for one Marquee page-builder instance
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/rich-text.php,
 * admin/faq.php, ...). There is no hardcoded list of known marquees: any
 * instance the page builder created is edited here, and no other pair is
 * ever trusted.
 *
 * ONE FORM, ONE SAVE (PAGE-EDITOR.md, "Eén formulier per blok-editor"). The
 * switch and every item — its text, whether it is shown, its place, a
 * removal mark, new items — post to api/admin/update-marquee-section.php
 * together, and its one "Opslaan" (or the save bar) stores all of it. ↑, ↓
 * and "Item toevoegen" work on screen (admin/assets/row-list.js); without
 * JavaScript ↑ and ↓ submit the whole form and one empty item waits at the
 * end of the list (App\Service\Blocks\EditorChildList, admin/_editor_rows.php).
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * every item shows the language chosen in the CMS shell, as stored and
 * without the default language's words in an empty translation, and is
 * required only in the default language; a save writes that language only.
 * An item keeps its id however often it is saved or moved, so the words of
 * the other languages stay with it. A NEW item is written in the default
 * language, like a new page. The section's visibility is the same in every
 * language and has no words. Input a refused save hands back comes back as
 * it was typed, with each message next to its field, and the form then
 * starts out unsaved in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKeyPart] = array_pad(explode(':', $sectionKey, 2), 2, null);

$repository = new MarqueeRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKeyPart === null || $sectionKeyPart === ''
    || $repository->findBySlugAndKey($pageSlug, $sectionKeyPart) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$section = [
    'page_slug' => $pageSlug,
    'section_key' => $sectionKeyPart,
    'page_label' => \App\Service\PageLocalization::name((int) $page['id']),
    'section_label' => SectionRegistry::label('marquee'),
];

$marqueeSection = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);

$sectionId = (int) $marqueeSection['id'];
$items = $repository->findItemsBySectionId($sectionId);

$errors = $_SESSION['admin_marquee_errors'] ?? [];
$fieldErrors = $_SESSION['admin_marquee_field_errors'] ?? [];
$old = $_SESSION['admin_marquee_old'] ?? null;
unset($_SESSION['admin_marquee_errors'], $_SESSION['admin_marquee_field_errors'], $_SESSION['admin_marquee_old']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();

// The words of every item in the section, in one query.
BlockLocalization::preloadBlocks(['marquee_sections' => [$sectionId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $marqueeSection['is_active'];

// The items on screen: as a refused save handed them back, else as stored.
$rows = editor_rows_on_screen(
    $items,
    $oldInThisLanguage ? (array) ($old['items'] ?? []) : null,
    static fn (array $item): array => [
        'label' => BlockLocalization::raw('marquee_items', (int) $item['id'], 'label', $editLanguage),
        'active' => (int) $item['is_active'] === 1 ? '1' : '',
    ]
);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$marker = admin_localized_required($editLanguage) !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);

/** One item; the template for a new one is the same markup with the key __KEY__. */
$itemRow = static function (string $key, array $fields, int $position, int $count) use ($marker, $placeholder, $fieldErrors): void {
    [$star, $hint] = editor_row_word_hints($key, $marker, $placeholder);
    editor_row_open('items', $key, admin_t('block_marquee.item'), $position, $count, ($fields['remove'] ?? '') !== '');
    editor_row_text('items', $key, 'label', admin_t('block_marquee.tekst') . $star, 100, $fields, $fieldErrors, $hint);
    editor_row_switch('items', $key, $fields, admin_t('common.visible'));
    editor_row_close();
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> <?= admin_te('block_marquee.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_marquee.text', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_marquee.sectie_wijzigingen_direct_zichtbaar', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></p>

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

  <form method="post" action="/api/admin/update-marquee-section.php" class="admin-product-form" data-save-name="<?= $h($section['section_label']) ?>"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <?php /* Enter in a text field presses the FIRST submit button of a form.
             This one is a plain save, so Enter never moves an item. */ ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= admin_te('common.save') ?></button>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">
    <?= admin_localized_input($editLanguage) ?>

    <section class="admin-card">
      <h2><?= admin_te('block_marquee.zichtbaarheid') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_marquee.sectie_heeft_eigen_titel') ?></p>
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_marquee.actief_uitgevinkt_sectie_alle') ?>
      </label>
    </section>

    <section class="admin-card" aria-labelledby="marquee-items-title">
      <h2 id="marquee-items-title"><?= admin_te('block_marquee.items') ?></h2>
      <?php admin_localized_bar($editLanguage); ?>

      <?php if ($rows === []): ?>
        <p class="admin-text-muted"><?= admin_te('block_marquee.items_sectie') ?></p>
      <?php endif; ?>

      <input type="hidden" name="items_present" value="1">
      <div class="admin-row-cards" data-row-list="marquee-items">
        <?php foreach ($rows as $position => $row): ?>
          <?php $itemRow($row['key'], $row['fields'], $position, count($rows)); ?>
        <?php endforeach; ?>
        <noscript>
          <?php $itemRow(editor_rows_free_key($rows), ['active' => '1'], count($rows), count($rows) + 1); ?>
        </noscript>
      </div>
      <?php editor_rows_status('marquee-items'); ?>
      <?php editor_rows_add('marquee-items', admin_t('block_marquee.item_toevoegen'), $editLanguage); ?>
      <template data-row-list-template="marquee-items"><?php $itemRow('__KEY__', ['active' => '1'], 0, 1); ?></template>
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
