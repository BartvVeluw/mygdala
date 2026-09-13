<?php

/**
 * POST /api/admin/update-site-settings.php
 *
 * Saves the global site settings form(s) on admin/settings.php. The page
 * has grown into several independent <form> sections (site branding,
 * company/invoicing, order-confirmation email copy) each posting only its
 * own fields — so a key missing from $_POST keeps its current stored value
 * rather than being blanked out; only keys actually present in the request
 * are written. Same PRG/session-flash pattern as api/admin/update-product.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\Language\LocalizedValue;
use App\Service\AdminAuth;
use App\Service\Branding;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\Seo;
use App\Service\SiteSettings;
use App\Repository\SiteSettingRepository;

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

$current = SiteSettings::all();

$fields = [];
$submittedKeys = [];
foreach (array_keys(SiteSettings::defaults()) as $key) {
    if (array_key_exists($key, $_POST)) {
        $fields[$key] = trim((string) $_POST[$key]);
        $submittedKeys[] = $key;
    } else {
        $fields[$key] = $current[$key] ?? '';
    }
}

/**
 * The branding images. Each is a Media Library reference now, submitted by
 * the picker on admin/settings.php as `<name>_media_id` — no file is
 * uploaded here and no file is ever deleted here.
 *
 * A form section that did not carry the field at all leaves the stored value
 * untouched, which is what keeps the several independent <form> sections on
 * that page from clearing each other. An EMPTY field is different: that is
 * the picker's "Wissen", and it means "this install has no logo/favicon/
 * social image", which App\Service\Branding turns into sensible behaviour.
 *
 * The legacy `*_path` setting is written with the chosen item's own path
 * rather than dropped, so it stays a true fallback for as long as it exists
 * (MEDIA.md) — and it is cleared together with the reference, so the two can
 * never disagree about whether there is a logo.
 *
 * Deleting the FILE is not this endpoint's business: the same image may be
 * the site's logo and a photo on three pages. That decision lives on
 * admin/media.php, and it refuses while anything still uses the item.
 */
$errors = [];

foreach (Branding::MEDIA_KEYS as $pathKey => $mediaKey) {
    // The picker's field is named after the setting it fills, so there is no
    // second naming scheme to keep in step.
    if (!array_key_exists($mediaKey, $_POST)) {
        continue;
    }

    $submitted = trim((string) $_POST[$mediaKey]);

    if ($submitted === '') {
        $fields[$mediaKey] = '';
        $fields[$pathKey] = '';
        $submittedKeys[] = $mediaKey;
        $submittedKeys[] = $pathKey;
        continue;
    }

    $media = MediaService::find((int) $submitted);

    if ($media === null) {
        // An id naming nothing must never be stored. Reported once, however
        // many of the four fields carried one.
        $errors['media'] = 'De gekozen afbeelding bestaat niet (meer) in de mediabibliotheek.';
        continue;
    }

    $fields[$mediaKey] = (string) $media->id;
    $fields[$pathKey] = $media->path;
    $submittedKeys[] = $mediaKey;
    $submittedKeys[] = $pathKey;
}

$required = ['site_name', 'email'];

/**
 * The localized ones, asked in the SITE'S OWN language only.
 *
 * A translation is optional by definition: App\Service\Language\LocalizedValue
 * falls back to the primary language wherever one is missing, so a visitor
 * never reads a blank. Demanding both was invisible while every editor
 * printed both fields side by side. It stopped being invisible once an
 * editor shows ONE language at a time (MULTILINGUAL.md): the untranslated
 * half is off screen, so this screen refused every save with an error
 * pointing at a field nobody could see, and an English footer description
 * could never be saved at all. update-nav-item.php and
 * update-footer-column.php already ask it this way.
 */
$requiredLocalized = ['city', 'footer_description'];

foreach ($required as $key) {
    if (in_array($key, $submittedKeys, true) && $fields[$key] === '') {
        $errors[] = AdminTranslator::trans('validation.veld_verplicht');
        break;
    }
}

foreach ($requiredLocalized as $base) {
    $dutchKey = $base . '_nl';
    $englishKey = $base . '_en';

    if (!in_array($dutchKey, $submittedKeys, true) && !in_array($englishKey, $submittedKeys, true)) {
        continue;
    }

    $localized = LocalizedValue::ofDutchEnglish($fields[$dutchKey] ?? '', $fields[$englishKey] ?? '');

    if ($localized->primaryValue() === '') {
        $errors[] = AdminTranslator::trans('validation.veld_verplicht');
        break;
    }
}

if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = AdminTranslator::trans('validation.ongeldig_e_mailadres');
}

/**
 * The global SEO defaults (App\Service\SeoDefaults).
 *
 * The indexing switch is stored as a CLOSED two-value enum, never as
 * whatever the request happened to send: it ends up in a <meta name="robots">
 * tag, and the one thing that must never be possible is arbitrary text
 * reaching a meta attribute through a settings row. Anything but an
 * explicit "1" is stored as "0", and the form's hidden companion field is
 * what makes an unticked checkbox reach here at all.
 *
 * The description is length-checked against the same limit the CMS page and
 * product editors use, and never silently truncated — an administrator's own
 * copy is not this endpoint's to rewrite.
 */
if (in_array('seo_robots_index_default', $submittedKeys, true)) {
    $fields['seo_robots_index_default'] = $fields['seo_robots_index_default'] === '1' ? '1' : '0';
}

if (
    in_array('seo_default_description', $submittedKeys, true)
    && mb_strlen($fields['seo_default_description']) > Seo::MAX_META_DESCRIPTION_LENGTH
) {
    $errors[] = 'Standaard meta description mag maximaal ' . Seo::MAX_META_DESCRIPTION_LENGTH . ' tekens zijn.';
}

/**
 * The order-number prefix (App\Repository\OrderRepository::formatOrderNumber()).
 *
 * Letters and digits only, and short: it ends up in customer e-mails, in the
 * Mollie description on a bank statement and in a CSV cell, and the formatter
 * owns the separators. Refused rather than silently cleaned, so what an owner
 * sees after saving is what they typed. An empty field is allowed and means
 * the generic default, the rule every other setting on this screen follows.
 */
if (
    in_array('order_number_prefix', $submittedKeys, true)
    && $fields['order_number_prefix'] !== ''
    && preg_match('/^[A-Za-z0-9]{1,10}$/', $fields['order_number_prefix']) !== 1
) {
    $errors[] = AdminTranslator::trans('validation.bestelnummerprefix_ongeldig');
}

if ($errors !== []) {
    $_SESSION['admin_settings_errors'] = $errors;
    $_SESSION['admin_settings_old'] = $fields;
    header('Location: /admin/settings.php');
    exit;
}

try {
    (new SiteSettingRepository())->upsertMany($fields);
    SiteSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-site-settings.php] ' . $e->getMessage());

    $_SESSION['admin_settings_errors'] = [AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw')];
    $_SESSION['admin_settings_old'] = $fields;
    header('Location: /admin/settings.php');
    exit;
}

header('Location: /admin/settings.php?saved=1');
exit;
