<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/_language_fields.php';

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogSlug;
use App\Service\Blog\BlogUrls;
use App\Service\Csrf;

/**
 * Beheer → Blogcategorieën: the list, one small form per category, and a
 * form to add one.
 *
 * EVERYTHING ON ONE SCREEN, deliberately. A category is five fields; giving
 * it an overview plus a detail screen would be two navigations to change a
 * name. Each row is its OWN <form> to its own endpoint, so saving one
 * category cannot blank another — the rule this project follows everywhere:
 * a form posts every field its endpoint reads, and no endpoint reads fields
 * that were not on the form it received.
 *
 * DELETION IS SAFE AND THE SCREEN SAYS SO. Removing a category removes the
 * link rows and nothing else: no post is deleted, and a post that loses its
 * only category simply becomes uncategorised. The count next to the delete
 * button is there so that is an informed decision rather than a surprise.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('blog.manage');

try {
    $repository = new BlogCategoryRepository();
    $categories = $repository->all();
    $posts = new BlogPostRepository();
    $loadFailed = false;
} catch (\Throwable $e) {
    error_log('[admin/blog-categories.php] ' . $e->getMessage());
    $categories = [];
    $posts = null;
    $loadFailed = true;
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$flash = $_SESSION['admin_blog_taxonomy_flash'] ?? null;
$errors = $_SESSION['admin_blog_taxonomy_errors'] ?? [];
unset($_SESSION['admin_blog_taxonomy_flash'], $_SESSION['admin_blog_taxonomy_errors']);
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Blogcategorieën — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1>Blogcategorieën</h1>
  </div>
  <p class="admin-text-muted">De vaste indeling van je blog. Elke categorie krijgt een eigen pagina op <code>/<?= $h(BlogUrls::ROOT) ?>/<?= $h(BlogUrls::CATEGORY_SEGMENT) ?>/&lt;slug&gt;</code>, en de volgorde hieronder bepaalt welke categorie op een berichtkaart getoond wordt.</p>

  <?php if ($flash !== null): ?>
    <p class="admin-alert admin-alert--success"><?= $h((string) $flash) ?></p>
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

  <?php if ($loadFailed): ?>
    <p class="admin-alert admin-alert--error">Categorieën konden niet worden geladen.</p>
  <?php endif; ?>

  <?php if (!$loadFailed && $categories === []): ?>
    <p>Er zijn nog geen categorieën. Maak er hieronder een aan.</p>
  <?php endif; ?>

  <?php foreach ($categories as $category): ?>
    <?php
      $categoryId = (int) $category['id'];
      $postCount = $posts === null ? 0 : $posts->countByCategory($categoryId);
    ?>
    <section class="admin-card">
      <h2><?= $h((string) $category['name']) ?></h2>
      <form method="post" action="/api/admin/update-blog-category.php" class="admin-product-form admin-product-form--wide">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $categoryId ?>">

        <?php admin_lang_tabs(); ?>
        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label>Naam*
            <input type="text" name="name" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= $h((string) $category['name']) ?>">
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label>Naam
            <input type="text" name="name_en" maxlength="150" value="<?= $h((string) ($category['name_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <label>URL (slug)*
            <input type="text" name="slug" maxlength="<?= BlogSlug::MAX_LENGTH ?>" required value="<?= $h((string) $category['slug']) ?>">
          </label>
          <label>Volgorde
            <input type="number" name="sort_order" value="<?= (int) $category['sort_order'] ?>" step="10">
          </label>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <?php admin_lang_pane_start('nl'); ?>
          <label>Korte omschrijving
            <textarea name="description" rows="2" maxlength="500"><?= $h((string) ($category['description'] ?? '')) ?></textarea>
          </label>
          <?php admin_lang_pane_end(); ?>
          <?php admin_lang_pane_start('en'); ?>
          <label>Korte omschrijving
            <textarea name="description_en" rows="2" maxlength="500"<?= admin_lang_placeholder_attr('en') ?>><?= $h((string) ($category['description_en'] ?? '')) ?></textarea>
          </label>
          <?php admin_lang_pane_end(); ?>
        </div>
        <p class="admin-text-muted">De omschrijving staat boven het categorie-archief en wordt gebruikt als meta description van die pagina.</p>

        <?php /* Hidden companion field, the same reason the noindex switch
                 has one: an unticked checkbox sends nothing at all. */ ?>
        <input type="hidden" name="is_active" value="0">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="is_active" value="1" <?= (int) $category['is_active'] === 1 ? 'checked' : '' ?>>
          Actief
        </label>
        <p class="admin-text-muted">Uit betekent: het archief van deze categorie geeft een 404 en de categorie staat niet in de sitemap. De berichten erin blijven gewoon staan en blijven bereikbaar.</p>

        <div>
          <button type="submit">Categorie opslaan</button>
          <a href="<?= $h(BlogUrls::categoryPath((string) $category['slug'])) ?>" class="admin-btn-text" target="_blank" rel="noopener">Bekijk archief &#8594;</a>
        </div>
      </form>

      <p class="admin-text-muted"><?= $postCount === 1 ? 'Eén bericht staat in deze categorie.' : $postCount . ' berichten staan in deze categorie.' ?></p>
      <form method="post" action="/api/admin/delete-blog-category.php" class="admin-inline-form" onsubmit="return confirm('Deze categorie verwijderen? De berichten erin blijven bestaan en raken alleen deze categorie kwijt.');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $categoryId ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger">Categorie verwijderen</button>
      </form>
    </section>
  <?php endforeach; ?>

  <section class="admin-card">
    <h2>Nieuwe categorie</h2>
    <form method="post" action="/api/admin/create-blog-category.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <label>Naam*
        <input type="text" name="name" maxlength="150" required placeholder="Bijvoorbeeld: Achter de schermen">
      </label>
      <label>URL (slug)
        <input type="text" name="slug" maxlength="<?= BlogSlug::MAX_LENGTH ?>" placeholder="Leeg = automatisch uit de naam">
      </label>
      <button type="submit">Categorie aanmaken</button>
    </form>
  </section>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
