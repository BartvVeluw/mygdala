<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_language_fields.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
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
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title" maxlength="<?= PageService::MAX_TITLE_LENGTH ?>" required value="<?= $h($value('title')) ?>" data-slug-source>
        </label>
        <label><?= admin_te('page.slug_url_leeg_automatisch') ?>
          <input type="text" name="slug" maxlength="<?= PageService::MAX_SLUG_LENGTH ?>" value="<?= $h($value('slug')) ?>" placeholder="bijv. veelgestelde-vragen" data-slug-target>
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_t('page.pagina_komt_na_publiceren') ?></p>
      <label><?= admin_te('common.status') ?>
        <select name="status">
          <?php foreach (array_keys(PageContent::STATUS_LABELS) as $statusKey): ?>
            <option value="<?= $h($statusKey) ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= admin_te('page.status_' . $statusKey) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="admin-text-muted"><?= admin_te('page.pagina_concept_alleen_hier') ?></p>
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
      <h2><?= admin_te('page.seo_optioneel') ?></h2>
      <p class="admin-text-muted"><?= admin_t('page.seo_title_fallback_new', ['site' => $h(\App\Service\SiteSettings::get('site_name'))]) ?></p>
      <?php /* Same language panes as the SEO block in admin/page.php —
               see the note there. */ ?>
      <?php admin_lang_bar(); ?>
      <div class="admin-product-form admin-product-form--wide">
        <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_title') ?>
              <input type="text" name="meta_title" maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($value('meta_title')) ?>"<?= admin_lang_placeholder_attr('nl') ?>>
            </label>
          </div>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_description') ?>
              <textarea name="meta_description" rows="3" maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('nl') ?>><?= $h($value('meta_description')) ?></textarea>
            </label>
          </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_title') ?>
              <input type="text" name="meta_title_en" maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($value('meta_title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
          </div>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_description') ?>
              <textarea name="meta_description_en" rows="3" maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('en') ?>><?= $h($value('meta_description_en')) ?></textarea>
            </label>
          </div>
        <?php admin_lang_pane_end(); ?>
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
