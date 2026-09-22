<?php

/**
 * POST /api/admin/update-card-carousel.php
 *
 * Saves the WHOLE editor of one Kaarten-carrousel
 * (admin/card-carousel.php?section=...) in one request: the optional
 * eyebrow/title/lead above the cards, whether the carousel is shown, its
 * layout on larger screens, and its cards — their order, which are shown, and
 * which the editor marked for removal. One form, one save
 * (PAGE-EDITOR.md, "Eén formulier per blok-editor").
 *
 * `editor_action` names what else the pressed button asks for, AFTER all of
 * the above is stored (App\Service\Blocks\EditorRows):
 *
 *   cards:up:<id> / cards:down:<id>   move a card (the no-JavaScript path;
 *                                     with JavaScript the order arrives as
 *                                     the order of the posted cards)
 *   cards:edit:<id>                   go on to that card's own screen
 *   cards:add                         append a new card and go to it
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing, hands
 * every typed value back, and the screen shows it again as unsaved.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow, title and lead are
 * the words of the language named in `language_code`, which must be an active
 * language of the website registry; which fields exist, how long they may be
 * and that none of them is required comes from
 * CardCarouselBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization. Only that language is written. A new
 * card gets its title in the default language, the language that decides
 * whether a card exists at all. Removing a card removes its words and its
 * tags' words in every language, in the same transaction.
 *
 * A NEW CARD IS A DRAFT: it is created switched off, so nothing half-filled
 * appears on the website until an editor switches it on. It starts with the
 * next free number ("03") as its label, which is ordinary, editable content
 * from then on.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorRows;
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
$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('card_carousels')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$isActive = isset($_POST['is_active']);
$layout = (string) ($_POST['desktop_layout'] ?? '');
$action = EditorRows::parseAction($_POST['editor_action'] ?? null);
$newCardTitle = trim((string) ($_POST['new_card_title'] ?? ''));

// The cards of THIS carousel, in the order they were posted in; a key naming
// any other row is dropped here.
$storedCards = [];
foreach ($repository->findCardsByCarouselId($carouselId) as $card) {
    $storedCards[(int) $card['id']] = $card;
}

$cardsPosted = isset($_POST['cards_present']);
$rows = array_values(array_filter(
    EditorRows::fromPost($_POST['cards'] ?? []),
    static fn (array $row): bool => isset($storedCards[$row['id']])
));
$rows = EditorRows::apply($rows, $action, 'cards');

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('card_carousels', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if (!in_array($layout, CardCarouselContent::LAYOUTS, true)) {
    $errors[] = AdminTranslator::trans('block_carousel.error_layout');
}

if (mb_strlen($newCardTitle) > 255) {
    $errors[] = AdminTranslator::trans('validation.text_too_long');
}

if ($action !== null && $action['list'] === 'cards' && $action['verb'] === 'edit') {
    $editId = EditorRows::idOf($action['key']);
    $editIsRemoved = false;
    foreach ($rows as $row) {
        if ($row['id'] === $editId && ($row['fields']['remove'] ?? '') !== '') {
            $editIsRemoved = true;
        }
    }
    if (!isset($storedCards[$editId]) || $editIsRemoved) {
        $errors[] = AdminTranslator::trans('block_carousel.error_edit_removed');
    }
}

// Handed back exactly as typed, in the order it was on screen.
$old = ['language_code' => $languageCode] + $words + [
    'is_active' => $isActive,
    'desktop_layout' => $layout,
    'new_card_title' => $newCardTitle,
    'cards' => [],
];
foreach ($rows as $row) {
    $old['cards'][$row['id']] = [
        'active' => ($row['fields']['active'] ?? '') !== '',
        'remove' => ($row['fields']['remove'] ?? '') !== '',
    ];
}

if ($errors !== []) {
    $_SESSION['admin_card_carousel_errors'] = $errors;
    $_SESSION['admin_card_carousel_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();
$newCardId = null;

try {
    $db->beginTransaction();

    $repository->updateSettings($carouselId, $isActive, $layout);
    BlockLocalization::save('card_carousels', $carouselId, $languageCode, $words);

    if ($cardsPosted) {
        $kept = [];
        foreach ($rows as $row) {
            if (($row['fields']['remove'] ?? '') !== '') {
                // Its words and its tags' words first: once the card row is
                // gone, ON DELETE CASCADE leaves nothing to find them by.
                BlockLocalization::deleteOwner('carousel_cards', $row['id']);
                $repository->deleteCard($row['id']);
                continue;
            }

            $repository->setCardActive($row['id'], ($row['fields']['active'] ?? '') !== '');
            $kept[] = $row['id'];
        }

        // A card that was not on the screen at all (added in another tab
        // meanwhile) keeps its place after the ones that were.
        foreach (array_keys($storedCards) as $id) {
            if (!in_array($id, $kept, true) && !in_array($id, array_column($rows, 'id'), true)) {
                $kept[] = $id;
            }
        }

        $repository->reorderCards($carouselId, $kept);
    }

    if ($action !== null && $action['list'] === 'cards' && $action['verb'] === 'add') {
        $newCardId = $repository->createCard($carouselId);
        $visibleCount = count($repository->findCardsByCarouselId($carouselId)) - 1;
        BlockLocalization::save('carousel_cards', $newCardId, $defaultLanguage, [
            'title' => $newCardTitle !== '' ? $newCardTitle : AdminTranslator::trans('block_carousel.nieuwe_kaart_titel'),
            'number_label' => CardCarouselContent::positionLabel($visibleCount),
        ]);
    }

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-card-carousel.php] ' . $e->getMessage());

    $_SESSION['admin_card_carousel_errors'] = [AdminTranslator::trans('block_carousel.error_save_failed')];
    $_SESSION['admin_card_carousel_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

if ($newCardId !== null) {
    header('Location: /admin/carousel-card.php?card_id=' . $newCardId . '&created=1');
    exit;
}

if ($action !== null && $action['list'] === 'cards' && $action['verb'] === 'edit') {
    header('Location: /admin/carousel-card.php?card_id=' . EditorRows::idOf($action['key']));
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
