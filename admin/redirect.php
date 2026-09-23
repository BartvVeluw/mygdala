<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Repository\RedirectRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Redirects\Redirect;
use App\Service\Redirects\RedirectPath;
use App\Service\Redirects\RedirectTarget;

/**
 * One redirect: add or edit. Same shape as admin/navigation-item.php — a form
 * that posts to a dedicated endpoint, with the session flash carrying the
 * errors and the submitted values back on a rejection, so nothing an editor
 * typed is lost when a source path turns out to collide.
 *
 * Every rule this form hints at is enforced server-side in
 * App\Service\Redirects\RedirectValidator; the disabled fields and the
 * dropdowns below are convenience, not validation.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$repository = new RedirectRepository();

$isNew = !array_key_exists('id', $_GET);
$redirect = null;

if (!$isNew) {
    $idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($idParam === false || $idParam === null || $idParam < 1) {
        http_response_code(404);
        exit(admin_t('screen.redirect_gevonden'));
    }
    $redirect = $repository->findById($idParam);
    if ($redirect === null) {
        http_response_code(404);
        exit(admin_t('screen.redirect_gevonden'));
    }
}

$redirect ??= [
    'id' => null,
    'source_path' => '',
    'target_type' => RedirectTarget::TYPE_INTERNAL,
    'target_value' => '',
    'status_code' => Redirect::DEFAULT_STATUS,
    'is_active' => 1,
    'origin' => Redirect::ORIGIN_MANUAL,
];

$errors = $_SESSION['admin_redirect_errors'] ?? [];
$old = $_SESSION['admin_redirect_old'] ?? null;
unset($_SESSION['admin_redirect_errors'], $_SESSION['admin_redirect_old']);

$fieldValue = static function (?array $old, array $redirect, string $key) : string {
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    return (string) ($redirect[$key] ?? '');
};

$targetType = $fieldValue($old, $redirect, 'target_type');
if (!RedirectTarget::isValidType($targetType)) {
    $targetType = RedirectTarget::TYPE_INTERNAL;
}

$statusCode = (int) $fieldValue($old, $redirect, 'status_code');
if (!Redirect::isValidStatusCode($statusCode)) {
    $statusCode = Redirect::DEFAULT_STATUS;
}

$isActive = $old !== null ? !empty($old['is_active']) : (int) $redirect['is_active'] === 1;
$origin = (string) $redirect['origin'];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$pageTitle = $isNew ? admin_t('redirects.new') : (string) $redirect['source_path'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> <?= admin_te('redirects.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/redirects.php"><?= admin_t('redirects.terug_redirects') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>

  <?php if (!$isNew && $origin === Redirect::ORIGIN_SLUG_CHANGE): ?>
    <p class="admin-text-muted"><?= admin_te('redirects.redirect_automatisch_aangemaakt_toen') ?></p>
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

  <form method="post" action="<?= $isNew ? '/api/admin/create-redirect.php' : '/api/admin/update-redirect.php' ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= (int) $redirect['id'] ?>">
    <?php endif; ?>

    <section class="admin-card">
      <h2><?= admin_te('redirects.vanaf') ?></h2>
      <label><?= admin_te('redirects.pad_site') ?>*
        <input type="text" name="source_path" maxlength="<?= RedirectPath::MAX_LENGTH ?>" required
               value="<?= $h($fieldValue($old, $redirect, 'source_path')) ?>" placeholder="/oude-pagina">
      </label>
      <p class="admin-text-muted"><?= admin_t('redirects.zonder_domeinnaam_zonder_vraagteken') ?></p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('redirects.text') ?></h2>
      <label><?= admin_te('redirects.soort_bestemming') ?>
        <select name="target_type" id="redirect-target-type">
          <?php /* The stored type never changes; only the word beside it. */ ?>
          <?php foreach (RedirectTarget::TYPE_LABELS as $type => $label): ?>
            <option value="<?= $h($type) ?>" <?= $targetType === $type ? 'selected' : '' ?>><?= $h(admin_registry_label('redirects.target_' . $type, $label)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-redirect-target-field="<?= RedirectTarget::TYPE_INTERNAL ?>"><?= admin_te('redirects.pad_site_2') ?>*
        <input type="text" name="target_internal" maxlength="<?= RedirectTarget::MAX_LENGTH ?>"
               value="<?= $h($targetType === RedirectTarget::TYPE_INTERNAL ? $fieldValue($old, $redirect, 'target_value') : '') ?>"
               placeholder="/nieuwe-pagina">
      </label>

      <label data-redirect-target-field="<?= RedirectTarget::TYPE_EXTERNAL ?>"><?= admin_te('redirects.externe_url') ?>*
        <input type="text" name="target_external" maxlength="<?= RedirectTarget::MAX_LENGTH ?>"
               value="<?= $h($targetType === RedirectTarget::TYPE_EXTERNAL ? $fieldValue($old, $redirect, 'target_value') : '') ?>"
               placeholder="https://voorbeeld.nl/pagina">
      </label>
      <p class="admin-text-muted" data-redirect-target-field="<?= RedirectTarget::TYPE_EXTERNAL ?>"><?= admin_t('redirects.bezoeker_verlaat_hiermee_website') ?></p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('redirects.soort_redirect') ?></h2>
      <label><?= admin_te('redirects.statuscode') ?>
        <select name="status_code">
          <?php foreach (Redirect::STATUS_LABELS as $code => $label): ?>
            <option value="<?= (int) $code ?>" <?= $statusCode === (int) $code ? 'selected' : '' ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="admin-text-muted"><?= admin_te('redirects.kies_301_wanneer_inhoud') ?></p>
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('common.active') ?>
      </label>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>

    <script>
      (function () {
        var select = document.getElementById('redirect-target-type');
        if (!select) return;
        function sync() {
          document.querySelectorAll('[data-redirect-target-field]').forEach(function (field) {
            field.hidden = field.getAttribute('data-redirect-target-field') !== select.value;
          });
        }
        select.addEventListener('change', sync);
        sync();
      })();
    </script>
  </form>

  <?php if (!$isNew): ?>
    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>
      <p class="admin-text-muted"><?= admin_te('redirects.verwijdert_redirect_definitief_vanaf') ?></p>
      <form method="post" action="/api/admin/delete-redirect.php" onsubmit="return confirm('Deze redirect verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $redirect['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('redirects.redirect_verwijderen') ?></button>
      </form>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
