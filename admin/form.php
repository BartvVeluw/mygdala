<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormRecipient;
use App\Service\Forms\FormUsage;

/**
 * The form editor: what the form is called, what it does when somebody sends
 * it, and its fields in order.
 *
 * Three sections, in the order an editor thinks about them — Algemeen,
 * Melding, Velden — and no drag-and-drop page builder anywhere. Reordering
 * is the same pair of up/down buttons every repeater in this CMS uses
 * (admin/faq.php, admin/card-carousel.php), which works without JavaScript
 * and needs no library.
 *
 * Editing ONE field happens on its own screen (admin/form-field.php), like a
 * carousel card: a field has nine settings, and nine of them per row inline
 * would make a five-field form unreadable.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit(admin_t('screen.ongeldig_formulier_id'));
}

try {
    $repository = new FormRepository();
    $row = $repository->find($id);
    $fieldRows = $row === null ? [] : $repository->fieldsFor($id);
} catch (\Throwable $e) {
    error_log('[admin/form.php] ' . $e->getMessage());
    http_response_code(500);
    exit(admin_t('screen.formulier_kon_geladen'));
}

if ($row === null) {
    http_response_code(404);
    exit(admin_t('screen.formulier_gevonden'));
}

$definition = FormCatalog::find($id);
$placements = FormUsage::placements($id);
$blockers = FormUsage::deletionBlockers($id);

$errors = $_SESSION['admin_form_errors'] ?? [];
$old = $_SESSION['admin_form_old'] ?? null;
unset($_SESSION['admin_form_errors'], $_SESSION['admin_form_old']);
$saved = isset($_GET['saved']);

$values = $old ?? [
    'name' => (string) $row['name'],
    'is_active' => (bool) $row['is_active'],
    'submit_label_nl' => (string) $row['submit_label_nl'],
    'submit_label_en' => (string) ($row['submit_label_en'] ?? ''),
    'success_message_nl' => (string) $row['success_message_nl'],
    'success_message_en' => (string) ($row['success_message_en'] ?? ''),
    'notification_email' => (string) ($row['notification_email'] ?? ''),
    'reply_to_field_key' => (string) ($row['reply_to_field_key'] ?? ''),
    'store_submissions' => (bool) $row['store_submissions'],
];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$v = static fn (array $values, string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');

$siteFallback = FormRecipient::siteFallback();
$replyToCandidates = $definition === null ? [] : $definition->replyToCandidates();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $row['name']) ?> <?= admin_te('forms.formulier_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/forms.php"><?= admin_t('forms.formulieren') ?></a></p>
  <div class="admin-main__heading">
    <h1><?= $h((string) $row['name']) ?></h1>
    <span class="admin-badge admin-badge--<?= $row['is_active'] ? 'info' : 'muted' ?>"><?= $row['is_active'] ? admin_t('common.active') : 'Uit' ?></span>
  </div>

  <?php if ($placements === []): ?>
    <p class="admin-text-muted"><?= admin_t('forms.formulier_staat_enkele_pagina') ?></p>
  <?php else: ?>
    <p class="admin-text-muted">Staat op:
      <?php foreach ($placements as $index => $placement): ?><?= $index > 0 ? ', ' : '' ?><?php if ($placement['edit_url'] !== ''): ?><a href="<?= $h($placement['edit_url']) ?>"><?= $h($placement['page_title']) ?></a><?php else: ?><?= $h($placement['page_title']) ?><?php endif; ?><?php endforeach; ?>.
    </p>
  <?php endif; ?>

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

  <form method="post" action="/api/admin/update-form.php" class="admin-product-form">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <section class="admin-card">
      <h2><?= admin_te('forms.algemeen') ?></h2>

      <label><?= admin_te('forms.naam_alleen_jezelf_bezoekers') ?>*
        <input type="text" name="name" maxlength="150" required value="<?= $v($values, 'name') ?>">
      </label>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('forms.actief_uitgevinkt_formulier_nergens') ?>
      </label>

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('forms.tekst_verstuurknop') ?>
          <input type="text" name="submit_label_nl" maxlength="150" value="<?= $v($values, 'submit_label_nl') ?>" placeholder="Versturen">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('forms.tekst_verstuurknop_2') ?>
          <input type="text" name="submit_label_en" maxlength="150" value="<?= $v($values, 'submit_label_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('forms.bedankbericht_na_versturen') ?>
          <textarea name="success_message_nl" maxlength="1000" rows="3" placeholder="Bedankt — je bericht is verstuurd."><?= $v($values, 'success_message_nl') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('forms.bedankbericht_na_versturen_2') ?>
          <textarea name="success_message_en" maxlength="1000" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= $v($values, 'success_message_en') ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('forms.melding') ?></h2>

      <label><?= admin_te('forms.e_mailadres_melding_krijgt') ?>
        <input type="email" name="notification_email" maxlength="254" value="<?= $v($values, 'notification_email') ?>" placeholder="<?= $h($siteFallback ?? 'Vul een adres in bij Site-instellingen') ?>">
      </label>
      <p class="admin-text-muted">
        <?php if ($siteFallback !== null): ?>
          <?= admin_t('forms.fallback_recipient', ['v1' => $h($siteFallback)]) ?>
        <?php else: ?>
          <?= admin_t('forms.no_fallback_recipient') ?>
        <?php endif; ?>
      </p>

      <label><?= admin_te('forms.antwoordadres_reply_to_overnemen') ?>
        <select name="reply_to_field_key">
          <option value=""><?= admin_te('forms.gebruiken') ?></option>
          <?php foreach ($replyToCandidates as $candidate): ?>
            <option value="<?= $h($candidate->key) ?>" <?= ($values['reply_to_field_key'] ?? '') === $candidate->key ? 'selected' : '' ?>><?= $h($candidate->label->nl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="admin-text-muted"><?= admin_t('forms.kies_e_mailveld_melding', ['v1' => $replyToCandidates === [] ? ' Voeg eerst een veld van het type "E-mailadres" toe.' : '']) ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="store_submissions" value="1" <?= ($values['store_submissions'] ?? false) ? 'checked' : '' ?>>
        <?= admin_te('forms.inzendingen_bewaren_cms') ?>
      </label>
      <p class="admin-text-muted"><?= admin_t('forms.uit_formulier_mailt_alleen') ?></p>
    </section>

    <button type="submit"><?= admin_te('common.save') ?></button>
  </form>

  <section class="admin-card">
    <h2><?= admin_te('forms.velden') ?></h2>

    <?php if ($fieldRows === []): ?>
      <p class="admin-text-muted"><?= admin_te('forms.formulier_heeft_velden_zolang') ?></p>
    <?php endif; ?>

    <?php foreach ($fieldRows as $index => $field): ?>
      <?php
        $fieldId = (int) $field['id'];
        $type = FormFieldTypes::get((string) $field['field_type']);
        $isFirst = $index === 0;
        $isLast = $index === count($fieldRows) - 1;
        $optionCount = $type !== null && $type->usesOptions()
            ? FormFieldOptions::fromStored($field['options'] ?? null)->count()
            : 0;
      ?>
      <article class="admin-card" style="margin-top:1rem;">
        <div class="admin-main__heading">
          <h3 style="margin:0;"><?= $h((string) $field['label_nl']) ?></h3>
          <?php if ((int) $field['is_required'] === 1): ?>
            <span class="admin-badge admin-badge--info"><?= admin_te('common.required_badge') ?></span>
          <?php endif; ?>
        </div>
        <p class="admin-text-muted">
          <?= $h($type !== null ? $type->label() : 'Onbekend veldtype (' . (string) $field['field_type'] . ')') ?>
          &middot; postnaam <code><?= $h((string) $field['field_key']) ?></code>
          <?php if ($type !== null && $type->usesOptions()): ?>
            &middot; <?= $optionCount ?> keuze<?= $optionCount === 1 ? '' : 's' ?>
          <?php endif; ?>
        </p>
        <?php if ($type === null): ?>
          <p class="admin-alert admin-alert--error"><?= admin_te('forms.veldtype_bestaat_meer_veld') ?></p>
        <?php elseif ($type->usesOptions() && $optionCount === 0): ?>
          <p class="admin-alert admin-alert--error"><?= admin_te('forms.keuzeveld_heeft_opties_dus') ?></p>
        <?php endif; ?>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <a class="admin-btn-text" href="/admin/form-field.php?id=<?= $fieldId ?>"><?= admin_te('common.edit') ?></a>
          <form method="post" action="/api/admin/move-form-field.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>><?= admin_t('common.move_up') ?></button>
          </form>
          <form method="post" action="/api/admin/move-form-field.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>><?= admin_t('common.move_down') ?></button>
          </form>
          <form method="post" action="/api/admin/delete-form-field.php" class="admin-inline-form" onsubmit="return confirm('Dit veld verwijderen? Bewaarde inzendingen blijven leesbaar.');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('forms.veld_toevoegen') ?></h2>
    <form method="post" action="/api/admin/create-form-field.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="form_id" value="<?= $id ?>">

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('forms.label') ?>*
          <input type="text" name="label_nl" maxlength="200" required>
        </label>
        <label><?= admin_te('forms.veldtype') ?>*
          <select name="field_type" required>
            <?php foreach (FormFieldTypes::choices() as $key => $label): ?>
              <option value="<?= $h($key) ?>"><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <button type="submit"><?= admin_te('forms.veld_toevoegen_2') ?></button>
    </form>
    <p class="admin-text-muted"><?= admin_te('forms.na_toevoegen_bewerken_engelse') ?></p>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('forms.formulier_verwijderen') ?></h2>
    <?php if ($blockers === []): ?>
      <p class="admin-text-muted"><?= admin_te('forms.formulier_staat_nergens_heeft') ?></p>
      <form method="post" action="/api/admin/delete-form.php" onsubmit="return confirm('Dit formulier definitief verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit"><?= admin_te('forms.definitief_verwijderen') ?></button>
      </form>
    <?php else: ?>
      <p class="admin-text-muted"><?= admin_te('forms.formulier_nu_verwijderd') ?></p>
      <ul class="admin-error-list">
        <?php foreach ($blockers as $blocker): ?>
          <li><?= $h($blocker) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
