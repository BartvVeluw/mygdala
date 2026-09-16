<?php

/**
 * POST /api/admin/update-header-footer-settings.php
 *
 * The footer's slogan — App\Service\SiteSettings keys (see
 * db/migrations/20260909220000_pin_header_cta_and_footer_slogan.php), owned
 * on the read side by App\Service\FooterService.
 *
 * NO HEADER BUTTON ANY MORE. This endpoint used to write the eight
 * header_cta_* keys on every save. Header buttons are navigation items now
 * (api/admin/create-nav-item.php, App\Service\NavigationPresentation), and
 * the old keys are deliberately left alone: writing them here, from a form
 * that no longer has those fields, would blank a stored button with every
 * slogan edit. Nothing reads them either.
 *
 * ONE form for both sections, like update-footer-settings.php and unlike
 * update-site-settings.php: every field this screen has is submitted on every
 * save, so each key is written explicitly, including a checkbox's "off"
 * state. That is what makes turning the slogan off actually work — an
 * unchecked checkbox sends nothing at all, so a handler that only writes the
 * keys present in $_POST could never store a 0.
 *
 * NO SOCIAL PROFILES ANY MORE either. They are footer_social_links rows since
 * Footer phase B (App\Service\SocialProfiles); the seven social_*_url keys
 * this endpoint used to write are legacy and left alone.
 *
 * Same PRG/session-flash pattern as api/admin/update-site-settings.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
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

$field = static fn (string $name): string => trim((string) ($_POST[$name] ?? ''));

$errors = [];

$sloganNl = $field('footer_slogan_nl');
$sloganEn = $field('footer_slogan_en');

if (mb_strlen($sloganNl) > 200 || mb_strlen($sloganEn) > 200) {
    $errors[] = AdminTranslator::trans('validation.footer_slogan_mag_maximaal_200');
}

$values = [
    'footer_slogan_enabled' => isset($_POST['footer_slogan_enabled']) ? '1' : '0',
    'footer_slogan_nl' => $sloganNl,
    'footer_slogan_en' => $sloganEn,
];

if ($errors !== []) {
    $_SESSION['admin_header_footer_errors'] = $errors;
    $_SESSION['admin_header_footer_old'] = $values;
    header('Location: /admin/header-footer.php');
    exit;
}

try {
    (new SiteSettingRepository())->upsertMany($values);
    SiteSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-header-footer-settings.php] ' . $e->getMessage());

    $_SESSION['admin_header_footer_errors'] = [AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw')];
    $_SESSION['admin_header_footer_old'] = $values;
    header('Location: /admin/header-footer.php');
    exit;
}

header('Location: /admin/header-footer.php?saved=1');
exit;
