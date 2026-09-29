<?php

/**
 * POST /api/admin/update-navigation-settings.php
 *
 * The "Zoeken" card of admin/navigation.php: whether the site search shows
 * in the header (the site setting App\Service\Search\SearchService::SETTING,
 * SEARCH.md). One switch, written on every save including its "off" state —
 * an unticked checkbox sends nothing, so a handler that only wrote what is
 * present could never store a 0 (the same rule as
 * api/admin/update-footer-settings.php, whose PRG pattern this copies).
 *
 * Takes nothing else from the request: no key name, no value but on/off.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Search\SearchService;
use App\Service\SiteSettings;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

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
    (new SiteSettingRepository())->upsertMany([
        SearchService::SETTING => isset($_POST[SearchService::SETTING]) ? '1' : '0',
    ]);
    SiteSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-navigation-settings.php] ' . $e->getMessage());
    $_SESSION['admin_nav_error'] = AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw');
    header('Location: /admin/navigation.php#navigation-search');
    exit;
}

header('Location: /admin/navigation.php?saved=1#navigation-search');
exit;
