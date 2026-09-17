<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\FeatureGridContent;
use App\Repository\FeatureGridRepository;

/**
 * Editor for one Feature grid (?section=<page content_key>:<section_key>):
 * its heading, when it has one, and its cards one card each.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the heading and every card's title and text show the language chosen in
 * the CMS shell, as stored and without the default language's words in an
 * empty translation, and are required only in the default language; each
 * save writes that language only, for that grid or that one card. A card's
 * icon and visibility are the same in every language and stay on screen in
 * each. A card keeps its id however often it is saved or moved, so the words
 * of the other languages stay with it. A NEW card is written in the default
 * language, like a new page, and translated afterwards on its own card.
 * Input a refused heading save hands back comes back in the language it was
 * typed in, and that form then starts out unsaved in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = FeatureGridContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new FeatureGridRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit(admin_t('screen.onbekende_sectie'));
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => \App\Service\PageLocalization::name((int) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['id']),
        'section_label' => \App\Service\SectionRegistry::label('feature_grid'),
        'has_heading' => true,
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];
$hasHeading = $section['has_heading'];

$repository = new FeatureGridRepository();

$grid = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($grid === null) {
    // First time this section is opened in the admin: create the row now,
    // empty and active exactly as FeatureGridBlock::create() does, so cards
    // can be attached to it.
    $repository->upsertGrid($pageSlug, $sectionKeyPart, ['is_active' => true]);
    $grid = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$gridId = (int) $grid['id'];
$items = $repository->findItemsByGridId($gridId);

$errors = $_SESSION['admin_feature_grid_errors'] ?? [];
$old = $_SESSION['admin_feature_grid_old'] ?? null;
unset($_SESSION['admin_feature_grid_errors'], $_SESSION['admin_feature_grid_old']);

$itemErrors = $_SESSION['admin_feature_grid_item_errors'] ?? [];
unset($_SESSION['admin_feature_grid_item_errors']);

$saved = isset($_GET['saved']);

$editLanguage = admin_localized_language();
$defaultLanguage = admin_localized_default();

// The words of the grid and of every card, in one query.
BlockLocalization::preloadBlocks(['feature_grids' => [$gridId]]);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $grid['is_active'];

/** The heading's words on screen: typed and handed back in this language, else stored in it. */
$sectionWord = static function (string $field) use ($old, $oldInThisLanguage, $gridId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('feature_grids', $gridId, $field, $editLanguage);
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
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> <?= admin_te('block_features.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($pageSlug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_features.text', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_features.sectie_wijzigingen_direct_zichtbaar', ['v1' => htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8')]) ?></p>

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

  <?php if ($hasHeading): ?>
  <section class="admin-card">
    <h2><?= admin_te('block_features.sectiekop') ?></h2>
    <form method="post" action="/api/admin/update-feature-grid.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_features.eyebrow') ?><?= $marker ?>
          <input type="text" name="eyebrow" maxlength="150"<?= $required ?> value="<?= $h($sectionWord('eyebrow')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_features.titel_h2') ?><?= $marker ?>
          <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($sectionWord('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_features.introtekst_lead') ?>
          <textarea name="lead" maxlength="500" rows="3"<?= $placeholder ?>><?= $h($sectionWord('lead')) ?></textarea>
        </label>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_features.actief_uitgevinkt_hele_sectie') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php else: ?>
  <section class="admin-card">
    <h2><?= admin_te('block_features.zichtbaarheid') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_features.sectie_heeft_eigen_titel') ?></p>
    <form method="post" action="/api/admin/update-feature-grid.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_features.actief_uitgevinkt_sectie_alle') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= admin_te('block_features.kaarten') ?></h2>

    <?php if ($items === []): ?>
      <p class="admin-text-muted"><?= admin_te('block_features.kaarten_sectie') ?></p>
    <?php endif; ?>

    <?php foreach ($items as $index => $item): ?>
      <?php
        $itemId = (int) $item['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($items) - 1;
        $itemWord = static fn (string $field): string => BlockLocalization::raw('feature_grid_items', $itemId, $field, $editLanguage);
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-feature-grid-item.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">
          <?= admin_localized_input($editLanguage) ?>

          <div class="admin-form-row">
            <label><?= admin_te('block_features.icoon') ?>
              <select name="icon_key">
                <?php foreach (FeatureGridContent::ICON_KEYS as $key => $label): ?>
                  <option value="<?= $h($key) ?>" <?= $item['icon_key'] === $key ? 'selected' : '' ?>><?= $h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <?php admin_localized_bar($editLanguage); ?>
          <div class="admin-form-row">
            <label><?= admin_te('common.title') ?><?= $marker ?>
              <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($itemWord('title')) ?>"<?= $placeholder ?>>
            </label>
          </div>

          <div class="admin-form-row">
            <label><?= admin_te('block_features.tekst') ?><?= $marker ?>
              <textarea name="body" maxlength="500" rows="3"<?= $required ?><?= $placeholder ?>><?= $h($itemWord('body')) ?></textarea>
            </label>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= (int) $item['is_active'] === 1 ? 'checked' : '' ?>>
            <?= admin_te('common.visible') ?>
          </label>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-feature-grid-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-feature-grid-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-feature-grid-item.php" class="admin-inline-form" onsubmit="return confirm('Deze kaart definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('block_features.nieuwe_kaart_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-feature-grid-item.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="grid_id" value="<?= $gridId ?>">

      <div class="admin-form-row">
        <label><?= admin_te('block_features.icoon_2') ?>
          <select name="icon_key">
            <?php foreach (FeatureGridContent::ICON_KEYS as $key => $label): ?>
              <option value="<?= $h($key) ?>"><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <?php admin_localized_bar($defaultLanguage); ?>
      <?php admin_localized_new_item_note($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title" maxlength="255" required>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_features.tekst') ?>*
          <textarea name="body" maxlength="500" rows="3" required></textarea>
        </label>
      </div>

      <button type="submit"><?= admin_te('block_features.kaart_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
