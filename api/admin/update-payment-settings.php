<?php

/**
 * POST /api/admin/update-payment-settings.php
 *
 * Saves Shop → Betalingen (admin/payments.php): a new Test or Live API key,
 * the mode, and which payment methods the checkout offers. Everything is
 * checked first (App\Service\Payment\PaymentSettingsEditor), then written in
 * one transaction, so a refused save changes nothing — the product editor's
 * contract (api/admin/update-product.php, ADMIN-UI.md "Een editor die
 * opslaat zonder te herladen").
 *
 * TWO ANSWERS, ONE SET OF RULES. With `Accept: application/json` (the
 * dynamic editor, admin/assets/admin-editor.js) the answer is
 * App\Service\AdminEditorResponse: 200 saved, 422 with every message keyed
 * by its field, 500 when the database failed. Without it, a form posted
 * without the script gets the PRG redirect with a session flash.
 *
 * NOTHING SECRET GOES BACK. No answer, flash, log line or redirect carries a
 * key: a refused save keeps nothing typed in the session, the messages come
 * from the catalog, and the log says only WHAT changed and by whom.
 *
 * The guards first, in their order (api/admin/CLAUDE.md): payments.manage,
 * which only a Super Admin can grant (ShopModule::PAYMENTS_MANAGE), and a
 * ModuleGuard in front of it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Module\ModuleGuard;
use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\AdminEditorResponse;
use App\Service\Csrf;
use App\Service\Language\AdminLocale;
use App\Service\Language\AdminTranslator;
use App\Service\Payment\MollieConfiguration;
use App\Service\Payment\MolliePaymentProvider;
use App\Service\Payment\PaymentProviderException;
use App\Service\Payment\PaymentSettingsEditor;
use App\Service\Secrets\SecretStoreException;
use App\Service\SiteSettings;

ModuleGuard::requireApi('shop');

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('payments.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$json = AdminEditorResponse::wantsJson();

/**
 * @param array<string, string|list<string>> $errors
 */
$refuse = static function (array $errors, string $message) use ($json): never {
    if ($json) {
        AdminEditorResponse::invalid($errors, $message);
    }

    $_SESSION['admin_payments_errors'] = AdminEditorResponse::messages($errors);
    header('Location: /admin/payments.php');
    exit;
};

$configuration = new MollieConfiguration();
$editor = new PaymentSettingsEditor($_POST, $configuration, new MolliePaymentProvider($configuration), AdminLocale::current());

$errors = $editor->validate();
if ($errors !== []) {
    $refuse($errors, AdminTranslator::trans('editor.invalid'));
}

$db = Database::connection();

try {
    $db->beginTransaction();
    $events = $editor->save(new SiteSettingRepository($db));
    $db->commit();
} catch (SecretStoreException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    SiteSettings::clearCache();
    error_log('[api/admin/update-payment-settings.php] a key could not be sealed: ' . $e->getMessage());

    $refuse(
        [AdminEditorResponse::FORM => AdminTranslator::trans('payments.error.storage_' . $e->reason)],
        AdminTranslator::trans('editor.invalid')
    );
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    SiteSettings::clearCache();
    error_log('[api/admin/update-payment-settings.php] ' . PaymentProviderException::redact($e->getMessage()));

    if ($json) {
        AdminEditorResponse::failed(AdminTranslator::trans('payments.save_failed'));
    }

    $_SESSION['admin_payments_errors'] = [AdminTranslator::trans('payments.save_failed')];
    header('Location: /admin/payments.php');
    exit;
}

// The Shop has no audit log; the server log says what changed and by whom,
// and never with which key.
foreach ($events as $event) {
    error_log('[payments] ' . $event . ' by admin user #' . (AdminAuth::userId() ?? 0));
}

if ($json) {
    AdminEditorResponse::saved(AdminTranslator::trans('common.saved'));
}

header('Location: /admin/payments.php?saved=1');
exit;
