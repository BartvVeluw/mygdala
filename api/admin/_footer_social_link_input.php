<?php

declare(strict_types=1);

/**
 * Shared input rules for api/admin/create-footer-social-link.php and
 * api/admin/update-footer-social-link.php, so a social profile is refused for
 * exactly the same reasons whichever endpoint saves it — same arrangement as
 * api/admin/_nav_item_input.php.
 *
 *   - the network must be a key of the closed registry
 *     App\Service\SocialProfiles: a request can never introduce a network,
 *     a label or an icon;
 *   - the address must pass SocialProfiles::isValidProfileUrl(), the very
 *     check the footer applies before it renders a row. There is no second
 *     rule here to drift from it;
 *   - visibility is a switch; a new profile is visible.
 *
 * The messages say what to do, in the editor's words, and name the network
 * rather than a field name (CODE-STYLE.md). The address is stored trimmed and
 * otherwise exactly as typed.
 *
 * SQL stays in App\Repository\FooterSocialLinkRepository.
 */

use App\Service\Language\AdminTranslator;
use App\Service\SocialProfiles;

/**
 * @param array<string, mixed> $input  raw $_POST
 * @param bool                 $isNew  a new profile is always visible
 * @return array{0: list<string>, 1: array{network: string, url: string, is_visible: bool}, 2: array{network: string, url: string, is_visible: bool}} [errors, data for the repository, old input for the form]
 */
function validateFooterSocialLinkInput(array $input, bool $isNew): array
{
    $network = is_string($input['network'] ?? null) ? trim($input['network']) : '';
    $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';
    $isVisible = $isNew || isset($input['is_visible']);

    $errors = [];

    if (!SocialProfiles::isKnownNetwork($network)) {
        $errors[] = AdminTranslator::trans('footer.social_error_network');
    } elseif ($url === '') {
        $errors[] = AdminTranslator::trans('footer.social_error_url_missing', ['network' => (string) SocialProfiles::label($network)]);
    } elseif (mb_strlen($url) > SocialProfiles::MAX_URL_LENGTH) {
        $errors[] = AdminTranslator::trans('footer.social_error_url_too_long');
    } elseif (!SocialProfiles::isValidProfileUrl($network, $url)) {
        $errors[] = AdminTranslator::trans('footer.social_error_url_invalid', ['network' => (string) SocialProfiles::label($network)]);
    }

    $data = ['network' => $network, 'url' => $url, 'is_visible' => $isVisible];

    return [$errors, $data, $data];
}
