<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\AdminAuth;

/**
 * Beheer → Inzendingen: what people sent through the site's forms.
 *
 * BEHIND ITS OWN PERMISSION, and the more restrictive of the two. Building a
 * form is content work that any page editor may do; reading the names,
 * addresses and questions of the people who filled it in is not, and there
 * is no reason those two should travel together (FORMS.md, "Rechten").
 *
 * Only forms with "inzendingen bewaren" switched on appear here at all — a
 * form that does not store leaves nothing behind to show.
 *
 * NO CRM. A list, a detail screen, read/unread and delete. No stages, no
 * notes, no assignments, no tags and no export: FORMS.md lists all of those
 * as deliberately out of scope, because the moment this becomes a place to
 * WORK the personal data in it starts being kept for a different reason than
 * "somebody wrote in".
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.submissions');

$formFilter = filter_input(INPUT_GET, 'form', FILTER_VALIDATE_INT);
$formFilter = ($formFilter === false || $formFilter === null || $formFilter < 1) ? null : $formFilter;

$readFilter = $_GET['read'] ?? 'all';
if (!in_array($readFilter, ['all', 'unread', 'read'], true)) {
    $readFilter = 'all';
}

try {
    $repository = new FormSubmissionRepository();
    $submissions = $repository->findAllForAdmin($formFilter, $readFilter === 'all' ? null : $readFilter);
    $previews = $repository->previewsFor(array_map(static fn (array $row): int => (int) $row['id'], $submissions));
    $forms = (new FormRepository())->all();
    $loadFailed = false;
} catch (\Throwable $e) {
    error_log('[admin/form-submissions.php] ' . $e->getMessage());
    $submissions = [];
    $previews = [];
    $forms = [];
    $loadFailed = true;
}

$deleted = isset($_GET['deleted']);
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$filters = ['all' => 'Alle', 'unread' => 'Ongelezen', 'read' => 'Gelezen'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inzendingen — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1>Inzendingen</h1>
  <p class="admin-text-muted">Wat bezoekers via de formulieren op de site hebben gestuurd. Alleen formulieren waarbij "inzendingen bewaren" aan staat, bewaren iets. Verwijderen is definitief.</p>

  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success">Inzending verwijderd.</p>
  <?php endif; ?>

  <div class="admin-filter-tabs" role="tablist" aria-label="Filter op status">
    <?php foreach ($filters as $value => $label): ?>
      <a href="/admin/form-submissions.php?read=<?= urlencode($value) ?><?= $formFilter !== null ? '&amp;form=' . $formFilter : '' ?>" class="admin-filter-tab<?= $readFilter === $value ? ' is-active' : '' ?>"><?= $h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($forms !== []): ?>
    <div class="admin-filter-tabs" role="tablist" aria-label="Filter op formulier">
      <a href="/admin/form-submissions.php?read=<?= urlencode($readFilter) ?>" class="admin-filter-tab<?= $formFilter === null ? ' is-active' : '' ?>">Alle formulieren</a>
      <?php foreach ($forms as $form): ?>
        <a href="/admin/form-submissions.php?read=<?= urlencode($readFilter) ?>&amp;form=<?= (int) $form['id'] ?>" class="admin-filter-tab<?= $formFilter === (int) $form['id'] ? ' is-active' : '' ?>"><?= $h((string) $form['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($loadFailed): ?>
    <p class="admin-alert admin-alert--error">Inzendingen konden niet worden geladen.</p>
  <?php elseif ($submissions === []): ?>
    <p>Geen inzendingen gevonden.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Formulier</th>
          <th>Eerste antwoord</th>
          <th>Pagina</th>
          <th>Ontvangen</th>
          <th>Gemaild</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($submissions as $submission): ?>
          <?php
            $submissionId = (int) $submission['id'];
            $isUnread = (int) $submission['is_read'] === 0;
            $preview = $previews[$submissionId] ?? '';
            $mailed = $submission['notification_sent_at'] !== null;
          ?>
          <tr class="<?= $isUnread ? 'admin-row--unread' : '' ?>">
            <td><a href="/admin/form-submission.php?id=<?= $submissionId ?>"><?= $h((string) $submission['form_name']) ?></a></td>
            <td><?= $h(mb_strimwidth($preview, 0, 60, '…')) ?></td>
            <td><?= $submission['source_path'] === null ? '<span class="admin-text-muted">—</span>' : $h((string) $submission['source_path']) ?></td>
            <td><?= $h(date('d-m-Y H:i', strtotime((string) $submission['created_at']))) ?></td>
            <td>
              <?php if ($mailed): ?>
                <span class="admin-badge admin-badge--muted">Ja</span>
              <?php else: ?>
                <span class="admin-badge admin-badge--info" title="De melding is niet verstuurd. De inzending is wel bewaard.">Nee</span>
              <?php endif; ?>
            </td>
            <td><span class="admin-badge admin-badge--<?= $isUnread ? 'info' : 'muted' ?>"><?= $isUnread ? 'Nieuw' : 'Gelezen' ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
