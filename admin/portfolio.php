<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('portfolio.manage');

$repository = new PortfolioGalleryRepository();
$categoryRepository = new PortfolioCategoryRepository();

// One catalogue row holds every portfolio item; it is created on first use.
// Where those items are SHOWN is not decided here any more — that is the
// Portfolio-/collectiegalerij block's job (admin/item-gallery.php), which is
// also where the section's visibility, filter bar and lightbox now live.
$gallery = $repository->ensureCatalogue();
$galleryId = (int) $gallery['id'];
$items = $repository->findItemsByGalleryId($galleryId);
$featuredItems = $repository->findFeaturedItemsByGalleryId($galleryId);
$categoriesWithCounts = $categoryRepository->findAllWithUsageCounts();
$categorySlugsByItemId = $repository->categorySlugsByItemIds(array_map(
    static fn (array $item): int => (int) $item['id'],
    $items
));

$errors = $_SESSION['admin_portfolio_errors'] ?? [];
unset($_SESSION['admin_portfolio_errors']);

$itemErrors = $_SESSION['admin_portfolio_item_errors'] ?? [];
unset($_SESSION['admin_portfolio_item_errors']);

$categoryErrors = $_SESSION['admin_portfolio_category_errors'] ?? [];
unset($_SESSION['admin_portfolio_category_errors']);

$saved = isset($_GET['saved']);
$created = isset($_GET['created']);
$deleted = isset($_GET['deleted']);
$categorySaved = isset($_GET['category_saved']);

$csrfToken = Csrf::token();

// The current search/filter state lives in the URL (q, cat, vis, detail,
// home) so it survives a reload and can be handed to the item editor's
// "back" link — see assets/portfolio-admin.js, which owns applying these
// once the page has loaded (client-side filtering only, per the approved
// design: ~100 lightweight cards is not enough to need a server round trip).
// PHP only reads them here to preselect the toolbar controls on first paint.
$initialQuery = trim((string) ($_GET['q'] ?? ''));
$initialCategory = (string) ($_GET['cat'] ?? 'all');
$initialVisibility = (string) ($_GET['vis'] ?? 'all');
$initialDetail = (string) ($_GET['detail'] ?? 'all');
$initialHome = (string) ($_GET['home'] ?? 'all');

$backQueryString = http_build_query(array_filter([
    'q' => $initialQuery,
    'cat' => $initialCategory !== 'all' ? $initialCategory : null,
    'vis' => $initialVisibility !== 'all' ? $initialVisibility : null,
    'detail' => $initialDetail !== 'all' ? $initialDetail : null,
    'home' => $initialHome !== 'all' ? $initialHome : null,
], static fn ($v) => $v !== null && $v !== ''));

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * CMS preview src for a Portfolio item: the small (~480px) thumbnail when
 * one exists, else the full image — items created before the Portfolio
 * image-optimization step have no thumbnail_path yet (never backfilled, see
 * db/migrations/20260906090000_add_thumbnail_path_to_portfolio_images.php)
 * and simply keep using the full image here, same as before that step.
 */
$cardImageSrc = static fn (array $row): string => '/' . ltrim((string) ($row['thumbnail_path'] ?: $row['image_path']), '/');

/**
 * What an item is called in the CMS. A title is optional, so an item without
 * one still gets a name on its card, in its link and in the homepage list —
 * never an empty line.
 */
$itemName = static fn (array $row): string => (string) $row['title_nl'] !== '' ? (string) $row['title_nl'] : admin_t('portfolio.untitled');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('portfolio.portfolio_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/portfolio-admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1><?= admin_te('portfolio.portfolio') ?></h1>
    <a href="/admin/portfolio-item.php" class="admin-btn-link"><?= admin_te('portfolio.nieuw_portfolio_item') ?></a>
  </div>
  <?= admin_info_panel(admin_t('help.portfolio.overview')) ?>
  <p class="admin-text-muted"><?= admin_t('portfolio.klik_item_bewerken_sleep') ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>
  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('portfolio.portfolio_item_aangemaakt_vul') ?></p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('portfolio.portfolio_item_verwijderd') ?></p>
  <?php endif; ?>
  <?php if ($categorySaved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('portfolio.categorie_opgeslagen') ?></p>
  <?php endif; ?>
  <?php if ($categoryErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($categoryErrors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
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
  <?php if ($itemErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($itemErrors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($featuredItems !== []): ?>
  <details class="admin-card admin-portfolio-collapsible">
    <summary><?= admin_t('portfolio.homepage_uitlichting', ['v1' => count($featuredItems)]) ?></summary>
    <p class="admin-text-muted"><?= admin_te('portfolio.volgorde_items_greep_uit') ?></p>
    <?php foreach ($featuredItems as $index => $item): ?>
      <?php
        $itemId = (int) $item['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($featuredItems) - 1;
      ?>
      <div class="admin-section-row" style="margin-top:0.6rem;">
        <img src="<?= $h($cardImageSrc($item)) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:var(--admin-radius-sm);">
        <span class="admin-section-row__body"><?= $h($itemName($item)) ?></span>
        <form method="post" action="/api/admin/move-featured-gallery-item.php" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">
          <input type="hidden" name="direction" value="up">
          <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr;</button>
        </form>
        <form method="post" action="/api/admin/move-featured-gallery-item.php" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">
          <input type="hidden" name="direction" value="down">
          <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr;</button>
        </form>
      </div>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>

  <details class="admin-card admin-portfolio-collapsible" open>
    <summary><?= admin_t('portfolio.categories_count', ['count' => count($categoriesWithCounts)]) ?></summary>
    <p class="admin-text-muted"><?= admin_te('portfolio.categorie_n_direct_beschikbaar') ?></p>

    <?php /* ONE indicator for the whole list, not one per row: every category
             is its own little form here, and repeating "Editing: English"
             above each of them would be a column of furniture. */ ?>
    <?php admin_lang_bar(); ?>

    <?php if ($categoriesWithCounts === []): ?>
      <p class="admin-text-muted"><?= admin_te('portfolio.categorie_n') ?></p>
    <?php else: ?>
      <div class="admin-portfolio-category-list">
        <?php foreach ($categoriesWithCounts as $category): ?>
          <?php
            $categoryId = (int) $category['id'];
            $itemCount = (int) $category['item_count'];
          ?>
          <div class="admin-portfolio-category-row">
            <form method="post" action="/api/admin/update-portfolio-category.php" class="admin-portfolio-category-row__form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="category_id" value="<?= $categoryId ?>">
              <?php admin_lang_pane_start('nl'); ?>
              <input type="text" name="name_nl" maxlength="100"<?= admin_lang_required('nl') ?> value="<?= $h((string) $category['name_nl']) ?>" aria-label="Naam">
              <?php admin_lang_pane_end(); ?>
              <?php admin_lang_pane_start('en'); ?>
              <input type="text" name="name_en" maxlength="100" value="<?= $h((string) ($category['name_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?> aria-label="Naam">
              <?php admin_lang_pane_end(); ?>
              <button type="submit" class="admin-btn-text"><?= admin_te('common.save') ?></button>
            </form>
            <span class="admin-text-muted admin-portfolio-category-row__count"><?= $itemCount ?> <?= admin_t('portfolio.project', ['v1' => $itemCount === 1 ? '' : 'en']) ?></span>
            <form method="post" action="/api/admin/delete-portfolio-category.php" class="admin-inline-form"<?= admin_confirm_attributes(
                admin_t('portfolio.categorie_verwijderen_titel'),
                admin_t('portfolio.categorie_verwijderen_uitleg', ['name' => (string) $category['name_nl']]),
                admin_t('common.delete')
            ) ?>>
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="category_id" value="<?= $categoryId ?>">
              <?php if ($itemCount > 0): ?>
                <span class="admin-text-muted" title="Nog in gebruik bij <?= $itemCount ?> portfolio-item(s) — verwijder eerst de toewijzing."><?= admin_te('portfolio.delete_not_applicable') ?></span>
              <?php else: ?>
                <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
              <?php endif; ?>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/api/admin/create-portfolio-category.php" class="admin-portfolio-category-row__form admin-portfolio-category-row__form--new">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <?php admin_lang_pane_start('nl'); ?>
        <input type="text" name="name_nl" maxlength="100"<?= admin_lang_required('nl') ?> placeholder="Nieuwe categorie" aria-label="Naam">
      <?php admin_lang_pane_end(); ?>
      <?php admin_lang_pane_start('en'); ?>
        <input type="text" name="name_en" maxlength="100" placeholder="Nieuwe categorie" aria-label="Naam">
      <?php admin_lang_pane_end(); ?>
      <button type="submit"><?= admin_te('portfolio.nieuwe_categorie') ?></button>
    </form>
  </details>

  <div class="admin-portfolio-toolbar" data-portfolio-toolbar>
    <label class="admin-search admin-portfolio-toolbar__search">
      <span class="admin-visually-hidden"><?= admin_te('portfolio.search_label') ?></span>
      <input type="search" placeholder="<?= admin_te('portfolio.search_placeholder') ?>" data-portfolio-search value="<?= $h($initialQuery) ?>">
    </label>

    <select class="admin-select" aria-label="Filter op categorie" data-portfolio-filter="category">
      <option value="all"><?= admin_te('portfolio.alle_categorie_n') ?></option>
      <?php /* "_none" carries an underscore, which no category slug can
               (generatePortfolioCategorySlug()), so a category that happens
               to be called "None" can still be filtered on. */ ?>
      <option value="_none" <?= $initialCategory === '_none' ? 'selected' : '' ?>><?= admin_te('portfolio.zonder_categorie') ?></option>
      <?php foreach ($categoriesWithCounts as $category): ?>
        <option value="<?= $h((string) $category['slug']) ?>" <?= $initialCategory === $category['slug'] ? 'selected' : '' ?>><?= $h((string) $category['name_nl']) ?></option>
      <?php endforeach; ?>
    </select>

    <select class="admin-select" aria-label="Filter op zichtbaarheid" data-portfolio-filter="visibility">
      <option value="all"><?= admin_t('portfolio.zichtbaar_verborgen') ?></option>
      <option value="visible" <?= $initialVisibility === 'visible' ? 'selected' : '' ?>><?= admin_te('portfolio.alleen_zichtbaar') ?></option>
      <option value="hidden" <?= $initialVisibility === 'hidden' ? 'selected' : '' ?>><?= admin_te('portfolio.alleen_verborgen') ?></option>
    </select>

    <select class="admin-select" aria-label="Filter op projectpagina" data-portfolio-filter="detail">
      <option value="all"><?= admin_t('portfolio.zonder_projectpagina') ?></option>
      <option value="yes" <?= $initialDetail === 'yes' ? 'selected' : '' ?>><?= admin_te('portfolio.projectpagina') ?></option>
      <option value="no" <?= $initialDetail === 'no' ? 'selected' : '' ?>><?= admin_te('portfolio.zonder_projectpagina_2') ?></option>
    </select>

    <select class="admin-select" aria-label="Filter op homepage" data-portfolio-filter="home">
      <option value="all"><?= admin_t('portfolio.wel_homepage') ?></option>
      <option value="yes" <?= $initialHome === 'yes' ? 'selected' : '' ?>><?= admin_te('portfolio.homepage') ?></option>
      <option value="no" <?= $initialHome === 'no' ? 'selected' : '' ?>><?= admin_te('portfolio.homepage_2') ?></option>
    </select>

    <span class="admin-text-muted" data-portfolio-count></span>
  </div>

  <?php if ($items === []): ?>
    <p><?= admin_t('portfolio.portfolio_items_maak_eerste') ?></p>
  <?php else: ?>
    <?php
      $categoryNameBySlug = [];
      foreach ($categoriesWithCounts as $category) {
          $categoryNameBySlug[$category['slug']] = (string) $category['name_nl'];
      }
    ?>
    <div class="admin-portfolio-grid"
         data-portfolio-grid
         data-reorder-url="/api/admin/reorder-portfolio-items.php"
         data-csrf-token="<?= $h($csrfToken) ?>">
      <?php foreach ($items as $item): ?>
        <?php
          $itemId = (int) $item['id'];
          $isActive = (int) $item['is_active'] === 1;
          $isFeatured = (int) $item['is_featured'] === 1;
          // "Projectpagina" is the ordinary page the item links to. An item
          // that only still has its old project page gets a badge of its own,
          // so an editor can find the ones that still need a page (MODULES.md).
          $hasDetail = $item['page_id'] !== null;
          $hasOldProjectPage = !$hasDetail && !empty($item['has_detail_page']) && (string) ($item['slug'] ?? '') !== '';
          $title = (string) $item['title_nl'];
          $name = $itemName($item);
          $itemCategorySlugs = $categorySlugsByItemId[$itemId] ?? [];
          $categoriesAttr = implode(' ', $itemCategorySlugs);
          $editUrl = '/admin/portfolio-item.php?id=' . $itemId . ($backQueryString !== '' ? '&back=' . urlencode($backQueryString) : '');
        ?>
        <article class="admin-portfolio-card"
                 data-portfolio-card
                 data-id="<?= $itemId ?>"
                 data-title="<?= $h(mb_strtolower($title)) ?>"
                 data-categories="<?= $h($categoriesAttr) ?>"
                 data-active="<?= $isActive ? '1' : '0' ?>"
                 data-detail="<?= $hasDetail ? '1' : '0' ?>"
                 data-home="<?= $isFeatured ? '1' : '0' ?>">
          <span class="admin-portfolio-card__handle" data-portfolio-drag-handle title="Sleep om te herordenen" aria-hidden="true">&#10021;</span>
          <a href="<?= $h($editUrl) ?>" class="admin-portfolio-card__link" aria-label="Bewerken: <?= $h($name) ?>">
            <div class="admin-portfolio-card__media">
              <img src="<?= $h($cardImageSrc($item)) ?>" alt="" loading="lazy">
              <span class="admin-badge admin-badge--<?= $isActive ? 'paid' : 'canceled' ?> admin-portfolio-card__status"><?= $isActive ? 'Zichtbaar' : 'Verborgen' ?></span>
            </div>
            <div class="admin-portfolio-card__body">
              <p class="admin-portfolio-card__name<?= $title === '' ? ' admin-portfolio-card__name--untitled' : '' ?>"><?= $h($name) ?></p>
              <?php if ($itemCategorySlugs !== []): ?>
              <p class="admin-portfolio-card__meta"><?= $h(implode(', ', array_map(
                  static fn (string $slug): string => $categoryNameBySlug[$slug] ?? $slug,
                  $itemCategorySlugs
              ))) ?></p>
              <?php endif; ?>
              <div class="admin-portfolio-card__badges">
                <?php if ($isFeatured): ?><span class="admin-badge admin-badge--info">Homepage</span><?php endif; ?>
                <?php if ($hasDetail): ?><span class="admin-badge admin-badge--editable">Projectpagina</span><?php endif; ?>
                <?php if ($hasOldProjectPage): ?><span class="admin-badge admin-badge--draft"><?= admin_te('portfolio.badge_old_project_page') ?></span><?php endif; ?>
              </div>
            </div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="admin-text-muted" data-portfolio-empty hidden><?= admin_te('portfolio.portfolio_items_gevonden_zoekopdracht') ?></p>
  <?php endif; ?>

  <?= admin_confirm_dialog() ?>
</main>
<?php admin_lang_script(); ?>
</body>
</html>
