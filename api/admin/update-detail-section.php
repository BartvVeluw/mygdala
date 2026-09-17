<?php

/**
 * POST /api/admin/update-detail-section.php
 *
 * Saves the section-level fields of one Detailsectie block
 * (admin/detail-section.php?section=...): anchor + nav label, title, lead,
 * rich body, image position, closing note, CTA and visibility. The main
 * image, the "kenmerken" and the gallery are saved by their own endpoints,
 * so this form can never drop content it does not show.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the nav label, title, lead, body,
 * closing note and CTA label are the words of the language named in
 * `language_code`, which must be an active language of the website registry.
 * Which fields exist, how long they may be and which are required in the
 * default language comes from DetailSectionBlock::translatableFields(),
 * through App\Service\Blocks\BlockLocalization, and only that language is
 * written. The body is rich text, and BlockLocalization sanitizes it through
 * RichTextSanitizer before it reaches the database because the block
 * declares it rich (the Quill toolbar is convenience, never the security
 * boundary). The anchor, the image position, the CTA URL and is_active are
 * the same in every language. The main image's alt text is one of the
 * section's words too, but it belongs to the image form: this form neither
 * shows nor checks it, and the words this language already has for it are
 * saved along unchanged, because BlockLocalization::save() writes a whole
 * language at a time.
 *
 * THE CTA shows only with a label in the default language and a URL
 * (DetailSectionContent). A half-filled CTA is not refused: the editor says
 * that one of the two alone shows no button, as it always did.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;

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

$repository = new DetailSectionRepository();

// Never trust an arbitrary page_slug:section_key pair from the request: the
// page must exist (by its immutable pages.content_key) and so must the
// content row App\Service\SectionRegistry::create() made for it.
if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode($sectionParam);

$sectionId = (int) $section['id'];
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request
// — except the main image's alt text, which is not on this form (see above).
$words = [];
foreach (array_keys(BlockLocalization::fields('detail_sections')) as $field) {
    if ($field !== 'main_image_alt') {
        $words[$field] = trim((string) ($_POST[$field] ?? ''));
    }
}

$imagePosition = (string) ($_POST['image_position'] ?? 'image_right');
if (!in_array($imagePosition, DetailSectionContent::IMAGE_POSITIONS, true)) {
    $imagePosition = 'image_right';
}

$settings = [
    'anchor' => trim((string) ($_POST['anchor'] ?? '')),
    'image_position' => $imagePosition,
    'cta_url' => trim((string) ($_POST['cta_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('detail_sections', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_detail_section_errors'] = $errors;
    $_SESSION['admin_detail_section_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The section's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, $settings);

    // save() writes a whole language: the main image's alt text this
    // language already has goes along unchanged.
    $words['main_image_alt'] = BlockLocalization::raw('detail_sections', $sectionId, 'main_image_alt', $languageCode);
    BlockLocalization::save('detail_sections', $sectionId, $languageCode, $words);

    $db->commit();
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-detail-section.php] ' . $e->getMessage());

    $_SESSION['admin_detail_section_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_detail_section_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
