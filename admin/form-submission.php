<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Repository\FormSubmissionRepository;
use App\Service\AdminAuth;
use App\Service\ContactAttachmentStorage;
use App\Service\Csrf;
use App\Service\Forms\FormFileTypes;
use App\Service\Forms\FormUpload;

/**
 * One submission, exactly as it was sent.
 *
 * EVERY LABEL HERE COMES FROM THE SUBMISSION, not from the form. The rows in
 * `form_submission_values` carry the label and the type each answer was
 * given under, so a form that has since been renamed, reordered or stripped
 * of a field still reads correctly months later — and a field that no longer
 * exists still shows its answer instead of disappearing (FORMS.md, "Wat een
 * inzending bewaart"). Nothing on this screen joins back to `form_fields`.
 *
 * FILES. An upload field's answer is the file's name and size, and it is a
 * download link here, to api/admin/form-submission-attachment.php with this
 * submission's id and the file's own id. A file sent with the contact block
 * before Forms 2.0 phase 2 belongs to no field, and keeps its own card. A
 * file whose row exists but whose bytes are gone says so instead of linking.
 *
 * Opening it marks it read; that is the only state a submission has.
 *
 * Deleting asks first, in the CMS's own dialog (ADMIN-UI.md), naming when
 * it was sent; api/admin/delete-form-submission.php deletes it for real,
 * attachment included.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.submissions');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit(admin_t('screen.ongeldig_inzending_id'));
}

try {
    $repository = new FormSubmissionRepository();
    $submission = $repository->findForAdmin($id);
    $values = $submission === null ? [] : $repository->valuesFor($id);
    $attachments = $submission === null ? [] : $repository->attachmentsFor($id);

    if ($submission !== null && (int) $submission['is_read'] === 0) {
        $repository->setReadState($id, true);
        $submission['is_read'] = 1;
    }
} catch (\Throwable $e) {
    error_log('[admin/form-submission.php] ' . $e->getMessage());
    http_response_code(500);
    exit(admin_t('screen.inzending_kon_geladen'));
}

if ($submission === null) {
    http_response_code(404);
    exit(admin_t('screen.inzending_gevonden'));
}

// The files by the field they answer; the one without a field is the older
// contact-block attachment.
$storage = new ContactAttachmentStorage();
$filesByField = [];
$legacyFiles = [];
foreach ($attachments as $attachment) {
    $attachment['present'] = is_file($storage->path((string) $attachment['stored_filename']));
    if ($attachment['field_key'] === null || (string) $attachment['field_key'] === '') {
        $legacyFiles[] = $attachment;
    } else {
        $filesByField[(string) $attachment['field_key']] = $attachment;
    }
}

$downloadUrl = static fn (array $attachment): string => '/api/admin/form-submission-attachment.php?submission='
    . (int) $attachment['submission_id'] . '&file=' . (int) $attachment['id'];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('forms.inzending_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/form-submissions.php"><?= admin_t('forms.terug_inzendingen') ?></a></p>
  <div class="admin-main__heading">
    <h1><?= $h((string) $submission['form_name']) ?></h1>
    <span class="admin-badge admin-badge--muted"><?= admin_te('forms.gelezen') ?></span>
  </div>

  <section class="admin-card">
    <h2><?= admin_te('forms.gegevens') ?></h2>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <tbody>
        <tr>
          <th scope="row"><?= admin_te('forms.ontvangen') ?></th>
          <td><?= $h(date('d-m-Y H:i', strtotime((string) $submission['created_at']))) ?></td>
        </tr>
        <tr>
          <th scope="row"><?= admin_te('forms.verstuurd_vanaf') ?></th>
          <td><?= $submission['source_path'] === null ? '<span class="admin-text-muted">Onbekend</span>' : $h((string) $submission['source_path']) ?></td>
        </tr>
        <tr>
          <th scope="row"><?= admin_te('forms.melding_gemaild') ?></th>
          <td>
            <?php if ($submission['notification_sent_at'] !== null): ?>
              <?= $h(date('d-m-Y H:i', strtotime((string) $submission['notification_sent_at']))) ?>
            <?php else: ?>
              <span class="admin-badge admin-badge--info"><?= admin_te('forms.not_sent') ?></span>
              <span class="admin-text-muted"><?= admin_te('forms.submission_kept_mail_failed') ?></span>
            <?php endif; ?>
          </td>
        </tr>
      </tbody>
    </table>
    </div>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('forms.antwoorden') ?></h2>
    <?php if ($values === []): ?>
      <p class="admin-text-muted"><?= admin_te('forms.inzending_bevat_antwoorden') ?></p>
    <?php else: ?>
      <div class="admin-table-wrap">
      <table class="admin-table">
        <tbody>
          <?php foreach ($values as $value): ?>
            <tr>
              <th scope="row"><?= $h((string) $value['field_label']) ?></th>
              <?php $file = $filesByField[(string) $value['field_key']] ?? null; ?>
              <?php if ($file !== null): ?>
                <td class="admin-submission-file">
                  <?php if ($file['present']): ?>
                    <a href="<?= $h($downloadUrl($file)) ?>" download><?= $h((string) $file['original_filename']) ?></a>
                  <?php else: ?>
                    <?= $h((string) $file['original_filename']) ?>
                  <?php endif; ?>
                  <span class="admin-text-muted">(<?= $h(FormFileTypes::label(FormFileTypes::forMime((string) $file['mime_type']) ?? '?')) ?>, <?= $h(FormUpload::sizeLabel((int) $file['file_size'])) ?>)</span>
                  <?php if (!$file['present']): ?>
                    <span class="admin-badge admin-badge--info"><?= admin_te('forms.file_missing') ?></span>
                  <?php endif; ?>
                </td>
              <?php else: ?>
                <td style="white-space:pre-line;"><?= ((string) $value['value']) === '' ? '<span class="admin-text-muted">—</span>' : $h((string) $value['value']) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($attachments !== []): ?>
    <p class="admin-text-muted"><?= admin_te('forms.bijlagen_staan_buiten_webroot') ?></p>
  <?php endif; ?>

  <?php foreach ($legacyFiles as $attachment): ?>
    <section class="admin-card">
      <h2><?= admin_te('forms.bijlage') ?></h2>
      <p class="admin-submission-file">
        <?php if ($attachment['present']): ?>
          <a href="<?= $h($downloadUrl($attachment)) ?>" download><?= $h((string) $attachment['original_filename']) ?></a>
        <?php else: ?>
          <?= $h((string) $attachment['original_filename']) ?>
          <span class="admin-badge admin-badge--info"><?= admin_te('forms.file_missing') ?></span>
        <?php endif; ?>
        <span class="admin-text-muted">(<?= $h((string) $attachment['mime_type']) ?>, <?= $h(FormUpload::sizeLabel((int) $attachment['file_size'])) ?>)</span>
      </p>
    </section>
  <?php endforeach; ?>

  <section class="admin-card">
    <h2><?= admin_te('common.delete') ?></h2>
    <p class="admin-text-muted"><?= admin_te('forms.verwijdert_inzending_alle_antwoorden') ?></p>
    <form method="post" action="/api/admin/delete-form-submission.php"<?= admin_confirm_attributes(
        admin_t('forms.delete_submission.title'),
        admin_t('forms.delete_submission.message', [
            'date' => date('d-m-Y H:i', strtotime((string) $submission['created_at'])),
            'form' => (string) $submission['form_name'],
        ]),
        admin_t('common.delete')
    ) ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= (int) $submission['id'] ?>">
      <button type="submit"><?= admin_te('forms.definitief_verwijderen') ?></button>
    </form>
  </section>
</main>
<?= admin_confirm_dialog() ?>
</body>
</html>
