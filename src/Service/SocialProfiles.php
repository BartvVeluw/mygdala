<?php

namespace App\Service;

/**
 * The site's social profiles: a CLOSED registry of networks, one optional
 * URL per network in App\Service\SiteSettings, and a project-owned icon for
 * each. Rendered by partials/footer.php.
 *
 * Closed on purpose, exactly like App\Service\Theme\ThemeFonts and
 * App\Module\ModuleRegistry. An editor picks from this list and fills in a
 * URL; nobody ever types a network name, an icon class or a piece of SVG.
 * That removes the entire category of "the CMS renders markup somebody typed
 * into a settings field" — the only thing that reaches the page from the
 * database is an href, and even that must survive isValidProfileUrl().
 *
 * NO ENABLED FLAG. A profile is shown when it has a valid URL and hidden
 * when it does not, which is the same rule the rest of this project uses for
 * optional identity (a logo, a second logo, an og:image). One field per
 * network, nothing to keep in sync, and clearing the field is how you remove
 * a profile. With no URLs at all the footer renders no social row and no
 * empty heading.
 *
 * WHICH NETWORKS. Seven, chosen for what this kind of site actually is — a
 * maker/webshop selling engraved goods: the three visual networks where that
 * work is shown (Instagram, Pinterest, TikTok), the two general ones a small
 * business is expected to have (Facebook, LinkedIn), video (YouTube), and
 * the marketplace such a shop is most likely to also sell on (Etsy). X is
 * left out rather than added "for completeness": every entry costs an icon
 * and a settings row, and the list is one line to extend when a site really
 * needs one.
 *
 * THE ICONS are part of this project, in the same Feather-ish stroke style
 * as the admin sidebar's set and the header's chevron: 24x24, currentColor,
 * no fills. They are drawn here rather than vendored so there is no icon
 * font, no third-party stylesheet, no build step and no runtime fetch — the
 * footer must keep working on shared hosting with nothing but PHP. Each link
 * carries its own accessible name, so the glyph never has to carry meaning
 * on its own.
 */
class SocialProfiles
{
    /**
     * key => label, the settings key holding the URL, the domain labels that
     * key accepts, and the icon body.
     *
     * `domains` matches the REGISTRABLE label of the host — the part right
     * before the public suffix — so www.instagram.com, nl.pinterest.com and
     * pinterest.de all pass while instagram.evil.com does not. Matching a
     * name rather than a full domain list is what keeps this from making
     * brittle assumptions about which country domains a network uses.
     *
     * @var array<string, array{label: string, key: string, domains: list<string>, icon: string}>
     */
    private const NETWORKS = [
        'instagram' => [
            'label' => 'Instagram',
            'key' => 'social_instagram_url',
            'domains' => ['instagram', 'instagr'],
            'icon' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/>',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'key' => 'social_facebook_url',
            'domains' => ['facebook', 'fb'],
            'icon' => '<path d="M18 3h-3a5 5 0 0 0-5 5v3H7v4h3v6h4v-6h3l1-4h-4V8a1 1 0 0 1 1-1h3z"/>',
        ],
        'pinterest' => [
            'label' => 'Pinterest',
            'key' => 'social_pinterest_url',
            'domains' => ['pinterest', 'pin'],
            'icon' => '<circle cx="12" cy="12" r="9"/><path d="M8.8 14.2A4.6 4.6 0 0 1 8.2 12c0-2.6 2-4.7 5-4.7 2.7 0 4.4 1.7 4.4 4 0 2.7-1.4 4.7-3.4 4.7-1 0-1.7-.8-1.5-1.8"/><path d="M12.7 14.2 11.3 20"/>',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'key' => 'social_linkedin_url',
            'domains' => ['linkedin', 'linked'],
            'icon' => '<path d="M16 9a5 5 0 0 1 5 5v6h-3.5v-6a1.5 1.5 0 0 0-3 0v6H11v-6a5 5 0 0 1 5-5z"/><path d="M4 10h3.5v10H4z"/><circle cx="5.75" cy="5.5" r="1.75"/>',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'key' => 'social_youtube_url',
            'domains' => ['youtube', 'youtu'],
            'icon' => '<rect x="2.5" y="6" width="19" height="12" rx="3.5"/><path d="M10.5 9.5 15 12l-4.5 2.5z"/>',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'key' => 'social_tiktok_url',
            'domains' => ['tiktok'],
            'icon' => '<path d="M14 4v10.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 4c.4 2.6 2.3 4.4 4.8 4.6"/>',
        ],
        'etsy' => [
            'label' => 'Etsy',
            'key' => 'social_etsy_url',
            'domains' => ['etsy'],
            'icon' => '<path d="M16.5 5H9v14h7.5"/><path d="M9 12h5"/><path d="M16.5 5v2.5M16.5 19v-2.5"/>',
        ],
    ];

    /**
     * Every supported network, for the admin screen: key, label and the
     * settings key its URL lives in. Never the icon — nothing outside this
     * class and partials/footer.php has any business with markup.
     *
     * @return array<string, array{label: string, key: string}>
     */
    public static function networks(): array
    {
        $networks = [];
        foreach (self::NETWORKS as $network => $definition) {
            $networks[$network] = ['label' => $definition['label'], 'key' => $definition['key']];
        }

        return $networks;
    }

    /** The site_settings keys this class owns, in registry order. @return list<string> */
    public static function settingKeys(): array
    {
        return array_map(static fn (array $d): string => $d['key'], array_values(self::NETWORKS));
    }

    public static function isKnownNetwork(string $network): bool
    {
        return array_key_exists($network, self::NETWORKS);
    }

    public static function label(string $network): ?string
    {
        return self::NETWORKS[$network]['label'] ?? null;
    }

    /**
     * The profiles the footer should render, in registry order. Empty when
     * nothing is configured — and an empty list means the footer draws no
     * social row at all rather than an empty one.
     *
     * A stored value that does not validate is skipped rather than rendered,
     * the same "a broken row behaves like an absent row" rule as
     * App\Service\LinkResolver. It cannot normally happen (the admin
     * endpoint rejects it at write time) but a settings table is editable by
     * other means, and this is the last gate before an href reaches a page.
     *
     * @return list<array{network: string, label: string, url: string, icon: string}>
     */
    public static function forFooter(): array
    {
        $profiles = [];

        foreach (self::NETWORKS as $network => $definition) {
            $url = trim(SiteSettings::get($definition['key']));

            if ($url === '' || !self::isValidProfileUrl($network, $url)) {
                continue;
            }

            $profiles[] = [
                'network' => $network,
                'label' => $definition['label'],
                'url' => $url,
                'icon' => $definition['icon'],
            ];
        }

        return $profiles;
    }

    /**
     * Strict enough to keep obvious rubbish out, loose enough not to guess
     * at a network's country domains:
     *
     *   - the network key must be one of ours;
     *   - https only. Every one of these platforms serves https, so there is
     *     no reason to accept the scheme that can be downgraded — and it
     *     rules out javascript:, data:, and a relative path in one line;
     *   - a parseable absolute URL with a host and no credentials in it;
     *   - the host's registrable label must be the network's own name.
     *
     * The last rule is what stops a "Facebook" button that quietly points
     * somewhere else. It is deliberately a name match, not a domain list:
     * pinterest.nl, nl.pinterest.com and www.pinterest.de all pass, while
     * facebook.example.com does not.
     */
    public static function isValidProfileUrl(string $network, string $url): bool
    {
        $definition = self::NETWORKS[$network] ?? null;
        if ($definition === null) {
            return false;
        }

        $url = trim($url);

        if (!str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return false;
        }

        // "https://facebook.com@evil.example" parses with host evil.example,
        // but a profile URL never needs credentials at all — refuse them
        // rather than reason about what the browser will do with them.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $labels = explode('.', strtolower(trim($parts['host'], '.')));
        if (count($labels) < 2) {
            return false;
        }

        $registrable = $labels[count($labels) - 2];

        return in_array($registrable, $definition['domains'], true);
    }
}
