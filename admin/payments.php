<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_editor.php';

use App\Module\ModuleGuard;
use App\Repository\OrderRepository;
use App\Service\AdminAuth;
use App\Service\AppUrl;
use App\Service\Csrf;
use App\Service\Language\AdminLocale;
use App\Service\Payment\MollieConfiguration;
use App\Service\Payment\MolliePaymentProvider;
use App\Service\Payment\MollieSetupStatus;
use App\Service\ShopOverview;

/**
 * Shop → Betalingen: Mollie set up by somebody who never set up a payment
 * provider (MODULES.md, "Betalingen").
 *
 * Five parts, top to bottom:
 *
 *   Status        where payments stand now — Niet ingesteld, Testmodus,
 *                 Live or Probleem — proven with one read-only call to
 *                 Mollie on every view (App\Service\Payment\MollieSetupStatus),
 *                 with "Verbinding testen" to ask again
 *   Stappenplan   six steps from "make a Mollie account" to "go live", the
 *                 first unfinished one open
 *   The editor    ONE form with one Opslaan (the dynamic editor,
 *                 admin/_admin_editor.php): the two API keys and the mode.
 *                 Key fields are never filled in: after saving a key only
 *                 its masked form ("test_••••••••abcd") is shown, and an
 *                 empty field keeps the stored key. With the key pinned by
 *                 the server environment the fields are replaced by a line
 *                 that says so
 *   Testbetaling  how to try the real checkout with a test payment, and a
 *                 link to the shop — no second, fake checkout
 *   Webhook       the address every payment gives Mollie, and why nobody
 *                 has to set it up in Mollie
 *
 * "Test deze sleutel" and "Verbinding testen" are actions, not part of the
 * save: admin/assets/payments.js posts to api/admin/test-payment-connection.php
 * and shows the answer, and without the script the same buttons post the
 * form there (`formaction`). A typed key travels in a POST body only.
 *
 * THE GUARD is settings.manage, like Shop-instellingen, plus a ModuleGuard
 * because that permission is Core's and survives the Shop being switched off.
 */

ModuleGuard::requireAdmin('shop');
AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$errors = $_SESSION['admin_payments_errors'] ?? [];
$testFlash = $_SESSION['admin_payments_test_result'] ?? null;
unset($_SESSION['admin_payments_errors'], $_SESSION['admin_payments_test_result']);
$saved = isset($_GET['saved']);

$language = AdminLocale::current();
$configuration = new MollieConfiguration();
$provider = new MolliePaymentProvider($configuration);
$status = MollieSetupStatus::current($configuration, $provider, $language);

$pinned = $configuration->isPinnedByEnvironment();
$environmentKey = $configuration->environmentKey();
$storedMode = $configuration->storedMode();
$storedKeys = [
    MollieConfiguration::MODE_TEST => $pinned ? null : $configuration->storedKey(MollieConfiguration::MODE_TEST),
    MollieConfiguration::MODE_LIVE => $pinned ? null : $configuration->storedKey(MollieConfiguration::MODE_LIVE),
];
$hasPayments = (new OrderRepository())->hasAnyPayment();
$liveReplaceNeedsConfirmation = !$pinned
    && $storedMode === MollieConfiguration::MODE_LIVE
    && $storedKeys[MollieConfiguration::MODE_LIVE] !== null
    && $hasPayments;

$baseUrl = AppUrl::base();
$webhookUrl = $provider->webhookUrl();
$shopUrl = ShopOverview::url() ?? '/';

/*
 * The six steps, each done or not as far as this screen can know. The first
 * one that is not done is open; the rest can be opened.
 */
$hasTestKey = $pinned
    ? $configuration->activeMode() === MollieConfiguration::MODE_TEST
    : ($storedKeys[MollieConfiguration::MODE_TEST]['readable'] ?? false);
$connected = in_array($status->state, [MollieSetupStatus::TEST, MollieSetupStatus::LIVE], true);
$steps = [
    1 => $pinned || $storedKeys[MollieConfiguration::MODE_TEST] !== null || $storedKeys[MollieConfiguration::MODE_LIVE] !== null,
    2 => $hasTestKey || $status->state === MollieSetupStatus::LIVE,
    3 => $hasTestKey || $status->state === MollieSetupStatus::LIVE,
    4 => $connected,
    5 => $hasPayments,
    6 => $status->state === MollieSetupStatus::LIVE,
];
$currentStep = null;
foreach ($steps as $number => $done) {
    if (!$done) {
        $currentStep = $number;
        break;
    }
}

$stepLinks = [
    1 => ['href' => 'https://www.mollie.com/', 'label' => 'payments.step1.link', 'external' => true],
    2 => ['href' => 'https://my.mollie.com/', 'label' => 'payments.step2.link', 'external' => true],
    3 => ['href' => '#payments-keys', 'label' => 'payments.step3.link', 'external' => false],
    5 => ['href' => '#payments-testpay', 'label' => 'payments.step5.link', 'external' => false],
    6 => ['href' => '#payments-mode', 'label' => 'payments.step6.link', 'external' => false],
];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$date = static fn (string $stamp): string => $stamp !== '' && strtotime($stamp) !== false ? date('d-m-Y H:i', (int) strtotime($stamp)) : '';

/**
 * A test button: posts the editor form to the connection test. The script
 * answers in place; without it the browser posts the form there.
 */
$testButton = static function (string $mode, string $labelKey) use ($h): string {
    return '<button type="submit" form="payments-editor" formaction="/api/admin/test-payment-connection.php"'
        . ' formnovalidate name="test_mode" value="' . $h($mode) . '" class="admin-btn-secondary"'
        . ' data-payments-test="' . $h($mode) . '" data-label-busy="' . admin_te('payments.testing') . '"'
        . ' data-label-failed="' . admin_te('payments.test_failed_request') . '">'
        . admin_te($labelKey) . '</button>';
};
?>
<!doctype html>
<html lang="<?= $h($language) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('payments.title_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/payments.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main admin-payments">
  <h1><?= admin_te('payments.title') ?></h1>
  <?= admin_info_panel(admin_t('help.payments.intro')) ?>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>
  <?php if (is_array($testFlash)): ?>
    <p class="admin-alert <?= !empty($testFlash['ok']) ? 'admin-alert--success' : 'admin-alert--error' ?>" role="status"><?= $h((string) ($testFlash['message'] ?? '')) ?></p>
  <?php endif; ?>

  <?php /* THE STATUS. Drawn again after every save (a region of the editor),
           so a new key or mode shows its real state at once. */ ?>
  <section class="admin-card admin-payments-status admin-payments-status--<?= $h($status->state) ?>" aria-labelledby="payments-status-title" data-admin-editor-region="payments-status">
    <div class="admin-payments-status__head">
      <h2 id="payments-status-title"><?= admin_te('payments.status.title') ?></h2>
      <span class="admin-payments-badge admin-payments-badge--<?= $h($status->state) ?>"><?= admin_te('payments.status.badge.' . $status->state) ?></span>
    </div>
    <p class="admin-payments-status__lead"><?= admin_te('payments.status.lead.' . $status->state) ?></p>

    <dl class="admin-payments-facts">
      <dt><?= admin_te('payments.status.provider') ?></dt>
      <dd>Mollie</dd>

      <dt><?= admin_te('payments.status.mode') ?></dt>
      <dd><?= admin_te($status->mode === null ? 'payments.status.mode_unknown' : 'payments.status.mode_' . $status->mode) ?></dd>

      <dt><?= admin_te('payments.status.key') ?></dt>
      <dd>
        <?php if ($pinned): ?>
          <?= admin_te('payments.status.key_environment', ['key' => MollieConfiguration::isKeyFormat((string) $environmentKey) ? MollieConfiguration::mask((string) $environmentKey) : admin_t('payments.keys.environment_invalid')]) ?>
        <?php elseif ($status->mode !== null && $storedKeys[$status->mode] !== null): ?>
          <?= admin_te('payments.status.key_cms', ['key' => $storedKeys[$status->mode]['hint']]) ?>
        <?php else: ?>
          <?= admin_te('payments.status.key_none') ?>
        <?php endif; ?>
      </dd>

      <dt><?= admin_te('payments.status.connection') ?></dt>
      <dd><?= $status->connection !== null ? $h($status->connection->message()) : admin_te('payments.status.connection_none') ?></dd>
    </dl>

    <div class="admin-payments-actions">
      <?php if ($status->state === MollieSetupStatus::NOT_CONFIGURED): ?>
        <a class="admin-btn-primary" href="#payments-setup"><?= admin_te('payments.status.setup_cta') ?></a>
      <?php else: ?>
        <?= $testButton('active', 'payments.test_connection') ?>
      <?php endif; ?>
      <p class="admin-payments-result" role="status" aria-live="polite" data-payments-test-result="active" hidden></p>
    </div>
  </section>

  <?php /* THE STEPS. Plain <details>: they open and close without a script. */ ?>
  <section class="admin-card" id="payments-setup" aria-labelledby="payments-setup-title">
    <h2 id="payments-setup-title"><?= admin_te('payments.setup.title') ?></h2>
    <p class="admin-text-muted"><?= admin_te('payments.setup.intro') ?></p>
    <ol class="admin-payments-steps">
      <?php foreach ($steps as $number => $done): ?>
        <li class="admin-payments-step<?= $done ? ' admin-payments-step--done' : '' ?>">
          <details class="admin-collapse admin-collapse--card"<?= $number === $currentStep ? ' open' : '' ?>>
            <summary class="admin-collapse__summary">
              <span class="admin-collapse__caret" aria-hidden="true"></span>
              <h3 class="admin-collapse__title"><?= admin_te('payments.step' . $number . '.title') ?></h3>
              <span class="admin-collapse__badges">
                <?php if ($done): ?>
                  <span class="admin-badge admin-badge--paid"><?= admin_te('payments.setup.done') ?></span>
                <?php elseif ($number === $currentStep): ?>
                  <span class="admin-badge admin-badge--pending"><?= admin_te('payments.setup.current') ?></span>
                <?php endif; ?>
              </span>
            </summary>
            <div class="admin-collapse__body">
              <div class="admin-payments-step__text"><?= admin_help_text(admin_t('payments.step' . $number . '.body')) ?></div>
              <?php if (isset($stepLinks[$number])): ?>
                <p>
                  <a class="admin-btn-link" href="<?= $h($stepLinks[$number]['href']) ?>"<?= $stepLinks[$number]['external'] ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= admin_te($stepLinks[$number]['label']) ?></a>
                </p>
              <?php endif; ?>
            </div>
          </details>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <?= admin_editor_summary(is_array($errors) ? array_values(array_map('strval', $errors)) : []) ?>

  <?php /* THE EDITOR: one form, one Opslaan in the bar. */ ?>
  <form method="post" action="/api/admin/update-payment-settings.php" id="payments-editor" class="admin-payments-editor" data-admin-editor autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

    <section class="admin-card" id="payments-keys" data-admin-editor-section="keys" aria-labelledby="payments-keys-title">
      <div class="admin-payments-heading">
        <h2 id="payments-keys-title"><?= admin_te('payments.keys.title') ?></h2>
        <?= admin_help(admin_t('payments.keys.title'), admin_t('help.payments.keys')) ?>
      </div>
      <div class="admin-alert admin-alert--error" data-admin-editor-errors="keys" hidden></div>

      <div data-admin-editor-region="payments-keys">
        <?php if ($pinned): ?>
          <div class="admin-payments-pinned">
            <p><strong><?= admin_te('payments.keys.environment_title') ?></strong></p>
            <p><?= admin_te('payments.keys.environment_body', ['key' => MollieConfiguration::isKeyFormat((string) $environmentKey) ? MollieConfiguration::mask((string) $environmentKey) : admin_t('payments.keys.environment_invalid')]) ?></p>
          </div>
        <?php else: ?>
          <?php foreach (MollieConfiguration::MODES as $mode): ?>
            <?php
              $fieldId = 'payments-' . $mode . '-key';
              $stored = $storedKeys[$mode];
              $storedId = $fieldId . '-stored';
              $hintId = $fieldId . '-hint';
            ?>
            <div class="admin-field admin-payments-key">
              <?= admin_field_label($fieldId, admin_t('payments.keys.' . $mode . '_label'), admin_t('help.payments.' . $mode . '_key')) ?>
              <p class="admin-payments-key__stored<?= $stored !== null && !$stored['readable'] ? ' admin-payments-key__stored--problem' : '' ?>" id="<?= $h($storedId) ?>">
                <?php if ($stored === null): ?>
                  <?= admin_te('payments.keys.none') ?>
                <?php elseif (!$stored['readable']): ?>
                  <?= admin_te('payments.keys.unreadable', ['hint' => $stored['hint']]) ?>
                <?php else: ?>
                  <?= admin_te('payments.keys.stored', ['hint' => $stored['hint'], 'date' => $date($stored['updated_at'])]) ?>
                <?php endif; ?>
              </p>
              <div class="admin-payments-key__row">
                <input type="password" id="<?= $h($fieldId) ?>" name="<?= $h($mode) ?>_api_key" value=""
                       autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="200"
                       placeholder="<?= admin_te('payments.keys.placeholder_' . $mode) ?>"
                       aria-describedby="<?= $h($storedId . ' ' . $hintId) ?>">
                <?= $testButton($mode, 'payments.keys.test_this') ?>
              </div>
              <p class="admin-text-muted" id="<?= $h($hintId) ?>"><?= admin_te('payments.keys.keep_hint') ?></p>
              <p class="admin-payments-result" role="status" aria-live="polite" data-payments-test-result="<?= $h($mode) ?>" hidden></p>

              <?php if ($mode === MollieConfiguration::MODE_LIVE && $liveReplaceNeedsConfirmation): ?>
                <div class="admin-field admin-field--inline admin-payments-confirm">
                  <label class="admin-checkbox-label">
                    <input type="checkbox" class="admin-switch" role="switch" name="confirm_live_key_replace" value="1">
                    <?= admin_te('payments.keys.confirm_live') ?>
                  </label>
                  <?= admin_help(admin_t('payments.keys.confirm_live'), admin_t('help.payments.confirm_live')) ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="admin-card" id="payments-mode" data-admin-editor-section="mode" aria-labelledby="payments-mode-title">
      <h2 id="payments-mode-title"><?= admin_te('payments.mode.title') ?></h2>
      <div class="admin-alert admin-alert--error" data-admin-editor-errors="mode" hidden></div>

      <div data-admin-editor-region="payments-mode">
        <?php if ($pinned): ?>
          <p><?= admin_te('payments.mode.environment', ['mode' => admin_t($configuration->activeMode() === null ? 'payments.status.mode_unknown' : 'payments.status.mode_' . $configuration->activeMode())]) ?></p>
        <?php else: ?>
          <fieldset class="admin-segmented-field">
            <legend><?= admin_te('payments.mode.legend') ?> <?= admin_help(admin_t('payments.mode.legend'), admin_t('help.payments.mode')) ?></legend>
            <div class="admin-segmented">
              <?php foreach (MollieConfiguration::MODES as $mode): ?>
                <label class="admin-segmented__option">
                  <input type="radio" name="payment_mode" value="<?= $h($mode) ?>"<?= $storedMode === $mode ? ' checked' : '' ?>>
                  <span><?= admin_te('payments.mode.' . $mode) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <p class="admin-text-muted"><?= admin_te('payments.mode.explain') ?></p>
        <?php endif; ?>
      </div>
    </section>

    <button type="submit" data-admin-editor-fallback><?= admin_te('common.save') ?></button>
  </form>

  <section class="admin-card" id="payments-testpay" aria-labelledby="payments-testpay-title">
    <h2 id="payments-testpay-title"><?= admin_te('payments.testpay.title') ?></h2>
    <p><?= admin_te('payments.testpay.intro') ?></p>
    <ol class="admin-payments-howto">
      <?php for ($number = 1; $number <= 8; $number++): ?>
        <li><?= admin_te('payments.testpay.step' . $number) ?></li>
      <?php endfor; ?>
    </ol>
    <?php if ($status->state === MollieSetupStatus::TEST): ?>
      <p><a class="admin-btn-secondary" href="<?= $h($shopUrl) ?>" target="_blank" rel="noopener"><?= admin_te('payments.testpay.open_shop') ?></a></p>
    <?php else: ?>
      <p class="admin-text-muted"><?= admin_te('payments.testpay.not_test') ?></p>
    <?php endif; ?>
  </section>

  <section class="admin-card" id="payments-webhook" aria-labelledby="payments-webhook-title">
    <h2 id="payments-webhook-title"><?= admin_te('payments.webhook.title') ?></h2>
    <p><?= admin_te('payments.webhook.intro') ?></p>
    <?php if ($webhookUrl !== null): ?>
      <p class="admin-payments-copy">
        <span class="admin-payments-copy__label"><?= admin_te('payments.webhook.url_label') ?></span>
        <code id="payments-webhook-url"><?= $h($webhookUrl) ?></code>
        <button type="button" class="admin-btn-secondary" data-payments-copy="payments-webhook-url" data-label-copied="<?= admin_te('payments.webhook.copied') ?>" hidden><?= admin_te('payments.webhook.copy') ?></button>
      </p>
      <?php if (!str_starts_with($baseUrl, 'https://')): ?>
        <p class="admin-alert admin-alert--warning"><?= admin_te('payments.webhook.not_https') ?></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="admin-alert admin-alert--warning"><?= admin_te('payments.webhook.unreachable', ['base' => $baseUrl]) ?></p>
    <?php endif; ?>
    <?php if (!AppUrl::isConfigured()): ?>
      <p class="admin-alert admin-alert--warning"><?= admin_te('payments.webhook.base_missing') ?></p>
    <?php endif; ?>
  </section>
</main>
<?php admin_editor_bar(); ?>
<?= admin_editor_leave_dialog() ?>
<?php admin_editor_script(); ?>
</body>
</html>
