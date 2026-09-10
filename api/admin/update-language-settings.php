<?php

/**
 * POST /api/admin/update-language-settings.php
 *
 * Which languages this WEBSITE publishes: one primary, and optionally one
 * secondary (Multilingual V1, MULTILINGUAL.md).
 *
 * NOT the CMS interface language. That is a preference of one person, it
 * lives on `admin_users`, and it is written by
 * api/admin/update-account-preferences.php. This endpoint cannot reach it,
 * which is how "the two settings are independent" stops being a promise and
 * becomes a fact — Tests\Service\ContentLanguagesTest checks both directions.
 *
 * TURNING A LANGUAGE OFF DELETES NOTHING. All this writes is two rows in
 * `site_settings`. Every `_en` column keeps whatever it held; the editors
 * simply stop showing it, and it comes back the moment the language is
 * enabled again (Part Q of the brief). There is no migration, no cleanup and
 * no "are you sure you want to lose your translations", because nothing is
 * lost.
 *
 * VALIDATION IS App\Service\Language\ContentLanguages::normalise()'s, and the
 * Setup Wizard calls the same method. One definition of a valid language
 * configuration, used by both places that can produce one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\ContentLanguages;
use App\Service\SiteSettings;

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

$primary = trim((string) ($_POST['primary_content_language'] ?? ''));
$secondary = trim((string) ($_POST['secondary_content_language'] ?? ''));

// An empty secondary is the normal single-language case, not an error.
$enabled = $secondary === '' ? [] : [$secondary];

$values = ContentLanguages::normalise($primary, $enabled);

try {
    (new SiteSettingRepository())->upsertMany($values);
    SiteSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-language-settings.php] ' . $e->getMessage());

    $_SESSION['admin_language_errors'] = ['De taalinstellingen konden niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/settings.php');
    exit;
}

header('Location: /admin/settings.php?saved=1&section=talen');
exit;
