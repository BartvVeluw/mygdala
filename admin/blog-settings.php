<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogUrls;
use App\Service\Csrf;

/**
 * Beheer → Bloginstellingen: the handful of decisions that are genuinely
 * editorial, and no more.
 *
 * WHAT IS NOT HERE, on purpose: colours, fonts, layouts, card shapes,
 * templates. How the blog LOOKS follows the public Theme like every other
 * page (THEMING.md); a site that changes its accent colour gets a blog that
 * matches without touching this screen. Building a second appearance editor
 * for one module is exactly the kind of thing that makes a module expensive
 * (BLOG.md).
 *
 * One form to one endpoint, and every field it reads is on it — so a save
 * from this screen can never blank a setting that was not shown.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('blog.manage');

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$flash = $_SESSION['admin_blog_settings_flash'] ?? null;
$errors = $_SESSION['admin_blog_settings_errors'] ?? [];
unset($_SESSION['admin_blog_settings_flash'], $_SESSION['admin_blog_settings_errors']);

$stored = BlogSettings::all();
$value = static fn (string $key, string $default = ''): string => (string) ($stored[$key] ?? $default);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('blog.bloginstellingen_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1><?= admin_te('blog.bloginstellingen') ?></h1>
  </div>
  <p class="admin-text-muted"><?= admin_t('blog.hoe_blog_zich_voorstelt') ?></p>

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

  <form method="post" action="/api/admin/update-blog-settings.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

    <section class="admin-card">
      <h2><?= admin_te('blog.kop_blog') ?></h2>
      <?php admin_lang_tabs(); ?>
      <?php admin_lang_pane_start('nl'); ?>
        <div class="admin-form-row">
          <label><?= admin_te('common.title') ?>
            <input type="text" name="<?= BlogSettings::TITLE ?>" maxlength="<?= BlogSettings::MAX_TITLE_LENGTH ?>" value="<?= $h($value(BlogSettings::TITLE, BlogSettings::DEFAULT_TITLE)) ?>" placeholder="<?= $h(BlogSettings::DEFAULT_TITLE) ?>">
          </label>
        </div>
        <div class="admin-form-row">
          <label><?= admin_te('blog.introtekst') ?>
            <textarea name="<?= BlogSettings::INTRO ?>" rows="3" maxlength="<?= BlogSettings::MAX_INTRO_LENGTH ?>"><?= $h($value(BlogSettings::INTRO)) ?></textarea>
          </label>
        </div>
      <?php admin_lang_pane_end(); ?>
      <?php admin_lang_pane_start('en'); ?>
        <div class="admin-form-row">
          <label><?= admin_te('common.title') ?>
            <input type="text" name="<?= BlogSettings::TITLE_EN ?>" maxlength="<?= BlogSettings::MAX_TITLE_LENGTH ?>" value="<?= $h($value(BlogSettings::TITLE_EN)) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </label>
        </div>
        <div class="admin-form-row">
          <label><?= admin_te('blog.introtekst_2') ?>
            <textarea name="<?= BlogSettings::INTRO_EN ?>" rows="3" maxlength="<?= BlogSettings::MAX_INTRO_LENGTH ?>"<?= admin_lang_placeholder_attr('en') ?>><?= $h($value(BlogSettings::INTRO_EN)) ?></textarea>
          </label>
        </div>
      <?php admin_lang_pane_end(); ?>
      <p class="admin-text-muted"><?= admin_te('blog.introtekst_staat_onder_titel') ?> <a href="<?= $h(BlogUrls::indexPath()) ?>" target="_blank" rel="noopener"><?= $h(BlogUrls::indexPath()) ?></a> <?= admin_te('blog.tegelijk_meta_description_pagina') ?></p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('blog.overzicht') ?></h2>
      <div class="admin-form-row">
        <label><?= admin_te('blog.berichten_per_pagina') ?>
          <input type="number" name="<?= BlogSettings::POSTS_PER_PAGE ?>" min="<?= BlogSettings::MIN_POSTS_PER_PAGE ?>" max="<?= BlogSettings::MAX_POSTS_PER_PAGE ?>" value="<?= (int) BlogSettings::postsPerPage() ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_t('blog.tussen_rest_komt_volgende', ['v1' => BlogSettings::MIN_POSTS_PER_PAGE, 'v2' => BlogSettings::MAX_POSTS_PER_PAGE]) ?></p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('blog.wat_er_onder_bericht') ?></h2>

      <?php /* Each switch has a hidden companion field before it: an
               unticked checkbox sends nothing at all, so without one these
               could be switched on but never off. */ ?>
      <input type="hidden" name="<?= BlogSettings::SHOW_DATE ?>" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="<?= BlogSettings::SHOW_DATE ?>" value="1" <?= BlogSettings::showDate() ? 'checked' : '' ?>>
        <?= admin_te('blog.publicatiedatum_tonen') ?>
      </label>

      <input type="hidden" name="<?= BlogSettings::SHOW_AUTHOR ?>" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="<?= BlogSettings::SHOW_AUTHOR ?>" value="1" <?= BlogSettings::showAuthor() ? 'checked' : '' ?>>
        <?= admin_te('blog.auteur_tonen_er_ingevuld') ?>
      </label>

      <input type="hidden" name="<?= BlogSettings::RELATED_POSTS ?>" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="<?= BlogSettings::RELATED_POSTS ?>" value="1" <?= BlogSettings::relatedPostsEnabled() ? 'checked' : '' ?>>
        <?= admin_te('blog.gerelateerde_berichten_tonen') ?>
      </label>
      <p class="admin-text-muted"><?= admin_t('blog.maximaal_berichten_dezelfde_categorie', ['v1' => BlogSettings::RELATED_POSTS_LIMIT]) ?></p>

      <input type="hidden" name="<?= BlogSettings::RSS_ENABLED ?>" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="<?= BlogSettings::RSS_ENABLED ?>" value="1" <?= BlogSettings::rssEnabled() ? 'checked' : '' ?>>
        <?= admin_te('blog.rss_feed_aanbieden') ?>
      </label>
      <p class="admin-text-muted"><?= admin_te('blog.feed_staat') ?> <a href="<?= $h(BlogUrls::feedPath()) ?>" target="_blank" rel="noopener"><?= $h(BlogUrls::feedPath()) ?></a> <?= admin_t('blog.bevat_alleen_gepubliceerde_berichten') ?></p>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('blog.bloginstellingen_opslaan') ?></button>
    </section>
  </form>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
