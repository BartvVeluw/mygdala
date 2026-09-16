<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SiteSettings;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

/**
 * The footer's closing line: the part of the shared footer that is CONTENT
 * rather than layout and that does not have a place on the Footer screen yet.
 * The social profiles left this screen in Footer phase B: they are
 * footer_social_links rows now (App\Service\SocialProfiles), and the seven
 * social_*_url settings it used to write are legacy. Everything else is
 * either structure (owned by Core) or has its own screen — the menu and the header buttons under
 * Header & navigatie, the columns and the company block under Footer, the
 * logo under Site-instellingen, the colours under Vormgeving.
 *
 * THE HEADER BUTTON MOVED. Until Navigation phase A this screen also held
 * the header's one call-to-action button. Header buttons are navigation
 * items now (App\Service\NavigationPresentation), managed on
 * admin/navigation.php; the old header_cta_* settings stay in the database
 * but nothing reads or writes them. The info panel on this screen says where
 * the button went, for an editor who comes looking for it here. Folding
 * what is left into the Footer screen is Footer phase B (HEADER-FOOTER.md).
 *
 * ONE form, one Opslaan. Two separate forms would each have to resubmit the
 * other's checkbox to avoid silently switching it back off (see
 * api/admin/update-header-footer-settings.php), and there is not enough here
 * to be worth that.
 */

$errors = $_SESSION['admin_header_footer_errors'] ?? [];
$old = $_SESSION['admin_header_footer_old'] ?? null;
unset($_SESSION['admin_header_footer_errors'], $_SESSION['admin_header_footer_old']);

$saved = isset($_GET['saved']);

$values = $old ?? SiteSettings::all();

$value = static fn (string $key): string => (string) ($values[$key] ?? '');
$checked = static fn (string $key): bool => ($values[$key] ?? '') === '1';

$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('headerfooter.header_footer_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_t('headerfooter.header_footer') ?></h1>
      <p class="admin-page-head__desc"><?= admin_te('headerfooter.knop_header_slotregel_footer') ?></p>
    </div>
  </header>

  <?= admin_info_panel(admin_t('help.header_footer.buttons_moved')) ?>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
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

  <form method="post" action="/api/admin/update-header-footer-settings.php" class="admin-product-form">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

    <section class="admin-card">
      <h2><?= admin_te('headerfooter.slotregel_footer') ?></h2>
      <p class="admin-text-muted"><?= admin_te('headerfooter.laatste_regel_onderin_naast') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="footer_slogan_enabled" value="1" <?= $checked('footer_slogan_enabled') ? 'checked' : '' ?>>
        <?= admin_te('headerfooter.slotregel_tonen') ?>
      </label>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('headerfooter.slotregel') ?>
          <input type="text" name="footer_slogan_nl" maxlength="200" value="<?= $h($value('footer_slogan_nl')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('headerfooter.slotregel_2') ?>
          <input type="text" name="footer_slogan_en" maxlength="200" value="<?= $h($value('footer_slogan_en')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('headerfooter.laat_engelse_tekst_leeg_2') ?></p>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php admin_lang_script(); ?>
</body>
</html>
