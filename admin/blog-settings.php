<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bloginstellingen — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1>Bloginstellingen</h1>
  </div>
  <p class="admin-text-muted">Hoe de blog zich voorstelt en wat er onder een bericht staat. De vormgeving zelf komt uit <a href="/admin/theme.php">Vormgeving</a>, net als bij elke andere pagina.</p>

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
      <h2>Kop van de blog</h2>
      <div class="admin-form-row admin-form-row--split">
        <label>Titel (NL)
          <input type="text" name="<?= BlogSettings::TITLE ?>" maxlength="<?= BlogSettings::MAX_TITLE_LENGTH ?>" value="<?= $h($value(BlogSettings::TITLE, BlogSettings::DEFAULT_TITLE)) ?>" placeholder="<?= $h(BlogSettings::DEFAULT_TITLE) ?>">
        </label>
        <label>Titel (EN)
          <input type="text" name="<?= BlogSettings::TITLE_EN ?>" maxlength="<?= BlogSettings::MAX_TITLE_LENGTH ?>" value="<?= $h($value(BlogSettings::TITLE_EN)) ?>" placeholder="Leeg = Nederlandse titel">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Introtekst (NL)
          <textarea name="<?= BlogSettings::INTRO ?>" rows="3" maxlength="<?= BlogSettings::MAX_INTRO_LENGTH ?>"><?= $h($value(BlogSettings::INTRO)) ?></textarea>
        </label>
        <label>Introtekst (EN)
          <textarea name="<?= BlogSettings::INTRO_EN ?>" rows="3" maxlength="<?= BlogSettings::MAX_INTRO_LENGTH ?>" placeholder="Leeg = Nederlandse tekst"><?= $h($value(BlogSettings::INTRO_EN)) ?></textarea>
        </label>
      </div>
      <p class="admin-text-muted">De introtekst staat onder de titel op <a href="<?= $h(BlogUrls::indexPath()) ?>" target="_blank" rel="noopener"><?= $h(BlogUrls::indexPath()) ?></a> en is tegelijk de meta description van die pagina. Laat 'm leeg om alleen de titel te tonen.</p>
    </section>

    <section class="admin-card">
      <h2>Overzicht</h2>
      <div class="admin-form-row">
        <label>Berichten per pagina
          <input type="number" name="<?= BlogSettings::POSTS_PER_PAGE ?>" min="<?= BlogSettings::MIN_POSTS_PER_PAGE ?>" max="<?= BlogSettings::MAX_POSTS_PER_PAGE ?>" value="<?= (int) BlogSettings::postsPerPage() ?>">
        </label>
      </div>
      <p class="admin-text-muted">Tussen <?= BlogSettings::MIN_POSTS_PER_PAGE ?> en <?= BlogSettings::MAX_POSTS_PER_PAGE ?>. De rest komt op volgende pagina's; het overzicht laadt nooit alles tegelijk.</p>
    </section>

    <section class="admin-card">
      <h2>Wat er onder een bericht staat</h2>

      <?php /* Each switch has a hidden companion field before it: an
               unticked checkbox sends nothing at all, so without one these
               could be switched on but never off. */ ?>
      <input type="hidden" name="<?= BlogSettings::SHOW_DATE ?>" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="<?= BlogSettings::SHOW_DATE ?>" value="1" <?= BlogSettings::showDate() ? 'checked' : '' ?>>
        Publicatiedatum tonen
      </label>

      <input type="hidden" name="<?= BlogSettings::SHOW_AUTHOR ?>" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="<?= BlogSettings::SHOW_AUTHOR ?>" value="1" <?= BlogSettings::showAuthor() ? 'checked' : '' ?>>
        Auteur tonen als er een is ingevuld
      </label>

      <input type="hidden" name="<?= BlogSettings::RELATED_POSTS ?>" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="<?= BlogSettings::RELATED_POSTS ?>" value="1" <?= BlogSettings::relatedPostsEnabled() ? 'checked' : '' ?>>
        Gerelateerde berichten tonen
      </label>
      <p class="admin-text-muted">Maximaal <?= BlogSettings::RELATED_POSTS_LIMIT ?> berichten die dezelfde categorie of tag delen, nieuwste eerst. Geen aanbevelingen op basis van gedrag &mdash; puur wat er inhoudelijk bij hoort.</p>

      <input type="hidden" name="<?= BlogSettings::RSS_ENABLED ?>" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="<?= BlogSettings::RSS_ENABLED ?>" value="1" <?= BlogSettings::rssEnabled() ? 'checked' : '' ?>>
        RSS-feed aanbieden
      </label>
      <p class="admin-text-muted">De feed staat op <a href="<?= $h(BlogUrls::feedPath()) ?>" target="_blank" rel="noopener"><?= $h(BlogUrls::feedPath()) ?></a> en bevat alleen gepubliceerde berichten. Uit betekent: die URL geeft een 404 en de verwijzing verdwijnt uit de <code>&lt;head&gt;</code>.</p>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit">Bloginstellingen opslaan</button>
    </section>
  </form>
</main>
</body>
</html>
