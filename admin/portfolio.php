<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portfolio — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/portfolio-admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1>Portfolio</h1>
    <a href="/admin/portfolio-item.php" class="admin-btn-link">+ Nieuw portfolio-item</a>
  </div>
  <p class="admin-text-muted">Klik op een item om het te bewerken. Sleep aan de <strong>&#10021;</strong>-greep om de volgorde te wijzigen.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
  <?php endif; ?>
  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Portfolio-item aangemaakt. Vul hieronder de overige gegevens aan.</p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success">Portfolio-item verwijderd.</p>
  <?php endif; ?>
  <?php if ($categorySaved): ?>
    <p class="admin-alert admin-alert--success">Categorie opgeslagen.</p>
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
    <summary>Homepage-uitlichting (<?= count($featuredItems) ?>)</summary>
    <p class="admin-text-muted">Volgorde van de items in de "Een greep uit eerder werk"-sectie op de homepage. Vink "Toon op homepage" bij een item aan/uit om het toe te voegen of te verwijderen.</p>
    <?php foreach ($featuredItems as $index => $item): ?>
      <?php
        $itemId = (int) $item['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($featuredItems) - 1;
      ?>
      <div class="admin-section-row" style="margin-top:0.6rem;">
        <img src="<?= $h($cardImageSrc($item)) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:var(--admin-radius-sm);">
        <span class="admin-section-row__body"><?= $h((string) $item['title_nl']) ?></span>
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
    <summary>Portfolio categorieën (<?= count($categoriesWithCounts) ?>)</summary>
    <p class="admin-text-muted">Categorieën zijn direct beschikbaar in elk portfolio-item en, zodra ze in gebruik zijn door een zichtbaar item, in het filter op de publieke portfolio-pagina.</p>

    <?php if ($categoriesWithCounts === []): ?>
      <p class="admin-text-muted">Nog geen categorieën.</p>
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
              <input type="text" name="name_nl" maxlength="100" required value="<?= $h((string) $category['name_nl']) ?>" aria-label="Naam (NL)">
              <input type="text" name="name_en" maxlength="100" value="<?= $h((string) ($category['name_en'] ?? '')) ?>" placeholder="Leeg = zelfde als NL" aria-label="Naam (EN)">
              <button type="submit" class="admin-btn-text">Opslaan</button>
            </form>
            <span class="admin-text-muted admin-portfolio-category-row__count"><?= $itemCount ?> project<?= $itemCount === 1 ? '' : 'en' ?></span>
            <form method="post" action="/api/admin/delete-portfolio-category.php" class="admin-inline-form" onsubmit="return confirm('Deze categorie definitief verwijderen?');">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="category_id" value="<?= $categoryId ?>">
              <?php if ($itemCount > 0): ?>
                <span class="admin-text-muted" title="Nog in gebruik bij <?= $itemCount ?> portfolio-item(s) — verwijder eerst de toewijzing.">Verwijderen n.v.t.</span>
              <?php else: ?>
                <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
              <?php endif; ?>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/api/admin/create-portfolio-category.php" class="admin-portfolio-category-row__form admin-portfolio-category-row__form--new">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="text" name="name_nl" maxlength="100" required placeholder="Nieuwe categorie (NL)" aria-label="Naam (NL)">
      <input type="text" name="name_en" maxlength="100" placeholder="Naam (EN, optioneel)" aria-label="Naam (EN)">
      <button type="submit">+ Nieuwe categorie</button>
    </form>
  </details>

  <div class="admin-portfolio-toolbar" data-portfolio-toolbar>
    <input type="search" placeholder="Zoek op titel…" aria-label="Zoek op titel" data-portfolio-search value="<?= $h($initialQuery) ?>">

    <select aria-label="Filter op categorie" data-portfolio-filter="category">
      <option value="all">Alle categorieën</option>
      <?php foreach ($categoriesWithCounts as $category): ?>
        <option value="<?= $h((string) $category['slug']) ?>" <?= $initialCategory === $category['slug'] ? 'selected' : '' ?>><?= $h((string) $category['name_nl']) ?></option>
      <?php endforeach; ?>
    </select>

    <select aria-label="Filter op zichtbaarheid" data-portfolio-filter="visibility">
      <option value="all">Zichtbaar &amp; verborgen</option>
      <option value="visible" <?= $initialVisibility === 'visible' ? 'selected' : '' ?>>Alleen zichtbaar</option>
      <option value="hidden" <?= $initialVisibility === 'hidden' ? 'selected' : '' ?>>Alleen verborgen</option>
    </select>

    <select aria-label="Filter op projectpagina" data-portfolio-filter="detail">
      <option value="all">Met &amp; zonder projectpagina</option>
      <option value="yes" <?= $initialDetail === 'yes' ? 'selected' : '' ?>>Met projectpagina</option>
      <option value="no" <?= $initialDetail === 'no' ? 'selected' : '' ?>>Zonder projectpagina</option>
    </select>

    <select aria-label="Filter op homepage" data-portfolio-filter="home">
      <option value="all">Wel &amp; niet op homepage</option>
      <option value="yes" <?= $initialHome === 'yes' ? 'selected' : '' ?>>Op homepage</option>
      <option value="no" <?= $initialHome === 'no' ? 'selected' : '' ?>>Niet op homepage</option>
    </select>

    <span class="admin-text-muted" data-portfolio-count></span>
  </div>

  <?php if ($items === []): ?>
    <p>Nog geen portfolio-items. <a href="/admin/portfolio-item.php">Maak het eerste item aan</a>.</p>
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
          $hasDetail = !empty($item['has_detail_page']) && (string) ($item['slug'] ?? '') !== '';
          $title = (string) $item['title_nl'];
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
          <a href="<?= $h($editUrl) ?>" class="admin-portfolio-card__link" aria-label="Bewerken: <?= $h($title) ?>">
            <div class="admin-portfolio-card__media">
              <img src="<?= $h($cardImageSrc($item)) ?>" alt="" loading="lazy">
              <span class="admin-badge admin-badge--<?= $isActive ? 'paid' : 'canceled' ?> admin-portfolio-card__status"><?= $isActive ? 'Zichtbaar' : 'Verborgen' ?></span>
            </div>
            <div class="admin-portfolio-card__body">
              <p class="admin-portfolio-card__name"><?= $h($title) ?></p>
              <p class="admin-portfolio-card__meta"><?= $h(implode(', ', array_map(
                  static fn (string $slug): string => $categoryNameBySlug[$slug] ?? $slug,
                  $itemCategorySlugs
              ))) ?></p>
              <div class="admin-portfolio-card__badges">
                <?php if ($isFeatured): ?><span class="admin-badge admin-badge--info">Homepage</span><?php endif; ?>
                <?php if ($hasDetail): ?><span class="admin-badge admin-badge--editable">Projectpagina</span><?php endif; ?>
              </div>
            </div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="admin-text-muted" data-portfolio-empty hidden>Geen portfolio-items gevonden voor deze zoekopdracht/filter.</p>
  <?php endif; ?>
</main>
</body>
</html>
