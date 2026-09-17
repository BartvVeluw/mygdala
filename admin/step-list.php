<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\StepListContent;
use App\Repository\StepListRepository;

/**
 * Editor for one step list (?section=<page content_key>:<section_key>): its
 * heading, and its steps one card each.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the heading and every step show the language chosen in the CMS shell, as
 * stored and without the default language's words in an empty translation,
 * and are required only in the default language; each save writes that
 * language only, for that section or that one step. A step keeps its id
 * however often it is saved or moved, so the words of the other languages
 * stay with it. A NEW step is written in the default language, like a new
 * page, and translated afterwards on its own card. Input a refused heading
 * save hands back comes back in the language it was typed in, and that form
 * then starts out unsaved in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = StepListContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StepListRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit(admin_t('screen.onbekende_sectie'));
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => \App\Service\PageLocalization::name((int) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['id']),
        'section_label' => \App\Service\SectionRegistry::label('step_list'),
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];

$repository = new StepListRepository();

$stepListSection = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($stepListSection === null) {
    // First time this section is opened in the admin: create the row now,
    // empty and active exactly as StepListBlock::create() does, so steps can
    // be attached to it.
    $repository->upsertSection($pageSlug, $sectionKeyPart, ['is_active' => true]);
    $stepListSection = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$sectionId = (int) $stepListSection['id'];
$items = $repository->findItemsBySectionId($sectionId);

$errors = $_SESSION['admin_step_list_errors'] ?? [];
$old = $_SESSION['admin_step_list_old'] ?? null;
unset($_SESSION['admin_step_list_errors'], $_SESSION['admin_step_list_old']);

$itemErrors = $_SESSION['admin_step_list_item_errors'] ?? [];
unset($_SESSION['admin_step_list_item_errors']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();

// The words of the section and of every step, in one query.
BlockLocalization::preloadBlocks(['step_list_sections' => [$sectionId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $stepListSection['is_active'];

/** The heading's words on screen: typed and handed back in this language, else stored in it. */
$sectionWord = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('step_list_sections', $sectionId, $field, $editLanguage);
};

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> <?= admin_te('block_steps.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($pageSlug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_steps.text', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_steps.sectie_wijzigingen_direct_zichtbaar', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></p>

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

  <?php if ($itemErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($itemErrors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= admin_te('block_steps.sectiekop') ?></h2>
    <form method="post" action="/api/admin/update-step-list-section.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_steps.eyebrow') ?><?= $marker ?>
          <input type="text" name="eyebrow" maxlength="150"<?= $required ?> value="<?= $h($sectionWord('eyebrow')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_steps.titel_h2') ?><?= $marker ?>
          <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($sectionWord('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_steps.actief_uitgevinkt_hele_sectie') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_steps.stappen') ?></h2>

    <?php if ($items === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_steps.stappen_sectie') ?></p>
    <?php endif; ?>

    <?php foreach ($items as $index => $item): ?>
      <?php
        $itemId = (int) $item['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($items) - 1;
        $itemWord = static fn (string $field): string => BlockLocalization::raw('step_list_items', $itemId, $field, $editLanguage);
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <p class="admin-text-muted"><?= admin_t('block_steps.stap', ['v1' => $index + 1]) ?></p>
        <form method="post" action="/api/admin/update-step-list-item.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">
          <?= admin_localized_input($editLanguage) ?>

          <?php admin_localized_bar($editLanguage); ?>
          <div class="admin-form-row">
            <label><?= admin_te('common.title') ?><?= $marker ?>
              <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($itemWord('title')) ?>"<?= $placeholder ?>>
            </label>
          </div>

          <div class="admin-form-row">
            <label><?= admin_te('block_steps.omschrijving') ?><?= $marker ?>
              <textarea name="body" maxlength="1000" rows="3"<?= $required ?><?= $placeholder ?>><?= $h($itemWord('body')) ?></textarea>
            </label>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= (int) $item['is_active'] === 1 ? 'checked' : '' ?>>
            <?= admin_te('common.visible') ?>
          </label>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-step-list-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-step-list-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-step-list-item.php" class="admin-inline-form" onsubmit="return confirm('Deze stap definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_steps.nieuwe_stap_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-step-list-item.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">

      <?php admin_localized_bar($defaultLanguage); ?>
      <?php admin_localized_new_item_note($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title" maxlength="255" required>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_steps.omschrijving') ?>*
          <textarea name="body" maxlength="1000" rows="3" required></textarea>
        </label>
      </div>

      <button type="submit"><?= admin_te('block_steps.stap_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
