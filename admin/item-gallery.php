<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use App\Repository\CollectionRepository;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;

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
    exit(admin_t('screen.onbekende_sectie'));
}

$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);

// The gallery shares its table with blocks built on it, such as a module's
// Projecten. Each row is edited by the editor of the block that placed it
// (page_sections.section_type), so this screen can never turn another block
// into a gallery of something else.
if ((new PageSectionRepository())->findBySectionTypeAndId('item_gallery', (int) $section['id']) === null) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$errors = $_SESSION['admin_item_gallery_errors'] ?? [];
$old = $_SESSION['admin_item_gallery_old'] ?? null;
unset($_SESSION['admin_item_gallery_errors'], $_SESSION['admin_item_gallery_old']);

$saved = isset($_GET['saved']);
$editLanguage = admin_localized_language();

// What is the same in every language: handed back, else stored.
$values = $old ?? [
    'source_type' => (string) $section['source_type'],
    'portfolio_scope' => (string) $section['portfolio_scope'],
    'collection_id' => $section['collection_id'] === null ? '' : (string) $section['collection_id'],
    'max_items' => $section['max_items'] === null ? '' : (string) $section['max_items'],
    'show_filter_bar' => (bool) $section['show_filter_bar'],
    'enable_lightbox' => (bool) $section['enable_lightbox'],
    'fallback_link_url' => (string) ($section['fallback_link_url'] ?? ''),
    'button_url' => (string) ($section['button_url'] ?? ''),
    'background' => (string) $section['background'],
    'tight_top' => (bool) $section['tight_top'],
    'is_active' => (bool) $section['is_active'],
];

$sectionId = (int) $section['id'];
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/**
 * The words of one field on screen (Multilingual 2.0, admin/_localized_fields.php):
 * typed and handed back in this language, else stored in it, without the
 * default language's words in an empty translation.
 */
$word = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('item_galleries', $sectionId, $field, $editLanguage);
};
$placeholder = admin_localized_placeholder_attr($editLanguage);

/**
 * Which sources this deployment offers, and whether any of them needs a
 * collection or a scope picked. All of it comes from
 * App\Service\ItemGallerySources, so a module that is switched off takes its
 * source — and the picker belonging to it — off this form without this file
 * naming the module.
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
$needsScopePicker = false;
foreach ($availableSources as $source) {
    $needsCollectionPicker = $needsCollectionPicker || (bool) $source['needs_collection'];
    $needsScopePicker = $needsScopePicker || (bool) ($source['needs_scope'] ?? false);
}

$collections = [];
if ($needsCollectionPicker) {
    try {
        $collections = (new CollectionRepository())->findAll();

        // Every option's collection name in one query rather than one per
        // option. A collection is named per website language since
        // Multilingual 2.0 phase 5 wave C, and the picker uses the one name
        // the CMS calls it by.
        ShopLocalization::preloadCollections(array_map(
            static fn (array $collection): int => (int) $collection['id'],
            $collections
        ));
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
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('item_gallery')) ?> <?= admin_t('block_gallery.admin', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_gallery.text', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('item_gallery')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_gallery.galerij_kiest_hier_w', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>

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

  <p class="admin-text-muted"><?= admin_t('block_gallery.galerij_toont_moment_item', ['v1' => (int) $itemCount]) ?></p>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-item-gallery.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <h2><?= admin_te('block_gallery.inhoudsbron') ?></h2>
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('block_gallery.toon') ?>
          <select name="source_type">
            <?php foreach ($availableSources as $sourceKey => $source): ?>
            <option value="<?= $h($sourceKey) ?>" <?= ($values['source_type'] ?? '') === $sourceKey ? 'selected' : '' ?>><?= $h((string) $source['label']) ?></option>
            <?php endforeach; ?>
            <?php if ($storedSourceUnavailable): ?>
            <option value="<?= $h($storedSource) ?>" selected><?= $h(ItemGallerySources::label($storedSource)) ?> <?= admin_t('block_gallery.beschikbaar') ?></option>
            <?php endif; ?>
          </select>
        </label>
        <label><?= admin_te('block_gallery.maximum_aantal_items') ?>
          <input type="number" name="max_items" min="1" max="200" value="<?= $h((string) ($values['max_items'] ?? '')) ?>" placeholder="Leeg = alles">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php if ($needsScopePicker): ?>
        <label><?= admin_te('block_gallery.portfolio_items_welke') ?>
          <select name="portfolio_scope">
            <?php foreach (ItemGalleryContent::PORTFOLIO_SCOPES as $scopeKey => $scope): ?>
            <option value="<?= $h($scopeKey) ?>" <?= ($values['portfolio_scope'] ?? '') === $scopeKey ? 'selected' : '' ?>><?= $h($scope['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php else: ?>
          <?php /* No source that reads the scope is on: keep the stored value, so saving the rest cannot change it. */ ?>
          <input type="hidden" name="portfolio_scope" value="<?= $h(ItemGalleryContent::isPortfolioScope((string) ($values['portfolio_scope'] ?? '')) ? (string) $values['portfolio_scope'] : ItemGalleryContent::SCOPE_ALL) ?>">
        <?php endif; ?>
        <?php if ($needsCollectionPicker): ?>
        <label><?= admin_te('block_gallery.collectie_welke') ?>
          <select name="collection_id">
            <option value=""><?= admin_te('block_gallery.kies_collectie') ?></option>
            <?php foreach ($collections as $collection): ?>
            <option value="<?= (int) $collection['id'] ?>" <?= (string) ($values['collection_id'] ?? '') === (string) $collection['id'] ? 'selected' : '' ?>><?= $h(ShopLocalization::collectionName((int) $collection['id'])) ?><?= (bool) $collection['is_active'] ? '' : ' (concept)' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php else: ?>
          <input type="hidden" name="collection_id" value="<?= $h((string) ($values['collection_id'] ?? '')) ?>">
        <?php endif; ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_gallery.elke_bron_heeft_eigen') ?></p>
      <?php if ($storedSourceUnavailable): ?>
      <p class="admin-alert admin-alert--warning"><?= admin_te('block_gallery.ingestelde_inhoudsbron_hoort_onderdeel') ?></p>
      <?php endif; ?>

      <h2 style="margin-top:2rem;"><?= admin_te('block_gallery.weergave') ?></h2>
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="show_filter_bar" value="1" <?= ($values['show_filter_bar'] ?? false) ? 'checked' : '' ?>>
        <?= admin_te('block_gallery.filterbalk_tonen_alleen_portfolio') ?>
      </label>
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="enable_lightbox" value="1" <?= ($values['enable_lightbox'] ?? false) ? 'checked' : '' ?>>
        <?= admin_te('block_gallery.lightbox_klik_kaart_zonder') ?>
      </label>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('block_gallery.achtergrond') ?>
          <select name="background">
            <?php foreach (ItemGalleryContent::BACKGROUNDS as $backgroundKey => $background): ?>
            <option value="<?= $h($backgroundKey) ?>" <?= ($values['background'] ?? '') === $backgroundKey ? 'selected' : '' ?>><?= $h($background['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= admin_te('block_gallery.kaarten_zonder_eigen_pagina') ?>
          <input type="text" name="fallback_link_url" maxlength="255" value="<?= $h((string) ($values['fallback_link_url'] ?? '')) ?>" placeholder="Leeg = geen link">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_gallery.laat_link_leeg_kaarten') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="tight_top" value="1" <?= ($values['tight_top'] ?? false) ? 'checked' : '' ?>>
        <?= admin_te('block_gallery.sluit_sectie_erboven_ruimte') ?>
      </label>

      <h2 style="margin-top:2rem;"><?= admin_te('block_gallery.kop_optioneel') ?></h2>
      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_gallery.bovenkop') ?>
          <input type="text" name="eyebrow" maxlength="255" value="<?= $h($word('eyebrow')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title" maxlength="255" value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_gallery.introtekst') ?>
          <textarea name="lead" maxlength="600" rows="3"<?= $placeholder ?>><?= $h($word('lead')) ?></textarea>
        </label>
      </div>

      <h2 style="margin-top:2rem;"><?= admin_te('block_gallery.onder_galerij_optioneel') ?></h2>
      <div class="admin-form-row">
        <label><?= admin_te('block_gallery.slottekst') ?>
          <textarea name="footer_note" maxlength="600" rows="3"<?= $placeholder ?>><?= $h($word('footer_note')) ?></textarea>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_gallery.knoplabel') ?>
          <input type="text" name="button_label" maxlength="150" value="<?= $h($word('button_label')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('block_gallery.knop_url') ?>
          <input type="text" name="button_url" maxlength="255" value="<?= $h((string) ($values['button_url'] ?? '')) ?>" placeholder="Bijvoorbeeld /portfolio.php">
        </label>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('block_gallery.actief_uitgevinkt_sectie_getoond') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
