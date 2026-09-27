<?php

/**
 * POST /api/admin/send-stock-notifications.php
 *
 * "Wachtende meldingen nu versturen" on Shop-instellingen → E-mails (Shop
 * Product & Ordering 2.0): writes the back-in-stock mail to everyone whose
 * product or variant can be ordered again and who has not had it yet — the
 * retry for a mail that failed before, since there is no queue and no cron
 * (App\Service\Inventory\StockNotifications::dispatchWaiting()). Nobody is
 * written to twice, and nobody whose unit is still sold out.
 *
 * Same guards as the screen: ModuleGuard, settings.manage, POST, CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Module\ModuleGuard;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Inventory\StockNotifications;
use App\Service\Language\AdminTranslator;

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

try {
    $result = (new StockNotifications())->dispatchWaiting();
    $_SESSION['admin_stock_mail_notice'] = [
        'type' => $result['failed'] > 0 ? 'warning' : 'success',
        'message' => AdminTranslator::trans('shop_settings.stock_waiting_sent', ['sent' => (string) $result['sent'], 'failed' => (string) $result['failed']]),
    ];
} catch (\Throwable $e) {
    error_log('[api/admin/send-stock-notifications.php] ' . $e->getMessage());
    $_SESSION['admin_stock_mail_notice'] = ['type' => 'error', 'message' => AdminTranslator::trans('shop_settings.stock_waiting_error')];
}

header('Location: /admin/shop-settings.php#stock-notification-mail');
exit;
