<?php

/**
 * POST /api/admin/update-footer-settings.php
 *
 * The two settings forms of admin/footer.php, told apart by `section`, a
 * closed list (FOOTER_SETTINGS_SECTIONS below):
 *
 *   brand   the company block: which of logo, site name, e-mail address,
 *           phone number and KVK number the footer shows (footer_show_*),
 *           and the footer description;
 *   bottom  the bottom line: the copyright text (footer_copyright_template),
 *           whether the closing line shows (footer_slogan_enabled) and its
 *           words.
 *
 * The switches and the copyright template are site_settings rows. The
 * description and the closing line are website TEXT, stored per language by
 * App\Service\LocalizedSiteSettings (Multilingual 2.0 phase 4): written only
 * in the active website language named by `language_code`, in the same
 * transaction as the section's switches, and every other language stays as
 * it is. Read by App\Service\FooterService and partials/footer.php.
 *
 * EACH SECTION WRITES EXACTLY ITS OWN KEYS, every one of them on every save,
 * including a switch's "off" state: an unticked checkbox sends nothing, so a
 * handler that only wrote the keys present in $_POST could never store a 0.
 * A key of the other section is never touched, so the two forms cannot switch
 * each other's settings off. An unknown section writes nothing at all.
 *
 * ONE PLACE FOR THE FOOTER DESCRIPTION. Until Footer phase B
 * update-site-settings.php could write it too.
 * App\Service\SiteSettingsValidator no longer lists them, so this is the only
 * screen that edits them (HEADER-FOOTER.md).
 *
 * WHAT IS NOT HERE. The company's name, e-mail address, phone number, KVK
 * number and logo belong to Site-instellingen; the footer only decides
 * whether to show them, and hiding one never changes or removes the value.
 *
 * Same PRG/session-flash pattern as api/admin/update-site-settings.php: a
 * refused save goes back to its own card with what was typed, without
 * saved=1, which is how the save bar tells it from a written one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\LocalizedSiteSettings;
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

/** section => the card on admin/footer.php it belongs to */
const FOOTER_SETTINGS_SECTIONS = ['brand' => 'footer-brand', 'bottom' => 'footer-bottom'];

$section = is_string($_POST['section'] ?? null) ? $_POST['section'] : '';
if (!array_key_exists($section, FOOTER_SETTINGS_SECTIONS)) {
    http_response_code(400);
    exit('Unknown section.');
}

$field = static fn (string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
$switch = static fn (string $name): string => isset($_POST[$name]) ? '1' : '0';

$errors = [];
$languageCode = LanguageCode::normalise($field('language_code')) ?? '';

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}

if ($section === 'brand') {
    $values = [
        'footer_show_logo' => $switch('footer_show_logo'),
        'footer_show_company_name' => $switch('footer_show_company_name'),
        'footer_show_email' => $switch('footer_show_email'),
        'footer_show_phone' => $switch('footer_show_phone'),
        'footer_show_kvk' => $switch('footer_show_kvk'),
    ];
    $localized = [LocalizedSiteSettings::FOOTER_DESCRIPTION => $field('footer_description')];

    if (LocalizedSiteSettings::problems($localized) !== []) {
        $errors[] = AdminTranslator::trans('footer.description_too_long');
    }
} else {
    $values = [
        'footer_copyright_template' => $field('footer_copyright_template'),
        'footer_slogan_enabled' => $switch('footer_slogan_enabled'),
    ];
    $localized = [LocalizedSiteSettings::FOOTER_SLOGAN => $field('footer_slogan')];

    if (mb_strlen($values['footer_copyright_template']) > 300) {
        $errors[] = AdminTranslator::trans('footer.copyright_too_long');
    }
    if (LocalizedSiteSettings::problems($localized) !== []) {
        $errors[] = AdminTranslator::trans('validation.footer_slogan_mag_maximaal_200');
    }
}

$card = '/admin/footer.php#' . FOOTER_SETTINGS_SECTIONS[$section];
$old = $values + $localized + ['language_code' => $languageCode];

if ($errors !== []) {
    $_SESSION['admin_footer_settings_error'] = ['section' => $section, 'errors' => $errors, 'old' => $old];
    header('Location: ' . $card);
    exit;
}

// An empty copyright text would leave the footer's bottom line without its
// most basic content; empty means "the standard one" instead, as it always
// did here.
if ($section === 'bottom' && $values['footer_copyright_template'] === '') {
    $values['footer_copyright_template'] = SiteSettings::defaults()['footer_copyright_template'];
}

$db = Database::connection();

try {
    $db->beginTransaction();
    (new SiteSettingRepository($db))->upsertMany($values);
    LocalizedSiteSettings::save($languageCode, $localized);
    $db->commit();
    SiteSettings::clearCache();
    LocalizedSiteSettings::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-footer-settings.php] ' . $e->getMessage());
    $_SESSION['admin_footer_settings_error'] = [
        'section' => $section,
        'errors' => [AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw')],
        'old' => $old,
    ];
    header('Location: ' . $card);
    exit;
}

header('Location: /admin/footer.php?saved=1#' . FOOTER_SETTINGS_SECTIONS[$section]);
exit;
