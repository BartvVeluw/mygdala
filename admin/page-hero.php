<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageHeroContent;
use App\Repository\PageHeroRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$slug = (string) ($_GET['slug'] ?? '');

// Known keys (PageHeroContent::PAGES) are the originally hardcoded pages;
// any other slug is only valid when the page builder has actually attached
// a Page Hero to it (App\Service\SectionRegistry::create()) — never trust an
// arbitrary slug from the query string beyond that.
$isDynamicallyAttached = (new \App\Repository\PageRepository())->findByContentKey($slug) !== null
    && (new PageHeroRepository())->findBySlug($slug) !== null;

if (!array_key_exists($slug, PageHeroContent::PAGES) && !$isDynamicallyAttached) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_pagina'));
}

$pageLabel = PageHeroContent::PAGES[$slug]
    ?? ((new \App\Repository\PageRepository())->findByContentKey($slug)['title'] ?? $slug);

$errors = $_SESSION['admin_page_hero_errors'] ?? [];
$old = $_SESSION['admin_page_hero_old'] ?? null;
unset($_SESSION['admin_page_hero_errors'], $_SESSION['admin_page_hero_old']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $values = $old;
} else {
    try {
        $row = (new PageHeroRepository())->findBySlug($slug);
    } catch (\Throwable $e) {
        error_log('[admin/page-hero.php] ' . $e->getMessage());
        $row = null;
    }

    if ($row !== null) {
        $values = [
            'eyebrow_nl' => (string) $row['eyebrow_nl'],
            'eyebrow_en' => (string) ($row['eyebrow_en'] ?? ''),
            'title_nl' => (string) $row['title_nl'],
            'title_en' => (string) ($row['title_en'] ?? ''),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
            'lead_en' => (string) ($row['lead_en'] ?? ''),
            'breadcrumb_label_nl' => (string) $row['breadcrumb_label_nl'],
            'breadcrumb_label_en' => (string) ($row['breadcrumb_label_en'] ?? ''),
            'is_active' => (bool) $row['is_active'],
        ];
    } else {
        $values = PageHeroContent::defaultsForSlug($slug) + ['is_active' => true];
    }
}

$csrfToken = Csrf::token();

/**
 * @param array<string, mixed> $values
 */
function pageHeroValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('block_pagehero.page_hero_admin', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($slug), ENT_QUOTES, 'UTF-8') ?>"><?= admin_t('block_pagehero.text', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= admin_t('block_pagehero.page_hero', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_pagehero.bovenste_sectie_breadcrumb_eyebrow', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></p>

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

  <section class="admin-card">
    <form method="post" action="/api/admin/update-page-hero.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_pagehero.eyebrow') ?>*
          <input type="text" name="eyebrow_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= pageHeroValue($values, 'eyebrow_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_pagehero.eyebrow_2') ?>
          <input type="text" name="eyebrow_en" maxlength="150" value="<?= pageHeroValue($values, 'eyebrow_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_pagehero.titel_h1') ?>*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= pageHeroValue($values, 'title_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_pagehero.titel_h1_2') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= pageHeroValue($values, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_pagehero.introtekst_lead') ?>
          <textarea name="lead_nl" maxlength="500" rows="3"><?= pageHeroValue($values, 'lead_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_pagehero.introtekst_lead_2') ?>
          <textarea name="lead_en" maxlength="500" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= pageHeroValue($values, 'lead_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_pagehero.leeg_laten_beide_talen') ?></p>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_pagehero.breadcrumb_label') ?>*
          <input type="text" name="breadcrumb_label_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= pageHeroValue($values, 'breadcrumb_label_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_pagehero.breadcrumb_label_2') ?>
          <input type="text" name="breadcrumb_label_en" maxlength="150" value="<?= pageHeroValue($values, 'breadcrumb_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('block_pagehero.actief_uitgevinkt_sectie_getoond') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
