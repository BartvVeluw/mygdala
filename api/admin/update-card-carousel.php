<?php

/**
 * POST /api/admin/update-card-carousel.php
 *
 * Saves the block-level fields of one Kaarten-carrousel
 * (admin/card-carousel.php?section=...): the optional eyebrow/title/lead
 * above the cards, plus visibility. The cards themselves are saved by the
 * card endpoints.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow, title and lead are
 * the words of the language named in `language_code`, which must be an active
 * language of the website registry; which fields exist, how long they may be
 * and that none of them is required comes from
 * CardCarouselBlock::translatableFields(), through
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
use App\Service\CardCarouselContent;
use App\Repository\CardCarouselRepository;
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

$repository = new CardCarouselRepository();

// Never trust an arbitrary page_slug:section_key pair from the request: the
// page must exist (by its immutable pages.content_key) and so must the
// content row App\Service\SectionRegistry::create() made for it.
$carousel = ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null)
    ? null
    : $repository->findBySlugAndKey($pageSlug, $sectionKey);

if ($carousel === null) {
    http_response_code(404);
    exit('Unknown section.');
}

$carouselId = (int) $carousel['id'];
$redirect = '/admin/card-carousel.php?section=' . urlencode($sectionParam);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('card_carousels')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = ['is_active' => isset($_POST['is_active'])];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('card_carousels', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_card_carousel_errors'] = $errors;
    $_SESSION['admin_card_carousel_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The carousel's visibility and its heading in this language are one save.
    $db->beginTransaction();

    $repository->upsertCarousel($pageSlug, $sectionKey, $settings);
    BlockLocalization::save('card_carousels', $carouselId, $languageCode, $words);

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-card-carousel.php] ' . $e->getMessage());

    $_SESSION['admin_card_carousel_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_card_carousel_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
