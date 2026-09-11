<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\StepListContent;
use App\Repository\StepListRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = StepListContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StepListRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Onbekende sectie.');
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => (string) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['title'],
        'section_label' => \App\Service\SectionRegistry::label('step_list'),
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];

$repository = new StepListRepository();

$stepListSection = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($stepListSection === null) {
    // First time this section is opened in the admin: create the row now
    // (seeded with its known defaults) so steps can be attached to it.
    $repository->upsertSection($pageSlug, $sectionKeyPart, StepListContent::defaultsForSection($pageSlug, $sectionKeyPart) + ['is_active' => true]);
    $stepListSection = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$sectionId = (int) $stepListSection['id'];
$items = $repository->findItemsBySectionId($sectionId);

$errors = $_SESSION['admin_step_list_errors'] ?? [];
$old = $_SESSION['admin_step_list_old'] ?? null;
unset($_SESSION['admin_step_list_errors'], $_SESSION['admin_step_list_old']);

$itemErrors = $_SESSION['admin_step_list_item_errors'] ?? [];
unset($_SESSION['admin_step_list_item_errors']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $sectionValues = $old;
} else {
    $sectionValues = [
        'eyebrow_nl' => (string) ($stepListSection['eyebrow_nl'] ?? ''),
        'eyebrow_en' => (string) ($stepListSection['eyebrow_en'] ?? ''),
        'title_nl' => (string) ($stepListSection['title_nl'] ?? ''),
        'title_en' => (string) ($stepListSection['title_en'] ?? ''),
        'is_active' => (bool) $stepListSection['is_active'],
    ];
}

$csrfToken = Csrf::token();

/**
 * @param array<string, mixed> $values
 */
function stepListValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
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
  <p class="admin-text-muted">Sectie op <strong><?= htmlspecialchars($section['page_label'], ENT_QUOTES, 'UTF-8') ?></strong>. Wijzigingen zijn direct zichtbaar op de pagina. Stapnummers worden automatisch bepaald op basis van de volgorde hieronder.</p>

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
    <h2>Sectiekop</h2>
    <form method="post" action="/api/admin/update-step-list-section.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section" value="<?= htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8') ?>">

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Eyebrow*
          <input type="text" name="eyebrow_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= stepListValue($sectionValues, 'eyebrow_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Eyebrow
          <input type="text" name="eyebrow_en" maxlength="150" value="<?= stepListValue($sectionValues, 'eyebrow_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Titel / H2*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= stepListValue($sectionValues, 'title_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Titel / H2
          <input type="text" name="title_en" maxlength="255" value="<?= stepListValue($sectionValues, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($sectionValues['is_active'] ?? true) ? 'checked' : '' ?>>
        Actief (uitgevinkt = deze hele sectie — kop en stappen — wordt niet getoond op de pagina)
      </label>

      <button type="submit">Opslaan</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Stappen</h2>

    <?php if ($items === []): ?>
      <p class="admin-text-muted">Nog geen stappen in deze sectie.</p>
    <?php endif; ?>

    <?php foreach ($items as $index => $item): ?>
      <?php
        $itemId = (int) $item['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($items) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <p class="admin-text-muted">Stap <?= $index + 1 ?></p>
        <form method="post" action="/api/admin/update-step-list-item.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="item_id" value="<?= $itemId ?>">

          <?php admin_lang_tabs(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label>Titel*
              <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= htmlspecialchars((string) $item['title_nl'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label>Titel
              <input type="text" name="title_en" maxlength="255" value="<?= htmlspecialchars((string) ($item['title_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label>Omschrijving*
              <textarea name="body_nl" maxlength="1000" rows="3" <?= admin_lang_required('nl') ?>><?= htmlspecialchars((string) $item['body_nl'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label>Omschrijving
              <textarea name="body_en" maxlength="1000" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= htmlspecialchars((string) ($item['body_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
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
          <form method="post" action="/api/admin/move-step-list-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr; Omhoog</button>
          </form>
          <form method="post" action="/api/admin/move-step-list-item.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr; Omlaag</button>
          </form>
          <form method="post" action="/api/admin/delete-step-list-item.php" class="admin-inline-form" onsubmit="return confirm('Deze stap definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2>Nieuwe stap toevoegen</h2>
    <form method="post" action="/api/admin/create-step-list-item.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Titel*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Titel
          <input type="text" name="title_en" maxlength="255"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Omschrijving*
          <textarea name="body_nl" maxlength="1000" rows="3" <?= admin_lang_required('nl') ?>></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Omschrijving
          <textarea name="body_en" maxlength="1000" rows="3"<?= admin_lang_placeholder_attr('en') ?>></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit">Stap toevoegen</button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
