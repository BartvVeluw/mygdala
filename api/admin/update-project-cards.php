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
 * WHICH PROJECTS (Projecten 2.0) — all visible ones, one category's, or picked
 * by hand, in what order and how many — is read and checked by
 * App\Service\ItemGallerySelection against the gallery's closed lists and the
 * Portfolio's own categories and projects, reached through its gallery source
 * (App\Service\ItemGallerySources): nothing here names a project or a table
 * of the Portfolio. The background is checked against
 * App\Service\ItemGalleryContent, how the cards look (`card_presentation`)
 * against what this block offers (App\Service\Blocks\CardPresentation) and
 * written in the same transaction. The picked projects are the source's own
 * relation, stored through ItemGallerySources::saveSelection(), and only when
 * the picker was on the form (`items_submitted`).
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the title and lead are the words of
 * the language named in `language_code`, which must be an active language of
 * the website registry, and only that language is written, through
 * ProjectCardsBlock::rowWords() and App\Service\Blocks\BlockLocalization, in
 * the same transaction as the settings and the picked projects.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Module\PortfolioModule;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\CardPresentation;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySelection;
use App\Service\ItemGallerySources;
use App\Service\SectionRegistry;
use App\Repository\ItemGalleryRepository;

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
    || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi((string) $pageSlug) === null
    || !\App\Service\Blocks\ContentBlockDrafts::belongsTo('project_cards', (int) $section['id'])
) {
    http_response_code(404);
    exit('Unknown section.');
}

// Which projects, in what order, how many: the choice both gallery editors
// share, checked against the Portfolio's own categories and projects.
$selection = ItemGallerySelection::fromRequest($_POST, PortfolioModule::GALLERY_SOURCE, $section);
[$maxItems, $maxErrors] = ItemGallerySelection::maxItems($_POST);

$fields = $selection['values'] + [
    'max_items' => $maxItems,
    'show_filter_bar' => isset($_POST['show_filter_bar']),
    // Not in the form any more (the background is Extra vormgeving now,
    // admin/_block_appearance.php): a request without it keeps what is stored.
    'background' => array_key_exists('background', $_POST) ? trim((string) $_POST['background']) : (string) ($section['background'] ?? 'default'),
    'is_active' => isset($_POST['is_active']),
];

// How the cards look (Card Presentation 2.0): one of the presentations this
// block offers, the stored one when the form did not send the field, and a
// refused save for any other word. Never a class or a style from the request.
$cardPresentation = CardPresentation::choiceFromRequest($_POST, BlockDefinitions::get('project_cards'), $section['card_presentation'] ?? null);

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

array_push($errors, ...$selection['errors'], ...$maxErrors);

if (!ItemGalleryContent::isBackground($fields['background'])) {
    $errors[] = AdminTranslator::trans('validation.kies_geldige_achtergrond');
}

if ($cardPresentation === null) {
    $errors[] = AdminTranslator::trans('validation.card_presentation_unknown');
}

$old = ['language_code' => $languageCode, 'title' => $words['title'], 'lead' => $words['lead']] + $fields
    + ['card_presentation' => $cardPresentation ?? CardPresentation::stored($section['card_presentation'] ?? null)]
    + ($selection['selected'] !== null ? ['item_ids' => $selection['selected']] : []);

if ($errors !== []) {
    $_SESSION['admin_project_cards_errors'] = $errors;
    $_SESSION['admin_project_cards_old'] = $old;
    header('Location: /admin/project-cards.php?section=' . urlencode($sectionParam));
    exit;
}

$db = Database::connection();

try {
    // The block's settings, its words in this language and its picked
    // projects are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, ProjectCardsBlock::rowValues($fields));
    $repository->saveCardPresentation((int) $section['id'], $cardPresentation);
    BlockLocalization::save('item_galleries', (int) $section['id'], $languageCode, $words);
    if ($selection['selected'] !== null) {
        ItemGallerySources::saveSelection(PortfolioModule::GALLERY_SOURCE, (int) $section['id'], $selection['selected']);
    }

    // A new block joins its page now, in this save's transaction
    // (App\Service\Blocks\ContentBlockDrafts); an existing one is found.
    $placed = \App\Service\Blocks\ContentBlockDrafts::place('project_cards', (int) $section['id']);
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

header('Location: ' . \App\Service\ContentOwners\ContentBlockAccess::afterSaveUrl($placed, '/admin/project-cards.php?section=' . urlencode($sectionParam)));
exit;
