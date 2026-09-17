<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\FeatureGridContent;
use App\Repository\FeatureGridRepository;

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

if ($old !== null) {
    $sectionValues = $old;
} else {
    $sectionValues = [
        'eyebrow_nl' => (string) ($grid['eyebrow_nl'] ?? ''),
        'eyebrow_en' => (string) ($grid['eyebrow_en'] ?? ''),
        'title_nl' => (string) ($grid['title_nl'] ?? ''),
        'title_en' => (string) ($grid['title_en'] ?? ''),
        'lead_nl' => (string) ($grid['lead_nl'] ?? ''),
        'lead_en' => (string) ($grid['lead_en'] ?? ''),
        'is_active' => (bool) $grid['is_active'],
    ];
}

$csrfToken = Csrf::token();

/**
 * @param array<string, mixed> $values
 */
function featureGridValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
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
    <form method="post" action="/api/admin/update-feature-grid.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section" value="<?= htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8') ?>">

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_features.eyebrow') ?>*
          <input type="text" name="eyebrow_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= featureGridValue($sectionValues, 'eyebrow_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_features.eyebrow_2') ?>
          <input type="text" name="eyebrow_en" maxlength="150" value="<?= featureGridValue($sectionValues, 'eyebrow_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_features.titel_h2') ?>*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= featureGridValue($sectionValues, 'title_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_features.titel_h2_2') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= featureGridValue($sectionValues, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_features.introtekst_lead') ?>
          <textarea name="lead_nl" maxlength="500" rows="3"><?= featureGridValue($sectionValues, 'lead_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_features.introtekst_lead_2') ?>
          <textarea name="lead_en" maxlength="500" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= featureGridValue($sectionValues, 'lead_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($sectionValues['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('block_features.actief_uitgevinkt_hele_sectie') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php else: ?>
  <section class="admin-card">
    <h2><?= admin_te('block_features.zichtbaarheid') ?></h2>
    <p class="admin-text-muted"><?= admin_te('block_features.sectie_heeft_eigen_titel') ?></p>
    <form method="post" action="/api/admin/update-feature-grid.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section" value="<?= htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8') ?>">

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($sectionValues['is_active'] ?? true) ? 'checked' : '' ?>>
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
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-feature-grid-item.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">

          <div class="admin-form-row">
            <label><?= admin_te('block_features.icoon') ?>
              <select name="icon_key">
                <?php foreach (FeatureGridContent::ICON_KEYS as $key => $label): ?>
                  <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $item['icon_key'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <?php admin_lang_bar(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label><?= admin_te('common.title') ?>*
              <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= htmlspecialchars((string) $item['title_nl'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label><?= admin_te('common.title') ?>
              <input type="text" name="title_en" maxlength="255" value="<?= htmlspecialchars((string) ($item['title_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label><?= admin_te('block_features.tekst') ?>*
              <textarea name="body_nl" maxlength="500" rows="3" <?= admin_lang_required('nl') ?>><?= htmlspecialchars((string) $item['body_nl'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label><?= admin_te('block_features.tekst_2') ?>
              <textarea name="body_en" maxlength="500" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= htmlspecialchars((string) ($item['body_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= (int) $item['is_active'] === 1 ? 'checked' : '' ?>>
            <?= admin_te('common.visible') ?>
          </label>

          <button type="submit"><?= admin_te('common.save') ?></button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-feature-grid-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-feature-grid-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-feature-grid-item.php" class="admin-inline-form" onsubmit="return confirm('Deze kaart definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
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
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="grid_id" value="<?= $gridId ?>">

      <div class="admin-form-row">
        <label><?= admin_te('block_features.icoon_2') ?>
          <select name="icon_key">
            <?php foreach (FeatureGridContent::ICON_KEYS as $key => $label): ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title_en" maxlength="255"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_features.tekst_3') ?>*
          <textarea name="body_nl" maxlength="500" rows="3" <?= admin_lang_required('nl') ?>></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_features.tekst_4') ?>
          <textarea name="body_en" maxlength="500" rows="3"<?= admin_lang_placeholder_attr('en') ?>></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit"><?= admin_te('block_features.kaart_toevoegen') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
