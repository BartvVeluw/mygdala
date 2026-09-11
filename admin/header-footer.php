<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\HeaderCta;
use App\Service\PageContent;
use App\Service\RouteRegistry;
use App\Service\SiteSettings;
use App\Service\SocialProfiles;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

/**
 * The parts of the shared header and footer that are CONTENT rather than
 * layout: the header's one call-to-action button, the footer's closing line,
 * and the site's social profiles. Everything else about the header and the
 * footer is either structure (owned by Core) or already has its own screen —
 * the menu under Navigatie, the columns and the company block under Footer,
 * the logo under Site-instellingen, the colours under Vormgeving.
 *
 * ONE form, one Opslaan. Three separate forms would each have to resubmit
 * the others' checkboxes to avoid silently switching them back on (see
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

$linkType = in_array($value('header_cta_link_type'), HeaderCta::LINK_TYPES, true)
    ? $value('header_cta_link_type')
    : 'page';

/**
 * The pages a CTA may point at: published ones, plus whatever it currently
 * points at even if that has since gone to Concept — editing this screen
 * must never silently drop a target the editor cannot see. Exactly the rule
 * admin/navigation-item.php uses for menu items.
 */
$currentTargetPageId = (int) ($value('header_cta_target_page_id') !== '' ? $value('header_cta_target_page_id') : 0);
$linkablePages = array_values(array_filter(
    (new PageRepository())->findAllForAdmin(),
    static fn (array $p): bool => PageContent::isPublished($p) || (int) $p['id'] === $currentTargetPageId
));

// Only routes that exist right now — a switched-off module contributes none,
// so its routes cannot be chosen as a new target here.
$routes = RouteRegistry::all();

// A target that WAS chosen and no longer resolves is not deleted and not
// corrected behind the editor's back; it is reported. HeaderCta reads the
// stored settings, so ask it before the form overwrites them in this
// request's memory — which it cannot, since nothing is saved on a GET.
$ctaWarning = $old === null ? HeaderCta::adminWarning() : null;
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

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($ctaWarning !== null): ?>
    <p class="admin-alert admin-alert--warning"><?= $h($ctaWarning) ?></p>
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
      <h2><?= admin_te('headerfooter.knop_header') ?></h2>
      <p class="admin-text-muted"><?= admin_te('headerfooter.enige_knop_rechts_header') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="header_cta_enabled" value="1" <?= $checked('header_cta_enabled') ? 'checked' : '' ?>>
        <?= admin_te('headerfooter.knop_tonen_header') ?>
      </label>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('headerfooter.tekst') ?>
          <input type="text" name="header_cta_label_nl" maxlength="100" value="<?= $h($value('header_cta_label_nl')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('headerfooter.tekst_2') ?>
          <input type="text" name="header_cta_label_en" maxlength="100" value="<?= $h($value('header_cta_label_en')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('headerfooter.laat_engelse_tekst_leeg') ?></p>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('headerfooter.bestemming') ?>
          <select name="header_cta_link_type" id="cta-link-type">
            <option value="page" <?= $linkType === 'page' ? 'selected' : '' ?>><?= admin_te('headerfooter.cms_pagina') ?></option>
            <option value="route" <?= $linkType === 'route' ? 'selected' : '' ?>><?= admin_te('headerfooter.applicatieroute') ?></option>
            <option value="external" <?= $linkType === 'external' ? 'selected' : '' ?>><?= admin_te('headerfooter.externe_url') ?></option>
          </select>
        </label>
        <label class="admin-checkbox-label" style="align-self:flex-end;">
          <input type="checkbox" name="header_cta_open_in_new_tab" value="1" <?= $checked('header_cta_open_in_new_tab') ? 'checked' : '' ?>>
          <?= admin_te('headerfooter.open_nieuw_tabblad') ?>
        </label>
      </div>

      <label data-cta-link-field="page"><?= admin_te('headerfooter.cms_pagina_2') ?>
        <select name="header_cta_target_page_id">
          <option value=""><?= admin_te('headerfooter.kies_pagina') ?></option>
          <?php foreach ($linkablePages as $page): ?>
            <option value="<?= (int) $page['id'] ?>" <?= $value('header_cta_target_page_id') === (string) $page['id'] ? 'selected' : '' ?>><?= $h((string) $page['title']) ?><?= PageContent::isPublished($page) ? '' : ' (concept)' ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-cta-link-field="route"><?= admin_te('headerfooter.applicatieroute_2') ?>
        <select name="header_cta_target_route">
          <option value=""><?= admin_te('headerfooter.kies_route') ?></option>
          <?php foreach ($routes as $key => $route): ?>
            <option value="<?= $h($key) ?>" <?= $value('header_cta_target_route') === $key ? 'selected' : '' ?>><?= $h($route['label_nl']) ?> (<?= $h($route['url']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-cta-link-field="external"><?= admin_te('headerfooter.externe_url_2') ?>
        <input type="text" name="header_cta_external_url" maxlength="2048" value="<?= $h($value('header_cta_external_url')) ?>" placeholder="https://... of /pad">
      </label>

      <script>
        (function () {
          var select = document.getElementById('cta-link-type');
          if (!select) return;
          function sync() {
            document.querySelectorAll('[data-cta-link-field]').forEach(function (field) {
              field.hidden = field.getAttribute('data-cta-link-field') !== select.value;
            });
          }
          select.addEventListener('change', sync);
          sync();
        })();
      </script>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('headerfooter.slotregel_footer') ?></h2>
      <p class="admin-text-muted"><?= admin_te('headerfooter.laatste_regel_onderin_naast') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="footer_slogan_enabled" value="1" <?= $checked('footer_slogan_enabled') ? 'checked' : '' ?>>
        <?= admin_te('headerfooter.slotregel_tonen') ?>
      </label>

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
      <h2><?= admin_te('headerfooter.social_media') ?></h2>
      <p class="admin-text-muted"><?= admin_t('headerfooter.vul_wat_hebt_laat') ?></p>

      <div class="admin-form-row admin-form-row--split">
        <?php foreach (SocialProfiles::networks() as $definition): ?>
          <label><?= $h($definition['label']) ?>
            <input type="url" name="<?= $h($definition['key']) ?>" maxlength="2048" value="<?= $h($value($definition['key'])) ?>" placeholder="https://">
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php admin_lang_script(); ?>
</body>
</html>
