<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repository\CollectionRepository;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\Csrf;

AdminAuth::requireLogin();
AdminAuth::requirePermission('collections.manage');

/**
 * CMS overview of every shop collection — the Collections counterpart of
 * admin/products.php, deliberately built from the same card grid
 * (.admin-product-grid/.admin-product-card) so the two catalog screens read
 * the same way, plus the Portfolio overview's drag handle for ordering.
 *
 * A collection card shows its image, name, published state and how many
 * products it holds, and offers Edit (the whole card is the link) and
 * Delete. Ordering here is the same sort_order the public /shop Collections
 * section uses.
 */
try {
    $collections = (new CollectionRepository())->findAllWithProductCounts();
} catch (\Throwable $e) {
    error_log('[admin/collections.php] ' . $e->getMessage());
    $collections = null;
}

$created = isset($_GET['created']);
$deleted = isset($_GET['deleted']);
$listError = $_SESSION['admin_collection_list_error'] ?? null;
unset($_SESSION['admin_collection_list_error']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Collecties — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/collections-admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1>Collecties</h1>
    <a href="/admin/collection.php" class="admin-btn-link">+ Nieuwe collectie</a>
  </div>
  <p class="admin-text-muted">Groepeer producten in een collectie met een eigen pagina op <code>/collecties/&lt;slug&gt;</code>. Een product kan in meerdere collecties zitten. Klik op een kaart om te bewerken; sleep aan de <strong>&#10021;</strong>-greep om de volgorde te wijzigen (die volgorde wordt ook op de shop-pagina gebruikt).</p>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Collectie aangemaakt.</p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success">Collectie verwijderd. De producten zelf zijn ongewijzigd gebleven.</p>
  <?php endif; ?>
  <?php if ($listError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= $h((string) $listError) ?></p>
  <?php endif; ?>

  <?php if ($collections === null): ?>
    <p class="admin-alert admin-alert--error">Collecties konden niet worden geladen.</p>
  <?php elseif ($collections === []): ?>
    <p>Nog geen collecties. <a href="/admin/collection.php">Maak de eerste collectie aan</a>.</p>
  <?php else: ?>
    <div class="admin-product-grid"
         data-collections-grid
         data-reorder-url="/api/admin/reorder-collections.php"
         data-csrf-token="<?= $h($csrfToken) ?>">
      <?php foreach ($collections as $collection): ?>
        <?php
          $collectionId = (int) $collection['id'];
          $isActive = (int) $collection['is_active'] === 1;
          $productCount = (int) $collection['product_count'];
          $name = (string) $collection['name'];
          $slug = (string) $collection['slug'];
          $imagePath = (string) ($collection['image_path'] ?? '');
        ?>
        <article class="admin-product-card" data-collection-card data-id="<?= $collectionId ?>">
          <span class="admin-portfolio-card__handle" data-collection-drag-handle title="Sleep om te herordenen" aria-hidden="true">&#10021;</span>
          <a href="/admin/collection.php?id=<?= $collectionId ?>" class="admin-product-card__link" aria-label="Bewerken: <?= $h($name) ?>">
            <div class="admin-product-card__media">
              <?php if ($imagePath !== ''): ?>
                <img src="/<?= $h(ltrim($imagePath, '/')) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="admin-product-card__media-empty">Geen afbeelding</span>
              <?php endif; ?>
              <span class="admin-badge admin-product-card__status admin-badge--<?= $isActive ? 'paid' : 'canceled' ?>">
                <?= $isActive ? 'Actief' : 'Inactief' ?>
              </span>
            </div>
            <div class="admin-product-card__body">
              <p class="admin-product-card__name"><?= $h($name) ?></p>
              <p class="admin-product-card__price"><?= $productCount ?> product<?= $productCount === 1 ? '' : 'en' ?></p>
            </div>
          </a>
          <div class="admin-product-card__footer">
            <?php if ($isActive): ?>
              <a class="admin-btn-text" href="<?= $h(CollectionContent::publicPath($slug)) ?>" target="_blank" rel="noopener">Bekijken</a>
            <?php else: ?>
              <span class="admin-text-muted" title="Een inactieve collectie is niet publiek zichtbaar.">Niet zichtbaar</span>
            <?php endif; ?>
            <?php
              // Deleting a collection can never delete a product — the pivot's
              // foreign key cascade only removes the membership rows (see
              // App\Service\CollectionService::delete()). The confirmation
              // says so, so the admin never has to guess.
              $confirmMessage = 'Weet je zeker dat je deze collectie definitief wilt verwijderen? De producten in deze collectie blijven gewoon bestaan.';
            ?>
            <form method="post" action="/api/admin/delete-collection.php" class="admin-inline-form" onsubmit="return confirm('<?= $h($confirmMessage) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= $collectionId ?>">
              <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
