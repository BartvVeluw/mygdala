<?php

/**
 * POST /api/admin/update-project-cards.php
 *
 * Saves one "Projecten" block (admin/project-cards.php?section=<page>:<key>),
 * App\Service\Blocks\ProjectCardsBlock. Same guard order, the same
 * PRG/session-flash pattern and the same "the page AND its content row must
 * already exist" gate as api/admin/update-item-gallery.php, whose row this
 * block shares.
 *
 * Two things that endpoint does not have to do:
 *
 *  - The source never comes from the request. ProjectCardsBlock::rowValues()
 *    writes the Portfolio's source together with every gallery setting this
 *    block does not offer, so a crafted POST cannot turn a Projecten block into
 *    a collection gallery or give it a zoom, a fallback link or a button.
 *  - The row must have been placed by THIS block (page_sections.section_type)
 *    and the block must be registered, which it only is while the Portfolio
 *    runs. A gallery's row is update-item-gallery.php's, and a switched-off
 *    module's block keeps its settings untouched until the module is back.
 *
 * Which projects and the background are checked against the gallery's own
 * closed lists (App\Service\ItemGalleryContent) before anything is stored.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the title and lead are the words of
 * the language named in `language_code`, which must be an active language of
 * the website registry, and only that language is written, through
 * ProjectCardsBlock::rowWords() and App\Service\Blocks\BlockLocalization, in
 * the same transaction as the settings.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\ItemGalleryContent;
use App\Service\SectionRegistry;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;

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

if (!SectionRegistry::exists('project_cards')) {
    http_response_code(404);
    exit('Unknown section.');
}

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ItemGalleryRepository();

$section = ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === '')
    ? null
    : $repository->findBySlugAndKey($pageSlug, $sectionKey);

if ($section === null
    || (new PageRepository())->findByContentKey((string) $pageSlug) === null
    || (new PageSectionRepository())->findBySectionTypeAndId('project_cards', (int) $section['id']) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$rawMaxItems = trim((string) ($_POST['max_items'] ?? ''));

$fields = [
    'portfolio_scope' => trim((string) ($_POST['portfolio_scope'] ?? '')),
    'max_items' => $rawMaxItems === '' ? null : (int) $rawMaxItems,
    'show_filter_bar' => isset($_POST['show_filter_bar']),
    'background' => trim((string) ($_POST['background'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Only the two words this block's editor offers; rowWords() empties the rest.
$words = ProjectCardsBlock::rowWords([
    'title' => trim((string) ($_POST['title'] ?? '')),
    'lead' => trim((string) ($_POST['lead'] ?? '')),
]);

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('item_galleries', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if (!ItemGalleryContent::isPortfolioScope($fields['portfolio_scope'])) {
    $errors[] = AdminTranslator::trans('validation.kies_welke_projecten');
}

if (!ItemGalleryContent::isBackground($fields['background'])) {
    $errors[] = AdminTranslator::trans('validation.kies_geldige_achtergrond');
}

if ($fields['max_items'] !== null && ($fields['max_items'] < 1 || $fields['max_items'] > 200)) {
    $errors[] = AdminTranslator::trans('validation.maximum_aantal_projecten');
}

$old = ['language_code' => $languageCode, 'title' => $words['title'], 'lead' => $words['lead']] + $fields;

if ($errors !== []) {
    $_SESSION['admin_project_cards_errors'] = $errors;
    $_SESSION['admin_project_cards_old'] = $old;
    header('Location: /admin/project-cards.php?section=' . urlencode($sectionParam));
    exit;
}

$db = Database::connection();

try {
    // The block's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, ProjectCardsBlock::rowValues($fields));
    BlockLocalization::save('item_galleries', (int) $section['id'], $languageCode, $words);

    $db->commit();
    ItemGalleryContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-project-cards.php] ' . $e->getMessage());

    $_SESSION['admin_project_cards_errors'] = [AdminTranslator::trans('validation.projecten_niet_opgeslagen')];
    $_SESSION['admin_project_cards_old'] = $old;
    header('Location: /admin/project-cards.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: /admin/project-cards.php?section=' . urlencode($sectionParam) . '&saved=1');
exit;
