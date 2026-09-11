<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SiteSettings;
use App\Repository\FooterRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$repository = new FooterRepository();
$columns = $repository->findAllColumnsForAdmin();

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$saved = isset($_GET['saved']);
$deleted = isset($_GET['deleted']);
$footerError = $_SESSION['admin_footer_error'] ?? null;
unset($_SESSION['admin_footer_error']);

function footerLinkSummary(array $link): string
{
    return match ($link['link_type']) {
        'page' => 'CMS-pagina',
        'route' => 'Route: ' . (string) $link['target_route'],
        'external' => (string) $link['external_url'],
        'action' => 'Actie: ' . (string) $link['action_key'],
        default => (string) $link['link_type'],
    };
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Footer — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title">Footer</h1>
      <p class="admin-page-head__desc">Beheer de footer-kolommen, links en het bedrijfsblok. Sleep aan <span aria-hidden="true">&#8801;</span> om te herordenen.</p>
    </div>
  </header>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success">Verwijderd.</p>
  <?php endif; ?>
  <?php if ($footerError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= $h($footerError) ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <h2>Bedrijfsblok</h2>
    <p class="admin-text-muted">Logo, naam, e-mail, telefoon en KVK komen uit Site-instellingen — hier bepaal je alleen wát er in de footer getoond wordt, niet de gegevens zelf.</p>
    <form method="post" action="/api/admin/update-footer-settings.php">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <div class="admin-form-row">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="footer_show_logo" value="1" <?= SiteSettings::get('footer_show_logo') === '1' ? 'checked' : '' ?>>
          Toon logo
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="footer_show_company_name" value="1" <?= SiteSettings::get('footer_show_company_name') === '1' ? 'checked' : '' ?>>
          Toon bedrijfsnaam
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="footer_show_email" value="1" <?= SiteSettings::get('footer_show_email') === '1' ? 'checked' : '' ?>>
          Toon e-mailadres
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="footer_show_phone" value="1" <?= SiteSettings::get('footer_show_phone') === '1' ? 'checked' : '' ?>>
          Toon telefoonnummer
        </label>
        <label class="admin-checkbox-label">
          <input type="checkbox" name="footer_show_kvk" value="1" <?= SiteSettings::get('footer_show_kvk') === '1' ? 'checked' : '' ?>>
          Toon KVK-nummer
        </label>
      </div>
      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Beschrijving
          <textarea name="footer_description_nl" maxlength="500" rows="3"><?= $h(SiteSettings::get('footer_description_nl')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Beschrijving
          <textarea name="footer_description_en" maxlength="500" rows="3"><?= $h(SiteSettings::get('footer_description_en')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <label>Copyright-tekst
        <input type="text" name="footer_copyright_template" maxlength="300" value="<?= $h(SiteSettings::get('footer_copyright_template')) ?>">
      </label>
      <p class="admin-text-muted">Ondersteunt <code>{{year}}</code> (huidig jaar) en <code>{{site_name}}</code>. KVK wordt automatisch toegevoegd als "Toon KVK-nummer" aan staat.</p>
      <button type="submit">Opslaan</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Kolommen</h2>
    <div class="admin-page-sections" data-footer-column-zone data-reorder-url="/api/admin/reorder-footer-columns.php" data-csrf-token="<?= $h($csrfToken) ?>">
      <?php if ($columns === []): ?>
        <p class="admin-text-muted">Nog geen footer-kolommen.</p>
      <?php endif; ?>
      <?php foreach ($columns as $column): ?>
        <?php
          $columnId = (int) $column['id'];
          $columnHidden = !(bool) $column['is_visible'];
          $links = $repository->findLinksForColumn($columnId);
        ?>
        <div class="admin-section-row admin-footer-column-row<?= $columnHidden ? ' is-hidden-section' : '' ?>" data-footer-column-id="<?= $columnId ?>">
          <span class="admin-drag-handle" draggable="true" role="button" tabindex="0" aria-label="Sleep om te herordenen">&#8801;</span>
          <div class="admin-section-row__body">
            <p class="admin-section-row__name"><?= $h(admin_lang_summary($column, 'title')) ?></p>
            <?php if ($columnHidden): ?><p class="admin-section-row__note">Verborgen</p><?php endif; ?>
          </div>
          <div class="admin-section-row__actions">
            <a href="/admin/footer-column.php?id=<?= $columnId ?>" class="admin-section-row__edit">Bewerken &#8594;</a>
            <a href="/admin/footer-link.php?column_id=<?= $columnId ?>" class="admin-btn-text">+ Link</a>
            <form method="post" action="/api/admin/toggle-footer-column.php" class="admin-inline-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= $columnId ?>">
              <input type="hidden" name="is_visible" value="<?= $columnHidden ? '1' : '0' ?>">
              <button type="submit" class="admin-btn-text"><?= $columnHidden ? 'Tonen' : 'Verbergen' ?></button>
            </form>
            <form method="post" action="/api/admin/delete-footer-column.php" class="admin-inline-form" onsubmit="return confirm('Deze kolom en al zijn links definitief verwijderen?');">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= $columnId ?>">
              <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
            </form>
          </div>
        </div>

        <div class="admin-nav-children" data-footer-link-zone data-column-id="<?= $columnId ?>" data-reorder-url="/api/admin/reorder-footer-links.php" data-csrf-token="<?= $h($csrfToken) ?>">
          <?php if ($links === []): ?>
            <p class="admin-text-muted admin-nav-children__empty">Nog geen links in deze kolom.</p>
          <?php endif; ?>
          <?php foreach ($links as $link): ?>
            <?php $linkId = (int) $link['id']; $linkHidden = !(bool) $link['is_visible']; ?>
            <div class="admin-section-row admin-footer-link-row<?= $linkHidden ? ' is-hidden-section' : '' ?>" data-footer-link-id="<?= $linkId ?>">
              <span class="admin-drag-handle" draggable="true" role="button" tabindex="0" aria-label="Sleep om te herordenen">&#8801;</span>
              <div class="admin-section-row__body">
                <p class="admin-section-row__name"><?= $h(admin_lang_summary($link, 'label')) ?></p>
                <p class="admin-section-row__note"><?= $h(footerLinkSummary($link)) ?><?= $linkHidden ? ' — verborgen' : '' ?></p>
              </div>
              <div class="admin-section-row__actions">
                <a href="/admin/footer-link.php?id=<?= $linkId ?>" class="admin-section-row__edit">Bewerken &#8594;</a>
                <form method="post" action="/api/admin/toggle-footer-link.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $linkId ?>">
                  <input type="hidden" name="is_visible" value="<?= $linkHidden ? '1' : '0' ?>">
                  <button type="submit" class="admin-btn-text"><?= $linkHidden ? 'Tonen' : 'Verbergen' ?></button>
                </form>
                <form method="post" action="/api/admin/delete-footer-link.php" class="admin-inline-form" onsubmit="return confirm('Deze link verwijderen?');">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $linkId ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" action="/api/admin/create-footer-column.php" class="admin-inline-form admin-add-section-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <?php admin_lang_pane_start('nl'); ?>
        <label class="admin-add-section-form__label">
          <span>Titel</span>
          <input type="text" name="title_nl" maxlength="100"<?= admin_lang_required('nl') ?>>
        </label>
      <?php admin_lang_pane_end(); ?>
      <?php admin_lang_pane_start('en'); ?>
        <label class="admin-add-section-form__label">
          <span>Titel</span>
          <input type="text" name="title_en" maxlength="100"<?= admin_lang_required('en') ?>>
        </label>
      <?php admin_lang_pane_end(); ?>
      <button type="submit" class="admin-btn-secondary">+ Kolom toevoegen</button>
    </form>
  </section>
</main>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
