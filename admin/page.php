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
use App\Service\AppUrl;
use App\Service\Breadcrumbs\PageBreadcrumb;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\PageUsage;
use App\Service\Redirects\Redirect;
use App\Service\SectionRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\RedirectRepository;

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
 * control always directly beneath it — one page, one ordered list. While the
 * page has nothing below its heading, that control is an invitation to add
 * the first block instead. Either button opens the visual block picker
 * (admin/_block_picker.php), where one click on a card both chooses and adds;
 * it replaced a dropdown of type names plus a separate confirm button.
 * Deleting a block asks first, in the CMS's shared dialog (ADMIN-UI.md).
 * The fixed blocks
 * a page template used to hardcode between the others are in that same list
 * (App\Service\SectionRegistry, `manual_add = false`); they carry a badge
 * naming the admin domain that owns their content and cannot be added or
 * deleted here. Editing a block still opens that block type's own dedicated
 * editor; there is no generic block form.
 *
 * Two independent locks, deliberately not the same thing:
 *
 *   - a page served at a FIXED URL (isRouteBound()) shows its web address as
 *     a fact and offers no field for it — its URL is decided by the route it
 *     is served from;
 *   - a PROTECTED page (isProtected(): the site root, or a page carrying
 *     application-critical functionality like the storefront) additionally
 *     shows Status locked and has no delete button.
 *
 * Diensten, Portfolio, Over mij and Contact are the ordinary content pages
 * in between: their URL is fixed, but they can be set to Concept and deleted
 * like any other page. Title and the SEO fields are editable everywhere.
 *
 * EVERY OTHER PAGE'S WEB ADDRESS is changed on purpose, never in passing. It
 * is shown as the link it is; the field sits behind "Webadres wijzigen", with
 * where the page is linked from (App\Service\PageUsage) written out above it;
 * and a save that really moves the page comes back here unwritten, with a
 * confirmation card at the top of the Pagina tab
 * (api/admin/update-page.php). That card is part of the settings form rather
 * than a form of its own: the fields already hold what the editor typed, so
 * confirming is sending them again with the address they agreed to.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($idParam === false || $idParam === null || $idParam < 1) {
    http_response_code(404);
    exit(admin_t('screen.pagina_gevonden'));
}

$pageRepository = new PageRepository();
$page = $pageRepository->findById($idParam);

if ($page === null) {
    http_response_code(404);
    exit(admin_t('screen.pagina_gevonden'));
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
// Where the page is linked from, for the "Webadres wijzigen" part of the
// Pagina tab. A page on a fixed URL has no address to change.
$usage = $hasFixedUrl ? [] : PageUsage::forPageId($pageId);

$errors = $_SESSION['admin_page_errors'] ?? [];
$old = $_SESSION['admin_page_old'] ?? null;
unset($_SESSION['admin_page_errors'], $_SESSION['admin_page_old']);

/**
 * A save that would move this page to another web address, handed back
 * unconfirmed by api/admin/update-page.php: nothing was written, the editor's
 * input is in $old exactly as after a refused save, and the Pagina tab asks.
 * Anything that is not about THIS page is ignored rather than trusted.
 */
$urlChange = $_SESSION['admin_page_url_change'] ?? null;
unset($_SESSION['admin_page_url_change']);

if (!is_array($urlChange) || (int) ($urlChange['page_id'] ?? 0) !== $pageId || $hasFixedUrl) {
    $urlChange = null;
}

/**
 * What confirming will do to the current address, decided by the same rule
 * api/admin/update-page.php applies (PageService::oldAddressWillRedirect()),
 * plus the one case that rule cannot see: a redirect somebody set on the
 * current address by hand, which SlugChangeRedirects deliberately leaves
 * alone. One of 'redirect', 'manual', 'unpublishing' or 'draft'.
 */
$urlChangeOutcome = null;
$urlChangeManualTarget = '';

if ($urlChange !== null) {
    if (PageService::oldAddressWillRedirect($page, (string) ($urlChange['status'] ?? ''))) {
        $existingRedirect = (new RedirectRepository())->findBySourcePath('/' . (string) $urlChange['old_slug']);

        if ($existingRedirect !== null && (string) $existingRedirect['origin'] !== Redirect::ORIGIN_SLUG_CHANGE) {
            $urlChangeOutcome = 'manual';
            $urlChangeManualTarget = (string) $existingRedirect['target_value'];
        } else {
            $urlChangeOutcome = 'redirect';
        }
    } else {
        $urlChangeOutcome = PageContent::isPublished($page) ? 'unpublishing' : 'draft';
    }
}

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
// Whether this page prints its breadcrumb. The site root never does — there
// is nothing above it — so it is not offered a switch it could not use.
$showsBreadcrumb = $old !== null
    ? !empty($old['show_breadcrumb'])
    : PageBreadcrumb::isEnabled($page);
$offersBreadcrumbChoice = !PageContent::isSiteRoot($page);
$pageSocialImage = trim((string) ($page['og_image_path'] ?? ''));
// A refused or unconfirmed save hands back the image that was chosen, like
// every other field; otherwise the stored one.
$pageSocialMedia = MediaService::find(
    $old !== null && array_key_exists('og_media_id', $old)
        ? (int) $old['og_media_id']
        : (int) ($page['og_media_id'] ?? 0)
);

// The search-result preview below shows what this page's head will really
// contain, resolved by the same App\Service\PageSeo the public page uses —
// never a second guess at the fallback rules. It is built from the STORED
// row, so it shows what is live, not what is half-typed in the form.
$seoPreview = \App\Service\PageSeo::forPage($page);

// The SEO title's explanation names the automatic title with this site's own
// name, so an editor can see what an empty field turns into.
$seoTitleHelp = admin_t('help.page.seo_title', ['site' => \App\Service\SiteSettings::get('site_name')]);

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
 * Pagina, and so does a new web address waiting for confirmation. A saved
 * one leaves the choice alone.
 */
$forcedTab = ($errors !== [] || $pagesError !== null || $urlChange !== null) ? 'pagina' : null;

// The address field stays open whenever the form holds an address other than
// the stored one — a refused save, or one waiting for confirmation — so the
// editor sees what they typed instead of a closed "Webadres wijzigen".
$urlFieldOpen = !$hasFixedUrl
    && $old !== null
    && PageService::sanitizeSlug((string) ($old['slug'] ?? '')) !== (string) $page['slug'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $page['title']) ?> <?= admin_te('page.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/pages.php"><?= admin_t('page.terug_pagina_s') ?></a></p>
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= $h((string) $page['title']) ?></h1>
      <p class="admin-page-head__desc"><?= admin_t('page.beheer_instellingen_inhoud_pagina') ?></p>
    </div>
    <?php /* A published page opens where visitors see it. A draft has no public
             address yet — it is a 404 there, on purpose — so it opens the
             preview only signed-in editors can reach
             (admin/page-preview.php). */ ?>
    <?php if (PageContent::isPublished($page)): ?>
      <a href="<?= $h(PageContent::publicUrl($page)) ?>" class="admin-btn-secondary" target="_blank" rel="noopener"><?= admin_te('page.bekijk_pagina') ?> &#8594;</a>
    <?php else: ?>
      <a href="/admin/page-preview.php?id=<?= $pageId ?>" class="admin-btn-secondary" target="_blank" rel="noopener"><?= admin_te('page.preview') ?> &#8594;</a>
    <?php endif; ?>
  </header>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('page.pagina_aangemaakt_voeg_hieronder') ?></p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('page.pagina_instellingen_opgeslagen') ?></p>
  <?php endif; ?>
  <?php if ($deletedSection): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('page.sectie_verwijderd') ?></p>
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
      'inhoud' => admin_t('tabs.content'),
      'pagina' => admin_t('tabs.page'),
      'seo' => admin_t('tabs.seo'),
  ], [
      'scope' => (string) $pageId,
      'label' => admin_t('page.tabs_label'),
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
    <?php if ($urlChange !== null): ?>
    <?php /* The confirmation a new web address waits for: nothing has been
             written yet (api/admin/update-page.php). It sits INSIDE the
             settings form on purpose. The fields below already hold what the
             editor typed, so confirming is submitting that same form again,
             now carrying the one address they agreed to — one form to one
             endpoint, as ever. */ ?>
    <section class="admin-card admin-url-confirm" aria-labelledby="page-url-confirm-title">
      <h2 id="page-url-confirm-title"><?= admin_te('page.url_confirm_title') ?></h2>
      <p><?= admin_te('page.url_confirm_intro') ?></p>
      <dl class="admin-url-confirm__addresses">
        <dt><?= admin_te('page.url_confirm_old') ?></dt>
        <dd><code>/<?= $h((string) $urlChange['old_slug']) ?></code></dd>
        <dt><?= admin_te('page.url_confirm_new') ?></dt>
        <dd><code>/<?= $h((string) $urlChange['new_slug']) ?></code></dd>
      </dl>
      <p>
        <?php if ($usage === []): ?>
          <?= admin_te('page.url_usage_none') ?>
        <?php else: ?>
          <?= $h(count($usage) === 1 ? admin_t('page.url_usage_one') : admin_t('page.url_usage_many', ['count' => (string) count($usage)])) ?>
          <?= admin_te('page.url_usage_follow') ?>
        <?php endif; ?>
      </p>
      <p>
        <?php if ($urlChangeOutcome === 'manual'): ?>
          <?= admin_te('page.url_confirm_manual', ['target' => $urlChangeManualTarget]) ?>
        <?php else: ?>
          <?= admin_te('page.url_confirm_' . $urlChangeOutcome) ?>
        <?php endif; ?>
      </p>
      <input type="hidden" name="confirmed_slug" value="<?= $h((string) $urlChange['new_slug']) ?>">
      <div class="admin-url-confirm__actions">
        <button type="submit"><?= admin_te('page.url_confirm_submit') ?></button>
        <a href="/admin/page.php?id=<?= $pageId ?>" class="admin-btn-secondary"><?= admin_te('page.url_confirm_cancel') ?></a>
      </div>
    </section>
    <?php endif; ?>
    <section class="admin-card">
      <h2><?= admin_te('page.algemeen') ?></h2>
      <?php /* The shared field styling (label above a full-width control, one
               rhythm between fields) — the same wrapper the SEO tab uses. */ ?>
      <div class="admin-product-form admin-product-form--wide">
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title" maxlength="<?= PageService::MAX_TITLE_LENGTH ?>" required value="<?= $h($fieldValue('title')) ?>">
        </label>
      </div>

      <?php /* The web address, shown as the link it is and changed only on
               purpose: the field sits behind "Webadres wijzigen", a native
               <details> in the collapse styling (admin/_admin_collapse.php),
               with what a change affects written out above it. The word
               "slug" appears in the explanation only. A page served at a
               fixed URL has no field at all — update-page.php would discard
               the value anyway. */ ?>
      <div class="admin-url-field">
        <div class="admin-field__label">
          <span><?= admin_te('page.url_label') ?></span>
          <?= admin_help(admin_t('page.url_label'), admin_t('help.page.url')) ?>
        </div>
        <p class="admin-url-field__current">
          <a href="<?= $h(PageContent::publicUrl($page)) ?>" target="_blank" rel="noopener"><?= $h(PageContent::canonicalUrl($page)) ?></a>
        </p>
        <?php if ($hasFixedUrl): ?>
          <p class="admin-text-muted"><?= admin_te('page.url_fixed') ?></p>
        <?php else: ?>
          <details class="admin-collapse admin-url-change"<?= $urlFieldOpen ? ' open' : '' ?>>
            <summary class="admin-collapse__summary admin-url-change__summary">
              <span class="admin-collapse__caret" aria-hidden="true"></span>
              <span class="admin-collapse__title"><?= admin_te('page.url_change') ?></span>
            </summary>
            <div class="admin-collapse__body admin-url-change__body">
              <p class="admin-alert admin-alert--warning"><?= admin_te('page.url_change_warning') ?></p>
              <?php if ($usage === []): ?>
                <p><?= admin_te('page.url_usage_none') ?></p>
              <?php else: ?>
                <p>
                  <?= $h(count($usage) === 1 ? admin_t('page.url_usage_one') : admin_t('page.url_usage_many', ['count' => (string) count($usage)])) ?>
                  <?= admin_te('page.url_usage_follow') ?>
                </p>
                <ul class="admin-url-usage">
                  <?php foreach ($usage as $place): ?>
                    <li>
                      <span class="admin-url-usage__kind"><?= admin_te('page.url_usage_kind_' . $place['kind']) ?>:</span>
                      <a href="<?= $h($place['edit_url']) ?>"><?= $h($place['label']) ?></a>
                      <?php if ($place['context'] !== ''): ?>
                        <span class="admin-text-muted">(<?= $h($place['context']) ?>)</span>
                      <?php endif; ?>
                      <?php if ($place['hidden']): ?>
                        <span class="admin-badge admin-badge--muted"><?= admin_te('page.url_usage_hidden') ?></span>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <p class="admin-text-muted"><?= admin_te('page.url_typed_links') ?></p>
              <div class="admin-field">
                <?= admin_field_label('page-slug', admin_t('page.url_new')) ?>
                <div class="admin-url-input">
                  <span class="admin-url-input__base" aria-hidden="true"><?= $h(AppUrl::canonical('/')) ?></span>
                  <input type="text" id="page-slug" name="slug" maxlength="<?= PageService::MAX_SLUG_LENGTH ?>" value="<?= $h($fieldValue('slug')) ?>" autocomplete="off" spellcheck="false">
                </div>
              </div>
            </div>
          </details>
        <?php endif; ?>
      </div>
      <label><?= admin_te('common.status') ?>
        <select name="status" <?= $isProtected ? 'disabled' : '' ?>>
          <?php foreach (array_keys(PageContent::STATUS_LABELS) as $statusKey): ?>
            <option value="<?= $h($statusKey) ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= admin_te('page.status_' . $statusKey) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($isProtected): ?>
        <p class="admin-text-muted"><?= PageContent::isSiteRoot($page) ? admin_t('page.protected_homepage') : admin_t('page.protected_shop') ?></p>
      <?php else: ?>
        <p class="admin-text-muted"><?= admin_te('page.concept_betekent_wel_bewerkbaar') ?></p>
      <?php endif; ?>

      <?php if ($offersBreadcrumbChoice): ?>
        <?php /* Hidden companion field, the same reason the noindex switch
                 has one: an unticked box sends nothing, and the endpoint
                 would have no way to tell "off" from "not on this form". */ ?>
        <input type="hidden" name="show_breadcrumb" value="0">
        <div class="admin-field admin-field--inline">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-switch" role="switch" name="show_breadcrumb" value="1"<?= $showsBreadcrumb ? ' checked' : '' ?>>
            <?= admin_te('page.show_breadcrumb') ?>
          </label>
          <?= admin_help(admin_t('page.show_breadcrumb'), admin_t('help.page.show_breadcrumb')) ?>
        </div>
      <?php endif; ?>
      </div>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('page.save_settings') ?></button>
      <p class="admin-text-muted"><?= admin_t('page.slaat_alles_wat_onder') ?></p>
    </section>
    <?php admin_tab_panel_end(); ?>

    <?php admin_tab_panel('seo'); ?>
    <section class="admin-card">
      <h2><?= admin_te('page.seo') ?></h2>
      <?php /* What SEO is, and what each field is for, as help
               (admin/_admin_ui.php): switching help off in the shell leaves
               the fields alone. The automatic title used to be spelled out in
               a paragraph of its own here; it is part of the SEO title's
               explanation now. */ ?>
      <?= admin_info_panel(admin_t('help.page.seo')) ?>
      <?php /* One pane per language, not one column per language. On a
               single-language site only the site's own language is on
               screen; the other pane is still rendered, still carries its
               stored value and is still submitted, but `hidden` — that is
               what keeps a translation alive through a save after the
               language was switched off (admin/_language_fields.php). */ ?>
      <?php admin_lang_bar(); ?>
      <div class="admin-product-form admin-product-form--wide">
        <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-field">
            <?= admin_field_label('page-meta-title-nl', admin_t('page.meta_title'), $seoTitleHelp) ?>
            <input type="text" name="meta_title" id="page-meta-title-nl" maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($fieldValue('meta_title')) ?>"<?= admin_lang_placeholder_attr('nl') ?>>
          </div>
          <div class="admin-field">
            <?= admin_field_label('page-meta-description-nl', admin_t('page.meta_description'), admin_t('help.page.meta_description')) ?>
            <textarea name="meta_description" rows="3" id="page-meta-description-nl" maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('nl') ?>><?= $h($fieldValue('meta_description')) ?></textarea>
          </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
          <div class="admin-field">
            <?= admin_field_label('page-meta-title-en', admin_t('page.meta_title'), $seoTitleHelp) ?>
            <input type="text" name="meta_title_en" id="page-meta-title-en" maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($fieldValue('meta_title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
          </div>
          <div class="admin-field">
            <?= admin_field_label('page-meta-description-en', admin_t('page.meta_description'), admin_t('help.page.meta_description')) ?>
            <textarea name="meta_description_en" rows="3" id="page-meta-description-en" maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('en') ?>><?= $h($fieldValue('meta_description_en')) ?></textarea>
          </div>
        <?php admin_lang_pane_end(); ?>
      </div>

      <h3 class="admin-seo-lang__title"><?= admin_te('page.google_preview') ?></h3>
      <p class="admin-text-muted"><?= admin_te('page.zo_ziet_pagina_er') ?></p>
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
      <p class="admin-text-muted"><?= admin_t('page.pagina_blijft_gewoon_bereikbaar') ?></p>

      <h3 class="admin-seo-lang__title"><?= admin_te('page.deel_afbeelding') ?></h3>
      <?php media_picker_field(
          'og_media_id',
          $pageSocialMedia,
          admin_t('page.og_image'),
          admin_t('page.og_image_help'),
          true
      ); ?>
      <?php if ($pageSocialMedia === null && $pageSocialImage !== ''): ?>
        <p class="admin-text-muted"><?= admin_t('page.huidige_waarde_mediabibliotheek', ['v1' => $h($pageSocialImage)]) ?></code></p>
      <?php endif; ?>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('page.save_settings') ?></button>
      <p class="admin-text-muted"><?= admin_t('page.slaat_alles_wat_onder_2') ?></p>
    </section>
    <?php admin_tab_panel_end(); ?>
  </form>

  <?php admin_tab_panel('inhoud'); ?>
  <h2><?= admin_te('page.inhoud') ?></h2>

  <section class="admin-card">
    <?php /* Both roles on one element: the drop zone the reorder script
             already used, and the collapse group whose open rows and return
             target are remembered per page (admin/_admin_collapse.php). */ ?>
    <div class="admin-page-sections" data-page-section-zone data-reorder-url="/api/admin/reorder-page-sections.php" data-csrf-token="<?= $h($csrfToken) ?>" data-page-id="<?= $pageId ?>" data-admin-collapse-group="page-blocks" data-admin-collapse-scope="<?= $pageId ?>">
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
                  <span class="admin-badge admin-badge--warning"><?= admin_te('page.not_supported') ?></span>
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
                  <p class="admin-section-row__note"><?= admin_t('page.type_onderdeel', ['v1' => $h($sectionType), 'v2' => $h(\App\Module\ModuleRegistry::label($disabledModule))]) ?></p>
                  <p class="admin-section-row__note"><?= admin_te('page.blok_hoort_onderdeel_moment') ?></p>
                <?php elseif ($isUnsupported): ?>
                  <p class="admin-section-row__note"><?= admin_t('page.type', ['v1' => $h($sectionType)]) ?></code></p>
                  <p class="admin-section-row__note"><?= admin_te('page.blok_kon_geladen_pagina') ?></p>
                <?php elseif ($note !== null): ?>
                  <p class="admin-section-row__note"><?= $h($note) ?></p>
                <?php endif; ?>
                <?php if ($isHidden): ?>
                  <p class="admin-section-row__note"><?= admin_t('page.verborgen_getoond_pagina') ?></p>
                <?php endif; ?>
              </div>
              <div class="admin-section-row__actions">
                <?php foreach (SectionRegistry::editLinks($pageSection) as $editLink): ?>
                  <a href="<?= $h($editLink['url']) ?>" class="admin-section-row__edit"><?= $h($editLink['label']) ?> &#8594;</a>
                <?php endforeach; ?>
                <?php /* Real buttons from the admin family, not text links:
                         both change what visitors see. The toggle's word says
                         what pressing it does; the badge in the summary says
                         the state, so neither rests on colour alone. */ ?>
                <form method="post" action="/api/admin/toggle-page-section.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= (int) $pageSection['id'] ?>">
                  <input type="hidden" name="is_active" value="<?= $isHidden ? '1' : '0' ?>">
                  <button type="submit" class="admin-btn-secondary admin-section-row__button"><?= $isHidden ? admin_te('common.show') : admin_te('common.hide') ?></button>
                </form>
                <?php if (SectionRegistry::isDeletable($sectionType)): ?>
                <?php /* Asks first, in the CMS's shared dialog printed at the end
                         of this screen, and names the block that would go. The
                         form, its token and the endpoint's guards are exactly
                         what they were. */ ?>
                <form method="post" action="/api/admin/delete-page-section.php" class="admin-inline-form admin-section-row__delete"<?= admin_confirm_attributes(
                    admin_t('page.block_delete_title'),
                    admin_t('page.block_delete_message', ['block' => $rowLabel]),
                    admin_t('common.delete')
                ) ?>>
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= (int) $pageSection['id'] ?>">
                  <button type="submit" class="admin-btn-danger admin-section-row__button"><?= admin_te('common.delete') ?></button>
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
             lijst namen, druk dán op toevoegen" is gone.

             While the page has nothing below its heading, that button is an
             invitation instead: a sentence saying so, and the same opener.
             A hidden block counts as content — it is the editor's own. */ ?>
    <?php if (!SectionRegistry::hasContentBlocks($allSections)): ?>
      <?php block_picker_empty_state($availableBlocks !== []); ?>
    <?php elseif ($availableBlocks !== []): ?>
      <?php block_picker_button(); ?>
    <?php else: ?>
      <p class="admin-text-muted"><?= admin_te('page.er_pagina_moment_contentblok') ?></p>
    <?php endif; ?>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php /* The second Pagina panel. It has to be one: it carries a <form> of
           its own, and that could not be nested inside the settings form the
           first Pagina panel lives in. The tab controls both. */ ?>
  <?php if (!$isProtected): ?>
    <?php admin_tab_panel('pagina'); ?>
    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>
      <?php if ($references['total'] > 0): ?>
        <p class="admin-alert admin-alert--error">
          <?= admin_t('page.in_use_by', ['references' => $h(PageService::describeReferences($references))]) ?>
        </p>
      <?php else: ?>
        <p class="admin-text-muted"><?= admin_te('page.verwijdert_pagina_definitief_inclusief') ?></p>
        <form method="post" action="/api/admin/delete-page.php" onsubmit="return confirm('Deze pagina en alle secties erop definitief verwijderen? Dit kan niet ongedaan worden gemaakt.');">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $pageId ?>">
          <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('page.pagina_verwijderen') ?></button>
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
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/block-picker.js') ?>" defer></script>
<?php admin_tabs_script(); ?>
<?php /* After the tabs: bringing a block back into view means opening its
         tab first, and that is window.AdminTabs. */ ?>
<?php admin_collapse_script(); ?>
<?php save_bar_script(); ?>
<?php media_picker_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
