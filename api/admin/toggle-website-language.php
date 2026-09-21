<?php

/**
 * POST /api/admin/toggle-website-language.php
 *
 * Switches a website language on or off. Off deletes nothing: the
 * language keeps every translation and comes back with it. The default
 * language cannot be switched off — a website always publishes it.
 *
 * Settings > Talen (admin/settings.php). The same four guards as every admin
 * write endpoint, then App\Service\Language\SiteLanguages, which owns every
 * rule about the registry; this file only translates its refusal into a
 * sentence for the screen. A refusal is a flash message, never a changed row.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\SiteLanguages;

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

$code = trim((string) ($_POST['code'] ?? ''));

$active = (string) ($_POST['is_active'] ?? '');
if (!in_array($active, ['0', '1'], true)) {
    http_response_code(400);
    exit('Invalid state.');
}

try {
    if ($active === '1') {
        SiteLanguages::activate($code);
    } else {
        SiteLanguages::deactivate($code);
    }
} catch (\InvalidArgumentException $e) {
    website_language_redirect($e->getMessage());
} catch (\Throwable $e) {
    error_log('[api/admin/toggle-website-language.php] ' . $e->getMessage());
    website_language_redirect('save');
}

website_language_redirect(null);

/**
 * Back to the Talen tab: saved, or with the one sentence that says why not.
 * The reason is a closed word from SiteLanguages, so only a sentence of the
 * CMS's own catalogue can reach the screen.
 */
function website_language_redirect(?string $reason): never
{
    if ($reason !== null) {
        $key = in_array($reason, ['code', 'exists', 'name', 'default', 'active', 'in_use', 'unknown'], true)
            ? 'language.error_' . $reason
            : 'language.error_save';
        $_SESSION['admin_language_errors'] = [AdminTranslator::trans($key)];
        header('Location: /admin/settings.php#tab-talen');
        exit;
    }

    header('Location: /admin/settings.php?saved=1&section=talen#tab-talen');
    exit;
}
