<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\AdminAuth;
use App\Service\AssetVersion;
use App\Service\Csrf;
use App\Update\AppVersion;
use App\Update\MaintenanceMode;
use App\Update\PreflightCheck;
use App\Update\ReleaseDescriptor;
use App\Update\ReleaseKeys;
use App\Update\UpdateConfig;
use App\Update\UpdateException;
use App\Update\Updater;
use App\Update\UpdateState;

/**
 * Instellingen → Updates: the built-in updater's screen (docs/updates/).
 *
 * What it shows: the installed version, the newest release the feed offers
 * with its notes and requirements, and — while an update runs — its steps.
 * What it never does: run an update step on render. Steps run through
 * api/admin/updates-step.php, one request each, driven by
 * admin/assets/updates.js; this page only reads the state and says where the
 * update is. A page loaded while an update is unfinished therefore shows
 * "onderbroken bij stap X" with a Doorgaan button, instead of silently
 * continuing something the administrator may have walked away from.
 *
 * ONE OF THE FEW SCREENS THAT STAYS OPEN DURING MAINTENANCE
 * (App\Update\MaintenanceGuard::EXEMPT). While the site is in the
 * maintenance window the normal shell is not rendered: it reads site
 * settings, modules and languages from a database that may be halfway
 * through a migration. A plain header takes its place, and nothing on this
 * page needs more than the update state, the version file and the admin's
 * own account.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('updates.manage');

$root = UpdateConfig::projectRoot();
$updater = Updater::fromConfig();
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * A stored message (catalog key + placeholders) as escaped text in the
 * administrator's CMS language.
 *
 * @param array{key?: string, params?: array<string, string|int>}|null $message
 */
$say = static function (?array $message): string {
    if ($message === null || !is_string($message['key'] ?? null)) {
        return '';
    }

    return admin_te($message['key'], is_array($message['params'] ?? null) ? $message['params'] : []);
};

$flash = $_SESSION['admin_update_flash'] ?? null;
unset($_SESSION['admin_update_flash']);

$stateError = null;
try {
    $state = $updater->state();
} catch (UpdateException $e) {
    $state = UpdateState::idle();
    $stateError = ['key' => $e->messageKey, 'params' => $e->params, 'detail' => $e->getMessage()];
}

try {
    $currentVersion = AppVersion::current();
} catch (\RuntimeException $e) {
    $currentVersion = '?';
}

$descriptor = null;
try {
    $descriptor = ReleaseDescriptor::installed();
} catch (UpdateException) {
    // Shown as "not a release installation" below; Preflight names the reason.
}

$installKind = match (true) {
    is_dir($root . '/.git') || is_file($root . '/.git') => 'git',
    $descriptor === null => 'none',
    default => 'release',
};

$check = $updater->lastCheck();
$history = $updater->history();
$maintenance = new MaintenanceMode($root);
$feedConfigured = UpdateConfig::manifestUrl() !== '';
$keyConfigured = ReleaseKeys::trusted() !== [];

$running = $state->isRunning();
$stepRunning = $running && $updater->store()->isLocked();
// Set once by api/admin/updates-start.php for the update it just started;
// anything else — a refresh, a bookmark, a link — shows where the update is
// and waits for Doorgaan.
$autorun = $running && ($_SESSION['admin_update_autorun'] ?? null) === $state->updateId();
unset($_SESSION['admin_update_autorun']);
$inLiveWindow = $maintenance->isActive() || ($running && in_array($state->step(), UpdateState::LIVE_STEPS, true));

$manifest = is_array($check['manifest'] ?? null) ? $check['manifest'] : null;
$available = $manifest !== null && ($check['available'] ?? false) === true;
$checks = array_map([PreflightCheck::class, 'fromArray'], is_array($check['checks'] ?? null) ? $check['checks'] : []);
$requirementsMet = \App\Update\Preflight::passes($checks);
$checkAge = $check !== null ? time() - (int) strtotime((string) ($check['checked_at'] ?? '')) : PHP_INT_MAX;
$canInstall = $available && $requirementsMet && $state->isSettled() && $installKind === 'release' && $checkAge <= Updater::CHECK_MAX_AGE;

$steps = $state->isRollingBack() || in_array($state->get('failed_step'), ['migrate', 'health'], true) && !$state->isSettled()
    ? [...UpdateState::STEPS, ...UpdateState::ROLLBACK_STEPS]
    : UpdateState::STEPS;
$stepIndex = array_search($state->step(), $steps, true);

$logEntries = $state->updateId() !== '' ? $updater->log($state->updateId())->entries(300) : [];

$formatDate = static function (string $iso): string {
    $time = strtotime($iso);

    return $time === false ? $iso : date('d-m-Y H:i', $time);
};

$statusBadge = static function (string $status) use ($h): string {
    $class = match ($status) {
        UpdateState::COMPLETED => 'admin-badge--published',
        UpdateState::RUNNING => 'admin-badge--pending',
        UpdateState::ROLLED_BACK => 'admin-badge--draft',
        UpdateState::FAILED, UpdateState::RECOVERY_REQUIRED => 'admin-badge--warning',
        default => 'admin-badge--muted',
    };

    return '<span class="admin-badge ' . $class . '">' . admin_te('update.status.' . $status) . '</span>';
};

/*
 * The shell. Outside the maintenance window it is the normal one; inside it,
 * or if the normal one cannot be built, a plain header (see the docblock).
 */
$shell = '';
if (!$inLiveWindow) {
    ob_start();
    try {
        require __DIR__ . '/_header.php';
        $shell = (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        error_log('[admin/updates.php] normal shell unavailable: ' . $e->getMessage());
        $shell = '';
    }
}
?>
<!doctype html>
<html lang="<?= $h(\App\Service\Language\AdminLocale::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('update.title') ?> · <?= admin_te('settings.admin') ?></title>
<link rel="stylesheet" href="<?= AssetVersion::url('/admin/assets/admin.css') ?>">
<link rel="stylesheet" href="<?= AssetVersion::url('/admin/assets/updates.css') ?>">
</head>
<body<?= $shell !== '' ? \App\Service\AdminTheme::bodyAttribute() : '' ?>>
<?php if ($shell !== ''): ?>
  <?= $shell ?>
<?php else: ?>
  <?php admin_ui_script(); ?>
  <header class="admin-update-bare">
    <strong><?= admin_te('update.bare_title') ?></strong>
    <form method="post" action="/admin/logout.php">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <button type="submit" class="admin-btn-text"><?= admin_te('shell.logout') ?></button>
    </form>
  </header>
<?php endif; ?>
<main class="admin-main admin-updates">
  <header class="admin-page-head">
    <div>
      <?php /* Updates is part of Instellingen (AdminNavigation, `within`); the
               eyebrow is the way back to its tab there, for who may open it. */ ?>
      <p class="admin-text-muted admin-updates__eyebrow">
        <?php if (AdminAuth::can(\App\Service\AdminPermissions::SETTINGS_MANAGE)): ?>
          <a href="/admin/settings.php?section=updates"><?= admin_te('update.eyebrow') ?></a>
        <?php else: ?>
          <?= admin_te('update.eyebrow') ?>
        <?php endif; ?>
      </p>
      <h1 class="admin-page-head__title"><?= admin_te('update.title') ?></h1>
      <p class="admin-page-head__desc"><?= admin_te('update.intro') ?></p>
    </div>
  </header>

  <?php if (is_array($flash)): ?>
    <p class="admin-alert admin-alert--<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= $say($flash) ?></p>
  <?php endif; ?>

  <?php if ($stateError !== null): ?>
    <div class="admin-alert admin-alert--error" role="alert">
      <p><?= $say($stateError) ?></p>
      <p class="admin-updates__detail"><?= $h($stateError['detail']) ?></p>
    </div>
  <?php endif; ?>

  <?php /* ---- The update that is running, or how the last one ended ---- */ ?>
  <?php if ($running): ?>
    <section class="admin-card admin-updates__run" id="update-run"
             data-update-runner
             data-endpoint="/api/admin/updates-step.php"
             data-csrf="<?= $h($csrfToken) ?>"
             data-update-id="<?= $h($state->updateId()) ?>"
             data-step="<?= $h($state->step()) ?>"
             data-autorun="<?= $autorun ? '1' : '0' ?>"
             data-text-connection="<?= admin_te('update.run.connection_lost') ?>"
             data-text-busy="<?= admin_te('update.run.busy') ?>"
             data-text-working="<?= admin_te('update.run.working') ?>">
      <h2><?= admin_te('update.run.title', ['from' => $state->fromVersion(), 'to' => $state->toVersion()]) ?></h2>

      <?php if (!$autorun): ?>
        <div class="admin-alert admin-alert--warning" role="status" data-update-interrupted>
          <?php if ($stepRunning): ?>
            <p><?= admin_te('update.run.step_active', ['step' => admin_t('update.step.' . $state->step())]) ?></p>
          <?php else: ?>
            <p><?= admin_te('update.run.interrupted', ['step' => admin_t('update.step.' . $state->step())]) ?></p>
            <?php if ($state->progress() !== null): ?>
              <p data-update-progress><?= admin_te('update.run.progress', ['percent' => $state->progress()]) ?></p>
            <?php endif; ?>
            <p class="admin-text-muted"><?= admin_te('update.run.interrupted_help') ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($state->message() !== null): ?>
        <p class="admin-alert admin-alert--warning" data-update-message><?= $say($state->message()) ?></p>
      <?php else: ?>
        <p class="admin-text-muted" data-update-message aria-live="polite"></p>
      <?php endif; ?>

      <ol class="admin-update-steps" data-update-steps>
        <?php foreach ($steps as $index => $step): ?>
          <?php $position = $stepIndex === false ? 'pending' : ($index < $stepIndex ? 'done' : ($index === $stepIndex ? 'current' : 'pending')); ?>
          <li class="admin-update-steps__item is-<?= $position ?>" data-step-name="<?= $h($step) ?>">
            <span class="admin-update-steps__label"><?= admin_te('update.step.' . $step) ?></span>
          </li>
        <?php endforeach; ?>
      </ol>

      <div class="admin-card--actions">
        <form method="post" action="/api/admin/updates-step.php" data-update-continue>
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="update_id" value="<?= $h($state->updateId()) ?>">
          <input type="hidden" name="step" value="<?= $h($state->step()) ?>">
          <button type="submit" class="admin-btn"<?= $stepRunning ? ' disabled' : '' ?>><?= admin_te('update.run.continue') ?></button>
        </form>
        <?php if (!$state->filesChanged() && !$state->databaseChanged()): ?>
          <form method="post" action="/api/admin/updates-abort.php"<?= admin_confirm_attributes(
              admin_t('update.abort.title'),
              admin_t('update.abort.message'),
              admin_t('update.abort.button')
          ) ?>>
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="update_id" value="<?= $h($state->updateId()) ?>">
            <button type="submit" class="admin-btn-secondary"<?= $stepRunning ? ' disabled' : '' ?>><?= admin_te('update.abort.button') ?></button>
          </form>
        <?php endif; ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('update.run.keep_open') ?></p>
    </section>

  <?php elseif ($state->status() === UpdateState::RECOVERY_REQUIRED): ?>
    <section class="admin-card admin-updates__recovery">
      <h2><?= admin_te('update.recovery.title') ?></h2>
      <div class="admin-alert admin-alert--error" role="alert">
        <p><?= admin_te('update.recovery.lead', ['from' => $state->fromVersion(), 'to' => $state->toVersion(), 'step' => admin_t('update.step.' . $state->step())]) ?></p>
        <?php if ($state->message() !== null): ?>
          <p><strong><?= $say($state->message()) ?></strong></p>
          <p class="admin-updates__detail"><?= $h((string) ($state->message()['detail'] ?? '')) ?></p>
        <?php endif; ?>
      </div>
      <dl class="admin-updates__facts">
        <dt><?= admin_te('update.recovery.update_id') ?></dt><dd><code><?= $h($state->updateId()) ?></code></dd>
        <dt><?= admin_te('update.recovery.backup') ?></dt><dd><code><?= $h($updater->store()->backupDirectory($state->updateId())) ?></code></dd>
        <dt><?= admin_te('update.recovery.log') ?></dt><dd><code><?= $h($updater->store()->logPath($state->updateId())) ?></code></dd>
        <dt><?= admin_te('update.recovery.maintenance') ?></dt><dd><?= admin_te($maintenance->isActive() ? 'update.recovery.maintenance_on' : 'update.recovery.maintenance_off') ?></dd>
      </dl>
      <p><?= admin_te('update.recovery.what_now') ?></p>
      <form method="post" action="/api/admin/updates-resolve.php"<?= admin_confirm_attributes(
          admin_t('update.resolve.title'),
          admin_t('update.resolve.message'),
          admin_t('update.resolve.button')
      ) ?>>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="update_id" value="<?= $h($state->updateId()) ?>">
        <button type="submit" class="admin-btn-danger"><?= admin_te('update.resolve.button') ?></button>
      </form>
    </section>

  <?php elseif (in_array($state->status(), [UpdateState::COMPLETED, UpdateState::FAILED, UpdateState::ROLLED_BACK], true) && $state->updateId() !== ''): ?>
    <?php
      $outcomeClass = match ($state->status()) {
          UpdateState::COMPLETED => 'admin-alert--success',
          UpdateState::ROLLED_BACK => 'admin-alert--warning',
          default => 'admin-alert--error',
      };
    ?>
    <section class="admin-card">
      <h2><?= admin_te('update.outcome.title') ?> <?= $statusBadge($state->status()) ?></h2>
      <div class="admin-alert <?= $outcomeClass ?>" role="status">
        <p><?= admin_te('update.outcome.' . $state->status(), ['from' => $state->fromVersion(), 'to' => $state->toVersion()]) ?></p>
        <?php if ($state->message() !== null && $state->status() !== UpdateState::COMPLETED): ?>
          <p><?= $say($state->message()) ?></p>
        <?php endif; ?>
      </div>
      <?php $failedChecks = array_filter(
          array_map([PreflightCheck::class, 'fromArray'], (array) $state->get('checks', [])),
          static fn (PreflightCheck $c): bool => $c->status !== PreflightCheck::OK
      ); ?>
      <?php if ($state->status() === UpdateState::FAILED && $failedChecks !== []): ?>
        <ul class="admin-update-checks">
          <?php foreach ($failedChecks as $failedCheck): ?>
            <li class="admin-update-checks__item is-<?= $h($failedCheck->status) ?>"><?= admin_te($failedCheck->messageKey, $failedCheck->params) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php /* ---- The installed version ---- */ ?>
  <section class="admin-card">
    <h2><?= admin_te('update.version.title') ?></h2>
    <dl class="admin-updates__facts">
      <dt><?= admin_te('update.version.current') ?></dt>
      <dd><strong class="admin-updates__version" data-current-version><?= $h($currentVersion) ?></strong></dd>
      <?php if ($descriptor !== null): ?>
        <dt><?= admin_te('update.version.build') ?></dt><dd><code><?= $h($descriptor->buildId) ?></code></dd>
        <dt><?= admin_te('update.version.released') ?></dt><dd><?= $h($formatDate($descriptor->releasedAt)) ?></dd>
      <?php endif; ?>
      <dt><?= admin_te('update.version.kind') ?></dt><dd><?= admin_te('update.kind.' . $installKind) ?></dd>
    </dl>
  </section>

  <?php /* ---- The newest release ---- */ ?>
  <section class="admin-card">
    <h2><?= admin_te('update.latest.title') ?>
      <?php if ($manifest !== null): ?>
        <span class="admin-badge <?= $available ? 'admin-badge--pending' : 'admin-badge--published' ?>" data-update-availability><?= admin_te($available ? 'update.latest.available' : 'update.latest.up_to_date') ?></span>
      <?php endif; ?>
    </h2>

    <?php if (!$feedConfigured || !$keyConfigured): ?>
      <div class="admin-alert admin-alert--warning" role="status">
        <p><?= admin_te(!$feedConfigured ? 'update.config.no_feed' : 'update.config.no_key') ?></p>
        <p class="admin-text-muted"><?= admin_te('update.config.help', ['feed' => UpdateConfig::MANIFEST_URL_VARIABLE, 'key' => UpdateConfig::PUBLIC_KEY_VARIABLE]) ?></p>
      </div>
    <?php endif; ?>

    <?php if (is_array($check['error'] ?? null)): ?>
      <div class="admin-alert admin-alert--error" role="alert">
        <p><?= $say($check['error']) ?></p>
        <p class="admin-updates__detail"><?= $h((string) ($check['error']['detail'] ?? '')) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($manifest !== null): ?>
      <dl class="admin-updates__facts">
        <dt><?= admin_te('update.latest.version') ?></dt><dd><strong class="admin-updates__version" data-latest-version><?= $h((string) $manifest['version']) ?></strong></dd>
        <dt><?= admin_te('update.latest.released') ?></dt><dd><?= $h($formatDate((string) $manifest['released_at'])) ?></dd>
        <dt><?= admin_te('update.latest.size') ?></dt><dd><?= $h(\App\Update\Preflight::megabytes((int) $manifest['size'])) ?></dd>
        <dt><?= admin_te('update.latest.requirements') ?></dt>
        <dd>
          <?= admin_te('update.latest.requirements_text', [
              'php' => (string) ($manifest['minimum_php'] ?: '—'),
              'mysql' => (string) ($manifest['minimum_mysql'] ?: '—'),
              'mariadb' => (string) ($manifest['minimum_mariadb'] ?: '—'),
          ]) ?>
          <?php if (($manifest['required_extensions'] ?? []) !== []): ?>
            <br><span class="admin-text-muted"><?= admin_te('update.latest.extensions', ['extensions' => implode(', ', (array) $manifest['required_extensions'])]) ?></span>
          <?php endif; ?>
        </dd>
      </dl>

      <?php if (trim((string) ($manifest['notes'] ?? '')) !== ''): ?>
        <h3><?= admin_te('update.latest.notes') ?></h3>
        <div class="admin-updates__notes"><?= nl2br($h((string) $manifest['notes'])) ?></div>
      <?php endif; ?>

      <?php if ($available && $checks !== []): ?>
        <h3><?= admin_te('update.latest.checks') ?></h3>
        <ul class="admin-update-checks">
          <?php foreach ($checks as $requirement): ?>
            <li class="admin-update-checks__item is-<?= $h($requirement->status) ?>"><?= admin_te($requirement->messageKey, $requirement->params) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php elseif ($check === null && $feedConfigured): ?>
      <p class="admin-text-muted"><?= admin_te('update.latest.never_checked') ?></p>
    <?php endif; ?>

    <?php if ($check !== null): ?>
      <p class="admin-text-muted"><?= admin_te('update.latest.checked_at', ['time' => $formatDate((string) ($check['checked_at'] ?? ''))]) ?></p>
    <?php endif; ?>

    <div class="admin-card--actions">
      <form method="post" action="/api/admin/updates-check.php">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <button type="submit" class="admin-btn-secondary"<?= !$feedConfigured || $running ? ' disabled' : '' ?>><?= admin_te('update.check.button') ?></button>
      </form>
      <?php if ($available): ?>
        <form method="post" action="/api/admin/updates-start.php"<?= admin_confirm_attributes(
            admin_t('update.start.title', ['version' => (string) $manifest['version']]),
            admin_t('update.start.message', ['version' => (string) $manifest['version']]),
            admin_t('update.start.button')
        ) ?>>
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <button type="submit" class="admin-btn"<?= $canInstall ? '' : ' disabled' ?>><?= admin_te('update.start.button') ?></button>
        </form>
      <?php endif; ?>
    </div>
    <?php if ($available && !$canInstall && !$running): ?>
      <p class="admin-text-muted"><?= admin_te(match (true) {
          $installKind !== 'release' => 'update.start.not_release',
          !$requirementsMet => 'update.start.requirements',
          $checkAge > Updater::CHECK_MAX_AGE => 'update.start.check_again',
          default => 'update.start.not_now',
      }) ?></p>
    <?php endif; ?>
  </section>

  <?php /* ---- What happened before ---- */ ?>
  <?php if ($history !== [] || $logEntries !== []): ?>
    <section class="admin-card">
      <h2><?= admin_te('update.history.title') ?></h2>
      <?php if ($history !== []): ?>
        <table class="admin-table">
          <thead><tr>
            <th scope="col"><?= admin_te('update.history.date') ?></th>
            <th scope="col"><?= admin_te('update.history.versions') ?></th>
            <th scope="col"><?= admin_te('update.history.status') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach (array_slice($history, 0, 10) as $entry): ?>
              <tr>
                <td><?= $h($formatDate((string) ($entry['finished_at'] ?? $entry['started_at'] ?? ''))) ?></td>
                <td><?= $h((string) ($entry['from_version'] ?? '')) ?> → <?= $h((string) ($entry['to_version'] ?? '')) ?></td>
                <td><?= $statusBadge((string) ($entry['status'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if ($logEntries !== []): ?>
        <details class="admin-updates__log">
          <summary><?= admin_te('update.history.log', ['id' => $state->updateId()]) ?></summary>
          <ol>
            <?php foreach ($logEntries as $entry): ?>
              <li class="is-<?= $h((string) ($entry['event'] ?? '')) ?>">
                <time><?= $h(substr((string) ($entry['at'] ?? ''), 11, 8)) ?></time>
                <span class="admin-updates__log-step"><?= admin_te('update.step.' . (string) ($entry['step'] ?? 'start')) ?></span>
                <?php if (is_string($entry['message'] ?? null)): ?>
                  <span><?= admin_te($entry['message'], is_array($entry['params'] ?? null) ? $entry['params'] : []) ?></span>
                <?php else: ?>
                  <span class="admin-text-muted"><?= admin_te('update.event.' . (string) ($entry['event'] ?? 'begin')) ?></span>
                <?php endif; ?>
                <?php if (is_string($entry['detail'] ?? null)): ?>
                  <pre class="admin-updates__detail"><?= $h($entry['detail']) ?></pre>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        </details>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?= admin_confirm_dialog() ?>
<script src="<?= AssetVersion::url('/admin/assets/updates.js') ?>" defer></script>
</body>
</html>
