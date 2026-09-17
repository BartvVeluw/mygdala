<?php

/**
 * POST /api/admin/update-step-list-section.php
 *
 * Saves the section-level fields (heading + visibility) for one step list
 * (admin/step-list.php?section=...). Same guard order and PRG/session-flash
 * pattern as api/admin/update-faq-section.php. Item content is saved
 * separately by create/update/delete/move-step-list-item.php.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow and title are the
 * words of the language named in `language_code`, which must be an active
 * language of the website registry; which fields exist, how long they may be
 * and that both are required in the default language comes from
 * StepListBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written, in
 * one transaction with is_active.
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
use App\Service\StepListContent;
use App\Repository\StepListRepository;

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
$section = StepListContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StepListRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
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
foreach (array_keys(BlockLocalization::fields('step_list_sections')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = ['is_active' => isset($_POST['is_active'])];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('step_list_sections', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_step_list_errors'] = $errors;
    $_SESSION['admin_step_list_old'] = $old;
    header('Location: /admin/step-list.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    // The section's visibility and its heading in this language are one save.
    $db->beginTransaction();

    $repository = new StepListRepository();
    $repository->upsertSection($section['page_slug'], $section['section_key'], $settings);
    $sectionId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];
    BlockLocalization::save('step_list_sections', $sectionId, $languageCode, $words);

    $db->commit();
    StepListContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-step-list-section.php] ' . $e->getMessage());

    $_SESSION['admin_step_list_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_step_list_old'] = $old;
    header('Location: /admin/step-list.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/step-list.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
