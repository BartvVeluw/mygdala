<?php

/**
 * POST /api/admin/update-contact-card.php
 *
 * Saves one Contactkaart block
 * (admin/contact-card.php?section=<page>:<key>). Same guard order and
 * PRG/session-flash pattern as api/admin/update-rich-text-section.php, and
 * the same "a page-builder-attached section is valid only when the page AND
 * its content row already exist" gate — an arbitrary page_slug:section_key
 * pair from the request is never trusted beyond that.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): heading, text and button label are
 * the words of the language named in `language_code`, which must be an
 * active language of the website registry. Which fields exist, their lengths
 * and the heading being required in the default language come from
 * ContactCardBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written.
 * The URL and is_active are the same in every language.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContactCardContent;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Repository\PageRepository;
use App\Repository\ContactCardRepository;

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
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ContactCardRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
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
foreach (array_keys(BlockLocalization::fields('contact_cards')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = [
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('contact_cards', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }

    // A URL without a label would be an invisible link; a label alone is
    // fine — an empty URL means "mail the address from Instellingen",
    // which App\Service\ContactCardContent resolves at render time. The
    // label that counts is the default language's, which every other
    // language falls back to.
    $defaultLabel = $isDefaultLanguage
        ? $words['button_label']
        : BlockLocalization::raw('contact_cards', $sectionId, 'button_label', BlockLocalization::defaultLanguage());

    if ($settings['button_url'] !== '' && $defaultLabel === '') {
        $errors[] = AdminTranslator::trans('validation.vul_label_knop_laat_ook');
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;
$redirect = '/admin/contact-card.php?section=' . urlencode($sectionParam);

if ($errors !== []) {
    $_SESSION['admin_contact_card_errors'] = $errors;
    $_SESSION['admin_contact_card_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The card's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, $settings);
    BlockLocalization::save('contact_cards', $sectionId, $languageCode, $words);

    $db->commit();
    ContactCardContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-contact-card.php] ' . $e->getMessage());

    $_SESSION['admin_contact_card_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_contact_card_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
