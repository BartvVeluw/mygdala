<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

require_once __DIR__ . '/_media_picker.php';

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionKey = (string) ($_GET['section'] ?? '');
$section = TextImageSplitContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // query string beyond that.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new TextImageSplitRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Onbekende sectie.');
    }
    $section = [
        'page_slug' => $dynPageSlug,
        'section_key' => $dynSectionKey,
        'page_label' => (string) (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug)['title'],
        'section_label' => \App\Service\SectionRegistry::label('text_image_split'),
    ];
}

$pageSlug = $section['page_slug'];
$sectionKeyPart = $section['section_key'];

$repository = new TextImageSplitRepository();

$split = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
if ($split === null) {
    // First time this section is opened in the admin: create the row now
    // (seeded with its known defaults) so paragraphs/images can be attached.
    $repository->upsertSection($pageSlug, $sectionKeyPart, TextImageSplitContent::defaultsForSection($pageSlug, $sectionKeyPart) + ['is_active' => true]);
    $split = $repository->findBySlugAndKey($pageSlug, $sectionKeyPart);
}

$splitId = (int) $split['id'];
$paragraphs = $repository->findParagraphsBySectionId($splitId);
$images = $repository->findImagesBySectionId($splitId);

$errors = $_SESSION['admin_tis_errors'] ?? [];
$old = $_SESSION['admin_tis_old'] ?? null;
unset($_SESSION['admin_tis_errors'], $_SESSION['admin_tis_old']);

$paragraphErrors = $_SESSION['admin_tis_paragraph_errors'] ?? [];
unset($_SESSION['admin_tis_paragraph_errors']);

$imageErrors = $_SESSION['admin_tis_image_errors'] ?? [];
unset($_SESSION['admin_tis_image_errors']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $sectionValues = $old;
} else {
    $sectionValues = [
        'layout' => (string) ($split['layout'] ?? 'image_right'),
        'eyebrow_nl' => (string) ($split['eyebrow_nl'] ?? ''),
        'eyebrow_en' => (string) ($split['eyebrow_en'] ?? ''),
        'title_nl' => (string) ($split['title_nl'] ?? ''),
        'title_en' => (string) ($split['title_en'] ?? ''),
        'button_label_nl' => (string) ($split['button_label_nl'] ?? ''),
        'button_label_en' => (string) ($split['button_label_en'] ?? ''),
        'button_url' => (string) ($split['button_url'] ?? ''),
        'is_active' => (bool) $split['is_active'],
    ];
}

$csrfToken = Csrf::token();

/**
 * @param array<string, mixed> $values
 */
function tisValue(array $values, string $key): string
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

  <?php if ($paragraphErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($paragraphErrors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($imageErrors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($imageErrors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <h2>Sectie</h2>
    <form method="post" action="/api/admin/update-text-image-split-section.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section" value="<?= htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row">
        <label>Afbeelding-positie
          <select name="layout">
            <option value="image_right" <?= $sectionValues['layout'] === 'image_right' ? 'selected' : '' ?>>Afbeelding rechts, tekst links</option>
            <option value="image_left" <?= $sectionValues['layout'] === 'image_left' ? 'selected' : '' ?>>Afbeelding links, tekst rechts</option>
          </select>
        </label>
      </div>

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Eyebrow
          <input type="text" name="eyebrow_nl" maxlength="150" value="<?= tisValue($sectionValues, 'eyebrow_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Eyebrow
          <input type="text" name="eyebrow_en" maxlength="150" value="<?= tisValue($sectionValues, 'eyebrow_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Titel / H2
          <input type="text" name="title_nl" maxlength="255" value="<?= tisValue($sectionValues, 'title_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Titel / H2
          <input type="text" name="title_en" maxlength="255" value="<?= tisValue($sectionValues, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <p class="admin-text-muted">Als er geen titel is ingevuld, krijgt de eerste alinea automatisch de grotere "lead"-stijl (zoals bij het huidige introblok).</p>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Knoptekst
          <input type="text" name="button_label_nl" maxlength="150" value="<?= tisValue($sectionValues, 'button_label_nl') ?>" placeholder="Optioneel">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Knoptekst
          <input type="text" name="button_label_en" maxlength="150" value="<?= tisValue($sectionValues, 'button_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row">
        <label>Knop-URL
          <input type="text" name="button_url" maxlength="255" value="<?= tisValue($sectionValues, 'button_url') ?>" placeholder="Bijv. contact.php — leeg = geen knop">
        </label>
      </div>
      <p class="admin-text-muted">Knoptekst en URL horen bij elkaar: is er maar één van de twee ingevuld, dan wordt er geen knop getoond.</p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($sectionValues['is_active'] ?? true) ? 'checked' : '' ?>>
        Actief (uitgevinkt = deze hele sectie wordt niet getoond op de pagina)
      </label>

      <button type="submit">Opslaan</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Alinea's</h2>

    <?php if ($paragraphs === []): ?>
      <p class="admin-text-muted">Nog geen alinea's in deze sectie.</p>
    <?php endif; ?>

    <?php foreach ($paragraphs as $index => $paragraph): ?>
      <?php
        $paragraphId = (int) $paragraph['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($paragraphs) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-text-image-split-paragraph.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="paragraph_id" value="<?= $paragraphId ?>">

          <?php admin_lang_tabs(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label>Tekst*
              <textarea name="content_nl" maxlength="1000" rows="3" <?= admin_lang_required('nl') ?>><?= htmlspecialchars((string) $paragraph['content_nl'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label>Tekst
              <textarea name="content_en" maxlength="1000" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= htmlspecialchars((string) ($paragraph['content_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <button type="submit">Opslaan</button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-text-image-split-paragraph.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="paragraph_id" value="<?= $paragraphId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr; Omhoog</button>
          </form>
          <form method="post" action="/api/admin/move-text-image-split-paragraph.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="paragraph_id" value="<?= $paragraphId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr; Omlaag</button>
          </form>
          <form method="post" action="/api/admin/delete-text-image-split-paragraph.php" class="admin-inline-form" onsubmit="return confirm('Deze alinea definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="paragraph_id" value="<?= $paragraphId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2>Nieuwe alinea toevoegen</h2>
    <form method="post" action="/api/admin/create-text-image-split-paragraph.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section_id" value="<?= $splitId ?>">

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Tekst*
          <textarea name="content_nl" maxlength="1000" rows="3" <?= admin_lang_required('nl') ?>></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Tekst
          <textarea name="content_en" maxlength="1000" rows="3"<?= admin_lang_placeholder_attr('en') ?>></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit">Alinea toevoegen</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Afbeeldingen</h2>
    <p class="admin-text-muted">1 afbeelding toont een enkele grote foto, 2 afbeeldingen tonen een mini-galerij naast elkaar.</p>

    <?php if ($images === []): ?>
      <p class="admin-text-muted">Nog geen afbeeldingen in deze sectie.</p>
    <?php endif; ?>

    <?php foreach ($images as $index => $image): ?>
      <?php
        $imageId = (int) $image['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($images) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-text-image-split-image.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="image_id" value="<?= $imageId ?>">

          <div class="admin-form-row">
            <?php media_picker_field('media_id', MediaService::find((int) ($image['media_id'] ?? 0)), 'Afbeelding', 'Kies dezelfde afbeelding gerust op meerdere plekken — hij wordt maar één keer opgeslagen.', false); ?>
          </div>

          <?php admin_lang_tabs(); ?>
          <div class="admin-form-row admin-form-row--split">
            <?php admin_lang_pane_start('nl'); ?>
            <label>Alt-tekst
              <input type="text" name="alt_nl" maxlength="255" value="<?= htmlspecialchars((string) ($image['alt_nl'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
            </label>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
            <label>Alt-tekst
              <input type="text" name="alt_en" maxlength="255" value="<?= htmlspecialchars((string) ($image['alt_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
            <?php admin_lang_pane_end(); ?>
          </div>

          <button type="submit">Opslaan</button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-text-image-split-image.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr; Omhoog</button>
          </form>
          <form method="post" action="/api/admin/move-text-image-split-image.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr; Omlaag</button>
          </form>
          <form method="post" action="/api/admin/delete-text-image-split-image.php" class="admin-inline-form" onsubmit="return confirm('Deze afbeelding definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2>Nieuwe afbeelding toevoegen</h2>
    <form method="post" action="/api/admin/create-text-image-split-image.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section_id" value="<?= $splitId ?>">

      <div class="admin-form-row">
        <?php media_picker_field('media_id', null, 'Afbeelding*', 'Kies er een uit de bibliotheek, of upload een nieuwe in het venster dat opent.', false); ?>
      </div>

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Alt-tekst
          <input type="text" name="alt_nl" maxlength="255" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Alt-tekst
          <input type="text" name="alt_en" maxlength="255"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <button type="submit">Afbeelding toevoegen</button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
