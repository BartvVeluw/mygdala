<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;

require __DIR__ . '/_richtext_field.php';

AdminAuth::requireLogin();
AdminAuth::requirePermission('portfolio.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = $id !== null && $id !== false && $id >= 1;

$item = null;
$extraImages = [];
$itemCategoryIds = [];
$allCategories = (new PortfolioCategoryRepository())->findAll();

if ($isEdit) {
    $repository = new PortfolioGalleryRepository();
    $item = $repository->findItemById($id);

    if ($item === null) {
        http_response_code(404);
        exit('Portfolio-item niet gevonden.');
    }

    $extraImages = (new PortfolioItemImageRepository())->findByPortfolioItemId($id);
    $itemCategoryIds = $repository->categoryIdsForItem($id);
}

$errors = $_SESSION['admin_portfolio_item_errors'] ?? [];
$old = $_SESSION['admin_portfolio_item_old'] ?? null;
unset($_SESSION['admin_portfolio_item_errors'], $_SESSION['admin_portfolio_item_old']);

$created = isset($_GET['created']);
$updated = isset($_GET['updated']);

// Back link to the overview: preserves whatever search/filter state was
// active there when this item was opened (see admin/portfolio.php, which
// appends ?back=... to every card link) — falls back to a plain link when
// absent (e.g. this item was opened fresh, not via a card).
$backQuery = (string) ($_GET['back'] ?? '');
$backUrl = '/admin/portfolio.php' . ($backQuery !== '' ? '?' . $backQuery : '');

/**
 * Value precedence: freshly re-submitted (invalid) input, then the stored
 * item (edit), then a sane default. Same helper as product-form.php's
 * fieldValue().
 */
function fieldValue(?array $old, ?array $item, string $key, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    if ($item !== null && array_key_exists($key, $item)) {
        return (string) ($item[$key] ?? '');
    }

    return $default;
}

$selectedCategoryIds = $old !== null
    ? array_map('intval', is_array($old['categories'] ?? null) ? $old['categories'] : [])
    : $itemCategoryIds;

$isActiveChecked = $old !== null ? true : ($item === null || (int) $item['is_active'] === 1);
$isFeaturedChecked = $item !== null && (int) $item['is_featured'] === 1;
$hasDetailPageChecked = $item !== null && !empty($item['has_detail_page']);

$csrfToken = Csrf::token();
$pageTitle = $isEdit ? (string) $item['title_nl'] : 'Nieuw portfolio-item';

/**
 * @param array<int, array<string, mixed>> $categories from PortfolioCategoryRepository::findAll()
 * @param list<int> $selectedIds
 */
function portfolioCategoryCheckboxes(array $categories, array $selectedIds): string
{
    if ($categories === []) {
        return '<p class="admin-text-muted">Nog geen categorie&euml;n. Maak er een aan via "Portfolio categorie&euml;n" op het portfolio-overzicht.</p>';
    }

    $html = '';
    foreach ($categories as $category) {
        $categoryId = (int) $category['id'];
        $checked = in_array($categoryId, $selectedIds, true) ? 'checked' : '';
        $id = 'cat_' . $categoryId;
        $html .= '<label class="admin-checkbox-label" for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" style="margin-right:1rem;display:inline-flex;">'
            . '<input type="checkbox" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="categories[]" value="' . $categoryId . '" ' . $checked . '> '
            . htmlspecialchars((string) $category['name_nl'], ENT_QUOTES, 'UTF-8')
            . '</label>';
    }

    return $html;
}

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * CMS preview src for a Portfolio image: the small (~480px) thumbnail when
 * one exists, else the full image — see admin/portfolio.php's identical
 * helper docblock for why some rows have no thumbnail_path yet.
 */
$cmsImageSrc = static fn (array $row): string => '/' . ltrim((string) ($row['thumbnail_path'] ?: $row['image_path']), '/');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="<?= $h($backUrl) ?>">&larr; Terug naar portfolio</a></p>
  <h1><?= $h($pageTitle) ?></h1>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Portfolio-item aangemaakt. Vul hieronder de overige gegevens aan.</p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
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

  <?php if (!$isEdit): ?>
    <section class="admin-card">
      <h2>Nieuw portfolio-item</h2>
      <p class="admin-text-muted">Na het aanmaken kun je hier ook een projectpagina, introtekst en extra afbeeldingen toevoegen.</p>
      <form method="post" action="/api/admin/create-portfolio-item.php" enctype="multipart/form-data" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

        <div class="admin-form-row">
          <label>Afbeelding*
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
          </label>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <label>Alt-tekst (NL)*
            <input type="text" name="alt_nl" maxlength="255" required value="<?= $h(fieldValue($old, null, 'alt_nl')) ?>">
          </label>
          <label>Alt-tekst (EN)
            <input type="text" name="alt_en" maxlength="255" value="<?= $h(fieldValue($old, null, 'alt_en')) ?>" placeholder="Leeg = zelfde als NL">
          </label>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <label>Titel (NL)*
            <input type="text" name="title_nl" maxlength="150" required value="<?= $h(fieldValue($old, null, 'title_nl')) ?>">
          </label>
          <label>Titel (EN)
            <input type="text" name="title_en" maxlength="150" value="<?= $h(fieldValue($old, null, 'title_en')) ?>" placeholder="Leeg = zelfde als NL">
          </label>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <label>Onderschrift (NL)*
            <input type="text" name="subtitle_nl" maxlength="150" required value="<?= $h(fieldValue($old, null, 'subtitle_nl')) ?>">
          </label>
          <label>Onderschrift (EN)
            <input type="text" name="subtitle_en" maxlength="150" value="<?= $h(fieldValue($old, null, 'subtitle_en')) ?>" placeholder="Leeg = zelfde als NL">
          </label>
        </div>

        <div class="admin-form-row">
          <span>Categorie&euml;n*</span><br>
          <?= portfolioCategoryCheckboxes($allCategories, $selectedCategoryIds) ?>
        </div>

        <button type="submit">Portfolio-item aanmaken</button>
      </form>
    </section>
  <?php else: ?>
    <form method="post" action="/api/admin/update-portfolio-item.php" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

      <section class="admin-card">
        <h2>Basisgegevens</h2>
        <div class="admin-form-row admin-form-row--split">
          <label>Titel (NL)*
            <input type="text" name="title_nl" maxlength="150" required value="<?= $h(fieldValue($old, $item, 'title_nl')) ?>">
          </label>
          <label>Titel (EN)
            <input type="text" name="title_en" maxlength="150" value="<?= $h(fieldValue($old, $item, 'title_en')) ?>" placeholder="Leeg = zelfde als NL">
          </label>
        </div>
        <div class="admin-form-row admin-form-row--split">
          <label>Onderschrift (NL)*
            <input type="text" name="subtitle_nl" maxlength="150" required value="<?= $h(fieldValue($old, $item, 'subtitle_nl')) ?>">
          </label>
          <label>Onderschrift (EN)
            <input type="text" name="subtitle_en" maxlength="150" value="<?= $h(fieldValue($old, $item, 'subtitle_en')) ?>" placeholder="Leeg = zelfde als NL">
          </label>
        </div>
      </section>

      <section class="admin-card">
        <h2>Hoofdafbeelding</h2>
        <div class="admin-image-card" style="max-width:220px;">
          <div class="admin-image-card__media">
            <img src="<?= $h($cmsImageSrc($item)) ?>" alt="" loading="lazy">
          </div>
        </div>
        <div class="admin-form-row" style="margin-top:0.75rem;">
          <label>Vervangen door nieuw bestand (optioneel)
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
          </label>
        </div>
        <div class="admin-form-row admin-form-row--split">
          <label>Alt-tekst (NL)*
            <input type="text" name="alt_nl" maxlength="255" required value="<?= $h(fieldValue($old, $item, 'alt_nl')) ?>">
          </label>
          <label>Alt-tekst (EN)
            <input type="text" name="alt_en" maxlength="255" value="<?= $h(fieldValue($old, $item, 'alt_en')) ?>" placeholder="Leeg = zelfde als NL">
          </label>
        </div>
      </section>

      <section class="admin-card">
        <h2>Zichtbaarheid &amp; categorie&euml;n</h2>
        <div class="admin-form-row">
          <span>Categorie&euml;n*</span><br>
          <?= portfolioCategoryCheckboxes($allCategories, $selectedCategoryIds) ?>
        </div>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_active" value="1" <?= $isActiveChecked ? 'checked' : '' ?>>
          Zichtbaar op de portfolio-pagina
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_featured" value="1" <?= $isFeaturedChecked ? 'checked' : '' ?>>
          Toon op homepage
        </label>
      </section>

      <section class="admin-card">
        <h2>Projectpagina</h2>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="has_detail_page" value="1" data-detail-toggle <?= $hasDetailPageChecked ? 'checked' : '' ?>>
          Projectpagina inschakelen
        </label>
        <p class="admin-text-muted">Ingeschakeld: de portfolio-kaart wordt klikbaar en linkt naar een eigen projectpagina met introtekst, projectbeschrijving en extra afbeeldingen.</p>

        <div data-detail-panel <?= $hasDetailPageChecked ? '' : 'hidden' ?>>
          <div class="admin-form-row">
            <label>Slug (URL: /portfolio/&hellip;)
              <input type="text" name="slug" maxlength="170" value="<?= $h(fieldValue($old, $item, 'slug')) ?>" placeholder="Leeg = automatisch gegenereerd uit de titel">
            </label>
            <?php if ($hasDetailPageChecked && (string) ($item['slug'] ?? '') !== ''): ?>
              <p class="admin-text-muted">Live op: <a href="/portfolio/<?= $h((string) $item['slug']) ?>" target="_blank" rel="noopener">/portfolio/<?= $h((string) $item['slug']) ?></a></p>
            <?php endif; ?>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <?php renderRichTextField('intro_nl', 'Introtekst (NL)', fieldValue($old, $item, 'intro_nl'), 'full', 'admin-richtext-editor--md'); ?>
            <?php renderRichTextField('intro_en', 'Introtekst (EN) — leeg = zelfde als NL', fieldValue($old, $item, 'intro_en'), 'full', 'admin-richtext-editor--md'); ?>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <?php renderRichTextField('description_nl', 'Projectbeschrijving (NL)', fieldValue($old, $item, 'description_nl'), 'full', 'admin-richtext-editor--lg'); ?>
            <?php renderRichTextField('description_en', 'Projectbeschrijving (EN) — leeg = zelfde als NL', fieldValue($old, $item, 'description_en'), 'full', 'admin-richtext-editor--lg'); ?>
          </div>
        </div>
      </section>

      <section class="admin-card">
        <button type="submit">Opslaan</button>
      </section>
    </form>

    <section class="admin-card" data-detail-panel <?= $hasDetailPageChecked ? '' : 'hidden' ?>>
      <h2>Projectafbeeldingen</h2>
      <p class="admin-text-muted">Extra foto's voor de projectpagina, naast de hoofdafbeelding hierboven. Sleep om te herordenen.</p>

      <?php if ($extraImages === []): ?>
        <p class="admin-text-muted">Nog geen extra afbeeldingen.</p>
      <?php else: ?>
        <div class="admin-portfolio-image-grid"
             data-portfolio-image-grid
             data-entity-id="<?= (int) $item['id'] ?>"
             data-reorder-url="/api/admin/reorder-portfolio-item-images.php"
             data-csrf-token="<?= $h($csrfToken) ?>">
          <?php foreach ($extraImages as $image): ?>
            <?php $imageId = (int) $image['id']; ?>
            <div class="admin-portfolio-image-card" draggable="true" data-image-id="<?= $imageId ?>">
              <div class="admin-portfolio-image-card__media">
                <img src="<?= $h($cmsImageSrc($image)) ?>" alt="" loading="lazy">
              </div>
              <form method="post" action="/api/admin/update-portfolio-item-image.php" class="admin-portfolio-image-card__meta">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="image_id" value="<?= $imageId ?>">
                <input type="hidden" name="portfolio_item_id" value="<?= (int) $item['id'] ?>">
                <input type="text" name="alt_nl" maxlength="255" placeholder="Alt-tekst (NL)" value="<?= $h((string) ($image['alt_nl'] ?? '')) ?>">
                <input type="text" name="alt_en" maxlength="255" placeholder="Alt-tekst (EN)" value="<?= $h((string) ($image['alt_en'] ?? '')) ?>">
                <button type="submit" class="admin-btn-text">Opslaan</button>
              </form>
              <form method="post" action="/api/admin/delete-portfolio-item-image.php" class="admin-inline-form" onsubmit="return confirm('Deze afbeelding verwijderen?');">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="image_id" value="<?= $imageId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/api/admin/add-portfolio-item-images.php" enctype="multipart/form-data" class="admin-form-row" data-portfolio-add-images-form>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="portfolio_item_id" value="<?= (int) $item['id'] ?>">
        <label>Afbeeldingen toevoegen (kies er meerdere tegelijk)
          <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
        </label>
        <button type="submit">Toevoegen</button>
      </form>
    </section>

    <section class="admin-card">
      <h2>Verwijderen</h2>
      <p class="admin-text-muted">Verwijdert dit portfolio-item, de hoofdafbeelding en alle projectafbeeldingen definitief.</p>
      <form method="post" action="/api/admin/delete-portfolio-item.php" onsubmit="return confirm('Dit portfolio-item definitief verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger">Portfolio-item verwijderen</button>
      </form>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
