<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_save_bar.php';
require __DIR__ . '/_richtext_field.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
use App\Service\Media\MediaService;
use App\Service\SectionRegistry;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;

/**
 * Editor for one "Detailsectie" block instance
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page and its content row
 * really exist" gate as every other repeater section editor
 * (admin/text-image-split.php, admin/faq.php, ...).
 *
 * This screen replaced admin/service-detail.php, which was reachable only
 * through four hardcoded material keys and therefore could never edit a
 * fifth section or the same section on another page.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new DetailSectionRepository();
$pages = new PageRepository();

if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || $pages->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Onbekende sectie.');
}

$page = $pages->findByContentKey($pageSlug);
$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$sectionId = (int) $section['id'];

$points = $repository->findPointsBySectionId($sectionId);
$images = $repository->findImagesBySectionId($sectionId);

$errors = $_SESSION['admin_detail_section_errors'] ?? [];
$old = $_SESSION['admin_detail_section_old'] ?? null;
unset($_SESSION['admin_detail_section_errors'], $_SESSION['admin_detail_section_old']);

$mainImageErrors = $_SESSION['admin_detail_section_main_image_errors'] ?? [];
unset($_SESSION['admin_detail_section_main_image_errors']);

$pointErrors = $_SESSION['admin_detail_section_point_errors'] ?? [];
unset($_SESSION['admin_detail_section_point_errors']);

$imageErrors = $_SESSION['admin_detail_section_image_errors'] ?? [];
unset($_SESSION['admin_detail_section_image_errors']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $values = $old;
} else {
    $values = [
        'anchor' => (string) ($section['anchor'] ?? ''),
        'nav_label_nl' => (string) ($section['nav_label_nl'] ?? ''),
        'nav_label_en' => (string) ($section['nav_label_en'] ?? ''),
        'title_nl' => (string) $section['title_nl'],
        'title_en' => (string) ($section['title_en'] ?? ''),
        'lead_nl' => (string) ($section['lead_nl'] ?? ''),
        'lead_en' => (string) ($section['lead_en'] ?? ''),
        'content_html' => (string) ($section['content_html'] ?? ''),
        'content_html_en' => (string) ($section['content_html_en'] ?? ''),
        'image_position' => (string) ($section['image_position'] ?? 'image_right'),
        'closing_note_nl' => (string) ($section['closing_note_nl'] ?? ''),
        'closing_note_en' => (string) ($section['closing_note_en'] ?? ''),
        'cta_label_nl' => (string) ($section['cta_label_nl'] ?? ''),
        'cta_label_en' => (string) ($section['cta_label_en'] ?? ''),
        'cta_url' => (string) ($section['cta_url'] ?? ''),
        'is_active' => (bool) $section['is_active'],
    ];
}

// "Does this section have a main image at all" — true for a Media Library
// reference and for a legacy path that predates it, which is what decides
// whether the "verwijderen" button is offered.
$mainImagePath = (string) ($section['main_image_path'] ?? '');
$hasMainImage = (int) ($section['main_media_id'] ?? 0) > 0 || $mainImagePath !== '';

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * @param array<string, mixed> $values
 */
function detailValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * @param list<string> $errors
 */
function detailErrorList(array $errors): void
{
    if ($errors === []) {
        return;
    }
    ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('detail_section')) ?> — <?= $h((string) $page['title']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>">&larr; Terug naar <?= $h((string) $page['title']) ?></a></p>
  <h1><?= $h(SectionRegistry::label('detail_section')) ?></h1>
  <p class="admin-text-muted">Sectie op de pagina "<?= $h((string) $page['title']) ?>". Wijzigingen zijn direct zichtbaar op de pagina.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
  <?php endif; ?>

  <?php detailErrorList($errors); ?>
  <?php detailErrorList($mainImageErrors); ?>
  <?php detailErrorList($pointErrors); ?>
  <?php detailErrorList($imageErrors); ?>

  <section class="admin-card">
    <h2>Algemene inhoud</h2>
    <form method="post" action="/api/admin/update-detail-section.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <div class="admin-form-row admin-form-row--split">
        <label>Titel / H2 (NL)*
          <input type="text" name="title_nl" maxlength="255" value="<?= detailValue($values, 'title_nl') ?>" required>
        </label>
        <label>Titel / H2 (EN)
          <input type="text" name="title_en" maxlength="255" value="<?= detailValue($values, 'title_en') ?>" placeholder="Leeg = zelfde als NL">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Lead (NL)
          <textarea name="lead_nl" maxlength="500" rows="2" placeholder="Optioneel"><?= detailValue($values, 'lead_nl') ?></textarea>
        </label>
        <label>Lead (EN)
          <textarea name="lead_en" maxlength="500" rows="2" placeholder="Leeg = zelfde als NL"><?= detailValue($values, 'lead_en') ?></textarea>
        </label>
      </div>

      <?php renderRichTextField('content_html', 'Tekst (NL)', (string) ($values['content_html'] ?? ''), 'full', 'admin-richtext-editor--lg'); ?>
      <?php renderRichTextField('content_html_en', 'Tekst (EN)', (string) ($values['content_html_en'] ?? ''), 'full', 'admin-richtext-editor--lg'); ?>
      <p class="admin-text-muted">Laat de Engelse tekst leeg om de Nederlandse tekst ook in het Engels te tonen.</p>

      <div class="admin-form-row admin-form-row--split">
        <label>Anker (URL-id)
          <input type="text" name="anchor" maxlength="100" value="<?= detailValue($values, 'anchor') ?>" placeholder="Bijv. hout — leeg = geen anker">
        </label>
        <label>Beeldpositie
          <select name="image_position">
            <option value="image_right" <?= ($values['image_position'] ?? 'image_right') === 'image_right' ? 'selected' : '' ?>>Afbeelding rechts</option>
            <option value="image_left" <?= ($values['image_position'] ?? '') === 'image_left' ? 'selected' : '' ?>>Afbeelding links</option>
          </select>
        </label>
      </div>
      <p class="admin-text-muted">Een sectie met een anker is bereikbaar via <code>#anker</code> en verschijnt automatisch in de Snelnavigatie van deze pagina.</p>

      <div class="admin-form-row admin-form-row--split">
        <label>Navigatielabel (NL)
          <input type="text" name="nav_label_nl" maxlength="100" value="<?= detailValue($values, 'nav_label_nl') ?>" placeholder="Leeg = de titel hierboven">
        </label>
        <label>Navigatielabel (EN)
          <input type="text" name="nav_label_en" maxlength="100" value="<?= detailValue($values, 'nav_label_en') ?>" placeholder="Leeg = zelfde als NL">
        </label>
      </div>
      <p class="admin-text-muted">De korte tekst in de Snelnavigatie — meestal korter dan de titel ("Hout" in plaats van "Hout graveren").</p>

      <div class="admin-form-row admin-form-row--split">
        <label>Slotnotitie (NL)
          <textarea name="closing_note_nl" maxlength="1000" rows="2" placeholder="Optioneel"><?= detailValue($values, 'closing_note_nl') ?></textarea>
        </label>
        <label>Slotnotitie (EN)
          <textarea name="closing_note_en" maxlength="1000" rows="2" placeholder="Leeg = zelfde als NL"><?= detailValue($values, 'closing_note_en') ?></textarea>
        </label>
      </div>
      <p class="admin-text-muted">Optionele extra tekst onderaan de sectie. Leeg laten = geen slotnotitie.</p>

      <div class="admin-form-row admin-form-row--split">
        <label>CTA-knoptekst (NL)
          <input type="text" name="cta_label_nl" maxlength="150" value="<?= detailValue($values, 'cta_label_nl') ?>" placeholder="Optioneel">
        </label>
        <label>CTA-knoptekst (EN)
          <input type="text" name="cta_label_en" maxlength="150" value="<?= detailValue($values, 'cta_label_en') ?>" placeholder="Leeg = zelfde als NL">
        </label>
      </div>
      <div class="admin-form-row">
        <label>CTA-knop URL
          <input type="text" name="cta_url" maxlength="255" value="<?= detailValue($values, 'cta_url') ?>" placeholder="Bijv. contact.php — leeg = geen knop">
        </label>
      </div>
      <p class="admin-text-muted">Knoptekst en URL horen bij elkaar: is er maar één van de twee ingevuld, dan wordt er geen knop getoond.</p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        Actief (uitgevinkt = deze hele sectie wordt niet getoond op de pagina)
      </label>

      <button type="submit">Opslaan</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Hoofdafbeelding</h2>
    <p class="admin-text-muted">Optioneel. Staat naast de tekst, aan de kant die je hierboven bij "Beeldpositie" kiest. Zonder hoofdafbeelding blijft de sectie tekst met kenmerken ernaast.</p>

    <form method="post" action="/api/admin/update-detail-section-main-image.php" class="admin-product-form" style="margin-top:0.75rem;">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <div class="admin-form-row">
        <?php media_picker_field('media_id', MediaService::find((int) ($section['main_media_id'] ?? 0)), 'Hoofdafbeelding', 'Kies er een uit de mediabibliotheek, of upload een nieuwe in het venster dat opent.', false); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Alt-tekst (NL)
          <input type="text" name="main_image_alt_nl" maxlength="255" value="<?= $h((string) ($section['main_image_alt_nl'] ?? '')) ?>" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
        </label>
        <label>Alt-tekst (EN)
          <input type="text" name="main_image_alt_en" maxlength="255" value="<?= $h((string) ($section['main_image_alt_en'] ?? '')) ?>" placeholder="Leeg = zelfde als NL">
        </label>
      </div>

      <button type="submit">Opslaan</button>
    </form>

    <?php if ($hasMainImage): ?>
      <form method="post" action="/api/admin/update-detail-section-main-image.php" class="admin-inline-form" style="margin-top:0.75rem;" onsubmit="return confirm('Hoofdafbeelding verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
        <input type="hidden" name="remove_image" value="1">
        <button type="submit" class="admin-btn-text admin-btn-text--danger">Hoofdafbeelding verwijderen</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2>Kenmerken</h2>
    <p class="admin-text-muted">Het vinkje-icoon staat vast en is niet instelbaar.</p>

    <?php if ($points === []): ?>
      <p class="admin-text-muted">Nog geen kenmerken in deze sectie.</p>
    <?php endif; ?>

    <?php foreach ($points as $index => $point): ?>
      <?php
        $pointId = (int) $point['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($points) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-detail-section-point.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="point_id" value="<?= $pointId ?>">

          <div class="admin-form-row admin-form-row--split">
            <label>Titel (NL)*
              <input type="text" name="title_nl" maxlength="255" value="<?= $h((string) $point['title_nl']) ?>" required>
            </label>
            <label>Titel (EN)
              <input type="text" name="title_en" maxlength="255" value="<?= $h((string) ($point['title_en'] ?? '')) ?>" placeholder="Leeg = zelfde als NL">
            </label>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <label>Tekst (NL)*
              <textarea name="body_nl" maxlength="500" rows="2" required><?= $h((string) $point['body_nl']) ?></textarea>
            </label>
            <label>Tekst (EN)
              <textarea name="body_en" maxlength="500" rows="2" placeholder="Leeg = zelfde als NL"><?= $h((string) ($point['body_en'] ?? '')) ?></textarea>
            </label>
          </div>

          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= ((bool) $point['is_active']) ? 'checked' : '' ?>>
            Actief (uitgevinkt = dit kenmerk wordt niet getoond)
          </label>

          <button type="submit">Opslaan</button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-detail-section-point.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="point_id" value="<?= $pointId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr; Omhoog</button>
          </form>
          <form method="post" action="/api/admin/move-detail-section-point.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="point_id" value="<?= $pointId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr; Omlaag</button>
          </form>
          <form method="post" action="/api/admin/delete-detail-section-point.php" class="admin-inline-form" onsubmit="return confirm('Dit kenmerk definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="point_id" value="<?= $pointId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2>Nieuw kenmerk toevoegen</h2>
    <form method="post" action="/api/admin/create-detail-section-point.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">

      <div class="admin-form-row admin-form-row--split">
        <label>Titel (NL)*
          <input type="text" name="title_nl" maxlength="255" required>
        </label>
        <label>Titel (EN)
          <input type="text" name="title_en" maxlength="255" placeholder="Leeg = zelfde als NL">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Tekst (NL)*
          <textarea name="body_nl" maxlength="500" rows="2" required></textarea>
        </label>
        <label>Tekst (EN)
          <textarea name="body_en" maxlength="500" rows="2" placeholder="Leeg = zelfde als NL"></textarea>
        </label>
      </div>

      <button type="submit">Kenmerk toevoegen</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Galerij</h2>
    <p class="admin-text-muted">Optioneel: een rij afbeeldingen onder de sectie. Sommige secties hebben er bewust geen.</p>

    <?php if ($images === []): ?>
      <p class="admin-text-muted">Nog geen afbeeldingen in deze galerij.</p>
    <?php endif; ?>

    <?php foreach ($images as $index => $image): ?>
      <?php
        $imageId = (int) $image['id'];
        $isFirst = $index === 0;
        $isLast = $index === count($images) - 1;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <form method="post" action="/api/admin/update-detail-section-image.php" class="admin-product-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="image_id" value="<?= $imageId ?>">

          <div class="admin-form-row">
            <?php media_picker_field('media_id', MediaService::find((int) ($image['media_id'] ?? 0)), 'Afbeelding', '', false); ?>
          </div>

          <div class="admin-form-row admin-form-row--split">
            <label>Alt-tekst (NL)
              <input type="text" name="alt_nl" maxlength="255" value="<?= $h((string) ($image['alt_nl'] ?? '')) ?>" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
            </label>
            <label>Alt-tekst (EN)
              <input type="text" name="alt_en" maxlength="255" value="<?= $h((string) ($image['alt_en'] ?? '')) ?>" placeholder="Leeg = zelfde als NL">
            </label>
          </div>

          <button type="submit">Opslaan</button>
        </form>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <form method="post" action="/api/admin/move-detail-section-image.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr; Omhoog</button>
          </form>
          <form method="post" action="/api/admin/move-detail-section-image.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr; Omlaag</button>
          </form>
          <form method="post" action="/api/admin/delete-detail-section-image.php" class="admin-inline-form" onsubmit="return confirm('Deze afbeelding definitief verwijderen?');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="image_id" value="<?= $imageId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2>Nieuwe afbeelding toevoegen</h2>
    <form method="post" action="/api/admin/create-detail-section-image.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">

      <div class="admin-form-row">
        <?php media_picker_field('media_id', null, 'Afbeelding*', 'Kies er een uit de bibliotheek, of upload een nieuwe in het venster dat opent.', false); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Alt-tekst (NL)
          <input type="text" name="alt_nl" maxlength="255" placeholder="Leeg = alt-tekst uit de mediabibliotheek">
        </label>
        <label>Alt-tekst (EN)
          <input type="text" name="alt_en" maxlength="255" placeholder="Leeg = zelfde als NL">
        </label>
      </div>

      <button type="submit">Afbeelding toevoegen</button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php media_picker_script(); ?>
<?php save_bar_script(); ?>
</body>
</html>
