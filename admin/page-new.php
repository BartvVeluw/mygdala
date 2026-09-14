<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_language_fields.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\AppUrl;
use App\Service\Csrf;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\PageTemplates\PageTemplates;
use App\Service\SectionRegistry;

/**
 * "+ Nieuwe pagina": the small create form (admin/pages.php links here).
 * Deliberately a separate screen from admin/page.php rather than the
 * "no ?id= means create" convention used elsewhere in this admin, because
 * the edit screen is settings PLUS the page builder — and a page has to
 * exist before any section can be attached to it. So creating asks only for
 * the settings, then drops the admin straight into the page builder for the
 * page it just made.
 *
 * A new page defaults to Concept (draft): its URL stays a 404 for visitors
 * until the owner has actually built and published it, which is what makes
 * "create it, fill it, then publish" safe — and what makes it safe for a
 * template to put starter blocks on the page, since none of it is public
 * until the owner says so.
 *
 * The template picker below chooses which blocks the page STARTS with, and
 * nothing more: the page it makes is an ordinary content page, and the
 * editor can change every block afterwards. "Lege pagina" is pre-selected,
 * so an editor who ignores this section gets exactly the empty page this
 * screen made before templates existed. See PAGE-TEMPLATES.md.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$errors = $_SESSION['admin_page_errors'] ?? [];
$old = $_SESSION['admin_page_old'] ?? null;
unset($_SESSION['admin_page_errors'], $_SESSION['admin_page_old']);

$value = static fn (string $key): string => (string) ($old[$key] ?? '');
$status = (string) ($old['status'] ?? PageContent::STATUS_DRAFT);
$selectedTemplate = (string) ($old['template'] ?? PageTemplates::DEFAULT_KEY);

// The address the preview line starts with: the one handed back after a
// refused save, else what the title would give. admin/assets/admin.js keeps
// it current while the editor types; api/admin/create-page.php decides the
// real one.
$slugPreview = PageService::sanitizeSlug($value('slug') !== '' ? $value('slug') : $value('title'));

// The SEO card folds shut — every field on it is optional — unless a refused
// save handed back something typed into it, which must not be hidden.
$seoOpen = $value('meta_title') !== '' || $value('meta_title_en') !== ''
    || $value('meta_description') !== '' || $value('meta_description_en') !== '';

// The automatic title, spelled out with this site's own name in the SEO
// title's explanation.
$seoTitleHelp = admin_t('help.page.seo_title', ['site' => \App\Service\SiteSettings::get('site_name')]);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('page.nieuwe_pagina_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/pages.php"><?= admin_t('page.terug_pagina_s') ?></a></p>
  <h1><?= admin_te('page.nieuwe_pagina') ?></h1>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/create-page.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

    <section class="admin-card">
      <h2><?= admin_te('page.algemeen') ?></h2>
      <?php /* The shared field styling, as on the SEO card below. */ ?>
      <div class="admin-product-form admin-product-form--wide">
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title" maxlength="<?= PageService::MAX_TITLE_LENGTH ?>" required value="<?= $h($value('title')) ?>" data-slug-source>
        </label>
        <?php /* The address starts out as the title's (admin/assets/admin.js)
                 and stays the editor's to change until the page exists. The
                 hidden flag says which of the two it is when the form is
                 sent: api/admin/create-page.php makes an automatic address
                 unique and validates a typed one. Once the page exists,
                 admin/page.php protects the address instead. */ ?>
        <div class="admin-field">
          <?= admin_field_label('page-new-slug', admin_t('page.url_label'), admin_t('help.page.url_new')) ?>
          <input type="text" id="page-new-slug" name="slug" maxlength="<?= PageService::MAX_SLUG_LENGTH ?>" value="<?= $h($value('slug')) ?>" placeholder="<?= admin_te('page.url_placeholder') ?>" autocomplete="off" spellcheck="false" data-slug-target>
          <input type="hidden" name="slug_auto" value="<?= $value('slug_auto') === '1' ? '1' : '0' ?>" data-slug-auto>
          <p class="admin-url-preview">
            <?= admin_te('page.url_preview') ?>
            <span class="admin-url-preview__address"><?= $h(AppUrl::canonical('/')) ?><strong data-slug-preview-value data-slug-preview-empty="<?= admin_te('page.url_preview_empty') ?>"><?= $h($slugPreview !== '' ? $slugPreview : admin_t('page.url_preview_empty')) ?></strong></span>
          </p>
        </div>
      </div>
      <label><?= admin_te('common.status') ?>
        <select name="status">
          <?php foreach (array_keys(PageContent::STATUS_LABELS) as $statusKey): ?>
            <option value="<?= $h($statusKey) ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= admin_te('page.status_' . $statusKey) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="admin-text-muted"><?= admin_te('page.pagina_concept_alleen_hier') ?></p>
      </div>
    </section>

    <?php /* SEO before Template: how a page is named in a search result
             belongs with its title and address, while a template only
             decides which blocks it starts with. The card is a native
             <details> in the collapse styling (admin/_admin_collapse.php),
             folded shut unless something in it is already filled in. The
             language panes are the ones admin/page.php's SEO tab uses — see
             the note there. */ ?>
    <section class="admin-card">
      <details class="admin-collapse admin-collapse--card"<?= $seoOpen ? ' open' : '' ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <h2 class="admin-collapse__title"><?= admin_te('page.seo_optioneel') ?></h2>
        </summary>
        <div class="admin-collapse__body">
          <?= admin_info_panel(admin_t('help.page.seo')) ?>
          <?php admin_lang_bar(); ?>
          <div class="admin-product-form admin-product-form--wide">
            <?php admin_lang_pane_start('nl'); ?>
              <div class="admin-field">
                <?= admin_field_label('page-new-meta-title-nl', admin_t('page.meta_title'), $seoTitleHelp) ?>
                <input type="text" name="meta_title" id="page-new-meta-title-nl" maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($value('meta_title')) ?>"<?= admin_lang_placeholder_attr('nl') ?>>
              </div>
              <div class="admin-field">
                <?= admin_field_label('page-new-meta-description-nl', admin_t('page.meta_description'), admin_t('help.page.meta_description')) ?>
                <textarea name="meta_description" rows="3" id="page-new-meta-description-nl" maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('nl') ?>><?= $h($value('meta_description')) ?></textarea>
              </div>
            <?php admin_lang_pane_end(); ?>
            <?php admin_lang_pane_start('en'); ?>
              <div class="admin-field">
                <?= admin_field_label('page-new-meta-title-en', admin_t('page.meta_title'), $seoTitleHelp) ?>
                <input type="text" name="meta_title_en" id="page-new-meta-title-en" maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($value('meta_title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
              </div>
              <div class="admin-field">
                <?= admin_field_label('page-new-meta-description-en', admin_t('page.meta_description'), admin_t('help.page.meta_description')) ?>
                <textarea name="meta_description_en" rows="3" id="page-new-meta-description-en" maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('en') ?>><?= $h($value('meta_description_en')) ?></textarea>
              </div>
            <?php admin_lang_pane_end(); ?>
          </div>
        </div>
      </details>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('page.template') ?></h2>
      <p class="admin-text-muted"><?= admin_t('page.kies_waarmee_pagina_begint') ?></p>
      <div class="admin-template-grid">
        <?php foreach (PageTemplates::all() as $templateKey => $template): ?>
          <?php
            $blockLabels = array_map(
                static fn (string $type): string => SectionRegistry::label($type),
                $template->blocks()
            );
          ?>
          <label class="admin-template-card">
            <input type="radio" name="template" value="<?= $h($templateKey) ?>" <?= $selectedTemplate === $templateKey ? 'checked' : '' ?>>
            <span class="admin-template-card__inner">
              <span class="admin-template-card__head">
                <?php if ($template->icon() !== null): ?>
                  <?php /* Trusted, hardcoded first-party markup from the definition
                           class itself — never request or database data. See
                           App\Service\PageTemplates\PageTemplateDefinition::icon(). */ ?>
                  <svg class="admin-template-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $template->icon() ?></svg>
                <?php endif; ?>
                <span class="admin-template-card__name"><?= $h(admin_registry_label('pagetemplate.' . $templateKey . '.label', $template->label())) ?></span>
              </span>
              <span class="admin-template-card__desc"><?= $h(admin_registry_label('pagetemplate.' . $templateKey . '.description', $template->description())) ?></span>
              <span class="admin-template-card__blocks">
                <?php if ($blockLabels === []): ?>
                  <?= admin_te('page.no_sections_short') ?>
                <?php else: ?>
                  <?= implode(' &middot; ', array_map($h, $blockLabels)) ?>
                <?php endif; ?>
              </span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>


    <section class="admin-card">
      <button type="submit"><?= admin_te('page.pagina_aanmaken') ?></button>
      <p class="admin-text-muted"><?= admin_te('page.daarna_meteen_secties_toevoegen') ?></p>
    </section>
  </form>
</main>
<?php admin_lang_script(); ?>
</body>
</html>
