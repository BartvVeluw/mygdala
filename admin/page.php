<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_block_picker.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_admin_tabs.php';
require_once __DIR__ . '/_admin_collapse.php';
require_once __DIR__ . '/_language_fields.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;

/**
 * One screen per CMS page: its settings (Title, Slug, Status, SEO title,
 * Meta description) followed by the page builder for its content. Exactly
 * the same screen for a page created five minutes ago and for one of the six
 * pages that used to be hardcoded PHP templates — that is the whole point of
 * the unified page model.
 *
 * THE SCREEN IS THREE TABS (admin/_admin_tabs.php): Inhoud — the blocks that
 * make up the page — plus Pagina and SEO for its settings. Navigation only:
 * every field is exactly where it was, in exactly the same form, posting to
 * the same endpoint. Pagina and SEO are two panels of ONE <form> for that
 * reason — splitting them would turn one save into two partial POSTs, and
 * api/admin/update-page.php reads the whole form. The Verwijderen card is a
 * second Pagina panel because it holds a <form> of its own, which could not
 * be nested inside that one.
 *
 * The page builder half is ONE draggable list of every block attached to
 * this page (edit / hide / delete), each row a <details> that folds down to
 * one identifying line (admin/_admin_collapse.php), with the
 * "+ Contentblok toevoegen"
 * control always directly beneath it — one page, one ordered list. That
 * button opens the visual block picker (admin/_block_picker.php), where one
 * click on a card both chooses and adds; it replaced a dropdown of type
 * names plus a separate confirm button. The fixed blocks
 * a page template used to hardcode between the others are in that same list
 * (App\Service\SectionRegistry, `manual_add = false`); they carry a badge
 * naming the admin domain that owns their content and cannot be added or
 * deleted here. Editing a block still opens that block type's own dedicated
 * editor; there is no generic block form.
 *
 * Two independent locks, deliberately not the same thing:
 *
 *   - a page served at a FIXED URL (isRouteBound()) shows its Slug locked —
 *     its URL is decided by the route it is served from;
 *   - a PROTECTED page (isProtected(): the site root, or a page carrying
 *     application-critical functionality like the storefront) additionally
 *     shows Status locked and has no delete button.
 *
 * Diensten, Portfolio, Over mij and Contact are the ordinary content pages
 * in between: their URL is fixed, but they can be set to Concept and deleted
 * like any other page. Title and the SEO fields are editable everywhere.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($idParam === false || $idParam === null || $idParam < 1) {
    http_response_code(404);
    exit('Pagina niet gevonden.');
}

$pageRepository = new PageRepository();
$page = $pageRepository->findById($idParam);

if ($page === null) {
    http_response_code(404);
    exit('Pagina niet gevonden.');
}

$pageId = (int) $page['id'];
$isProtected = PageContent::isProtected($page);
$hasFixedUrl = PageContent::isRouteBound($page);

$repository = new PageSectionRepository();
$allSections = $repository->findForPage($pageId);
// The definitions themselves rather than just their labels: the block picker
// draws each card from the block's own label, description, category, icon and
// schematic preview, and api/admin/add-page-section.php validates the posted
// type against this exact same list. One answer, two readers.
$availableBlocks = SectionRegistry::availableDefinitionsForPage($page, $repository);
$references = PageService::references($pageId);

$errors = $_SESSION['admin_page_errors'] ?? [];
$old = $_SESSION['admin_page_old'] ?? null;
unset($_SESSION['admin_page_errors'], $_SESSION['admin_page_old']);

$pagesError = $_SESSION['admin_pages_error'] ?? null;
unset($_SESSION['admin_pages_error']);

$created = isset($_GET['created']);
$updated = isset($_GET['updated']);
$deletedSection = isset($_GET['deleted']);

// A block that was just added lands here only when it has no editor of its
// own to open (api/admin/add-page-section.php redirects straight into that
// editor when there is one). Then the least the page can do is point at the
// row that appeared, instead of leaving the editor to spot it.
$addedSectionId = filter_input(INPUT_GET, 'added', FILTER_VALIDATE_INT) ?: 0;

/**
 * Value precedence: freshly re-submitted (invalid) input, then the stored
 * page — same helper shape as the other admin forms in this project.
 */
$fieldValue = static function (string $key) use ($old, $page): string {
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    return (string) ($page[$key] ?? '');
};

$status = $old !== null ? (string) ($old['status'] ?? '') : (string) $page['status'];

// The two SEO fields that are not plain text. Both follow the same
// precedence rule as everything else on this form: a failed save's own input
// wins over what is stored.
$noindexChecked = $old !== null
    ? !empty($old['noindex'])
    : (int) ($page['noindex'] ?? 0) === 1;
$pageSocialImage = trim((string) ($page['og_image_path'] ?? ''));
$pageSocialMedia = MediaService::find((int) ($page['og_media_id'] ?? 0));

// The search-result preview below shows what this page's head will really
// contain, resolved by the same App\Service\PageSeo the public page uses —
// never a second guess at the fallback rules. It is built from the STORED
// row, so it shows what is live, not what is half-typed in the form.
$seoPreview = \App\Service\PageSeo::forPage($page);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * The badge a fixed block wears, per SectionRegistry::kind() — the same
 * visual language the page builder already used, now driven by the one
 * registry instead of a second one alongside it.
 */
$kindMeta = [
    SectionRegistry::KIND_FUNCTIONAL => ['label' => 'Functioneel (thema)', 'badge' => 'admin-badge--theme'],
    SectionRegistry::KIND_DYNAMIC => ['label' => 'Beheerd elders', 'badge' => 'admin-badge--info'],
];

/**
 * Which tab this render insists on. Normally none: the browser reopens the
 * tab this editor last had open on this page. But a rejected save puts its
 * messages above a form that lives on one particular tab, and "Titel is
 * verplicht" above a closed tab helps nobody — so a failed save opens
 * Pagina, and a saved one leaves the choice alone.
 */
$forcedTab = ($errors !== [] || $pagesError !== null) ? 'pagina' : null;
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $page['title']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/pages.php">&larr; Terug naar pagina's</a></p>
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= $h((string) $page['title']) ?></h1>
      <p class="admin-page-head__desc">Beheer de instellingen en de inhoud van deze pagina. Sleep aan <span aria-hidden="true">&#8801;</span> om de volgorde van secties te wijzigen.</p>
    </div>
    <a href="<?= $h(PageContent::publicUrl($page)) ?>" class="admin-btn-secondary" target="_blank" rel="noopener">Bekijk pagina &#8594;</a>
  </header>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Pagina aangemaakt. Voeg hieronder secties toe en publiceer 'm zodra je tevreden bent.</p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Pagina-instellingen opgeslagen.</p>
  <?php endif; ?>
  <?php if ($deletedSection): ?>
    <p class="admin-alert admin-alert--success">Sectie verwijderd.</p>
  <?php endif; ?>
  <?php if ($pagesError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= $h($pagesError) ?></p>
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

  <?php /* The three tabs. Their order on screen is the order of this list —
           Inhoud first, because building the page is what an editor comes
           here for — and has nothing to do with the order the panels are
           written in below, where the settings form comes first simply
           because it always did. */ ?>
  <?php admin_tabs_start('page-editor', [
      'inhoud' => 'Inhoud',
      'pagina' => 'Pagina',
      'seo' => 'SEO',
  ], [
      'scope' => (string) $pageId,
      'label' => 'Onderdelen van deze pagina',
      'force' => $forcedTab,
  ]); ?>

  <?php /* No enctype: the page's social image is a Media Library reference
           now, and uploading happens inside the picker. */ ?>
  <?php /* ONE form over two panels. Pagina and SEO are two views of the same
           save: api/admin/update-page.php reads title, slug, status AND the
           meta fields from one request, so two forms would each blank what
           the other carries. Both panels therefore end in the same
           "Instellingen opslaan", and either one saves both. */ ?>
  <form method="post" action="/api/admin/update-page.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $pageId ?>">

    <?php admin_tab_panel('pagina'); ?>
    <section class="admin-card">
      <h2>Algemeen</h2>
      <div class="admin-form-row admin-form-row--split">
        <label>Titel*
          <input type="text" name="title" maxlength="<?= PageService::MAX_TITLE_LENGTH ?>" required value="<?= $h($fieldValue('title')) ?>">
        </label>
        <label>Slug (URL)<?= $hasFixedUrl ? '' : '*' ?>
          <input type="text" name="slug" maxlength="<?= PageService::MAX_SLUG_LENGTH ?>" value="<?= $h($fieldValue('slug')) ?>" <?= $hasFixedUrl ? 'disabled' : 'required' ?>>
        </label>
      </div>
      <p class="admin-text-muted">
        Live op:
        <a href="<?= $h(PageContent::publicUrl($page)) ?>" target="_blank" rel="noopener"><?= $h(PageContent::publicUrl($page)) ?></a>
        <?php if ($hasFixedUrl): ?>
          &mdash; deze pagina wordt geserveerd op een vaste URL, die ligt daarom vast. Titel, SEO-velden en de inhoud hieronder kun je gewoon aanpassen<?= $isProtected ? '' : ', en de pagina kun je op Concept zetten of verwijderen zoals elke andere contentpagina' ?>.
        <?php endif; ?>
      </p>
      <label><?= admin_te('common.status') ?>
        <select name="status" <?= $isProtected ? 'disabled' : '' ?>>
          <?php foreach (array_keys(PageContent::STATUS_LABELS) as $statusKey): ?>
            <option value="<?= $h($statusKey) ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= admin_te('page.status_' . $statusKey) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($isProtected): ?>
        <p class="admin-text-muted"><?= PageContent::isSiteRoot($page) ? 'De homepage is het startpunt van de website en blijft altijd gepubliceerd.' : 'Deze pagina bevat functionaliteit waar de webshop van afhankelijk is en blijft daarom gepubliceerd.' ?></p>
      <?php else: ?>
        <p class="admin-text-muted">Concept betekent: wel bewerkbaar hier, maar de publieke URL geeft een 404 en links ernaartoe in de navigatie/footer worden verborgen.</p>
      <?php endif; ?>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('page.save_settings') ?></button>
      <p class="admin-text-muted">Slaat alles op wat onder Pagina en SEO staat &mdash; het is één formulier met twee tabbladen.</p>
    </section>
    <?php admin_tab_panel_end(); ?>

    <?php admin_tab_panel('seo'); ?>
    <section class="admin-card">
      <h2>SEO</h2>
      <p class="admin-text-muted">Laat de SEO-titel leeg om automatisch "<em>Titel</em> &mdash; <?= $h(\App\Service\SiteSettings::get('site_name')) ?>" te gebruiken. Vul je 'm wel in, dan is dat exact de tekst in het browsertabblad en in Google.</p>
      <?php /* One pane per language, not one column per language. On a
               single-language site only the site's own language is on
               screen; the other pane is still rendered, still carries its
               stored value and is still submitted, but `hidden` — that is
               what keeps a translation alive through a save after the
               language was switched off (admin/_language_fields.php). */ ?>
      <?php admin_lang_tabs(); ?>
      <div class="admin-product-form admin-product-form--wide">
        <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_title') ?>
              <input type="text" name="meta_title" maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($fieldValue('meta_title')) ?>"<?= admin_lang_placeholder_attr('nl') ?>>
            </label>
          </div>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_description') ?>
              <textarea name="meta_description" rows="3" maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('nl') ?>><?= $h($fieldValue('meta_description')) ?></textarea>
            </label>
          </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_title') ?>
              <input type="text" name="meta_title_en" maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($fieldValue('meta_title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
          </div>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_description') ?>
              <textarea name="meta_description_en" rows="3" maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('en') ?>><?= $h($fieldValue('meta_description_en')) ?></textarea>
            </label>
          </div>
        <?php admin_lang_pane_end(); ?>
      </div>

      <h3 class="admin-seo-lang__title"><?= admin_te('page.google_preview') ?></h3>
      <p class="admin-text-muted">Zo ziet deze pagina er ongeveer uit in een zoekresultaat, met de titel en tekst die nu zijn opgeslagen.</p>
      <div class="admin-seo-preview">
        <div class="admin-seo-preview__url"><?= $h((string) ($seoPreview->canonical ?? PageContent::publicUrl($page))) ?></div>
        <div class="admin-seo-preview__title"><?= $h($seoPreview->titleNl) ?></div>
        <div class="admin-seo-preview__description">
          <?php if ($seoPreview->hasDescription()): ?>
            <?= $h($seoPreview->descriptionNl) ?>
          <?php else: ?>
            <em><?= admin_te('page.no_description') ?></em>
          <?php endif; ?>
        </div>
      </div>

      <h3 class="admin-seo-lang__title"><?= admin_te('page.visibility') ?></h3>
      <?php /* Hidden companion field: an unticked checkbox sends nothing,
               and the save endpoint would then have no way to tell "leave it
               out of the index" from "field not on this form". PHP keeps the
               last value for a repeated name, so ticking the box wins. */ ?>
      <input type="hidden" name="noindex" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="noindex" value="1" <?= $noindexChecked ? 'checked' : '' ?>>
        <?= admin_te('page.noindex') ?>
      </label>
      <p class="admin-text-muted">De pagina blijft gewoon bereikbaar en gepubliceerd; hij krijgt alleen <code>noindex</code> mee en verdwijnt uit de sitemap. Voor een pagina die wel online moet staan maar niet gevonden hoeft te worden &mdash; een bedankpagina bijvoorbeeld.</p>

      <h3 class="admin-seo-lang__title">Deel-afbeelding</h3>
      <?php media_picker_field(
          'og_media_id',
          $pageSocialMedia,
          'Eigen deel-afbeelding (optioneel)',
          'De preview wanneer iemand juist deze pagina deelt. Laat leeg om de Standaard deel-afbeelding uit Instellingen te gebruiken. Liggend, bij voorkeur 1200 x 630 pixels.',
          true
      ); ?>
      <?php if ($pageSocialMedia === null && $pageSocialImage !== ''): ?>
        <p class="admin-text-muted">Huidige waarde (nog niet in de mediabibliotheek): <code><?= $h($pageSocialImage) ?></code></p>
      <?php endif; ?>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('page.save_settings') ?></button>
      <p class="admin-text-muted">Slaat alles op wat onder Pagina en SEO staat &mdash; het is één formulier met twee tabbladen.</p>
    </section>
    <?php admin_tab_panel_end(); ?>
  </form>

  <?php admin_tab_panel('inhoud'); ?>
  <h2>Inhoud</h2>

  <section class="admin-card">
    <?php /* Both roles on one element: the drop zone the reorder script
             already used, and the collapse group whose open rows and return
             target are remembered per page (admin/_admin_collapse.php). */ ?>
    <div class="admin-page-sections" data-page-section-zone data-reorder-url="/api/admin/reorder-page-sections.php" data-csrf-token="<?= $h($csrfToken) ?>" data-page-id="<?= $pageId ?>" data-admin-collapse-group="page-blocks" data-admin-collapse-scope="<?= $pageId ?>">
      <?php if ($allSections === []): ?>
        <p class="admin-text-muted">Nog geen secties op deze pagina.</p>
      <?php endif; ?>
      <?php foreach ($allSections as $pageSection): ?>
        <?php
          $sectionType = (string) $pageSection['section_type'];
          $isHidden = !(bool) $pageSection['is_active'];
          $note = SectionRegistry::note($sectionType);
          $kind = SectionRegistry::kind($sectionType);
          // A row naming a block type this CMS does not currently register.
          // The public page skips it — see SectionRegistry::render() — so
          // without this the editor would see an ordinary-looking row that
          // simply never appears on the site.
          //
          // Two different reasons, and the editor must not confuse them. A
          // block belonging to a module that is switched OFF is expected and
          // fully reversible: turning the module back on restores it exactly
          // as it was. A type nothing declares at all is data that has
          // outlived its code and is worth reporting. Neither is ever
          // deleted, and neither uses the stored type for anything but
          // printing it.
          $isUnsupported = !SectionRegistry::exists($sectionType);
          $disabledModule = $isUnsupported ? SectionRegistry::disabledModuleFor($sectionType) : null;
          $isJustAdded = $addedSectionId === (int) $pageSection['id'];

          // The one line a collapsed row shows. For an ordinary block that
          // is the registry's own instance label — "Tekstblok — Over onze
          // diensten" — so no block type has to invent a summary of its own,
          // and a type without a title simply shows its name.
          if ($disabledModule !== null) {
              $rowLabel = 'Blok van een uitgeschakeld onderdeel';
          } elseif ($isUnsupported) {
              $rowLabel = 'Niet-ondersteund contentblok';
          } else {
              $rowLabel = SectionRegistry::instanceLabel($pageSection);
          }
        ?>
        <?php /* One id per row, and always the same one: #blok-<id> is what
                 api/admin/add-page-section.php sends a new block to, what a
                 link from anywhere else can point at, and what
                 admin-collapse.js scrolls back to after an edit. */ ?>
        <div class="admin-section-row admin-page-section-row<?= $isHidden ? ' is-hidden-section' : '' ?><?= $isJustAdded ? ' is-just-added' : '' ?>" id="blok-<?= (int) $pageSection['id'] ?>" data-page-section-id="<?= (int) $pageSection['id'] ?>">
          <?php /* Outside the <details> on purpose: a collapsed row must
                   still be draggable, and that is most of the reason to
                   collapse rows at all. */ ?>
          <span class="admin-drag-handle" draggable="true" role="button" tabindex="0" aria-label="Sleep om te herordenen">&#8801;</span>
          <?php /* One generic disclosure per block, whatever its type: the
                   browser gives us click, Enter/Space, the tab order and the
                   expanded/collapsed state for free, and no block type has
                   to know it exists (admin/_admin_collapse.php). A block
                   that was just added opens itself. */ ?>
          <details class="admin-collapse" data-admin-collapse-id="<?= (int) $pageSection['id'] ?>"<?= $isJustAdded ? ' open data-admin-collapse-open' : '' ?>>
            <summary class="admin-collapse__summary">
              <span class="admin-collapse__caret" aria-hidden="true"></span>
              <span class="admin-section-row__name admin-collapse__title"><?= $h($rowLabel) ?></span>
              <span class="admin-collapse__badges">
                <?php if ($disabledModule !== null): ?>
                  <span class="admin-badge admin-badge--info">Onderdeel uit</span>
                <?php elseif ($isUnsupported): ?>
                  <span class="admin-badge admin-badge--warning">Niet ondersteund</span>
                <?php endif; ?>
                <?php if ($isHidden): ?>
                  <span class="admin-badge admin-badge--muted">Verborgen</span>
                <?php endif; ?>
                <?php if ($kind !== null && isset($kindMeta[$kind])): ?>
                  <span class="admin-badge <?= $h($kindMeta[$kind]['badge']) ?>"><?= $h(SectionRegistry::badgeLabel($sectionType) ?? $kindMeta[$kind]['label']) ?></span>
                <?php endif; ?>
              </span>
            </summary>
            <div class="admin-collapse__body">
              <div class="admin-section-row__body">
                <?php if ($disabledModule !== null): ?>
                  <p class="admin-section-row__note">Type: <code><?= $h($sectionType) ?></code> &mdash; onderdeel: <?= $h(\App\Module\ModuleRegistry::label($disabledModule)) ?></p>
                  <p class="admin-section-row__note">Dit blok hoort bij een onderdeel dat op dit moment uit staat, en wordt daarom niet op de pagina getoond. De gegevens blijven bewaard: zodra het onderdeel weer aan staat, werkt dit blok weer zoals het was.</p>
                <?php elseif ($isUnsupported): ?>
                  <p class="admin-section-row__note">Type: <code><?= $h($sectionType) ?></code></p>
                  <p class="admin-section-row__note">Dit blok kon niet geladen worden en wordt niet op de pagina getoond. De gegevens zijn bewaard. Meld dit type aan de beheerder van de site.</p>
                <?php elseif ($note !== null): ?>
                  <p class="admin-section-row__note"><?= $h($note) ?></p>
                <?php endif; ?>
                <?php if ($isHidden): ?>
                  <p class="admin-section-row__note">Verborgen &mdash; wordt niet getoond op de pagina.</p>
                <?php endif; ?>
              </div>
              <div class="admin-section-row__actions">
                <?php foreach (SectionRegistry::editLinks($pageSection) as $editLink): ?>
                  <a href="<?= $h($editLink['url']) ?>" class="admin-section-row__edit"><?= $h($editLink['label']) ?> &#8594;</a>
                <?php endforeach; ?>
                <form method="post" action="/api/admin/toggle-page-section.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= (int) $pageSection['id'] ?>">
                  <input type="hidden" name="is_active" value="<?= $isHidden ? '1' : '0' ?>">
                  <button type="submit" class="admin-btn-text"><?= $isHidden ? 'Tonen' : 'Verbergen' ?></button>
                </form>
                <?php if (SectionRegistry::isDeletable($sectionType)): ?>
                <form method="post" action="/api/admin/delete-page-section.php" class="admin-inline-form" onsubmit="return confirm('Deze sectie en de bijbehorende inhoud definitief verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= (int) $pageSection['id'] ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          </details>
        </div>
      <?php endforeach; ?>
    </div>

    <?php /* Always the LAST thing under the block list, so "toevoegen" adds
             where the editor is looking — api/admin/add-page-section.php
             appends to the bottom of that same list. One button, and the
             choice itself happens in the picker it opens
             (admin/_block_picker.php): the old "kies eerst een type uit een
             lijst namen, druk dán op toevoegen" is gone. */ ?>
    <?php if ($availableBlocks !== []): ?>
      <?php block_picker_button(); ?>
    <?php else: ?>
      <p class="admin-text-muted">Er is op deze pagina op dit moment geen contentblok meer dat je kunt toevoegen.</p>
    <?php endif; ?>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php /* The second Pagina panel. It has to be one: it carries a <form> of
           its own, and that could not be nested inside the settings form the
           first Pagina panel lives in. The tab controls both. */ ?>
  <?php if (!$isProtected): ?>
    <?php admin_tab_panel('pagina'); ?>
    <section class="admin-card">
      <h2>Verwijderen</h2>
      <?php if ($references['total'] > 0): ?>
        <p class="admin-alert admin-alert--error">
          Deze pagina wordt gebruikt door <?= $h(PageService::describeReferences($references)) ?>.
          Verwijder of wijzig die link(s) eerst via
          <a href="/admin/navigation.php">Navigatie</a> / <a href="/admin/footer.php">Footer</a>;
          daarna kan de pagina verwijderd worden.
        </p>
      <?php else: ?>
        <p class="admin-text-muted">Verwijdert deze pagina definitief, inclusief alle secties en hun inhoud. Dit kan niet ongedaan worden gemaakt.</p>
        <form method="post" action="/api/admin/delete-page.php" onsubmit="return confirm('Deze pagina en alle secties erop definitief verwijderen? Dit kan niet ongedaan worden gemaakt.');">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $pageId ?>">
          <button type="submit" class="admin-btn-text admin-btn-text--danger">Pagina verwijderen</button>
        </form>
      <?php endif; ?>
    </section>
    <?php admin_tab_panel_end(); ?>
  <?php endif; ?>

  <?php admin_tabs_end(); ?>
</main>
<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php block_picker_modal($availableBlocks, $pageId, $csrfToken); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/block-picker.js') ?>" defer></script>
<?php admin_tabs_script(); ?>
<?php /* After the tabs: bringing a block back into view means opening its
         tab first, and that is window.AdminTabs. */ ?>
<?php admin_collapse_script(); ?>
<?php save_bar_script(); ?>
<?php media_picker_script(); ?>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
