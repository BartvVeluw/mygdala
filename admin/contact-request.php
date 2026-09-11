<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_labels.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ContactRequestRepository;
use App\Repository\ContactRequestAttachmentRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('contact.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit(admin_t('screen.ongeldig_aanvraag_id'));
}

try {
    $request = (new ContactRequestRepository())->findByIdForAdmin($id);
    $attachment = $request !== null ? (new ContactRequestAttachmentRepository())->findByContactRequestId($id) : null;
} catch (\Throwable $e) {
    error_log('[admin/contact-request.php] ' . $e->getMessage());
    http_response_code(500);
    exit(admin_t('screen.aanvraag_kon_geladen'));
}

if ($request === null) {
    http_response_code(404);
    exit(admin_t('screen.aanvraag_gevonden'));
}

$updated = isset($_GET['updated']);
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('contact.contactaanvraag_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/contact-requests.php"><?= admin_t('contact.terug_contactaanvragen') ?></a></p>
  <div class="admin-main__heading">
    <h1><?= $h((string) $request['name']) ?></h1>
    <span class="admin-badge admin-badge--<?= $request['status'] === 'nieuw' ? 'info' : 'muted' ?>"><?= $h(adminContactStatusLabel((string) $request['status'])) ?></span>
  </div>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('contact.aanvraag_bijgewerkt') ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= admin_te('contact.contactgegevens') ?></h2>
    <p>
      <?= $h((string) $request['name']) ?><br>
      <a href="mailto:<?= $h((string) $request['email']) ?>"><?= $h((string) $request['email']) ?></a><br>
      <?php if (!empty($request['phone'])): ?>
        <?= $h((string) $request['phone']) ?><br>
      <?php endif; ?>
    </p>
    <p><?= admin_t('contact.audience_is', ['v1' => $h(adminContactAudienceLabel((string) $request['audience']))]) ?></strong></p>
    <p><?= admin_t('contact.received_at', ['v1' => $h(date('d-m-Y H:i', strtotime((string) $request['created_at'])))]) ?></p>
    <p>Notificatiemail: <?php if (!empty($request['notification_sent_at'])): ?>
        verzonden op <?= $h(date('d-m-Y H:i', strtotime((string) $request['notification_sent_at']))) ?>
      <?php else: ?>
        <span class="admin-text-muted"><?= admin_te('contact.notification_not_sent') ?></span>
      <?php endif; ?>
    </p>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('contact.bericht') ?></h2>
    <p style="white-space:pre-line;"><?= $h((string) $request['message']) ?></p>

    <?php if ($attachment !== null): ?>
      <p>
        <?= admin_te('contact.bijlage') ?>
        <a href="/api/admin/contact-request-attachment.php?id=<?= (int) $request['id'] ?>"><?= $h((string) $attachment['original_filename']) ?></a>
        <span class="admin-text-muted">(<?= $h(number_format((float) $attachment['file_size'] / 1024, 0, ',', '.')) ?> KB)</span>
      </p>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('common.actions') ?></h2>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
      <?php if ($request['status'] === 'nieuw'): ?>
        <form method="post" action="/api/admin/update-contact-request-status.php" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
          <input type="hidden" name="status" value="gelezen">
          <button type="submit" class="admin-btn-link"><?= admin_te('contact.markeer_gelezen') ?></button>
        </form>
      <?php else: ?>
        <form method="post" action="/api/admin/update-contact-request-status.php" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
          <input type="hidden" name="status" value="nieuw">
          <button type="submit" class="admin-btn-secondary"><?= admin_te('contact.markeer_nieuw') ?></button>
        </form>
      <?php endif; ?>

      <form method="post" action="/api/admin/delete-contact-request.php" class="admin-inline-form" onsubmit="return confirm('Deze aanvraag definitief verwijderen? Dit verwijdert ook de bijlage.');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
      </form>
    </div>
  </section>
</main>
</body>
</html>
