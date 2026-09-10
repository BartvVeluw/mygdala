<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repository\RedirectRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Redirects\Redirect;
use App\Service\Redirects\RedirectTarget;
use App\Service\Redirects\RedirectValidator;

/**
 * Beheer → Redirects: every URL this site has moved, in one list.
 *
 * Manual rows and the ones a page rename created sit together on purpose (see
 * REDIRECTS.md): an automatic redirect an editor cannot see is an automatic
 * redirect they cannot understand, so each row says where it came from.
 *
 * Two things a row can be flagged for, both checked while rendering rather
 * than stored, because both can become true long after the row was written:
 * its destination belongs to a module that is switched off, and its source
 * path has since been taken over by real content.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$repository = new RedirectRepository();
$validator = new RedirectValidator($repository);

$search = trim((string) ($_GET['q'] ?? ''));
$redirects = $repository->findAllForAdmin($search);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$created = isset($_GET['created']);
$updated = isset($_GET['updated']);
$deleted = isset($_GET['deleted']);
$flashError = $_SESSION['admin_redirects_error'] ?? null;
unset($_SESSION['admin_redirects_error']);
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Redirects — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title">Redirects</h1>
      <p class="admin-page-head__desc">Stuur een oude of gewijzigde URL door naar de plek waar de inhoud nu staat. Een redirect werkt alleen op een pad dat op deze site niets meer oplevert &mdash; een bestaande pagina, route of bestand gaat altijd v&oacute;&oacute;r.</p>
    </div>
    <a href="/admin/redirect.php" class="admin-btn-link">+ Redirect toevoegen</a>
  </header>

  <?php if ($flashError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= $h((string) $flashError) ?></p>
  <?php endif; ?>
  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Redirect toegevoegd.</p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Redirect opgeslagen.</p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success">Redirect verwijderd.</p>
  <?php endif; ?>

  <section class="admin-card">
    <form method="get" action="/admin/redirects.php" class="admin-form-row admin-form-row--split">
      <label>Zoeken in vanaf-pad en bestemming
        <input type="search" name="q" value="<?= $h($search) ?>" placeholder="/oude-pagina of /diensten">
      </label>
      <button type="submit" style="align-self:flex-end;">Zoeken</button>
      <?php if ($search !== ''): ?>
        <a href="/admin/redirects.php" class="admin-btn-text" style="align-self:flex-end;">Wis zoekopdracht</a>
      <?php endif; ?>
    </form>
  </section>

  <section class="admin-card">
    <?php if ($redirects === []): ?>
      <p class="admin-text-muted">
        <?= $search === ''
            ? 'Nog geen redirects. Hernoem je de slug van een gepubliceerde pagina, dan komt de oude URL hier vanzelf te staan.'
            : 'Geen redirects gevonden voor deze zoekopdracht.' ?>
      </p>
    <?php else: ?>
      <div class="admin-page-sections">
        <?php foreach ($redirects as $redirect): ?>
          <?php
            $redirectId = (int) $redirect['id'];
            $sourcePath = (string) $redirect['source_path'];
            $targetType = (string) $redirect['target_type'];
            $targetValue = (string) $redirect['target_value'];
            $isActive = (bool) $redirect['is_active'];
            $isExternal = $targetType === RedirectTarget::TYPE_EXTERNAL;

            $disabledModule = RedirectTarget::disabledModuleFor($targetType, $targetValue);
            $takenOverBy = $validator->routeOwner($sourcePath);
          ?>
          <div class="admin-section-row<?= $isActive ? '' : ' is-hidden-section' ?>">
            <div class="admin-section-row__body">
              <p class="admin-section-row__name">
                <?= $h($sourcePath) ?>
                <span class="admin-text-muted">&rarr;</span>
                <?= $h($targetValue) ?>
                <?php if ($isExternal): ?>
                  <span class="admin-text-muted">(externe site)</span>
                <?php endif; ?>
              </p>
              <p class="admin-section-row__note">
                <?= $h(Redirect::statusLabel((int) $redirect['status_code'])) ?>
                &middot; <?= $isActive ? 'Actief' : 'Uit' ?>
                &middot; <?= $h(Redirect::originLabel((string) $redirect['origin'])) ?>
              </p>
              <?php if ($disabledModule !== null): ?>
                <p class="admin-section-row__note">
                  Let op: de bestemming hoort bij het onderdeel &quot;<?= $h(\App\Module\ModuleRegistry::label($disabledModule)) ?>&quot;, dat nu uit staat. Deze redirect wordt niet uitgevoerd zolang dat zo is; de instelling blijft bewaard.
                </p>
              <?php endif; ?>
              <?php if ($takenOverBy !== null): ?>
                <p class="admin-section-row__note">
                  Let op: <?= $h($takenOverBy) ?> Deze redirect wordt daarom niet meer uitgevoerd.
                </p>
              <?php endif; ?>
            </div>
            <div class="admin-section-row__actions">
              <a href="/admin/redirect.php?id=<?= $redirectId ?>" class="admin-section-row__edit">Bewerken &#8594;</a>
              <form method="post" action="/api/admin/toggle-redirect.php" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $redirectId ?>">
                <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                <button type="submit" class="admin-btn-text"><?= $isActive ? 'Uitzetten' : 'Aanzetten' ?></button>
              </form>
              <form method="post" action="/api/admin/delete-redirect.php" class="admin-inline-form" onsubmit="return confirm('Deze redirect verwijderen? De oude URL geeft daarna weer een 404.');">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $redirectId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
</body>
</html>
