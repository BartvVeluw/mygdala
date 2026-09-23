<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_localized_fields.php';

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogLocalization;
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

// The website language this screen's words are in, and the words of every
// category it is about to print — one query rather than one per row.
$editingLanguage = admin_localized_language();
$isDefaultLanguage = $editingLanguage === admin_localized_default();
BlogLocalization::preloadCategories(array_map(
    static fn (array $category): int => (int) $category['id'],
    $categories
));

$flash = $_SESSION['admin_blog_taxonomy_flash'] ?? null;
$errors = $_SESSION['admin_blog_taxonomy_errors'] ?? [];
$old = $_SESSION['admin_blog_taxonomy_old'] ?? null;
unset(
    $_SESSION['admin_blog_taxonomy_flash'],
    $_SESSION['admin_blog_taxonomy_errors'],
    $_SESSION['admin_blog_taxonomy_old']
);

/**
 * A refused save comes back with what was typed, on the card it was typed on
 * and in the language it was typed in — one card per category, so the input
 * is matched on both before it is used. Every other card shows what is
 * stored, which is exactly what admin/page.php and admin/blog-post.php do
 * with their own `$old`.
 *
 * @return array<string, mixed>|null
 */
$refusedInput = static function (int $categoryId) use ($old, $editingLanguage): ?array {
    if (!is_array($old)
        || (int) ($old['id'] ?? 0) !== $categoryId
        || ($old['language_code'] ?? null) !== $editingLanguage
    ) {
        return null;
    }

    return $old;
};

/**
 * The category's address IN THE LANGUAGE BEING EDITED (Multilingual 2.0
 * phase 6, docs/multilingual/ROUTING.md). '' means this language has no
 * public archive URL for it yet, which is a real and ordinary state.
 *
 * @param array<string, mixed> $category
 */
$slugValue = static function (array $category) use ($refusedInput, $editingLanguage): string {
    $input = $refusedInput((int) $category['id']);

    if ($input !== null && array_key_exists('slug', $input)) {
        return (string) ($input['slug'] ?? '');
    }

    return (string) (BlogLocalization::categorySlug($category, $editingLanguage) ?? '');
};

/**
 * The archive path this category can be visited at in the language being
 * edited, or null when it has no address there.
 *
 * @param array<string, mixed> $category
 */
$archivePath = static function (array $category) use ($editingLanguage): ?string {
    $slug = BlogLocalization::categorySlug($category, $editingLanguage);

    return $slug === null ? null : BlogUrls::categoryPath($slug, 1, $editingLanguage);
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('blog.blogcategorie_n_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1><?= admin_te('blog.blogcategorie_n') ?></h1>
  </div>
  <p class="admin-text-muted"><?= admin_t('blog.vaste_indeling_blog_elke', ['v1' => $h(BlogUrls::ROOT), 'v2' => $h(BlogUrls::CATEGORY_SEGMENT)]) ?></p>

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
    <p class="admin-alert admin-alert--error"><?= admin_te('blog.categorie_n_konden_geladen') ?></p>
  <?php endif; ?>

  <?php if (!$loadFailed && $categories === []): ?>
    <p><?= admin_t('blog.er_categorie_n_maak') ?></p>
  <?php endif; ?>

  <?php foreach ($categories as $category): ?>
    <?php
      $categoryId = (int) $category['id'];
      $postCount = $posts === null ? 0 : $posts->countByCategory($categoryId);
      $input = $refusedInput($categoryId);
      $path = $archivePath($category);
    ?>
    <section class="admin-card">
      <h2><?= $h(BlogLocalization::categoryLabel($categoryId)) ?></h2>
      <form method="post" action="/api/admin/update-blog-category.php" class="admin-product-form admin-product-form--wide">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $categoryId ?>">

        <?php admin_localized_bar($editingLanguage); ?>
        <?= admin_localized_input($editingLanguage) ?>
        <div class="admin-form-row">
          <label><?= admin_te('common.name') ?><?= $isDefaultLanguage ? '*' : '' ?>
            <input type="text" name="name" maxlength="<?= BlogLocalization::CATEGORY_NAME_MAX_LENGTH ?>"<?= admin_localized_required($editingLanguage) ?> value="<?= $h($input !== null ? (string) ($input['name'] ?? '') : BlogLocalization::rawCategory($categoryId, BlogLocalization::NAME, $editingLanguage)) ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
          </label>
        </div>

        <div class="admin-form-row admin-form-row--split">
          <?php /* Not `required`: only the default language must have an
                   address. A translation without one simply has no public
                   archive URL yet, and the endpoint makes one from the name
                   when this field is left blank. */ ?>
          <label><?= admin_te('blog.url_slug') ?><?= $isDefaultLanguage ? '*' : '' ?>
            <input type="text" name="slug" maxlength="<?= BlogSlug::MAX_LENGTH ?>"<?= admin_localized_required($editingLanguage) ?> value="<?= $h($slugValue($category)) ?>">
          </label>
          <label><?= admin_te('common.order') ?>
            <input type="number" name="sort_order" value="<?= (int) ($input !== null ? ($input['sort_order'] ?? 0) : $category['sort_order']) ?>" step="10">
          </label>
        </div>
<?php if ($path === null): ?>
        <p class="admin-text-muted"><?= admin_te('blog.category_url_none_in_language') ?></p>
<?php endif; ?>

        <div class="admin-form-row">
          <label><?= admin_te('blog.korte_omschrijving') ?>
            <textarea name="description" rows="2" maxlength="<?= BlogLocalization::CATEGORY_DESCRIPTION_MAX_LENGTH ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?>><?= $h($input !== null ? (string) ($input['description'] ?? '') : BlogLocalization::rawCategory($categoryId, BlogLocalization::DESCRIPTION, $editingLanguage)) ?></textarea>
          </label>
        </div>
        <p class="admin-text-muted"><?= admin_te('blog.omschrijving_staat_boven_categorie') ?></p>

        <?php /* Hidden companion field, the same reason the noindex switch
                 has one: an unticked checkbox sends nothing at all. */ ?>
        <input type="hidden" name="is_active" value="0">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= ($input !== null ? !empty($input['is_active']) : (int) $category['is_active'] === 1) ? 'checked' : '' ?>>
          <?= admin_te('common.active') ?>
        </label>
        <p class="admin-text-muted"><?= admin_te('blog.uit_betekent_archief_categorie') ?></p>

        <div>
          <button type="submit"><?= admin_te('blog.categorie_opslaan') ?></button>
<?php if ($path !== null): ?>
          <a href="<?= $h($path) ?>" class="admin-btn-text" target="_blank" rel="noopener"><?= admin_te('blog.bekijk_archief') ?> &#8594;</a>
<?php endif; ?>
        </div>
      </form>

      <p class="admin-text-muted"><?= $postCount === 1 ? 'Eén bericht staat in deze categorie.' : $postCount . ' berichten staan in deze categorie.' ?></p>
      <form method="post" action="/api/admin/delete-blog-category.php" class="admin-inline-form" onsubmit="return confirm('Deze categorie verwijderen? De berichten erin blijven bestaan en raken alleen deze categorie kwijt.');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $categoryId ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('blog.categorie_verwijderen') ?></button>
      </form>
    </section>
  <?php endforeach; ?>

  <section class="admin-card">
    <h2><?= admin_te('blog.nieuwe_categorie') ?></h2>
    <?php /* A NEW category is written in the DEFAULT language, like a new page
             and every new item since phase 3B: its slug is generated from that
             name. Translating it happens on its own card afterwards. */ ?>
    <form method="post" action="/api/admin/create-blog-category.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <?= admin_localized_input(admin_localized_default()) ?>
      <label><?= admin_te('common.name') ?>*
        <input type="text" name="name" maxlength="<?= BlogLocalization::CATEGORY_NAME_MAX_LENGTH ?>" required placeholder="Bijvoorbeeld: Achter de schermen">
      </label>
      <label><?= admin_te('blog.url_slug_2') ?>
        <input type="text" name="slug" maxlength="<?= BlogSlug::MAX_LENGTH ?>" placeholder="Leeg = automatisch uit de naam">
      </label>
      <button type="submit"><?= admin_te('blog.categorie_aanmaken') ?></button>
    </form>
    <?php admin_localized_new_item_note($editingLanguage); ?>
  </section>
</main>
</body>
</html>
