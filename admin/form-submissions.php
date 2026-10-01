<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormSubmissionBulk;

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
 *
 * PAGED, newest first (FormSubmissionRepository::PAGE_SIZE). Status and form
 * are filters, not a sort order: an unread enquiry stays where it arrived.
 *
 * IN BULK (FORMS.md, "Inzendingen in bulk"). Every row has a checkbox, and
 * the header one selects the rows ON THIS PAGE, never more: another page,
 * another filter or a reload starts with nothing selected, and nothing about
 * a selection is remembered anywhere. The bar above the table sends the
 * chosen ids and one action from a closed list to
 * api/admin/bulk-form-submissions.php, which decides what is allowed.
 * admin/assets/form-submissions.js only counts, shows the actions once
 * something is chosen and puts the number into the delete question. Without
 * the script the checkboxes and the three buttons still work.
 *
 * Nothing a visitor wrote goes into an attribute: a row's checkbox carries
 * the submission's id, and its label says when and on which form.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.submissions');

$formFilter = filter_input(INPUT_GET, 'form', FILTER_VALIDATE_INT);
$formFilter = ($formFilter === false || $formFilter === null || $formFilter < 1) ? null : $formFilter;

$readFilter = $_GET['read'] ?? 'all';
if (!in_array($readFilter, ['all', 'unread', 'read'], true)) {
    $readFilter = 'all';
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = ($page === false || $page === null || $page < 1) ? 1 : $page;

try {
    $repository = new FormSubmissionRepository();
    $readState = $readFilter === 'all' ? null : $readFilter;
    $result = $repository->findPageForAdmin($formFilter, $readState, $page);
    $lastPage = max(1, (int) ceil($result['total'] / FormSubmissionRepository::PAGE_SIZE));

    // A page past the last one — an old address, or a bulk delete that
    // emptied the page it came from — shows the last page there is.
    if ($page > $lastPage) {
        $page = $lastPage;
        $result = $repository->findPageForAdmin($formFilter, $readState, $page);
    }

    $submissions = $result['rows'];
    $total = $result['total'];
    $previews = $repository->previewsFor(array_map(static fn (array $row): int => (int) $row['id'], $submissions));
    $forms = (new FormRepository())->all();
    $loadFailed = false;
} catch (\Throwable $e) {
    error_log('[admin/form-submissions.php] ' . $e->getMessage());
    $submissions = [];
    $previews = [];
    $forms = [];
    $total = 0;
    $lastPage = 1;
    $loadFailed = true;
}

$deleted = isset($_GET['deleted']);
$flash = $_SESSION['admin_form_submissions_flash'] ?? null;
unset($_SESSION['admin_form_submissions_flash']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/** One way to write an overview address, so filters and paging agree on it. */
$overviewUrl = static function (string $read, ?int $form, int $page): string {
    $query = array_filter(
        ['read' => $read, 'form' => $form ?? '', 'page' => $page > 1 ? $page : ''],
        static fn (string|int $value): bool => $value !== ''
    );

    return '/admin/form-submissions.php' . ($query === [] ? '' : '?' . http_build_query($query));
};

$filters = ['all' => 'Alle', 'unread' => 'Ongelezen', 'read' => 'Gelezen'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('forms.inzendingen_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('forms.inzendingen') ?></h1>
  <p class="admin-text-muted"><?= admin_te('forms.wat_bezoekers_via_formulieren') ?></p>

  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('forms.inzending_verwijderd') ?></p>
  <?php endif; ?>

  <?php if (is_array($flash) && isset($flash['message'])): ?>
    <p class="admin-alert admin-alert--<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>"<?= ($flash['type'] ?? '') === 'success' ? '' : ' role="alert"' ?>><?= $h((string) $flash['message']) ?></p>
  <?php endif; ?>

  <div class="admin-filter-tabs" role="tablist" aria-label="Filter op status">
    <?php foreach ($filters as $value => $label): ?>
      <a href="<?= $h($overviewUrl($value, $formFilter, 1)) ?>" class="admin-filter-tab<?= $readFilter === $value ? ' is-active' : '' ?>"><?= $h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($forms !== []): ?>
    <div class="admin-filter-tabs" role="tablist" aria-label="Filter op formulier">
      <a href="<?= $h($overviewUrl($readFilter, null, 1)) ?>" class="admin-filter-tab<?= $formFilter === null ? ' is-active' : '' ?>">Alle formulieren</a>
      <?php foreach ($forms as $form): ?>
        <a href="<?= $h($overviewUrl($readFilter, (int) $form['id'], 1)) ?>" class="admin-filter-tab<?= $formFilter === (int) $form['id'] ? ' is-active' : '' ?>"><?= $h((string) $form['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($loadFailed): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('forms.inzendingen_konden_geladen') ?></p>
  <?php elseif ($submissions === []): ?>
    <p><?= admin_te('forms.inzendingen_gevonden') ?></p>
  <?php else: ?>
    <form method="post" action="/api/admin/bulk-form-submissions.php" class="admin-submission-bulk" data-submission-bulk>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <?php if ($formFilter !== null): ?>
        <input type="hidden" name="form" value="<?= (int) $formFilter ?>">
      <?php endif; ?>
      <input type="hidden" name="return_read" value="<?= $h($readFilter) ?>">
      <input type="hidden" name="return_page" value="<?= (int) $page ?>">

      <div class="admin-submission-bulk__bar" data-submission-bulk-bar>
        <p class="admin-submission-bulk__count" role="status" aria-live="polite" data-submission-selected-count
           data-text-one="<?= admin_te('forms.bulk.count_one') ?>"
           data-text-many="<?= admin_te('forms.bulk.count') ?>"
           data-text-none="<?= admin_te('forms.bulk.count_none') ?>"><?= admin_te('forms.bulk.count_none') ?></p>
        <?php if ($lastPage > 1): ?>
          <p class="admin-submission-bulk__scope admin-text-muted"><?= admin_te('forms.bulk.page_only') ?></p>
        <?php endif; ?>
        <div class="admin-submission-bulk__actions" role="group" aria-label="<?= admin_te('forms.bulk.actions_label') ?>" data-submission-bulk-actions>
          <button type="submit" name="action" value="<?= FormSubmissionBulk::MARK_READ ?>" class="admin-btn-secondary"><?= admin_te('forms.bulk.mark_read') ?></button>
          <button type="submit" name="action" value="<?= FormSubmissionBulk::MARK_UNREAD ?>" class="admin-btn-secondary"><?= admin_te('forms.bulk.mark_unread') ?></button>
          <button type="submit" name="action" value="<?= FormSubmissionBulk::DELETE ?>" class="admin-btn-danger" data-submission-bulk-delete
                  data-text-one="<?= admin_te('forms.bulk.confirm_one') ?>"
                  data-text-many="<?= admin_te('forms.bulk.confirm_many') ?>"<?= admin_confirm_attributes(
              admin_t('forms.bulk.confirm_title'),
              admin_t('forms.bulk.confirm_selected'),
              admin_t('common.delete')
          ) ?>><?= admin_te('forms.bulk.delete') ?></button>
        </div>
      </div>

      <div class="admin-table-wrap">
      <table class="admin-table admin-submission-table">
        <thead>
          <tr>
            <th class="admin-submission-table__select" scope="col">
              <input type="checkbox" class="admin-checkbox" id="submission-select-all" data-submission-select-all hidden>
              <label class="admin-visually-hidden" for="submission-select-all"><?= admin_te('forms.bulk.select_all') ?></label>
            </th>
            <th scope="col"><?= admin_te('forms.formulier') ?></th>
            <th scope="col"><?= admin_te('forms.eerste_antwoord') ?></th>
            <th scope="col"><?= admin_te('forms.pagina') ?></th>
            <th scope="col"><?= admin_te('forms.ontvangen') ?></th>
            <th scope="col"><?= admin_te('forms.gemaild') ?></th>
            <th scope="col"><?= admin_te('common.status') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($submissions as $submission): ?>
            <?php
              $submissionId = (int) $submission['id'];
              $isUnread = (int) $submission['is_read'] === 0;
              $preview = $previews[$submissionId] ?? '';
              $mailed = $submission['notification_sent_at'] !== null;
              $receivedAt = date('d-m-Y H:i', strtotime((string) $submission['created_at']));
            ?>
            <tr class="<?= $isUnread ? 'admin-row--unread' : '' ?>" data-submission-row>
              <td class="admin-submission-table__select">
                <input type="checkbox" class="admin-checkbox" name="ids[]" value="<?= $submissionId ?>" id="submission-select-<?= $submissionId ?>" data-submission-select>
                <label class="admin-visually-hidden" for="submission-select-<?= $submissionId ?>"><?= admin_te('forms.bulk.select_row', ['date' => $receivedAt, 'form' => (string) $submission['form_name']]) ?></label>
              </td>
              <td><a href="/admin/form-submission.php?id=<?= $submissionId ?>"><?= $h((string) $submission['form_name']) ?></a></td>
              <td><?= $h(mb_strimwidth($preview, 0, 60, '…')) ?></td>
              <td><?= $submission['source_path'] === null ? '<span class="admin-text-muted">—</span>' : $h((string) $submission['source_path']) ?></td>
              <td><?= $h($receivedAt) ?></td>
              <td>
                <?php if ($mailed): ?>
                  <span class="admin-badge admin-badge--muted">Ja</span>
                <?php else: ?>
                  <span class="admin-badge admin-badge--info" title="De melding is niet verstuurd. De inzending is wel bewaard.">Nee</span>
                <?php endif; ?>
              </td>
              <td><span class="admin-badge admin-badge--<?= $isUnread ? 'info' : 'muted' ?>"><?= $isUnread ? admin_t('common.new_item') : 'Gelezen' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </form>

    <?php if ($lastPage > 1): ?>
      <nav class="admin-pagination" aria-label="<?= admin_te('forms.page.label') ?>">
        <?php if ($page > 1): ?>
          <a class="admin-btn-text" href="<?= $h($overviewUrl($readFilter, $formFilter, $page - 1)) ?>"><?= admin_te('forms.page.previous') ?></a>
        <?php endif; ?>
        <span class="admin-text-muted"><?= admin_te('forms.page.x_of_y', ['page' => (int) $page, 'last' => (int) $lastPage, 'total' => (int) $total]) ?></span>
        <?php if ($page < $lastPage): ?>
          <a class="admin-btn-text" href="<?= $h($overviewUrl($readFilter, $formFilter, $page + 1)) ?>"><?= admin_te('forms.page.next') ?></a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/form-submissions.js') ?>" defer></script>
</body>
</html>
