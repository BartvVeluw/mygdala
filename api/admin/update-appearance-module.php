<?php

/**
 * POST /api/admin/update-appearance-module.php
 *
 * Switches one module that is part of how the site looks on or off, from the
 * Vormgeving screen (admin/theme.php, "Onderdelen van de vormgeving"): a
 * module that says so itself (ModuleDefinition::switchableFromAppearance(),
 * today Paginathema's). Found by that capability and by a key
 * that must be a registered module's — never a class name from the request —
 * and written through App\Module\ModuleSettings, the store the Setup Wizard
 * writes. The same pattern as api/admin/update-multilingual-publishing.php.
 *
 * OFF DELETES NOTHING (MODULES.md): the module's tables and every page's
 * choice stay stored, and switching it on again brings them back.
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

$moduleKey = is_string($_POST['module'] ?? null) ? trim($_POST['module']) : '';
$module = ModuleRegistry::definition($moduleKey);

// A key that is not a registered module, and a module that is not offered on
// this screen, are the same answer: nothing to switch here.
if ($module === null || !$module->switchableFromAppearance()) {
    http_response_code(404);
    exit('Not available.');
}

$wanted = (string) ($_POST['enabled'] ?? '0') === '1';

if (ModuleConfig::isPinnedByEnvironment($moduleKey)) {
    $_SESSION['admin_theme_errors'] = [
        AdminTranslator::trans('design.module_error_pinned', [
            'module' => $module->label(),
            'variable' => ModuleConfig::variableName($moduleKey),
        ]),
    ];
    header('Location: /admin/theme.php#onderdelen');
    exit;
}

try {
    ModuleSettings::save([$moduleKey => $wanted]);
} catch (\Throwable $e) {
    error_log('[api/admin/update-appearance-module.php] ' . $e->getMessage());
    $_SESSION['admin_theme_errors'] = [AdminTranslator::trans('design.module_error_save')];
    header('Location: /admin/theme.php#onderdelen');
    exit;
}

header('Location: /admin/theme.php?updated=1#onderdelen');
exit;
