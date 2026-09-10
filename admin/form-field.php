<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
    exit('Ongeldig veld-id.');
}

try {
    $repository = new FormRepository();
    $field = $repository->findField($id);
    $form = $field === null ? null : $repository->find((int) $field['form_id']);
} catch (\Throwable $e) {
    error_log('[admin/form-field.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Veld kon niet worden geladen.');
}

if ($field === null || $form === null) {
    http_response_code(404);
    exit('Veld niet gevonden.');
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
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $field['label_nl']) ?> — Veld — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/form.php?id=<?= $formId ?>">&larr; <?= $h((string) $form['name']) ?></a></p>
  <h1><?= $h((string) $field['label_nl']) ?></h1>
  <p class="admin-text-muted">Veld in <strong><?= $h((string) $form['name']) ?></strong>. De postnaam <code><?= $h((string) $field['field_key']) ?></code> ligt vast: bewaarde inzendingen zijn eronder opgeslagen. De labels mag je gerust wijzigen — oude inzendingen houden de tekst waarmee ze verstuurd zijn.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
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

      <label>Veldtype*
        <select name="field_type" required>
          <?php foreach (FormFieldTypes::choices() as $key => $label): ?>
            <option value="<?= $h($key) ?>" <?= ($values['field_type'] ?? '') === $key ? 'selected' : '' ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Label*
          <input type="text" name="label_nl" maxlength="200" <?= admin_lang_required('nl') ?> value="<?= $v($values, 'label_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Label
          <input type="text" name="label_en" maxlength="200" value="<?= $v($values, 'label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Tussenkopje / uitleg
          <input type="text" name="help_text_nl" maxlength="500" value="<?= $v($values, 'help_text_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Tussenkopje / uitleg
          <input type="text" name="help_text_en" maxlength="500" value="<?= $v($values, 'help_text_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Placeholder
          <input type="text" name="placeholder_nl" maxlength="200" value="<?= $v($values, 'placeholder_nl') ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Placeholder
          <input type="text" name="placeholder_en" maxlength="200" value="<?= $v($values, 'placeholder_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted">Een placeholder heeft alleen zin bij een invulveld; bij een keuzelijst, keuzerondjes of een vinkje wordt hij genegeerd.</p>

      <label>Opties (alleen bij een keuzelijst of keuzerondjes)
        <textarea name="options" rows="6" placeholder="Ja|Yes&#10;Nee|No&#10;Misschien|Maybe"><?= $v($values, 'options') ?></textarea>
      </label>
      <p class="admin-text-muted">Eén keuze per regel. Wil je ook een Engelse versie, zet die er dan achter met een <code>|</code> ertussen: <code>Ja|Yes</code>. Wat de bezoeker kiest wordt bewaard zoals het er in het Nederlands staat. Maximaal <?= FormFieldOptions::MAX_OPTIONS ?> keuzes.</p>

      <?php if ($currentType !== null && $currentType->usesDefaultValue()): ?>
        <label>Standaardwaarde (alvast aangevinkt of geselecteerd)
          <select name="default_value">
            <option value="">Geen — de bezoeker kiest zelf</option>
            <?php foreach ($currentOptions->all() as $option): ?>
              <option value="<?= $h($option->nl) ?>" <?= ($values['default_value'] ?? '') === $option->nl ? 'selected' : '' ?>><?= $h($option->nl) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="admin-text-muted">Je kunt alleen kiezen uit de opties hierboven; heb je die net gewijzigd, sla dan eerst op. De bezoeker kan altijd iets anders kiezen, en wat hij invulde blijft na een foutmelding gewoon staan.</p>
      <?php endif; ?>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_required" value="1" <?= ($values['is_required'] ?? false) ? 'checked' : '' ?> <?= $currentType !== null && $currentType->requiredIsFixed() ? 'checked disabled' : '' ?>>
        Verplicht invullen
      </label>
      <?php if ($currentType !== null && $currentType->requiredIsFixed()): ?>
        <p class="admin-text-muted">Een akkoordvinkje is altijd verplicht: een akkoord dat je mag overslaan is geen akkoord.</p>
      <?php endif; ?>

      <button type="submit">Opslaan</button>
    </form>
  </section>

  <section class="admin-card">
    <h2>Veld verwijderen</h2>
    <p class="admin-text-muted">Het veld verdwijnt uit het formulier. Bewaarde inzendingen blijven gewoon leesbaar: die hebben hun eigen kopie van het label en het antwoord.</p>
    <form method="post" action="/api/admin/delete-form-field.php" onsubmit="return confirm('Dit veld verwijderen?');">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="field_id" value="<?= $id ?>">
      <button type="submit">Definitief verwijderen</button>
    </form>
  </section>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
