<?php

/**
 * POST /api/admin/update-footer-settings.php
 *
 * Writes the footer's Brand/Company block toggles + description +
 * copyright template — all site_settings keys (see
 * db/migrations/20260907230000_add_footer_settings.php), so this is the
 * second admin entry point that can edit footer_description_nl/en (the
 * first being Settings -> Branding) — same underlying key, no duplicate
 * storage. Unlike update-site-settings.php (which only overwrites keys
 * present in $_POST because 3 separate forms share one page), this form
 * always submits every one of its own fields, so every key here is always
 * written explicitly, including the checkboxes' "off" state.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SiteSettings;
use App\Repository\SiteSettingRepository;

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

$descriptionNl = trim((string) ($_POST['footer_description_nl'] ?? ''));
$descriptionEn = trim((string) ($_POST['footer_description_en'] ?? ''));
$copyrightTemplate = trim((string) ($_POST['footer_copyright_template'] ?? ''));

if (mb_strlen($descriptionNl) > 500 || mb_strlen($descriptionEn) > 500 || mb_strlen($copyrightTemplate) > 300) {
    $_SESSION['admin_footer_error'] = 'Een van de velden is te lang.';
    header('Location: /admin/footer.php');
    exit;
}

if ($copyrightTemplate === '') {
    $copyrightTemplate = '© {{year}} {{site_name}}';
}

$values = [
    'footer_show_logo' => isset($_POST['footer_show_logo']) ? '1' : '0',
    'footer_show_company_name' => isset($_POST['footer_show_company_name']) ? '1' : '0',
    'footer_show_email' => isset($_POST['footer_show_email']) ? '1' : '0',
    'footer_show_phone' => isset($_POST['footer_show_phone']) ? '1' : '0',
    'footer_show_kvk' => isset($_POST['footer_show_kvk']) ? '1' : '0',
    'footer_copyright_template' => $copyrightTemplate,
    'footer_description_nl' => $descriptionNl,
    'footer_description_en' => $descriptionEn,
];

try {
    (new SiteSettingRepository())->upsertMany($values);
    SiteSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-footer-settings.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = 'Instellingen konden niet worden opgeslagen.';
    header('Location: /admin/footer.php');
    exit;
}

header('Location: /admin/footer.php?saved=1');
exit;
