<?php

/**
 * POST /api/admin/update-language-settings.php
 *
 * The WEBSITE's default language: which of Dutch and English a visitor gets
 * first (MULTILINGUAL.md).
 *
 * NOT the CMS interface language. That is a preference of one person, it
 * lives on `admin_users`, and it is written by
 * api/admin/update-account-preferences.php. This endpoint cannot reach it,
 * which is how "the two settings are independent" stops being a promise and
 * becomes a fact — Tests\Service\MultilingualBoundaryTest checks both
 * directions.
 *
 * WHERE IT IS STORED. Since Multilingual 2.0 phase 1 the default is the
 * default row of the website language registry (`site_languages`), not a
 * settings row (docs/multilingual/ARCHITECTURE.md). Nothing else changes:
 * both languages stay published and no `_nl` or `_en` column is touched.
 *
 * VALIDATION AND WRITING ARE App\Service\Language\ContentLanguages::
 * savePrimary()'s, and the Setup Wizard calls the same method. One definition
 * of a valid default language, used by both places that can choose one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\ContentLanguages;

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

// There is no "second language" question to answer: this product publishes
// Dutch and English, always, and the only thing an owner chooses is which of
// the two a visitor gets first. An old form posting the removed field changes
// nothing.
try {
    ContentLanguages::savePrimary($primary);
} catch (\Throwable $e) {
    error_log('[api/admin/update-language-settings.php] ' . $e->getMessage());

    $_SESSION['admin_language_errors'] = ['De taalinstellingen konden niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/settings.php');
    exit;
}

header('Location: /admin/settings.php?saved=1&section=talen');
exit;
