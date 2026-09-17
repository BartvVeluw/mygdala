<?php

declare(strict_types=1);

/**
 * Shared input rules for api/admin/create-footer-link.php and
 * api/admin/update-footer-link.php, so a footer link is refused for exactly
 * the same reasons whichever endpoint saves it. The footer's counterpart of
 * api/admin/_nav_item_input.php: read that file's docblock first, the rules
 * are the same wherever a footer link and a menu item share a field.
 *
 *   - the label, in ONE website language, stored through
 *     App\Service\FooterLocalization: a new link in the default language,
 *     an update in the active language named by `language_code`; required
 *     only in the default language;
 *   - the destination: App\Service\LinkResolver::validate() with the footer's
 *     kinds, which add one controlled action (open the cookie settings) to a
 *     page, a fixed part of the site and another address;
 *   - the column: only on create, from the request, and it must exist. An
 *     update never moves a link to another column.
 *
 * KEEPING A DESTINATION THAT IS UNAVAILABLE RIGHT NOW. A route of a
 * switched-off module is not in App\Service\RouteRegistry, so validate()
 * refuses it — correct for a NEW choice, wrong for a link that already
 * points there. Before Footer phase B the footer link editor offered only the
 * routes that existed, so it silently selected the first one instead, and
 * saving a typo fix in the label moved the link somewhere else. An update
 * that leaves such a route exactly as it was is accepted as it stands, so
 * switching the module back on brings the link back (HEADER-FOOTER.md,
 * "Verdwijnen is niet vergeten").
 *
 * SQL stays in the repositories; this file only asks them.
 */

use App\Repository\FooterRepository;
use App\Repository\PageRepository;
use App\Service\FooterLocalization;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteLanguages;
use App\Service\LinkResolver;

/**
 * @param array<string, mixed>      $input    raw $_POST
 * @param array<string, mixed>|null $existing the stored row on update, null on create
 * @return array{0: list<string>, 1: array<string, mixed>, 2: array<string, mixed>} [errors, data for the repository plus `label` and `language_code`, old input for the form]
 */
function validateFooterLinkInput(
    array $input,
    ?array $existing,
    PageRepository $pageRepository
): array {
    $text = static fn (string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';

    $label = $text('label');
    $languageCode = $existing === null
        ? LanguageFallback::defaultLanguage()
        : (LanguageCode::normalise($text('language_code')) ?? '');
    $linkType = $text('link_type');
    $targetPageIdRaw = $text('target_page_id');
    $targetPageId = ctype_digit($targetPageIdRaw) ? (int) $targetPageIdRaw : null;
    $targetRoute = $text('target_route') !== '' ? $text('target_route') : null;
    $externalUrl = $text('external_url') !== '' ? $text('external_url') : null;
    $actionKey = $text('action_key') !== '' ? $text('action_key') : null;
    $openInNewTab = isset($input['open_in_new_tab']);
    $isVisible = isset($input['is_visible']);

    $errors = [];

    if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
        $errors[] = AdminTranslator::trans('validation.language_unknown');
    } else {
        $problems = FooterLocalization::links()->problems($languageCode, [FooterLocalization::LABEL => $label], [FooterLocalization::LABEL]);
        if (($problems[FooterLocalization::LABEL] ?? null) === 'missing') {
            $errors[] = AdminTranslator::trans('validation.label_verplicht');
        }
        if (($problems[FooterLocalization::LABEL] ?? null) === 'too_long') {
            $errors[] = AdminTranslator::trans('validation.label_mag_maximaal_100_tekens');
        }
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
            $actionKey,
            LinkResolver::LINK_TYPES_FOOTER,
            $pageRepository
        );
        if ($linkError !== null) {
            $errors[] = $linkError;
        }
    }

    $data = [
        'label' => $label,
        'language_code' => $languageCode,
        'link_type' => $linkType,
        // Only the companion field of the chosen kind is stored, so a row can
        // never carry a leftover target of a kind nobody selected any more.
        'target_page_id' => $linkType === 'page' ? $targetPageId : null,
        'target_route' => $linkType === 'route' ? $targetRoute : null,
        'external_url' => $linkType === 'external' ? $externalUrl : null,
        'action_key' => $linkType === 'action' ? $actionKey : null,
        // An action opens something on this page; a new tab means nothing there.
        'open_in_new_tab' => $linkType !== 'action' && $openInNewTab,
        'is_visible' => $isVisible,
    ];

    $old = [
        'language_code' => $languageCode,
        'label' => $label,
        'link_type' => $linkType,
        'target_page_id' => $targetPageIdRaw,
        'target_route' => $targetRoute,
        'external_url' => $externalUrl,
        'action_key' => $actionKey,
        'open_in_new_tab' => $openInNewTab,
        'is_visible' => $isVisible,
    ];

    return [$errors, $data, $old];
}

/**
 * The column a NEW link goes into, read from the request and looked up; null
 * when it does not exist.
 *
 * @param array<string, mixed> $input raw $_POST
 * @return array<string, mixed>|null
 */
function footerLinkTargetColumn(array $input, FooterRepository $repository): ?array
{
    $raw = is_string($input['column_id'] ?? null) ? trim($input['column_id']) : '';

    if (!ctype_digit($raw) || (int) $raw < 1) {
        return null;
    }

    return $repository->findColumnById((int) $raw);
}
