<?php

/**
 * POST /api/admin/update-multilingual-publishing.php
 *
 * Switches the publication of the website's other languages on or off:
 * the stored preference of the module that says it publishes them
 * (ModuleDefinition::publishesTranslations(), App\Module\MultilingualModule).
 * Found by that capability, never by its key, and written through
 * App\Module\ModuleSettings, the same store the Setup Wizard writes
 * (MODULES.md, "En wat het CMS erover mag zeggen").
 *
 * OFF DELETES NOTHING. The languages stay registered with their own active
 * flag, every translation stays stored, and switching it on again publishes
 * the same languages at the same addresses.
 *
 * An environment variable pinning the module has the last word, as
 * everywhere: then this refuses and says which variable to change.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Module\ModuleSettings;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;

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

$wanted = (string) ($_POST['enabled'] ?? '0') === '1';

$moduleKey = null;
foreach (ModuleRegistry::all() as $key => $module) {
    if ($module->publishesTranslations()) {
        $moduleKey = $key;
        break;
    }
}

if ($moduleKey === null) {
    http_response_code(404);
    exit('Not available.');
}

if (ModuleConfig::isPinnedByEnvironment($moduleKey)) {
    $_SESSION['admin_language_errors'] = [
        AdminTranslator::trans('language.error_pinned', ['variable' => ModuleConfig::variableName($moduleKey)]),
    ];
    header('Location: /admin/settings.php#tab-talen');
    exit;
}

try {
    ModuleSettings::save([$moduleKey => $wanted]);
} catch (\Throwable $e) {
    error_log('[api/admin/update-multilingual-publishing.php] ' . $e->getMessage());
    $_SESSION['admin_language_errors'] = [AdminTranslator::trans('language.error_save')];
    header('Location: /admin/settings.php#tab-talen');
    exit;
}

header('Location: /admin/settings.php?saved=1&section=talen#tab-talen');
exit;
