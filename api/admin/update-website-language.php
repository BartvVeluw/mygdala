<?php

/**
 * POST /api/admin/update-website-language.php
 *
 * Stores the two names of a website language: its English name, and its
 * name in itself — what the language switch shows a visitor.
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

$name = (string) ($_POST['name'] ?? '');
$nativeName = (string) ($_POST['native_name'] ?? '');

try {
    SiteLanguages::rename($code, $name, $nativeName);
} catch (\InvalidArgumentException $e) {
    website_language_redirect($e->getMessage());
} catch (\Throwable $e) {
    error_log('[api/admin/update-website-language.php] ' . $e->getMessage());
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
