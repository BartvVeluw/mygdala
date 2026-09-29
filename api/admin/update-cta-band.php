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
 * and only that language is written. The destinations, the presentation and
 * is_active are the same in every language.
 *
 * THE PRESENTATION (CTA 2.0) is a word from a closed list each
 * (App\Service\CtaBandContent): an unknown word is refused with a message at
 * its field, never stored, and a form without the field keeps what is stored.
 * The background picture is a Media Library id, checked to be a picture that
 * exists (MediaService::findImage()); a number that matches nothing, or a
 * video, is refused.
 *
 * THE BUTTONS follow the rule every block button follows
 * (App\Service\Routing\LinkChoice, admin/_link_target_field.php): "Geen knop",
 * a page, a blog post, a product, or an own address. A button with a
 * destination needs its label in the default language — the one every other
 * language falls back to — so a translation save checks the stored default
 * label. "Geen knop" is no button whatever else is posted: its label and
 * address are hidden with it and may still hold old values, so the label is
 * not checked and the address is stored empty (a row without a type but with
 * an address would read as an address, LinkChoice::storedType()). The second
 * button exists only next to the first: with "Geen knop" on the first, the
 * second is stored as no button too, whatever was posted for it.
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
use App\Service\Media\ResponsiveImage;
use App\Repository\ResponsiveImageRepository;
use App\Service\Media\MediaService;
use App\Service\Routing\LinkChoice;
use App\Repository\CtaBandRepository;

AdminAuth::requireLoginForApi();
\App\Service\ContentOwners\ContentBlockAccess::requireAnyForApi();

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
    || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi($slug) === null
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

$fieldErrors = [];

// ------------------------------------------------------------ presentation
// Each a word from its closed list. A form without the field keeps what is
// stored; a word that is not on the list is refused at its field.
$stored = CtaBandContent::presentation($section);
$choices = [
    'content_align' => [CtaBandContent::ALIGNMENTS, $stored['align'], 'block_cta.error_align'],
    'lead_width' => [CtaBandContent::LEAD_WIDTHS, $stored['lead_width'], 'block_cta.error_lead_width'],
    'background_overlay' => [CtaBandContent::OVERLAYS, CtaBandContent::choice(CtaBandContent::OVERLAYS, $section['background_overlay'] ?? null), 'block_cta.error_overlay'],
    'text_panel_opacity' => [CtaBandContent::PANEL_OPACITIES, CtaBandContent::choice(CtaBandContent::PANEL_OPACITIES, $section['text_panel_opacity'] ?? null), 'block_cta.error_panel_opacity'],
];
$presentation = [];
foreach ($choices as $name => [$list, $current, $message]) {
    $value = array_key_exists($name, $_POST) ? (string) $_POST[$name] : $current;
    if (!in_array($value, $list, true)) {
        $fieldErrors[$name] = AdminTranslator::trans($message);
        $value = $current;
    }
    $presentation[$name] = $value;
}
$presentation['full_width'] = isset($_POST['full_width']);
$presentation['text_panel'] = isset($_POST['text_panel']);

// The background picture: empty is none, anything else must name a picture.
$backgroundPosted = trim((string) ($_POST['background_media_id'] ?? ''));
$background = MediaService::findImage(ctype_digit($backgroundPosted) ? (int) $backgroundPosted : null);
if ($backgroundPosted !== '' && $backgroundPosted !== '0' && $background === null) {
    $fieldErrors['background_media_id'] = AdminTranslator::trans('editor_rows.error_media_unknown');
}
$presentation['background_media_id'] = $background?->id;

// How the background sits in the band, on a large screen and on a phone
// (Responsive Media 2.0): refused part by part, a part the form does not
// carry keeps what is stored.
$backgroundSlot = CtaBandContent::backgroundSlot();
[$backgroundPresentation, $backgroundErrors] = ResponsiveImage::fromRequest($_POST, $backgroundSlot, ResponsiveImage::fromRow($section, $backgroundSlot));
foreach ($backgroundErrors as $part => $message) {
    $fieldErrors['presentation.' . $part] = $message;
}

// ----------------------------------------------------------------- buttons
$storedDefault = static fn (string $field): string => BlockLocalization::raw('cta_bands', $sectionId, $field, BlockLocalization::defaultLanguage());

$linkTypes = [];
$postedTargets = [];
$postedUrls = [];
$links = [];
foreach (['primary', 'secondary'] as $button) {
    $linkTypes[$button] = (string) ($_POST[$button . '_link_type'] ?? LinkChoice::NONE);
    $postedTargets[$button] = is_array($_POST[$button . '_link_target'] ?? null) ? $_POST[$button . '_link_target'] : [];
    $postedUrls[$button] = trim((string) ($_POST[$button . '_url'] ?? ''));

    $links[$button] = LinkChoice::fromRequest(
        $linkTypes[$button],
        $postedTargets[$button][$linkTypes[$button]] ?? null,
        $postedUrls[$button],
        (string) ($section[$button . '_link_type'] ?? ''),
        (int) ($section[$button . '_link_target_id'] ?? 0)
    );
}

// No first button, no second one: whatever was posted for it is hidden with
// the first button's group and is not a button.
if ($links['primary']['link_type'] === null) {
    $links['secondary'] = ['link_type' => null, 'link_target_id' => null, 'error' => null];
}

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('cta_bands', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }

    foreach (['primary', 'secondary'] as $button) {
        if ($links[$button]['error'] !== null) {
            $fieldErrors[$button . '_url'] = $links[$button]['error'];
        }

        // A button needs its label in the default language; a translation
        // may stay empty and falls back to it (CtaBandContent).
        $defaultLabel = $isDefaultLanguage ? $words[$button . '_label'] : $storedDefault($button . '_label');
        if ($links[$button]['link_type'] !== null && $defaultLabel === '') {
            $fieldErrors[$button . '_label'] ??= AdminTranslator::trans('block_cta.error_' . $button . '_label');
        }
    }
}

array_push($errors, ...array_values($fieldErrors));

$settings = $presentation + [
    'is_active' => isset($_POST['is_active']),
];
foreach (['primary', 'secondary'] as $button) {
    $settings[$button . '_link_type'] = $links[$button]['link_type'];
    $settings[$button . '_link_target_id'] = $links[$button]['link_target_id'];
    // "Geen knop" stores no address either: a row without a type but with an
    // address reads as an address (LinkChoice::storedType()).
    $settings[$button . '_url'] = $links[$button]['link_type'] === null ? '' : $postedUrls[$button];
}

// What a refused save hands back: everything as posted, the buttons' kinds
// and targets included, so the editor reopens on what was typed.
$old = [
    'language_code' => $languageCode,
    'is_active' => $settings['is_active'],
    'background_media_id' => $backgroundPosted,
    'background_presentation' => $backgroundPresentation->toRow($backgroundSlot),
] + $words + $presentation;
foreach (['primary', 'secondary'] as $button) {
    $old[$button . '_link_type'] = $linkTypes[$button];
    $old[$button . '_link_target'] = array_map('intval', array_filter($postedTargets[$button], 'is_scalar'));
    $old[$button . '_url'] = $postedUrls[$button];
}

$redirect = '/admin/cta-band.php?section=' . urlencode($sectionParam);

if ($errors !== []) {
    $_SESSION['admin_cta_band_errors'] = $errors;
    $_SESSION['admin_cta_band_field_errors'] = $fieldErrors;
    $_SESSION['admin_cta_band_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The band's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository->upsertSection($slug, $sectionKey, $settings);
    (new ResponsiveImageRepository())->save('cta_bands', $sectionId, $backgroundSlot, $backgroundPresentation);
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
