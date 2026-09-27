<?php

/**
 * POST /api/admin/update-stock-notification-mail.php
 *
 * Saves the "back in stock" mail of Shop-instellingen → E-mails (Shop
 * Product & Ordering 2.0): its subject and its text, in ONE website language
 * — the one the form names — through App\Service\ShopLocalizedSettings. Every
 * other language stays as it is; an emptied field removes that language's
 * text, so the standard text applies again.
 *
 * The pattern of update-shop-settings.php, including its guard: ModuleGuard
 * first, because settings.manage is Core's and stays holdable with the Shop
 * off. Plain text only; the placeholders are replaced when the mail is built
 * (App\Mail\StockNotificationBuilder), never here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Module\ModuleGuard;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\ShopLocalizedSettings;

ModuleGuard::requireApi('shop');

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$back = '/admin/shop-settings.php#stock-notification-mail';
$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

$values = [];
foreach (array_keys(ShopLocalizedSettings::KEYS) as $key) {
    $values[$key] = is_string($_POST[$key] ?? null) ? trim(str_replace("\r\n", "\n", $_POST[$key])) : '';
}

$errors = [];
if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}
foreach (array_keys(ShopLocalizedSettings::problems($values)) as $key) {
    $errors[] = AdminTranslator::trans('validation.stock_mail_too_long', [
        'field' => AdminTranslator::trans($key === ShopLocalizedSettings::STOCK_SUBJECT ? 'shop_settings.stock_mail_subject' : 'shop_settings.stock_mail_body'),
        'max' => (string) ShopLocalizedSettings::KEYS[$key],
    ]);
}
// A subject is one line.
$values[ShopLocalizedSettings::STOCK_SUBJECT] = str_replace("\n", ' ', $values[ShopLocalizedSettings::STOCK_SUBJECT]);

if ($errors !== []) {
    $_SESSION['admin_stock_mail_errors'] = $errors;
    $_SESSION['admin_stock_mail_old'] = $values;
    header('Location: ' . $back);
    exit;
}

try {
    ShopLocalizedSettings::save($language, $values);
    ShopLocalizedSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-stock-notification-mail.php] ' . $e->getMessage());
    $_SESSION['admin_stock_mail_errors'] = [AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw')];
    $_SESSION['admin_stock_mail_old'] = $values;
    header('Location: ' . $back);
    exit;
}

$_SESSION['admin_stock_mail_notice'] = ['type' => 'success', 'message' => AdminTranslator::trans('common.saved')];
header('Location: ' . $back);
exit;
