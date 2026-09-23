<?php

/**
 * POST /api/admin/update-site-settings.php
 *
 * Saves the forms on admin/settings.php that post here. They are independent
 * <form> sections, each posting only its own fields, so only the keys a
 * request actually carries are written; everything else keeps its stored
 * value. WHICH keys may be written at all, and what makes a value acceptable,
 * is App\Service\SiteSettingsValidator — one closed list shared with the
 * screen's own required and maxlength attributes. Same PRG/session-flash
 * pattern as api/admin/update-product.php.
 *
 * THE PLACE VISITORS READ (`city`) is website text in one language
 * (App\Service\LocalizedSiteSettings): written only in the active website
 * language named by `language_code`, in the same transaction as the other
 * values, and every other language's place stays as it is.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\FormRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Branding;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\LocalizedSiteSettings;
use App\Service\Media\MediaService;
use App\Service\SiteSettings;
use App\Service\SiteSettingsValidator;
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

$current = SiteSettings::all();

/*
 * The forms are read for one rule only: the contact address may not be
 * emptied while an active form depends on it to deliver its submissions.
 * Unreadable forms are logged and treated as none, the same direction the
 * rest of this save takes when the database is in trouble.
 */
$forms = [];
try {
    $forms = (new FormRepository())->all();
} catch (\Throwable $e) {
    error_log('[api/admin/update-site-settings.php] could not read the forms: ' . $e->getMessage());
}

$validated = SiteSettingsValidator::validate($_POST, $current, $forms);
$fields = $validated['values'];
$errors = $validated['errors'];

// The localized setting this form carries, in the language it was typed in.
$localized = [];
$languageCode = '';
if (array_key_exists(LocalizedSiteSettings::CITY, $_POST)) {
    $languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
    $localized[LocalizedSiteSettings::CITY] = is_scalar($_POST[LocalizedSiteSettings::CITY]) ? trim((string) $_POST[LocalizedSiteSettings::CITY]) : '';

    if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
        $errors[] = AdminTranslator::trans('validation.language_unknown');
    } elseif (LocalizedSiteSettings::problems($localized) !== []) {
        $errors[] = AdminTranslator::trans('validation.a_field_is_too_long');
    }
}
$oldLocalized = $localized === [] ? [] : ['language_code' => $languageCode] + $localized;

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
        continue;
    }

    // The share image is a raster image (MediaType::SOCIAL_IMAGE); the one the
    // site already has stays acceptable.
    $media = $pathKey === 'og_image_path'
        ? MediaService::findSocialImage((int) $submitted, (int) SiteSettings::get($mediaKey))
        : MediaService::findImage((int) $submitted);

    if ($media === null) {
        // An id naming nothing must never be stored. Reported once, however
        // many of the four fields carried one.
        $errors['media'] = 'De gekozen afbeelding bestaat niet (meer) in de mediabibliotheek.';
        continue;
    }

    $fields[$mediaKey] = (string) $media->id;
    $fields[$pathKey] = $media->path;
}

if ($errors !== []) {
    $_SESSION['admin_settings_errors'] = $errors;
    $_SESSION['admin_settings_old'] = array_merge($current, $fields, $oldLocalized);
    header('Location: /admin/settings.php');
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();
    (new SiteSettingRepository($db))->upsertMany($fields);
    if ($localized !== []) {
        LocalizedSiteSettings::save($languageCode, $localized);
    }
    $db->commit();
    SiteSettings::clearCache();
    LocalizedSiteSettings::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-site-settings.php] ' . $e->getMessage());

    $_SESSION['admin_settings_errors'] = [AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw')];
    $_SESSION['admin_settings_old'] = array_merge($current, $fields, $oldLocalized);
    header('Location: /admin/settings.php');
    exit;
}

header('Location: /admin/settings.php?saved=1');
exit;
