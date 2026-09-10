<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
    exit('Ongeldig formulier-id.');
}

try {
    $repository = new FormRepository();
    $row = $repository->find($id);
    $fieldRows = $row === null ? [] : $repository->fieldsFor($id);
} catch (\Throwable $e) {
    error_log('[admin/form.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Formulier kon niet worden geladen.');
}

if ($row === null) {
    http_response_code(404);
    exit('Formulier niet gevonden.');
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
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $row['name']) ?> — Formulier — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/forms.php">&larr; Formulieren</a></p>
  <div class="admin-main__heading">
    <h1><?= $h((string) $row['name']) ?></h1>
    <span class="admin-badge admin-badge--<?= $row['is_active'] ? 'info' : 'muted' ?>"><?= $row['is_active'] ? 'Actief' : 'Uit' ?></span>
  </div>

  <?php if ($placements === []): ?>
    <p class="admin-text-muted">Dit formulier staat nog op geen enkele pagina. Voeg op een pagina het blok <strong>Formulier</strong> toe en kies dit formulier.</p>
  <?php else: ?>
    <p class="admin-text-muted">Staat op:
      <?php foreach ($placements as $index => $placement): ?><?= $index > 0 ? ', ' : '' ?><?php if ($placement['edit_url'] !== ''): ?><a href="<?= $h($placement['edit_url']) ?>"><?= $h($placement['page_title']) ?></a><?php else: ?><?= $h($placement['page_title']) ?><?php endif; ?><?php endforeach; ?>.
    </p>
  <?php endif; ?>

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

  <form method="post" action="/api/admin/update-form.php" class="admin-product-form">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <section class="admin-card">
      <h2>Algemeen</h2>

      <label>Naam (alleen voor jezelf, bezoekers zien dit niet)*
        <input type="text" name="name" maxlength="150" required value="<?= $v($values, 'name') ?>">
      </label>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        Actief (uitgevinkt = het formulier wordt nergens getoond, ook niet op pagina's waar het staat)
      </label>

      <div class="admin-form-row admin-form-row--split">
        <label>Tekst op de verstuurknop (NL)
          <input type="text" name="submit_label_nl" maxlength="150" value="<?= $v($values, 'submit_label_nl') ?>" placeholder="Versturen">
        </label>
        <label>Tekst op de verstuurknop (EN)
          <input type="text" name="submit_label_en" maxlength="150" value="<?= $v($values, 'submit_label_en') ?>" placeholder="Leeg = zelfde als NL">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Bedankbericht na versturen (NL)
          <textarea name="success_message_nl" maxlength="1000" rows="3" placeholder="Bedankt — je bericht is verstuurd."><?= $v($values, 'success_message_nl') ?></textarea>
        </label>
        <label>Bedankbericht na versturen (EN)
          <textarea name="success_message_en" maxlength="1000" rows="3" placeholder="Leeg = zelfde als NL"><?= $v($values, 'success_message_en') ?></textarea>
        </label>
      </div>
    </section>

    <section class="admin-card">
      <h2>Melding</h2>

      <label>E-mailadres dat de melding krijgt
        <input type="email" name="notification_email" maxlength="254" value="<?= $v($values, 'notification_email') ?>" placeholder="<?= $h($siteFallback ?? 'Vul een adres in bij Site-instellingen') ?>">
      </label>
      <p class="admin-text-muted">
        <?php if ($siteFallback !== null): ?>
          Leeg laten kan: dan gaat de melding naar <strong><?= $h($siteFallback) ?></strong>, het adres uit <a href="/admin/settings.php">Site-instellingen</a>.
        <?php else: ?>
          Er staat nog geen bruikbaar e-mailadres in <a href="/admin/settings.php">Site-instellingen</a>, dus vul hier een adres in — anders wordt er geen melding verstuurd.
        <?php endif; ?>
      </p>

      <label>Antwoordadres (Reply-To) overnemen uit
        <select name="reply_to_field_key">
          <option value="">Niet gebruiken</option>
          <?php foreach ($replyToCandidates as $candidate): ?>
            <option value="<?= $h($candidate->key) ?>" <?= ($values['reply_to_field_key'] ?? '') === $candidate->key ? 'selected' : '' ?>><?= $h($candidate->label->nl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="admin-text-muted">Kies een e-mailveld en je kunt de melding direct beantwoorden naar de bezoeker. De afzender blijft altijd het adres van de site zelf — een bezoeker kan die nooit veranderen.<?= $replyToCandidates === [] ? ' Voeg eerst een veld van het type "E-mailadres" toe.' : '' ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="store_submissions" value="1" <?= ($values['store_submissions'] ?? false) ? 'checked' : '' ?>>
        Inzendingen bewaren in het CMS
      </label>
      <p class="admin-text-muted">Uit = het formulier mailt alleen, en er blijft niets van de bezoeker achter in de database. Aan = je kunt de inzendingen teruglezen onder <strong>Inzendingen</strong>, en een inzending gaat niet verloren als het versturen van de e-mail mislukt. Je bewaart dan wel persoonsgegevens; verwijder wat je niet meer nodig hebt.</p>
    </section>

    <button type="submit">Opslaan</button>
  </form>

  <section class="admin-card">
    <h2>Velden</h2>

    <?php if ($fieldRows === []): ?>
      <p class="admin-text-muted">Dit formulier heeft nog geen velden. Zolang dat zo is, wordt het nergens getoond.</p>
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
            <span class="admin-badge admin-badge--info">Verplicht</span>
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
          <p class="admin-alert admin-alert--error">Dit veldtype bestaat niet (meer). Het veld wordt niet getoond en niet gevalideerd; verwijder het of kies een geldig type.</p>
        <?php elseif ($type->usesOptions() && $optionCount === 0): ?>
          <p class="admin-alert admin-alert--error">Dit keuzeveld heeft nog geen opties, dus het wordt niet getoond. Voeg ze toe bij "Bewerken".</p>
        <?php endif; ?>

        <div class="admin-image-card__actions" style="margin-top:0.75rem;">
          <a class="admin-btn-text" href="/admin/form-field.php?id=<?= $fieldId ?>">Bewerken</a>
          <form method="post" action="/api/admin/move-form-field.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <input type="hidden" name="direction" value="up">
            <button type="submit" class="admin-btn-text" <?= $isFirst ? 'disabled' : '' ?>>&uarr; Omhoog</button>
          </form>
          <form method="post" action="/api/admin/move-form-field.php" class="admin-inline-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <input type="hidden" name="direction" value="down">
            <button type="submit" class="admin-btn-text" <?= $isLast ? 'disabled' : '' ?>>&darr; Omlaag</button>
          </form>
          <form method="post" action="/api/admin/delete-form-field.php" class="admin-inline-form" onsubmit="return confirm('Dit veld verwijderen? Bewaarde inzendingen blijven leesbaar.');">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
            <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="admin-card">
    <h2>Veld toevoegen</h2>
    <form method="post" action="/api/admin/create-form-field.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="form_id" value="<?= $id ?>">

      <div class="admin-form-row admin-form-row--split">
        <label>Label (NL)*
          <input type="text" name="label_nl" maxlength="200" required>
        </label>
        <label>Veldtype*
          <select name="field_type" required>
            <?php foreach (FormFieldTypes::choices() as $key => $label): ?>
              <option value="<?= $h($key) ?>"><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <button type="submit">Veld toevoegen</button>
    </form>
    <p class="admin-text-muted">Na het toevoegen kun je bij "Bewerken" de Engelse tekst, een tussenkopje, een placeholder en (bij een keuzeveld) de opties invullen.</p>
  </section>

  <section class="admin-card">
    <h2>Formulier verwijderen</h2>
    <?php if ($blockers === []): ?>
      <p class="admin-text-muted">Dit formulier staat nergens en heeft geen bewaarde inzendingen, dus het kan weg. Verwijderen kan niet ongedaan worden gemaakt.</p>
      <form method="post" action="/api/admin/delete-form.php" onsubmit="return confirm('Dit formulier definitief verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit">Definitief verwijderen</button>
      </form>
    <?php else: ?>
      <p class="admin-text-muted">Dit formulier kan nu niet worden verwijderd:</p>
      <ul class="admin-error-list">
        <?php foreach ($blockers as $blocker): ?>
          <li><?= $h($blocker) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
