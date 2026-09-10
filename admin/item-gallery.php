<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;
use App\Service\SectionRegistry;
use App\Repository\CollectionRepository;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;

/**
 * Editor for one Portfolio-/collectiegalerij block
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/card-carousel.php,
 * admin/contact-card.php, ...). A page may carry several of these; each is
 * edited here under its own key, with its own source and its own display
 * settings.
 *
 * The ITEMS themselves are not edited here: portfolio items stay in
 * Portfolio and products stay in Collecties/Producten. This screen only says
 * which of them this block shows, and how.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ItemGalleryRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Onbekende sectie.');
}

$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);

$errors = $_SESSION['admin_item_gallery_errors'] ?? [];
$old = $_SESSION['admin_item_gallery_old'] ?? null;
unset($_SESSION['admin_item_gallery_errors'], $_SESSION['admin_item_gallery_old']);

$saved = isset($_GET['saved']);

$values = $old ?? [
    'source_type' => (string) $section['source_type'],
    'portfolio_scope' => (string) $section['portfolio_scope'],
    'collection_id' => $section['collection_id'] === null ? '' : (string) $section['collection_id'],
    'max_items' => $section['max_items'] === null ? '' : (string) $section['max_items'],
    'show_filter_bar' => (bool) $section['show_filter_bar'],
    'enable_lightbox' => (bool) $section['enable_lightbox'],
    'fallback_link_url' => (string) ($section['fallback_link_url'] ?? ''),
    'eyebrow_nl' => (string) ($section['eyebrow_nl'] ?? ''),
    'eyebrow_en' => (string) ($section['eyebrow_en'] ?? ''),
    'title_nl' => (string) ($section['title_nl'] ?? ''),
    'title_en' => (string) ($section['title_en'] ?? ''),
    'lead_nl' => (string) ($section['lead_nl'] ?? ''),
    'lead_en' => (string) ($section['lead_en'] ?? ''),
    'footer_note_nl' => (string) ($section['footer_note_nl'] ?? ''),
    'footer_note_en' => (string) ($section['footer_note_en'] ?? ''),
    'button_label_nl' => (string) ($section['button_label_nl'] ?? ''),
    'button_label_en' => (string) ($section['button_label_en'] ?? ''),
    'button_url' => (string) ($section['button_url'] ?? ''),
    'background' => (string) $section['background'],
    'tight_top' => (bool) $section['tight_top'],
    'is_active' => (bool) $section['is_active'],
];

/**
 * Which sources this deployment offers, and whether any of them needs a
 * collection picked. Both come from App\Service\ItemGallerySources, so a
 * module that is switched off takes its source — and the picker belonging to
 * it — off this form without this file naming the module.
 *
 * A block already SET to a source that is no longer available keeps it: the
 * option is rendered, marked, and preselected, so saving the rest of the form
 * cannot silently rewrite the block's source to something else. The public
 * page renders that block empty in the meantime (ItemGallerySources::items()).
 */
$availableSources = ItemGallerySources::available();
$storedSource = (string) $section['source_type'];
$storedSourceUnavailable = $storedSource !== '' && !isset($availableSources[$storedSource]);

$needsCollectionPicker = false;
foreach ($availableSources as $source) {
    $needsCollectionPicker = $needsCollectionPicker || (bool) $source['needs_collection'];
}

$collections = [];
if ($needsCollectionPicker) {
    try {
        $collections = (new CollectionRepository())->findAll();
    } catch (\Throwable $e) {
        error_log('[admin/item-gallery.php] collection list failed: ' . $e->getMessage());
        $collections = [];
    }
}

// How many items this block would render right now — the one number that
// tells the owner whether the source they picked actually has content,
// without them having to open the public page.
$preview = ItemGalleryContent::mapRow($section);
$itemCount = count($preview['items']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('item_gallery')) ?> — <?= $h((string) $page['title']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>">&larr; <?= $h((string) $page['title']) ?></a></p>
  <h1><?= $h(SectionRegistry::label('item_gallery')) ?></h1>
  <p class="admin-text-muted">Een galerij op <strong><?= $h((string) $page['title']) ?></strong>. Je kiest hier wát er getoond wordt en hoe; de items zelf beheer je via <a href="/admin/portfolio.php">Portfolio</a> of <a href="/admin/collections.php">Collecties</a>.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
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

  <p class="admin-text-muted">Deze galerij toont op dit moment <strong><?= (int) $itemCount ?></strong> item(s).</p>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-item-gallery.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <h2>Inhoudsbron</h2>
      <div class="admin-form-row admin-form-row--split">
        <label>Toon
          <select name="source_type">
            <?php foreach ($availableSources as $sourceKey => $source): ?>
            <option value="<?= $h($sourceKey) ?>" <?= ($values['source_type'] ?? '') === $sourceKey ? 'selected' : '' ?>><?= $h((string) $source['label']) ?></option>
            <?php endforeach; ?>
            <?php if ($storedSourceUnavailable): ?>
            <option value="<?= $h($storedSource) ?>" selected><?= $h(ItemGallerySources::label($storedSource)) ?> &mdash; niet beschikbaar</option>
            <?php endif; ?>
          </select>
        </label>
        <label>Maximum aantal items
          <input type="number" name="max_items" min="1" max="200" value="<?= $h((string) ($values['max_items'] ?? '')) ?>" placeholder="Leeg = alles">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Bij portfolio-items: welke
          <select name="portfolio_scope">
            <?php foreach (ItemGalleryContent::PORTFOLIO_SCOPES as $scopeKey => $scope): ?>
            <option value="<?= $h($scopeKey) ?>" <?= ($values['portfolio_scope'] ?? '') === $scopeKey ? 'selected' : '' ?>><?= $h($scope['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($needsCollectionPicker): ?>
        <label>Bij een collectie: welke
          <select name="collection_id">
            <option value="">— Kies een collectie —</option>
            <?php foreach ($collections as $collection): ?>
            <option value="<?= (int) $collection['id'] ?>" <?= (string) ($values['collection_id'] ?? '') === (string) $collection['id'] ? 'selected' : '' ?>><?= $h((string) $collection['name']) ?><?= (bool) $collection['is_active'] ? '' : ' (concept)' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php else: ?>
          <input type="hidden" name="collection_id" value="<?= $h((string) ($values['collection_id'] ?? '')) ?>">
        <?php endif; ?>
      </div>
      <p class="admin-text-muted">Elke bron heeft zijn eigen instelling hierboven; alleen die van de gekozen bron doet iets. Een collectie op concept toont niets.</p>
      <?php if ($storedSourceUnavailable): ?>
      <p class="admin-alert admin-alert--warning">De ingestelde inhoudsbron hoort bij een onderdeel dat op dit moment uit staat. De instellingen van dit blok blijven bewaard, maar er wordt niets getoond zolang dat onderdeel uit staat.</p>
      <?php endif; ?>

      <h2 style="margin-top:2rem;">Weergave</h2>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="show_filter_bar" value="1" <?= ($values['show_filter_bar'] ?? false) ? 'checked' : '' ?>>
        Filterbalk tonen (alleen bij portfolio-items — een collectie heeft geen categorieën)
      </label>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="enable_lightbox" value="1" <?= ($values['enable_lightbox'] ?? false) ? 'checked' : '' ?>>
        Lightbox: klik op een kaart zonder eigen pagina vergroot de foto
      </label>

      <div class="admin-form-row admin-form-row--split">
        <label>Achtergrond
          <select name="background">
            <?php foreach (ItemGalleryContent::BACKGROUNDS as $backgroundKey => $background): ?>
            <option value="<?= $h($backgroundKey) ?>" <?= ($values['background'] ?? '') === $backgroundKey ? 'selected' : '' ?>><?= $h($background['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Kaarten zonder eigen pagina linken naar
          <input type="text" name="fallback_link_url" maxlength="255" value="<?= $h((string) ($values['fallback_link_url'] ?? '')) ?>" placeholder="Leeg = geen link">
        </label>
      </div>
      <p class="admin-text-muted">Laat de link leeg om die kaarten niet aanklikbaar te maken; alleen dán kan de lightbox ze vergroten.</p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="tight_top" value="1" <?= ($values['tight_top'] ?? false) ? 'checked' : '' ?>>
        Sluit aan op de sectie erboven (geen ruimte aan de bovenkant)
      </label>

      <h2 style="margin-top:2rem;">Kop (optioneel)</h2>
      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Bovenkop
          <input type="text" name="eyebrow_nl" maxlength="255" value="<?= $h((string) ($values['eyebrow_nl'] ?? '')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Bovenkop
          <input type="text" name="eyebrow_en" maxlength="255" value="<?= $h((string) ($values['eyebrow_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Titel
          <input type="text" name="title_nl" maxlength="255" value="<?= $h((string) ($values['title_nl'] ?? '')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Titel
          <input type="text" name="title_en" maxlength="255" value="<?= $h((string) ($values['title_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Introtekst
          <textarea name="lead_nl" maxlength="600" rows="3"><?= $h((string) ($values['lead_nl'] ?? '')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Introtekst
          <textarea name="lead_en" maxlength="600" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= $h((string) ($values['lead_en'] ?? '')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <h2 style="margin-top:2rem;">Onder de galerij (optioneel)</h2>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Slottekst
          <textarea name="footer_note_nl" maxlength="600" rows="3"><?= $h((string) ($values['footer_note_nl'] ?? '')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Slottekst
          <textarea name="footer_note_en" maxlength="600" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= $h((string) ($values['footer_note_en'] ?? '')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Knoplabel
          <input type="text" name="button_label_nl" maxlength="150" value="<?= $h((string) ($values['button_label_nl'] ?? '')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Knoplabel
          <input type="text" name="button_label_en" maxlength="150" value="<?= $h((string) ($values['button_label_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row">
        <label>Knop-URL
          <input type="text" name="button_url" maxlength="255" value="<?= $h((string) ($values['button_url'] ?? '')) ?>" placeholder="Bijvoorbeeld /portfolio.php">
        </label>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        Actief (uitgevinkt = deze sectie wordt niet getoond op de pagina)
      </label>

      <button type="submit">Opslaan</button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
