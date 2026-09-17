<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Repository\PageRepository;
use App\Repository\CtaBandRepository;

/**
 * Editor for one CTA band page-builder instance
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/rich-text.php,
 * admin/faq.php, ...). A page may carry several CTA bands; each is edited
 * here under its own key and never overwrites another.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * the five text fields show the language chosen in the CMS shell, as stored
 * and without the default language's words in an empty translation, and are
 * required only in the default language; the save writes that language only.
 * The two URLs and "Actief" are the same in every language and stay on
 * screen in each. Input a refused save hands back comes back in the language
 * it was typed in, and the form then starts out unsaved in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$slug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new CtaBandRepository();
$page = ($slug === null || $slug === '') ? null : (new PageRepository())->findByContentKey($slug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || $repository->findBySlugAndKey($slug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$pageId = (int) $page['id'];
$pageLabel = \App\Service\PageLocalization::name((int) $page['id']);
$editLanguage = admin_localized_language();

$errors = $_SESSION['admin_cta_band_errors'] ?? [];
$old = $_SESSION['admin_cta_band_old'] ?? null;
unset($_SESSION['admin_cta_band_errors'], $_SESSION['admin_cta_band_old']);

$saved = isset($_GET['saved']);

try {
    $row = $repository->findBySlugAndKey($slug, $sectionKey);
} catch (\Throwable $e) {
    error_log('[admin/cta-band.php] ' . $e->getMessage());
    $row = null;
}

// Unreachable in practice (the gate above already required the row) — an
// empty form is still the safe degradation if the second read fails.
$sectionId = (int) ($row['id'] ?? 0);
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The words of one field on screen: typed and handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return $sectionId > 0 ? BlockLocalization::raw('cta_bands', $sectionId, $field, $editLanguage) : '';
};

/** A language-neutral value: handed back, else stored. */
$setting = static fn (string $key): string => is_array($old) ? (string) ($old[$key] ?? '') : (string) ($row[$key] ?? '');
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) ($row['is_active'] ?? true);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$marker = $required !== '' ? '*' : '';
$placeholder = admin_localized_placeholder_attr($editLanguage);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('block_cta.cta_band_admin', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= $pageId ?>"><?= admin_t('block_cta.text', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></a></p>
  <h1><?= admin_t('block_cta.cta_band', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_cta.oproep_tot_actie_sectie', ['v1' => htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8')]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
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

  <section class="admin-card">
    <form method="post" action="/api/admin/update-cta-band.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section" value="<?= htmlspecialchars($sectionParam, ENT_QUOTES, 'UTF-8') ?>">
      <?= admin_localized_input($editLanguage) ?>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_cta.eyebrow') ?><?= $marker ?>
          <input type="text" name="eyebrow" maxlength="150"<?= $required ?> value="<?= $h($word('eyebrow')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_cta.titel_h2') ?><?= $marker ?>
          <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_cta.introtekst_lead') ?>
          <textarea name="lead" maxlength="500" rows="3"<?= $placeholder ?>><?= $h($word('lead')) ?></textarea>
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_cta.leeg_laten_beide_talen') ?></p>

      <h2 style="margin-top:2rem;"><?= admin_te('block_cta.primaire_knop') ?></h2>
      <div class="admin-form-row">
        <label><?= admin_te('block_cta.label') ?><?= $marker ?>
          <input type="text" name="primary_label" maxlength="150"<?= $required ?> value="<?= $h($word('primary_label')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>*
          <input type="text" name="primary_url" maxlength="255" required value="<?= $h($setting('primary_url')) ?>">
        </label>
      </div>

      <h2 style="margin-top:2rem;"><?= admin_te('block_cta.secundaire_knop_optioneel') ?></h2>
      <div class="admin-form-row">
        <label><?= admin_te('block_cta.label') ?>
          <input type="text" name="secondary_label" maxlength="150" value="<?= $h($word('secondary_label')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>
          <input type="text" name="secondary_url" maxlength="255" value="<?= $h($setting('secondary_url')) ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_cta.laat_label_url_leeg') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_cta.actief_uitgevinkt_sectie_getoond') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
