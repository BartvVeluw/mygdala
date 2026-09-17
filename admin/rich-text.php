<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require __DIR__ . '/_richtext_field.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Repository\PageRepository;
use App\Repository\RichTextRepository;

/**
 * Editor for one Rich text page-builder section
 * (?section=<page content_key>:<section_key>) — the same
 * `<page>:<key>` addressing and the same "valid only when the page and its
 * content row really exist" gate as the other repeater section editors
 * (admin/faq.php, admin/feature-grid.php, ...), and the same shared Quill
 * field (admin/_richtext_field.php) every other rich-text field in this
 * project uses.
 *
 * This is where the body of the three migrated information pages is now
 * edited. It is deliberately the ONLY rich-text page-content editor: the old
 * admin/information-page.php combined page settings and body in one
 * bespoke screen, which is exactly the "second page-content editor" the
 * unified page model removes — settings now live on admin/page.php, body
 * content is a section like any other.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new RichTextRepository();

if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$page = (new PageRepository())->findByContentKey($pageSlug);
$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);

$errors = $_SESSION['admin_rich_text_errors'] ?? [];
$old = $_SESSION['admin_rich_text_old'] ?? null;
unset($_SESSION['admin_rich_text_errors'], $_SESSION['admin_rich_text_old']);

$saved = isset($_GET['saved']);

$contentHtml = $old !== null ? (string) ($old['content_html'] ?? '') : (string) ($section['content_html'] ?? '');
$contentHtmlEn = $old !== null ? (string) ($old['content_html_en'] ?? '') : (string) ($section['content_html_en'] ?? '');
$isActive = $old !== null ? !empty($old['is_active']) : (bool) $section['is_active'];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('block_richtext.tekstblok_admin', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_richtext.terug', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('rich_text')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_richtext.sectie_pagina', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>

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

  <form method="post" action="/api/admin/update-rich-text-section.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

    <section class="admin-card">
      <h2><?= admin_te('block_richtext.inhoud') ?></h2>
      <?php admin_lang_bar(); ?>
      <?php admin_lang_pane_start('nl'); ?>
        <?php renderRichTextField('content_html', 'Tekst', $contentHtml, 'full', 'admin-richtext-editor--lg'); ?>
      <?php admin_lang_pane_end(); ?>
      <?php admin_lang_pane_start('en'); ?>
        <?php renderRichTextField('content_html_en', 'Tekst', $contentHtmlEn, 'full', 'admin-richtext-editor--lg'); ?>
      <?php admin_lang_pane_end(); ?>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_richtext.actief_zichtbaar_pagina') ?>
      </label>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
