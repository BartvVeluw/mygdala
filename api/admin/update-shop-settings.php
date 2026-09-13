<?php

/**
 * POST /api/admin/update-shop-settings.php
 *
 * Saves one tab of admin/shop-settings.php. The pattern is
 * api/admin/update-site-settings.php, which these fields used to post to:
 * only the keys the request carries are written, App\Service\ShopSettings
 * decides which keys may be written at all, and the PRG redirect goes back to
 * the tab that was saved.
 *
 * ModuleGuard first. settings.manage is Core's permission and stays holdable
 * with the Shop switched off, so unlike the other Shop endpoints the
 * permission alone would not close this one (MODULES.md, "Guards").
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Module\ModuleGuard;
use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\ShopSettings;
use App\Service\SiteSettings;

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

$section = ShopSettings::section($_POST['section'] ?? null);
$current = SiteSettings::all();
$validated = ShopSettings::validate($_POST, $current);

if ($validated['errors'] !== []) {
    $_SESSION['admin_shop_settings_errors'] = $validated['errors'];
    $_SESSION['admin_shop_settings_old'] = array_merge($current, $validated['values']);
    $_SESSION['admin_shop_settings_section'] = $section;
    header('Location: /admin/shop-settings.php');
    exit;
}

try {
    (new SiteSettingRepository())->upsertMany($validated['values']);
    SiteSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-shop-settings.php] ' . $e->getMessage());

    $_SESSION['admin_shop_settings_errors'] = [AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw')];
    $_SESSION['admin_shop_settings_old'] = array_merge($current, $validated['values']);
    $_SESSION['admin_shop_settings_section'] = $section;
    header('Location: /admin/shop-settings.php');
    exit;
}

header('Location: /admin/shop-settings.php?saved=1&section=' . rawurlencode($section));
exit;
