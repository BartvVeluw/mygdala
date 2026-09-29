<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageThemes\PageThemeService;
use App\Service\Theme\ThemeFonts;

/**
 * Paginathema's: every page theme, with its colours, its fonts and how many
 * pages use it, and what can be done to it — edit, duplicate, delete. The
 * overview of App\Module\PageThemesModule (THEMING.md, "Paginathema's").
 *
 * Behind page_themes.manage, which nobody holds while the module is off
 * (App\Service\AdminPermissions): so this screen, the editor and every
 * endpoint refuse without a module check of their own (MODULES.md,
 * "Guards").
 *
 * Deleting a theme that a page still uses is refused by
 * api/admin/delete-page-theme.php; the refusal comes back here and lists
 * those pages, each with a link to its editor.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('page_themes.manage');

$themes = PageThemeService::allWithUsage();
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$notice = is_string($_GET['done'] ?? null) ? $_GET['done'] : '';
$refusal = $_SESSION['admin_page_themes_refusal'] ?? null;
$flashError = $_SESSION['admin_page_themes_error'] ?? null;
unset($_SESSION['admin_page_themes_refusal'], $_SESSION['admin_page_themes_error']);

/** The five colours, in the editor's order, for the swatches. */
$swatchKeys = ['background_color', 'surface_color', 'text_color', 'primary_color', 'on_primary_color'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('pagethemes.title_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_te('pagethemes.title') ?></h1>
      <p class="admin-page-head__desc"><?= admin_te('pagethemes.intro') ?></p>
    </div>
    <a href="/admin/page-theme.php" class="admin-btn-link"><?= admin_te('pagethemes.new') ?></a>
  </header>

  <?= admin_info_panel(admin_t('help.pagethemes.overview')) ?>

  <?php if (in_array($notice, ['created', 'saved', 'duplicated', 'deleted'], true)): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('pagethemes.done_' . $notice) ?></p>
  <?php endif; ?>

  <?php if (is_string($flashError)): ?>
    <p class="admin-alert admin-alert--error"><?= $h($flashError) ?></p>
  <?php endif; ?>

  <?php if (is_array($refusal)): ?>
    <div class="admin-alert admin-alert--error" data-page-theme-refusal>
      <p><?= admin_te('pagethemes.delete_refused', ['name' => (string) ($refusal['name'] ?? '')]) ?></p>
      <ul class="admin-error-list">
        <?php foreach ((array) ($refusal['pages'] ?? []) as $usingPage): ?>
          <li><a href="/admin/page.php?id=<?= (int) $usingPage['id'] ?>"><?= $h((string) $usingPage['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <?php if ($themes === []): ?>
      <p class="admin-text-muted" data-page-themes-empty><?= admin_te('pagethemes.empty') ?></p>
    <?php else: ?>
      <div class="admin-page-sections">
        <?php foreach ($themes as $theme): ?>
          <?php
            $themeId = (int) $theme['id'];
            $usage = (int) $theme['usage'];
            $pairing = ThemeFonts::pairing((string) $theme['font_pairing']);
          ?>
          <div class="admin-section-row" data-page-theme-row="<?= $themeId ?>">
            <div class="admin-section-row__body">
              <p class="admin-section-row__name">
                <span class="admin-page-theme-swatches" aria-hidden="true">
                  <?php foreach ($swatchKeys as $swatchKey): ?>
                    <?php $swatch = \App\Service\Theme\ThemeColor::normalise((string) ($theme[$swatchKey] ?? '')); ?>
                    <?php if ($swatch !== null): ?>
                      <span class="admin-page-theme-swatch" style="background:<?= $h($swatch) ?>;"></span>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </span>
                <?= $h((string) $theme['name']) ?>
              </p>
              <p class="admin-section-row__note">
                <?= $h(admin_registry_label('themefont.' . (string) $theme['font_pairing'], (string) $pairing['label'])) ?>
                &middot;
                <?= $usage === 0 ? admin_te('pagethemes.usage_none') : admin_te($usage === 1 ? 'pagethemes.usage_one' : 'pagethemes.usage_many', ['count' => $usage]) ?>
              </p>
            </div>
            <div class="admin-section-row__actions">
              <a href="/admin/page-theme.php?id=<?= $themeId ?>" class="admin-section-row__edit"><?= admin_te('common.edit') ?> &#8594;</a>
              <form method="post" action="/api/admin/duplicate-page-theme.php" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $themeId ?>">
                <button type="submit" class="admin-btn-text"><?= admin_te('pagethemes.duplicate') ?></button>
              </form>
              <form method="post" action="/api/admin/delete-page-theme.php" class="admin-inline-form"<?= admin_confirm_attributes(
                  admin_t('pagethemes.delete_confirm_title'),
                  admin_t($usage > 0 ? 'pagethemes.delete_confirm_used' : 'pagethemes.delete_confirm', ['name' => (string) $theme['name'], 'count' => $usage]),
                  admin_t('common.delete')
              ) ?>>
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $themeId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
</body>
</html>
