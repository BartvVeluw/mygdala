<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypes;

/**
 * One field of one form: its type, its bilingual texts, whether it is
 * required and — for a dropdown or a radio group — its choices.
 *
 * Its own screen for the same reason a carousel card has one
 * (admin/carousel-card.php): nine settings inline, repeated per field, makes
 * the form editor unreadable at five fields.
 *
 * THE POST NAME IS SHOWN BUT NOT EDITABLE. It was generated from the label
 * when the field was created, and answers have been filed under it ever
 * since; letting an editor change it would silently orphan every stored
 * submission that used the old one. Renaming the LABEL is free and does
 * exactly what an editor means by "rename this field" — history keeps the
 * label it was sent with (FORMS.md, "Wat een inzending bewaart").
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit(admin_t('screen.ongeldig_veld_id'));
}

try {
    $repository = new FormRepository();
    $field = $repository->findField($id);
    $form = $field === null ? null : $repository->find((int) $field['form_id']);
} catch (\Throwable $e) {
    error_log('[admin/form-field.php] ' . $e->getMessage());
    http_response_code(500);
    exit(admin_t('screen.veld_kon_geladen'));
}

if ($field === null || $form === null) {
    http_response_code(404);
    exit(admin_t('screen.veld_gevonden'));
}

$formId = (int) $form['id'];

$errors = $_SESSION['admin_form_field_errors'] ?? [];
$old = $_SESSION['admin_form_field_old'] ?? null;
unset($_SESSION['admin_form_field_errors'], $_SESSION['admin_form_field_old']);
$saved = isset($_GET['saved']);

$values = $old ?? [
    'field_type' => (string) $field['field_type'],
    'label_nl' => (string) $field['label_nl'],
    'label_en' => (string) ($field['label_en'] ?? ''),
    'placeholder_nl' => (string) ($field['placeholder_nl'] ?? ''),
    'placeholder_en' => (string) ($field['placeholder_en'] ?? ''),
    'help_text_nl' => (string) ($field['help_text_nl'] ?? ''),
    'help_text_en' => (string) ($field['help_text_en'] ?? ''),
    'is_required' => (bool) $field['is_required'],
    'options' => FormFieldOptions::toStored($field['options'] ?? null),
    'default_value' => (string) ($field['default_value'] ?? ''),
];

$currentType = FormFieldTypes::get((string) $values['field_type']);

// The default dropdown is built from the options as they are STORED right
// now, so an editor can only pick something the field really offers. Change
// the options and save, and this list is rebuilt on the next render.
$currentOptions = FormFieldOptions::fromStored($field['options'] ?? null);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$v = static fn (array $values, string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $field['label_nl']) ?> <?= admin_te('forms.veld_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/form.php?id=<?= $formId ?>"><?= admin_t('forms.text', ['v1' => $h((string) $form['name'])]) ?></a></p>
  <h1><?= $h((string) $field['label_nl']) ?></h1>
  <p class="admin-text-muted"><?= admin_t('forms.veld_postnaam_ligt_vast', ['v1' => $h((string) $form['name']), 'v2' => $h((string) $field['field_key'])]) ?></p>

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

  <section class="admin-card">
    <form method="post" action="/api/admin/update-form-field.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="field_id" value="<?= $id ?>">

      <label><?= admin_te('forms.veldtype') ?>*
        <select name="field_type" required>
          <?php foreach (FormFieldTypes::choices() as $key => $label): ?>
            <option value="<?= $h($key) ?>" <?= ($values['field_type'] ?? '') === $key ? 'selected' : '' ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('forms.label') ?>*
          <input type="text" name="label_nl" maxlength="200" <?= admin_lang_required('nl') ?> value="<?= $v($values, 'label_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('forms.label_2') ?>
          <input type="text" name="label_en" maxlength="200" value="<?= $v($values, 'label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('forms.tussenkopje_uitleg') ?>
          <input type="text" name="help_text_nl" maxlength="500" value="<?= $v($values, 'help_text_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('forms.tussenkopje_uitleg_2') ?>
          <input type="text" name="help_text_en" maxlength="500" value="<?= $v($values, 'help_text_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('forms.placeholder') ?>
          <input type="text" name="placeholder_nl" maxlength="200" value="<?= $v($values, 'placeholder_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('forms.placeholder_2') ?>
          <input type="text" name="placeholder_en" maxlength="200" value="<?= $v($values, 'placeholder_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('forms.placeholder_heeft_alleen_zin') ?></p>

      <label><?= admin_te('forms.opties_alleen_keuzelijst_keuzerondjes') ?>
        <textarea name="options" rows="6" placeholder="Ja|Yes&#10;Nee|No&#10;Misschien|Maybe"><?= $v($values, 'options') ?></textarea>
      </label>
      <p class="admin-text-muted"><?= admin_t('forms.e_n_keuze_per', ['v1' => FormFieldOptions::MAX_OPTIONS]) ?></p>

      <?php if ($currentType !== null && $currentType->usesDefaultValue()): ?>
        <label><?= admin_te('forms.standaardwaarde_alvast_aangevinkt_geselectee') ?>
          <select name="default_value">
            <option value=""><?= admin_te('forms.bezoeker_kiest_zelf') ?></option>
            <?php foreach ($currentOptions->all() as $option): ?>
              <option value="<?= $h($option->nl) ?>" <?= ($values['default_value'] ?? '') === $option->nl ? 'selected' : '' ?>><?= $h($option->nl) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="admin-text-muted"><?= admin_te('forms.alleen_kiezen_uit_opties') ?></p>
      <?php endif; ?>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_required" value="1" <?= ($values['is_required'] ?? false) ? 'checked' : '' ?> <?= $currentType !== null && $currentType->requiredIsFixed() ? 'checked disabled' : '' ?>>
        <?= admin_te('forms.verplicht_invullen') ?>
      </label>
      <?php if ($currentType !== null && $currentType->requiredIsFixed()): ?>
        <p class="admin-text-muted"><?= admin_te('forms.akkoordvinkje_altijd_verplicht_akkoord') ?></p>
      <?php endif; ?>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('forms.veld_verwijderen') ?></h2>
    <p class="admin-text-muted"><?= admin_te('forms.veld_verdwijnt_uit_formulier') ?></p>
    <form method="post" action="/api/admin/delete-form-field.php" onsubmit="return confirm('Dit veld verwijderen?');">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="field_id" value="<?= $id ?>">
      <button type="submit"><?= admin_te('forms.definitief_verwijderen') ?></button>
    </form>
  </section>
</main>
<?php admin_lang_script(); ?>
</body>
</html>
