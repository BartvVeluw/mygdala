<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogSlug;
use App\Service\Blog\BlogUrls;
use App\Service\Csrf;

/**
 * Beheer → Blogtags: rename or remove the tags that exist.
 *
 * THERE IS NO "ADD A TAG" FORM HERE, and that is the point of the design.
 * Tags are created where they are used — an editor types them on a post and
 * the ones that are new are created, the ones that already exist are reused
 * (App\Service\Blog\BlogPostService::resolveTagIds()). A tag created here
 * with no post on it would be a taxonomy row that means nothing.
 *
 * What this screen is for is the two things that cannot be done from a post:
 * correcting a name, and removing a tag everywhere at once. Deleting one
 * removes its link rows and nothing else — no post is touched beyond losing
 * that tag — which is what "safe deletion" means for a taxonomy that carries
 * no content of its own.
 *
 * Each row is its own form to its own endpoint, exactly like the categories
 * screen, so saving one tag cannot blank another.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('blog.manage');

try {
    $tags = (new BlogTagRepository())->all();
    $posts = new BlogPostRepository();
    $loadFailed = false;
} catch (\Throwable $e) {
    error_log('[admin/blog-tags.php] ' . $e->getMessage());
    $tags = [];
    $posts = null;
    $loadFailed = true;
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// The website language this screen's names are in, and the names of every
// tag it is about to print — one query rather than one per row. The list
// itself is sorted on what the CMS calls a tag, because a tag has no sort
// order of its own and its name is no longer a column to ORDER BY.
$editingLanguage = admin_localized_language();
BlogLocalization::preloadTags(array_map(static fn (array $tag): int => (int) $tag['id'], $tags));
usort($tags, static fn (array $a, array $b): int => strnatcasecmp(
    BlogLocalization::tagLabel((int) $a['id']),
    BlogLocalization::tagLabel((int) $b['id'])
));

$flash = $_SESSION['admin_blog_taxonomy_flash'] ?? null;
$errors = $_SESSION['admin_blog_taxonomy_errors'] ?? [];
unset($_SESSION['admin_blog_taxonomy_flash'], $_SESSION['admin_blog_taxonomy_errors']);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('blog.blogtags_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1><?= admin_te('blog.blogtags') ?></h1>
  </div>
  <p class="admin-text-muted"><?= admin_t('blog.tags_maak_bericht_zelf', ['v1' => $h(BlogUrls::ROOT), 'v2' => $h(BlogUrls::TAG_SEGMENT)]) ?></p>

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
    <p class="admin-alert admin-alert--error"><?= admin_te('blog.tags_konden_geladen') ?></p>
  <?php endif; ?>

  <?php if (!$loadFailed && $tags === []): ?>
    <p><?= admin_te('blog.er_tags_ze_verschijnen') ?></p>
  <?php else: ?>
    <?php /* One indicator for the whole table rather than one per row: every
             row carries the same field in the same language, and the language
             is the CMS-wide editing language anyway. */ ?>
    <?php admin_localized_bar($editingLanguage); ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th><?= admin_te('common.name') ?></th>
          <th><?= admin_te('blog.slug') ?></th>
          <th><?= admin_te('blog.berichten') ?></th>
          <th></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tags as $tag): ?>
          <?php
            $tagId = (int) $tag['id'];
            $postCount = $posts === null ? 0 : $posts->countByTag($tagId);
          ?>
          <tr>
            <td>
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>" form="tag-form-<?= $tagId ?>">
              <input type="hidden" name="id" value="<?= $tagId ?>" form="tag-form-<?= $tagId ?>">
              <?php /* ONE name, in the language this screen is editing
                       (Multilingual 2.0 phase 5 wave B). The hidden
                       language_code rides along on the row's own form, so
                       saving a tag writes exactly that language and leaves
                       every other name of the same tag standing. */ ?>
              <?= admin_localized_input($editingLanguage, 'tag-form-' . $tagId) ?>
              <input type="text" name="name" maxlength="<?= BlogLocalization::TAG_NAME_MAX_LENGTH ?>"<?= admin_localized_required($editingLanguage) ?> value="<?= $h(BlogLocalization::rawTagName($tagId, $editingLanguage)) ?>" form="tag-form-<?= $tagId ?>"<?= admin_localized_placeholder_attr($editingLanguage) ?>>
            </td>
            <td><input type="text" name="slug" maxlength="<?= BlogSlug::MAX_LENGTH ?>" required value="<?= $h((string) $tag['slug']) ?>" form="tag-form-<?= $tagId ?>"></td>
            <td>
              <?php if ($postCount > 0): ?>
                <a href="<?= $h(BlogUrls::tagPath((string) $tag['slug'])) ?>" target="_blank" rel="noopener"><?= $postCount ?></a>
              <?php else: ?>
                <span class="admin-text-muted">0</span>
              <?php endif; ?>
            </td>
            <td><button type="submit" class="admin-btn-text" form="tag-form-<?= $tagId ?>"><?= admin_te('common.save') ?></button></td>
            <td>
              <form method="post" action="/api/admin/delete-blog-tag.php" class="admin-inline-form" onsubmit="return confirm('Deze tag verwijderen? De berichten blijven bestaan en raken alleen deze tag kwijt.');">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $tagId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php /* One <form> per row, OUTSIDE the table: a form element may not be
             a child of <tr>, but a control anywhere in the document may name
             the form it belongs to. Each row's inputs carry form="tag-form-N",
             so every row still posts its own complete set of fields to its own
             endpoint — one row's save can never blank another's. */ ?>
    <?php foreach ($tags as $tag): ?>
      <form method="post" action="/api/admin/update-blog-tag.php" id="tag-form-<?= (int) $tag['id'] ?>"></form>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
</body>
</html>
