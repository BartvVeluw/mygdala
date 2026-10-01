<?php

declare(strict_types=1);

/**
 * Shared input rules for api/admin/create-nav-item.php and
 * api/admin/update-nav-item.php, so a menu link, a submenu item and a header
 * button are refused for exactly the same reasons whichever endpoint saves
 * them — same arrangement as api/admin/_collection_validation.php.
 *
 * What is decided here, and what is not:
 *
 *   - the label, in ONE website language (Multilingual 2.0 phase 4): a new
 *     item is written in the default language, an update in the language
 *     named by `language_code`, which must be an active website language.
 *     The label is required only in the default language, where every other
 *     language falls back to. It is stored through
 *     App\Service\NavigationLocalization, never in nav_items;
 *   - "Gebruik titel van bestemming" (`label_follows`, Pages & Destinations
 *     3.0): for a PAGE link only, the item keeps no words at all and shows its
 *     page's title in every language (NavigationLocalization::labelFor()), so
 *     no label is required and the endpoint clears every stored one. Any other
 *     kind needs words of its own, whatever the switch said. Switching it off
 *     from another language than the default is refused until the default
 *     language has a text, since that language decides whether an item has
 *     words (MULTILINGUAL.md);
 *   - the destination: App\Service\LinkResolver::validate(), the one check
 *     every link in the header and footer goes through;
 *   - the presentation and button variant: App\Service\NavigationPresentation,
 *     a closed list, never a class name from the request;
 *   - the parent ("Bovenliggend item"): on create a menu link on level 1 or
 *     2, so the new item lands on level 3 at most
 *     (NavigationRepository::canBeParent(), MAX_DEPTH). On update the same
 *     list may move the item: App\Service\NavigationTree::placementError()
 *     refuses the item itself, anything below it, a header button, an
 *     unknown id and a place where its own submenu would pass the deepest
 *     level — the rules a drag on the overview meets too. A form without
 *     the field keeps the stored parent. The move itself is
 *     NavigationRepository::place(), in the endpoint's transaction; a new
 *     parent puts the item LAST in its new list (HEADER-FOOTER.md,
 *     "Verplaatsen").
 *
 * KEEPING A DESTINATION THAT IS UNAVAILABLE RIGHT NOW. A route of a
 * switched-off module is not in App\Service\RouteRegistry, so validate()
 * refuses it — correct for a NEW choice, wrong for an item that already
 * points there: the editor could not even fix a typo in its label without
 * losing the target, and switching the module back on would no longer bring
 * the link back. An update that leaves such a route exactly as it was is
 * therefore accepted as it stands (HEADER-FOOTER.md, "Verdwijnen is niet
 * vergeten").
 *
 * SQL stays in the repository; this file only reads it through two
 * repository methods.
 */

use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteLanguages;
use App\Service\LinkResolver;
use App\Service\NavigationLocalization;
use App\Service\NavigationPresentation;

/**
 * @param array<string, mixed>      $input    raw $_POST
 * @param array<string, mixed>|null $existing the stored row on update, null on create
 * @return array{0: list<string>, 1: array<string, mixed>, 2: array<string, mixed>} [errors, data for the repository plus `label` and `language_code`, old input for the form]
 */
function validateNavItemInput(
    array $input,
    ?array $existing,
    NavigationRepository $repository,
    PageRepository $pageRepository
): array {
    $text = static fn (string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';

    $label = $text('label');
    $defaultLanguage = LanguageFallback::defaultLanguage();
    $languageCode = $existing === null
        ? $defaultLanguage
        : (LanguageCode::normalise($text('language_code')) ?? '');
    $languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
    $linkType = $text('link_type');
    $targetPageIdRaw = $text('target_page_id');
    $targetPageId = ctype_digit($targetPageIdRaw) ? (int) $targetPageIdRaw : null;
    $targetRoute = $text('target_route') !== '' ? $text('target_route') : null;
    $externalUrl = $text('external_url') !== '' ? $text('external_url') : null;
    $openInNewTab = isset($input['open_in_new_tab']);
    $isVisible = isset($input['is_visible']);

    // "Geen (hoofdniveau)" posts 0 or nothing; anything that is not a
    // whole number is no parent anybody could have chosen.
    $parentIdRaw = $text('parent_id');
    $parentIdIsValid = $parentIdRaw === '' || ctype_digit($parentIdRaw);
    $postedParentId = ctype_digit($parentIdRaw) && (int) $parentIdRaw > 0 ? (int) $parentIdRaw : null;

    if ($existing === null) {
        $parentId = $postedParentId;
        $storedParentId = null;
        $childCount = 0;
    } else {
        $storedParentId = $existing['parent_id'] === null ? null : (int) $existing['parent_id'];
        $parentId = array_key_exists('parent_id', $input) ? $postedParentId : $storedParentId;
        $childCount = $repository->countChildren((int) $existing['id']);
    }

    // A form without the field keeps what is stored (or a plain link for a
    // new item): the submenu editor has no presentation choice at all.
    $presentation = $text('presentation') !== ''
        ? $text('presentation')
        : ($existing !== null ? NavigationPresentation::of($existing) : NavigationPresentation::LINK);
    $variant = $text('button_variant') !== ''
        ? $text('button_variant')
        : ($existing !== null ? NavigationPresentation::variantOf($existing) : NavigationPresentation::VARIANT_PRIMARY);

    // Only a page has a title to follow; the switch means nothing for any
    // other kind of destination.
    $labelFollows = ($input['label_follows'] ?? '') === '1' && $linkType === 'page';

    $errors = [];

    if (!$languageIsWritable) {
        $errors[] = AdminTranslator::trans('validation.language_unknown');
    } elseif (!$labelFollows) {
        $problems = NavigationLocalization::items()->problems($languageCode, [NavigationLocalization::LABEL => $label], [NavigationLocalization::LABEL]);
        if (($problems[NavigationLocalization::LABEL] ?? null) === 'missing') {
            $errors[] = AdminTranslator::trans('validation.label_verplicht');
        }
        if (($problems[NavigationLocalization::LABEL] ?? null) === 'too_long') {
            $errors[] = AdminTranslator::trans('validation.label_mag_maximaal_100_tekens');
        }

        // An item that follows its page has no words in the default
        // language; words of its own start there.
        if ($existing !== null
            && $languageCode !== $defaultLanguage
            && !NavigationLocalization::hasDefaultLabel((int) $existing['id'])) {
            $errors[] = AdminTranslator::trans('validation.nav_label_default_first');
        }
    }

    if ($parentId !== null) {
        // A submenu item's own link may never be 'none' (a dropdown heading
        // only makes sense as a top-level item), and the depth is capped at
        // NavigationRepository::MAX_DEPTH — the parent must be a menu link
        // on a level above the deepest one.
        if ($linkType === 'none') {
            $errors[] = AdminTranslator::trans('validation.submenu_item_eigen_link_hebben');
        }
        if ($existing === null && !$repository->canBeParent($parentId)) {
            $errors[] = AdminTranslator::trans('validation.ongeldig_hoofditem_navigatie_ondersteunt_maximaa');
        }
    }

    if (!$parentIdIsValid) {
        $errors[] = AdminTranslator::trans('validation.nav_place_parent_unknown');
    } elseif ($existing !== null && $parentId !== $storedParentId && !NavigationPresentation::isButton($existing)) {
        // The kind of destination is judged above on what the form says, so
        // the stored kind does not get a second say here.
        $placementError = $repository->tree()->placementError((int) $existing['id'], $parentId);
        if ($placementError !== null && $placementError !== 'validation.submenu_item_eigen_link_hebben') {
            $errors[] = AdminTranslator::trans($placementError);
        }
    }

    foreach (NavigationPresentation::errors($presentation, $variant, $parentId, $linkType, $childCount) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }

    $keepsUnavailableRoute = $existing !== null
        && $linkType === 'route'
        && (string) $existing['link_type'] === 'route'
        && $targetRoute !== null
        && $targetRoute === (string) ($existing['target_route'] ?? '');

    if (!$keepsUnavailableRoute) {
        $linkError = LinkResolver::validate(
            $linkType,
            $targetPageId,
            $targetRoute,
            $externalUrl,
            null,
            LinkResolver::LINK_TYPES_NAV,
            $pageRepository
        );
        if ($linkError !== null) {
            $errors[] = $linkError;
        }
    }

    $isButton = $presentation === NavigationPresentation::BUTTON;

    $data = [
        'label' => $label,
        'label_follows' => $labelFollows,
        'language_code' => $languageCode,
        'link_type' => $linkType,
        // Only the companion field of the chosen kind is stored, so a row can
        // never carry a leftover target of a kind nobody selected any more.
        'target_page_id' => $linkType === 'page' ? $targetPageId : null,
        'target_route' => $linkType === 'route' ? $targetRoute : null,
        'external_url' => $linkType === 'external' ? $externalUrl : null,
        'open_in_new_tab' => $openInNewTab,
        'is_visible' => $isVisible,
        'presentation' => $isButton ? NavigationPresentation::BUTTON : NavigationPresentation::LINK,
        'button_variant' => in_array($variant, NavigationPresentation::variants(), true) ? $variant : NavigationPresentation::VARIANT_PRIMARY,
        'parent_id' => $parentId,
        'parent_changed' => $existing !== null && $parentId !== $storedParentId,
    ];

    $old = [
        'language_code' => $languageCode,
        'label' => $label,
        'label_follows' => ($input['label_follows'] ?? '') === '1',
        'link_type' => $linkType,
        'target_page_id' => $targetPageIdRaw,
        'target_route' => $targetRoute,
        'external_url' => $externalUrl,
        'open_in_new_tab' => $openInNewTab,
        'is_visible' => $isVisible,
        'presentation' => $presentation,
        'button_variant' => $variant,
        'parent_id' => $parentIdRaw,
    ];

    return [$errors, $data, $old];
}
