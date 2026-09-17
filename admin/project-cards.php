<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ItemGalleryContent;
use App\Service\SectionRegistry;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;

/**
 * Editor for one "Projecten" block (?section=<page content_key>:<section_key>):
 * App\Service\Blocks\ProjectCardsBlock, the Portfolio's projects on an
 * ordinary page.
 *
 * admin/item-gallery.php with everything taken out that a project does not
 * use. The source is always the Portfolio's, so there is nothing to choose,
 * and a collection, zoom, a link for cards without a page of their own, a
 * closing text and a button are not on this screen at all
 * (ProjectCardsBlock::rowValues() stores them fixed). What is left: which
 * projects, how many, the filter buttons, the background, an optional title
 * and introduction, and whether the block shows.
 *
 * The projects are not edited here, and neither is where a card links to:
 * that is each project's own page, chosen in admin/portfolio-item.php.
 *
 * Valid only while the Portfolio runs, because a switched-off module's block
 * is not even registered and the settings it keeps are nobody's to change
 * until it is back. And only for a row this block placed, on a page that
 * exists: the gallery keeps its rows in the same table, and those are
 * admin/item-gallery.php's.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

if (!SectionRegistry::exists('project_cards')) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);
$section = ($page === null || $sectionKey === null || $sectionKey === '')
    ? null
    : (new ItemGalleryRepository())->findBySlugAndKey($pageSlug, $sectionKey);

if ($section === null
    || (new PageSectionRepository())->findBySectionTypeAndId('project_cards', (int) $section['id']) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$errors = $_SESSION['admin_project_cards_errors'] ?? [];
$old = $_SESSION['admin_project_cards_old'] ?? null;
unset($_SESSION['admin_project_cards_errors'], $_SESSION['admin_project_cards_old']);

$saved = isset($_GET['saved']);

$values = $old ?? [
    'portfolio_scope' => (string) $section['portfolio_scope'],
    'max_items' => $section['max_items'] === null ? '' : (string) $section['max_items'],
    'show_filter_bar' => (bool) $section['show_filter_bar'],
    'background' => (string) $section['background'],
    'title_nl' => (string) ($section['title_nl'] ?? ''),
    'title_en' => (string) ($section['title_en'] ?? ''),
    'lead_nl' => (string) ($section['lead_nl'] ?? ''),
    'lead_en' => (string) ($section['lead_en'] ?? ''),
    'is_active' => (bool) $section['is_active'],
];

// The choices come from the gallery's own closed lists, the ones the endpoint
// validates against; only the words are this screen's.
$scopeLabels = [
    ItemGalleryContent::SCOPE_ALL => admin_t('block_projects.scope_all'),
    ItemGalleryContent::SCOPE_FEATURED => admin_t('block_projects.scope_featured'),
];
$backgroundLabels = [
    'default' => admin_t('block_projects.background_default'),
    'soft' => admin_t('block_projects.background_soft'),
];

// How many projects this block would show right now, so an empty choice shows
// up here rather than as a section that is missing from the public page.
$projectCount = count(ItemGalleryContent::mapRow($section)['items']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('project_cards')) ?> <?= admin_t('block_projects.admin', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_projects.back', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('project_cards')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_projects.intro', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>

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

  <p class="admin-text-muted"><?= admin_t('block_projects.count', ['v1' => (int) $projectCount]) ?></p>
  <?php if ($projectCount === 0): ?>
  <p class="admin-alert admin-alert--warning"><?= admin_te('block_projects.none') ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-project-cards.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <h2><?= admin_te('block_projects.which') ?></h2>
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('block_projects.show') ?>
          <select name="portfolio_scope">
            <?php foreach (ItemGalleryContent::PORTFOLIO_SCOPES as $scopeKey => $scope): ?>
            <option value="<?= $h($scopeKey) ?>" <?= ($values['portfolio_scope'] ?? '') === $scopeKey ? 'selected' : '' ?>><?= $h($scopeLabels[$scopeKey] ?? $scope['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= admin_te('block_projects.max') ?>
          <input type="number" name="max_items" min="1" max="200" value="<?= $h((string) ($values['max_items'] ?? '')) ?>" placeholder="<?= admin_te('block_projects.max_placeholder') ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_projects.order') ?></p>

      <h2 style="margin-top:2rem;"><?= admin_te('block_projects.display') ?></h2>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="show_filter_bar" value="1" <?= ($values['show_filter_bar'] ?? false) ? 'checked' : '' ?>>
        <?= admin_te('block_projects.filter_bar') ?>
      </label>
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('block_projects.background') ?>
          <select name="background">
            <?php foreach (ItemGalleryContent::BACKGROUNDS as $backgroundKey => $background): ?>
            <option value="<?= $h($backgroundKey) ?>" <?= ($values['background'] ?? '') === $backgroundKey ? 'selected' : '' ?>><?= $h($backgroundLabels[$backgroundKey] ?? $background['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <h2 style="margin-top:2rem;"><?= admin_te('block_projects.heading') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_projects.heading_hint') ?></p>
      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title_nl" maxlength="255" value="<?= $h((string) ($values['title_nl'] ?? '')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= $h((string) ($values['title_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_projects.intro_text') ?>
          <textarea name="lead_nl" maxlength="600" rows="3"><?= $h((string) ($values['lead_nl'] ?? '')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_projects.intro_text') ?>
          <textarea name="lead_en" maxlength="600" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= $h((string) ($values['lead_en'] ?? '')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('block_projects.active') ?>
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
