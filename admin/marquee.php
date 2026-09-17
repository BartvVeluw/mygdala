<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

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
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * every item shows the language chosen in the CMS shell, as stored and
 * without the default language's words in an empty translation, and is
 * required only in the default language; each save writes that language
 * only, for that one item. An item keeps its id however often it is saved or
 * moved, so the words of the other languages stay with it. A NEW item is
 * written in the default language, like a new page, and translated
 * afterwards on its own card. The section's visibility is the same in every
 * language and has no words.
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
unset($_SESSION['admin_marquee_errors']);

$itemErrors = $_SESSION['admin_marquee_item_errors'] ?? [];
unset($_SESSION['admin_marquee_item_errors']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();

// The words of every item in the section, in one query.
BlockLocalization::preloadBlocks(['marquee_sections' => [$sectionId]]);

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
    <h2><?= admin_te('block_marquee.zichtbaarheid') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_marquee.sectie_heeft_eigen_titel') ?></p>
    <form method="post" action="/api/admin/update-marquee-section.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= (bool) $marqueeSection['is_active'] ? 'checked' : '' ?>>
        <?= admin_te('block_marquee.actief_uitgevinkt_sectie_alle') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_marquee.items') ?></h2>

    <?php if ($items === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_marquee.items_sectie') ?></p>
    <?php endif; ?>

    <?php foreach ($items as $index => $item): ?>
      <?php
        $itemId = (int) $item['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($items) - 1;
        $itemWord = static fn (string $field): string => BlockLocalization::raw('marquee_items', $itemId, $field, $editLanguage);
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-marquee-item.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">
          <?= admin_localized_input($editLanguage) ?>

          <?php admin_localized_bar($editLanguage); ?>
          <div class="admin-form-row">
            <label><?= admin_te('block_marquee.tekst') ?><?= $marker ?>
              <input type="text" name="label" maxlength="100"<?= $required ?> value="<?= $h($itemWord('label')) ?>"<?= $placeholder ?>>
            </label>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= (int) $item['is_active'] === 1 ? 'checked' : '' ?>>
            <?= admin_te('common.visible') ?>
          </label>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-marquee-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-marquee-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-marquee-item.php" class="admin-inline-form" onsubmit="return confirm('Dit item definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_marquee.nieuw_item_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-marquee-item.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">

      <?php admin_localized_bar($defaultLanguage); ?>
      <?php admin_localized_new_item_note($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_marquee.tekst') ?>*
          <input type="text" name="label" maxlength="100" required>
        </label>
      </div>

      <button type="submit"><?= admin_te('block_marquee.item_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
