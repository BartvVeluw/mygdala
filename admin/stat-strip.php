<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\StatStripContent;
use App\Repository\StatStripRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = StatStripContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StatStripRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Onbekende sectie.');
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => (string) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['title'],
        'section_label' => \App\Service\SectionRegistry::label('stat_strip'),
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];

$repository = new StatStripRepository();

$strip = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($strip === null) {
    // First time this section is opened in the admin: create the row now
    // so stats can be attached to it.
    $repository->upsertStrip($pageSlug, $sectionKeyPart, ['is_active' => true]);
    $strip = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$stripId = (int) $strip['id'];
$items = $repository->findItemsByStripId($stripId);

$errors = $_SESSION['admin_stat_strip_errors'] ?? [];
unset($_SESSION['admin_stat_strip_errors']);

$itemErrors = $_SESSION['admin_stat_strip_item_errors'] ?? [];
unset($_SESSION['admin_stat_strip_item_errors']);

$saved = isset($_GET['saved']);

$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="<?= htmlspecialchars(\App\Service\PageContent::builderUrl($pageSlug), ENT_QUOTES, 'UTF-8') ?>">&larr; <?= htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8') ?></a></p>
  <h1><?= htmlspecialchars($section['section_label'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="admin-text-muted">Sectie op <strong><?= htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8') ?></strong>. Wijzigingen zijn direct zichtbaar op de pagina.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($itemErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($itemErrors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <h2>Zichtbaarheid</h2>
    <p class="admin-text-muted">Deze sectie heeft geen eigen titel/introtekst — alleen de stats hieronder zijn zichtbaar.</p>
    <form method="post" action="/api/admin/update-stat-strip.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section" value="<?= htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8') ?>">

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= (bool) $strip['is_active'] ? 'checked' : '' ?>>
        Actief (uitgevinkt = deze sectie — alle stats — wordt niet getoond op de pagina)
      </label>

      <button type="submit">Opslaan</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Stats</h2>

    <?php if ($items === []): ?>
      <p class="admin-text-muted">Nog geen stats in deze sectie.</p>
    <?php endif; ?>

    <?php foreach ($items as $index => $item): ?>
      <?php
        $itemId = (int) $item['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($items) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-stat-strip-item.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">

          <?php admin_lang_tabs(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label>Primaire tekst*
              <input type="text" name="primary_text_nl" maxlength="100" <?= admin_lang_required('nl') ?> value="<?= htmlspecialchars((string) $item['primary_text_nl'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label>Primaire tekst
              <input type="text" name="primary_text_en" maxlength="100" value="<?= htmlspecialchars((string) ($item['primary_text_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label>Secundaire tekst*
              <input type="text" name="secondary_text_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= htmlspecialchars((string) $item['secondary_text_nl'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label>Secundaire tekst
              <input type="text" name="secondary_text_en" maxlength="150" value="<?= htmlspecialchars((string) ($item['secondary_text_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= (int) $item['is_active'] === 1 ? 'checked' : '' ?>>
            Zichtbaar
          </label>

          <button type="submit">Opslaan</button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-stat-strip-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr; Omhoog</button>
          </form>
          <form method="post" action="/api/admin/move-stat-strip-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr; Omlaag</button>
          </form>
          <form method="post" action="/api/admin/delete-stat-strip-item.php" class="admin-inline-form" onsubmit="return confirm('Deze stat definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2>Nieuwe stat toevoegen</h2>
    <form method="post" action="/api/admin/create-stat-strip-item.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="strip_id" value="<?= $stripId ?>">

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Primaire tekst*
          <input type="text" name="primary_text_nl" maxlength="100" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Primaire tekst
          <input type="text" name="primary_text_en" maxlength="100"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Secundaire tekst*
          <input type="text" name="secondary_text_nl" maxlength="150" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Secundaire tekst
          <input type="text" name="secondary_text_en" maxlength="150"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit">Stat toevoegen</button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
