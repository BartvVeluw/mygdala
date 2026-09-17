<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\StatStripContent;
use App\Repository\StatStripRepository;

/**
 * Editor for one Stat strip (?section=<page content_key>:<section_key>): its
 * visibility, and its stats one card each.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * every stat shows the language chosen in the CMS shell, as stored and
 * without the default language's words in an empty translation, and is
 * required only in the default language; each save writes that language
 * only, for that one stat. A stat keeps its id however often it is saved or
 * moved, so the words of the other languages stay with it. A NEW stat is
 * written in the default language, like a new page, and translated
 * afterwards on its own card. The strip's visibility is the same in every
 * language and has no words.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = StatStripContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StatStripRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit(admin_t('screen.onbekende_sectie'));
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => \App\Service\PageLocalization::name((int) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['id']),
        'section_label' => \App\Service\SectionRegistry::label('stat_strip'),
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];

$repository = new StatStripRepository();

$strip = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($strip === null) {
    // First time this section is opened in the admin: create the row now
    // so stats can be attached to it.
    $repository->upsertStrip($pageSlug, $sectionKeyPart, ['is_active' => true]);
    $strip = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$stripId = (int) $strip['id'];
$items = $repository->findItemsByStripId($stripId);

$errors = $_SESSION['admin_stat_strip_errors'] ?? [];
unset($_SESSION['admin_stat_strip_errors']);

$itemErrors = $_SESSION['admin_stat_strip_item_errors'] ?? [];
unset($_SESSION['admin_stat_strip_item_errors']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();

// The words of every stat in the strip, in one query.
BlockLocalization::preloadBlocks(['stat_strips' => [$stripId]]);

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
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> <?= admin_te('block_stats.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($pageSlug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_stats.text', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_stats.sectie_wijzigingen_direct_zichtbaar', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></p>

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
    <h2><?= admin_te('block_stats.zichtbaarheid') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_stats.sectie_heeft_eigen_titel') ?></p>
    <form method="post" action="/api/admin/update-stat-strip.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= (bool) $strip['is_active'] ? 'checked' : '' ?>>
        <?= admin_te('block_stats.actief_uitgevinkt_sectie_alle') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_stats.stats') ?></h2>

    <?php if ($items === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_stats.stats_sectie') ?></p>
    <?php endif; ?>

    <?php foreach ($items as $index => $item): ?>
      <?php
        $itemId = (int) $item['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($items) - 1;
        $itemWord = static fn (string $field): string => BlockLocalization::raw('stat_strip_items', $itemId, $field, $editLanguage);
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-stat-strip-item.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">
          <?= admin_localized_input($editLanguage) ?>

          <?php admin_localized_bar($editLanguage); ?>
          <div class="admin-form-row">
            <label><?= admin_te('block_stats.primaire_tekst') ?><?= $marker ?>
              <input type="text" name="primary_text" maxlength="100"<?= $required ?> value="<?= $h($itemWord('primary_text')) ?>"<?= $placeholder ?>>
            </label>
          </div>

          <div class="admin-form-row">
            <label><?= admin_te('block_stats.secundaire_tekst') ?><?= $marker ?>
              <input type="text" name="secondary_text" maxlength="150"<?= $required ?> value="<?= $h($itemWord('secondary_text')) ?>"<?= $placeholder ?>>
            </label>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= (int) $item['is_active'] === 1 ? 'checked' : '' ?>>
            <?= admin_te('common.visible') ?>
          </label>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-stat-strip-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-stat-strip-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-stat-strip-item.php" class="admin-inline-form" onsubmit="return confirm('Deze stat definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_stats.nieuwe_stat_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-stat-strip-item.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="strip_id" value="<?= $stripId ?>">

      <?php admin_localized_bar($defaultLanguage); ?>
      <?php admin_localized_new_item_note($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_stats.primaire_tekst') ?>*
          <input type="text" name="primary_text" maxlength="100" required>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_stats.secundaire_tekst') ?>*
          <input type="text" name="secondary_text" maxlength="150" required>
        </label>
      </div>

      <button type="submit"><?= admin_te('block_stats.stat_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
