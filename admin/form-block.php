<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Repository\FormBlockRepository;
use App\Repository\FormRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\SectionRegistry;

/**
 * Editor for one "Formulier" block
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/rich-text.php,
 * admin/cta-band.php, ...).
 *
 * It edits WHERE a form appears, never WHAT it asks. The fields, the
 * recipient and the confirmation belong to the form itself, under Beheer →
 * Formulieren, and the link at the top of this screen is how an editor gets
 * there. That split is the point of Core Forms: the same form on three
 * pages is one definition, not three copies (FORMS.md).
 *
 * The dropdown lists the ACTIVE forms plus whichever one this block already
 * points at, so a form that was switched off does not silently vanish from
 * the block that uses it (FormRepository::selectable()).
 *
 * A page editor may choose a form here — that is placing content — but does
 * not thereby get to change it, or to read what people sent: those are
 * `forms.manage` and `forms.submissions`, and this screen only needs
 * `pages.manage`.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the heading and introduction show the language chosen in the CMS shell, as
 * stored and without the default language's words in an empty translation;
 * the save writes that language only. The chosen form and "Actief" are the
 * same in every language. Input a refused save hands back comes back in the
 * language it was typed in, and the form then starts out unsaved.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new FormBlockRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$currentFormId = $section['form_id'] === null ? null : (int) $section['form_id'];

try {
    $forms = (new FormRepository())->selectable($currentFormId);
} catch (\Throwable $e) {
    error_log('[admin/form-block.php] ' . $e->getMessage());
    $forms = [];
}

$errors = $_SESSION['admin_form_block_errors'] ?? [];
$old = $_SESSION['admin_form_block_old'] ?? null;
unset($_SESSION['admin_form_block_errors'], $_SESSION['admin_form_block_old']);

$saved = isset($_GET['saved']);
$editLanguage = admin_localized_language();

// What is the same in every language: handed back, else stored.
$values = $old ?? [
    'form_id' => $currentFormId === null ? '' : (string) $currentFormId,
    'is_active' => (bool) $section['is_active'],
];

$sectionId = (int) $section['id'];
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The words of one field on screen: typed and handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw('form_blocks', $sectionId, $field, $editLanguage);
};

$csrfToken = Csrf::token();
$placeholder = admin_localized_placeholder_attr($editLanguage);
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('form')) ?> <?= admin_t('forms.admin', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('forms.text', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('form')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('forms.sectie_kiest_hier_welk', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>

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

  <?php if ($forms === []): ?>
    <p class="admin-alert admin-alert--error"><?= admin_t('forms.er_formulieren_kiezen_maak') ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-form-block.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <label><?= admin_te('forms.welk_formulier') ?>
        <select name="form_id">
          <option value=""><?= admin_te('forms.formulier_gekozen') ?></option>
          <?php foreach ($forms as $form): ?>
            <option value="<?= (int) $form['id'] ?>" <?= (string) $values['form_id'] === (string) $form['id'] ? 'selected' : '' ?>>
              <?= $h((string) $form['name']) ?><?= $form['is_active'] ? '' : ' (staat uit)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="admin-text-muted"><?= admin_te('forms.zolang_er_formulier_gekozen') ?></p>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('forms.kop_boven_formulier') ?>
          <input type="text" name="title" maxlength="255" value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('forms.inleiding') ?>
          <textarea name="intro" maxlength="1000" rows="3"<?= $placeholder ?>><?= $h($word('intro')) ?></textarea>
        </label>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('forms.actief_uitgevinkt_sectie_getoond') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
