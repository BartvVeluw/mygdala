<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
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
$pageLabel = (string) $page['title'];

$errors = $_SESSION['admin_cta_band_errors'] ?? [];
$old = $_SESSION['admin_cta_band_old'] ?? null;
unset($_SESSION['admin_cta_band_errors'], $_SESSION['admin_cta_band_old']);

$saved = isset($_GET['saved']);

if ($old !== null) {
    $values = $old;
} else {
    try {
        $row = $repository->findBySlugAndKey($slug, $sectionKey);
    } catch (\Throwable $e) {
        error_log('[admin/cta-band.php] ' . $e->getMessage());
        $row = null;
    }

    if ($row !== null) {
        $values = [
            'eyebrow_nl' => (string) $row['eyebrow_nl'],
            'eyebrow_en' => (string) ($row['eyebrow_en'] ?? ''),
            'title_nl' => (string) $row['title_nl'],
            'title_en' => (string) ($row['title_en'] ?? ''),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
            'lead_en' => (string) ($row['lead_en'] ?? ''),
            'primary_label_nl' => (string) $row['primary_label_nl'],
            'primary_label_en' => (string) ($row['primary_label_en'] ?? ''),
            'primary_url' => (string) $row['primary_url'],
            'secondary_label_nl' => (string) ($row['secondary_label_nl'] ?? ''),
            'secondary_label_en' => (string) ($row['secondary_label_en'] ?? ''),
            'secondary_url' => (string) ($row['secondary_url'] ?? ''),
            'is_active' => (bool) $row['is_active'],
        ];
    } else {
        // Unreachable in practice (the gate above already required the row)
        // — an empty form is still the safe degradation if the second read
        // fails.
        $values = ['is_active' => true];
    }
}

$csrfToken = Csrf::token();

/**
 * @param array<string, mixed> $values
 */
function ctaBandValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
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
    <form method="post" action="/api/admin/update-cta-band.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="section" value="<?= htmlspecialchars($sectionParam, ENT_QUOTES, 'UTF-8') ?>">

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_cta.eyebrow') ?>*
          <input type="text" name="eyebrow_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= ctaBandValue($values, 'eyebrow_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_cta.eyebrow_2') ?>
          <input type="text" name="eyebrow_en" maxlength="150" value="<?= ctaBandValue($values, 'eyebrow_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_cta.titel_h2') ?>*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= ctaBandValue($values, 'title_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_cta.titel_h2_2') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= ctaBandValue($values, 'title_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_cta.introtekst_lead') ?>
          <textarea name="lead_nl" maxlength="500" rows="3"><?= ctaBandValue($values, 'lead_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_cta.introtekst_lead_2') ?>
          <textarea name="lead_en" maxlength="500" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= ctaBandValue($values, 'lead_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_cta.leeg_laten_beide_talen') ?></p>

      <h2 style="margin-top:2rem;"><?= admin_te('block_cta.primaire_knop') ?></h2>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_cta.label') ?>*
          <input type="text" name="primary_label_nl" maxlength="150" <?= admin_lang_required('nl') ?> value="<?= ctaBandValue($values, 'primary_label_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_cta.label_2') ?>
          <input type="text" name="primary_label_en" maxlength="150" value="<?= ctaBandValue($values, 'primary_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>*
          <input type="text" name="primary_url" maxlength="255" required value="<?= ctaBandValue($values, 'primary_url') ?>">
        </label>
      </div>

      <h2 style="margin-top:2rem;"><?= admin_te('block_cta.secundaire_knop_optioneel') ?></h2>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_cta.label_3') ?>
          <input type="text" name="secondary_label_nl" maxlength="150" value="<?= ctaBandValue($values, 'secondary_label_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_cta.label_4') ?>
          <input type="text" name="secondary_label_en" maxlength="150" value="<?= ctaBandValue($values, 'secondary_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>
          <input type="text" name="secondary_url" maxlength="255" value="<?= ctaBandValue($values, 'secondary_url') ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('block_cta.laat_label_url_leeg') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('block_cta.actief_uitgevinkt_sectie_getoond') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
