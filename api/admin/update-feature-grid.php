<?php

/**
 * POST /api/admin/update-feature-grid.php
 *
 * Saves the section-level fields (heading + visibility) for one Feature
 * grid (admin/feature-grid.php?section=...). Same guard order and
 * PRG/session-flash pattern as api/admin/update-page-hero.php /
 * update-cta-band.php. Card content is saved separately by
 * create/update/delete/move-feature-grid-item.php.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow, title and lead are the
 * words of the language named in `language_code`, which must be an active
 * language of the website registry; which fields exist, how long they may be
 * and that the eyebrow and title are required in the default language comes
 * from FeatureGridBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written, in
 * one transaction with is_active. A grid without a heading of its own
 * (FeatureGridContent::SECTIONS, has_heading) saves is_active only: its form
 * sends no words and no language, and whatever a request sends anyway is
 * never read, so the frontend never gains a heading it has no markup for.
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
use App\Service\FeatureGridContent;
use App\Repository\FeatureGridRepository;

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
$section = FeatureGridContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new FeatureGridRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey, 'has_heading' => true];
}

$hasHeading = $section['has_heading'];

$settings = ['is_active' => isset($_POST['is_active'])];

$languageCode = '';
$words = [];
$errors = [];

// Sections without a heading in the current design (see SECTIONS) never
// expose the heading inputs in the admin form, so their words are not read
// at all: nothing submitted can give the frontend a heading it has no markup
// for.
if ($hasHeading) {
    $languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
    $languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

    // Exactly the fields the block declares, never a name taken from the request.
    foreach (array_keys(BlockLocalization::fields('feature_grids')) as $field) {
        $words[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if (!$languageIsWritable) {
        $errors[] = AdminTranslator::trans('validation.language_unknown');
    } else {
        foreach (BlockLocalization::messageKeys(BlockLocalization::problems('feature_grids', $languageCode, $words)) as $key) {
            $errors[] = AdminTranslator::trans($key);
        }
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_feature_grid_errors'] = $errors;
    $_SESSION['admin_feature_grid_old'] = $old;
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    // The grid's visibility and its heading in this language are one save.
    $db->beginTransaction();

    $repository = new FeatureGridRepository();
    $repository->upsertGrid($section['page_slug'], $section['section_key'], $settings);

    if ($hasHeading) {
        $gridId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];
        BlockLocalization::save('feature_grids', $gridId, $languageCode, $words);
    }

    $db->commit();
    FeatureGridContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-feature-grid.php] ' . $e->getMessage());

    $_SESSION['admin_feature_grid_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_feature_grid_old'] = $old;
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
