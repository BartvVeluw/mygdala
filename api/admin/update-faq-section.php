<?php

/**
 * POST /api/admin/update-faq-section.php
 *
 * Saves the section-level fields (heading + visibility) for one FAQ list
 * (admin/faq.php?section=...). Same guard order and PRG/session-flash
 * pattern as api/admin/update-feature-grid.php. Item content is saved
 * separately by create/update/delete/move-faq-item.php.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow and title are the
 * words of the language named in `language_code`, which must be an active
 * language of the website registry; which fields exist, how long they may be
 * and that both are required in the default language comes from
 * FaqBlock::translatableFields(), through App\Service\Blocks\BlockLocalization,
 * and only that language is written, in one transaction with is_active.
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
use App\Service\FaqContent;
use App\Repository\FaqRepository;

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
$section = FaqContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new FaqRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
}

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('faq_sections')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = ['is_active' => isset($_POST['is_active'])];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('faq_sections', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_faq_errors'] = $errors;
    $_SESSION['admin_faq_old'] = $old;
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    // The section's visibility and its heading in this language are one save.
    $db->beginTransaction();

    $repository = new FaqRepository();
    $repository->upsertSection($section['page_slug'], $section['section_key'], $settings);
    $sectionId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];
    BlockLocalization::save('faq_sections', $sectionId, $languageCode, $words);

    $db->commit();
    FaqContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-faq-section.php] ' . $e->getMessage());

    $_SESSION['admin_faq_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_faq_old'] = $old;
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/faq.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
