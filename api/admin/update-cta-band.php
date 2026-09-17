<?php

/**
 * POST /api/admin/update-cta-band.php
 *
 * Saves one CTA band page-builder instance
 * (admin/cta-band.php?section=<page>:<key>). Same guard order and
 * PRG/session-flash pattern as api/admin/update-rich-text-section.php, and
 * the same "a page-builder-attached section is valid only when the page AND
 * its content row already exist" gate — an arbitrary page_slug:section_key
 * pair from the request is never trusted beyond that.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the five text fields are the words
 * of the language named in `language_code`, which must be an active language
 * of the website registry. Which fields exist, how long they may be and
 * which are required in the default language comes from
 * CtaBandBlock::translatableFields(), through App\Service\Blocks\BlockLocalization,
 * and only that language is written. The URLs and is_active are the same in
 * every language.
 *
 * THE SECONDARY BUTTON needs a label and a URL, or neither. The label that
 * counts is the default language's — the one every other language falls back
 * to — so a translation save checks the stored default label, and a
 * translated label without a URL is refused too, since it could never show.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\CtaBandContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Repository\PageRepository;
use App\Repository\CtaBandRepository;

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

$sectionParam = (string) ($_POST['section'] ?? '');
[$slug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new CtaBandRepository();

if ($slug === null || $slug === '' || $sectionKey === null || $sectionKey === ''
    || (new PageRepository())->findByContentKey($slug) === null
    || ($section = $repository->findBySlugAndKey($slug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$sectionId = (int) $section['id'];
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$isDefaultLanguage = $languageIsWritable && $languageCode === BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('cta_bands')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = [
    'primary_url' => trim((string) ($_POST['primary_url'] ?? '')),
    'secondary_url' => trim((string) ($_POST['secondary_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('cta_bands', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($settings['primary_url'] === '' && !in_array(AdminTranslator::trans('validation.veld_verplicht'), $errors, true)) {
    $errors[] = AdminTranslator::trans('validation.veld_verplicht');
}

// A secondary button needs both a label and a URL, or neither — a
// half-filled optional button would be broken/dead on the frontend.
$defaultSecondaryLabel = $isDefaultLanguage
    ? $words['secondary_label']
    : BlockLocalization::raw('cta_bands', $sectionId, 'secondary_label', BlockLocalization::defaultLanguage());
$secondaryUrlSet = $settings['secondary_url'] !== '';

if ($languageIsWritable && (($defaultSecondaryLabel !== '') !== $secondaryUrlSet || ($words['secondary_label'] !== '' && !$secondaryUrlSet))) {
    $errors[] = AdminTranslator::trans('validation.secondary_button_label_and_url');
}

$old = ['language_code' => $languageCode] + $words + $settings;
$redirect = '/admin/cta-band.php?section=' . urlencode($sectionParam);

if ($errors !== []) {
    $_SESSION['admin_cta_band_errors'] = $errors;
    $_SESSION['admin_cta_band_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The band's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository->upsertSection($slug, $sectionKey, $settings);
    BlockLocalization::save('cta_bands', $sectionId, $languageCode, $words);

    $db->commit();
    CtaBandContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-cta-band.php] ' . $e->getMessage());

    $_SESSION['admin_cta_band_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_cta_band_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
