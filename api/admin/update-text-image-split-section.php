<?php

/**
 * POST /api/admin/update-text-image-split-section.php
 *
 * Saves the section-level fields (layout, optional eyebrow/title, optional
 * button, visibility) for one Text + image split block
 * (admin/text-image-split.php?section=...). Same guard order and
 * PRG/session-flash pattern as api/admin/update-faq-section.php.
 * Paragraph/image content is saved separately by the paragraph/image
 * endpoints.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow, title and button
 * label are the words of the language named in `language_code`, which must be
 * an active language of the website registry; which fields exist and how
 * long they may be comes from TextImageSplitBlock::translatableFields(),
 * through App\Service\Blocks\BlockLocalization, and only that language is
 * written, in one transaction with the layout, the button URL and
 * is_active.
 *
 * A half-filled button is not refused, as it never was: the editor says that
 * a label without a URL, or a URL without a label, shows no button, and
 * TextImageSplitContent drops it. The label that counts there is the default
 * language's.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

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

$sectionKey = (string) ($_POST['section'] ?? '');
$section = TextImageSplitContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new TextImageSplitRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
}

$layout = (string) ($_POST['layout'] ?? 'image_right');
if (!in_array($layout, ['image_left', 'image_right'], true)) {
    $layout = 'image_right';
}

$settings = [
    'layout' => $layout,
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('text_image_splits')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('text_image_splits', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_tis_errors'] = $errors;
    $_SESSION['admin_tis_old'] = $old;
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    // The section's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository = new TextImageSplitRepository();
    $repository->upsertSection($section['page_slug'], $section['section_key'], $settings);
    $sectionId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];
    BlockLocalization::save('text_image_splits', $sectionId, $languageCode, $words);

    $db->commit();
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-text-image-split-section.php] ' . $e->getMessage());

    $_SESSION['admin_tis_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_tis_old'] = $old;
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
