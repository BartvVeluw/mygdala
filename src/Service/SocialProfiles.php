<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FooterSocialLinkRepository;

/**
 * The site's social profiles: a CLOSED registry of networks, the one check an
 * address has to pass to be shown as one of them, and what the footer renders
 * from the repeatable rows in `footer_social_links` (Footer phase B,
 * HEADER-FOOTER.md). Rendered by partials/footer.php; App\Service\PageSeo
 * claims the same profiles as `sameAs`.
 *
 * Closed on purpose, exactly like App\Service\Theme\ThemeFonts and
 * App\Module\ModuleRegistry. An editor picks a network from this list and
 * fills in an address; nobody ever types a network name, an icon class or a
 * piece of SVG. That removes the entire category of "the CMS renders markup
 * somebody typed into a field" — the only thing that reaches the page from
 * the database is an href, and even that must survive isValidProfileUrl().
 *
 * ONE CHECK FOR THE SCREEN AND THE SITE. The Footer screen's endpoints
 * (api/admin/_footer_social_link_input.php) refuse an address with
 * isValidProfileUrl(), and forFooter() skips a stored row that fails the very
 * same method. There is no second copy of the rule to drift from.
 *
 * WHAT IS NOT HERE ANY MORE. Until Footer phase B each network had one
 * `social_<network>_url` setting and this class read those. Migration
 * 20260917100000 copied them into `footer_social_links`; the settings rows
 * stay in the database, and nothing reads or writes them.
 *
 * WHICH NETWORKS. Seven, chosen for what this kind of site actually is — a
 * maker/webshop selling engraved goods: the three visual networks where that
 * work is shown (Instagram, Pinterest, TikTok), the two general ones a small
 * business is expected to have (Facebook, LinkedIn), video (YouTube), and
 * the marketplace such a shop is most likely to also sell on (Etsy). X is
 * left out rather than added "for completeness": every entry costs an icon,
 * and the list is one line to extend when a site really needs one.
 *
 * THE ICONS are part of this project, in the same Feather-ish stroke style
 * as the admin sidebar's set and the header's chevron: 24x24, currentColor,
 * no fills. They are drawn here rather than vendored so there is no icon
 * font, no third-party stylesheet, no build step and no runtime fetch — the
 * footer must keep working on shared hosting with nothing but PHP. Each link
 * carries its own accessible name, so the glyph never has to carry meaning
 * on its own.
 */
final class SocialProfiles
{
    /** The longest address accepted, and the width of footer_social_links.url. */
    public const MAX_URL_LENGTH = 2048;

    /**
     * key => label, the hosts that belong to the network, and the icon body.
     *
     * `brands` are the network's own name as a domain: accepted on `.com`, on
     * any two-letter country domain, and on the `co.`/`com.` form of one — so
     * www.instagram.com, nl.pinterest.com, pinterest.de, pinterest.co.uk and
     * pinterest.com.au all pass, while pinterest.evil.example,
     * facebook.xyz and instagram-login.com do not.
     *
     * `domains` are the network's own short domains, which carry a different
     * name (fb.com, youtu.be): accepted as they are, with any subdomain.
     *
     * Until Footer phase B this matched only the label right before the LAST
     * dot, with a few short names mixed in. That refused every
     * `name.co.uk`-style country domain (the label there is "co") and
     * accepted any domain whose label happened to be a short name — pin.nl as
     * Pinterest, linked.com as LinkedIn, fb.org as Facebook.
     * Tests\Service\HeaderFooterSettingsTest holds both.
     *
     * @var array<string, array{label: string, brands: list<string>, domains: list<string>, icon: string}>
     */
    private const NETWORKS = [
        'instagram' => [
            'label' => 'Instagram',
            'brands' => ['instagram'],
            'domains' => ['instagr.am'],
            'icon' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/>',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'brands' => ['facebook'],
            'domains' => ['fb.com', 'fb.me'],
            'icon' => '<path d="M18 3h-3a5 5 0 0 0-5 5v3H7v4h3v6h4v-6h3l1-4h-4V8a1 1 0 0 1 1-1h3z"/>',
        ],
        'pinterest' => [
            'label' => 'Pinterest',
            'brands' => ['pinterest'],
            'domains' => ['pin.it'],
            'icon' => '<circle cx="12" cy="12" r="9"/><path d="M8.8 14.2A4.6 4.6 0 0 1 8.2 12c0-2.6 2-4.7 5-4.7 2.7 0 4.4 1.7 4.4 4 0 2.7-1.4 4.7-3.4 4.7-1 0-1.7-.8-1.5-1.8"/><path d="M12.7 14.2 11.3 20"/>',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'brands' => ['linkedin'],
            'domains' => ['lnkd.in'],
            'icon' => '<path d="M16 9a5 5 0 0 1 5 5v6h-3.5v-6a1.5 1.5 0 0 0-3 0v6H11v-6a5 5 0 0 1 5-5z"/><path d="M4 10h3.5v10H4z"/><circle cx="5.75" cy="5.5" r="1.75"/>',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'brands' => ['youtube'],
            'domains' => ['youtu.be'],
            'icon' => '<rect x="2.5" y="6" width="19" height="12" rx="3.5"/><path d="M10.5 9.5 15 12l-4.5 2.5z"/>',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'brands' => ['tiktok'],
            'domains' => [],
            'icon' => '<path d="M14 4v10.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 4c.4 2.6 2.3 4.4 4.8 4.6"/>',
        ],
        'etsy' => [
            'label' => 'Etsy',
            'brands' => ['etsy'],
            'domains' => [],
            'icon' => '<path d="M16.5 5H9v14h7.5"/><path d="M9 12h5"/><path d="M16.5 5v2.5M16.5 19v-2.5"/>',
        ],
    ];

    /**
     * The visible rows as the database returned them, per request. Null until
     * read; overrideForTests() fills it without a database.
     *
     * @var list<array<string, mixed>>|null
     */
    private static ?array $visibleRows = null;

    /**
     * Every supported network, for the Footer screen's network choice: key
     * and label, in registry order. Never the icon — nothing outside this
     * class and partials/footer.php has any business with markup.
     *
     * @return array<string, array{label: string}>
     */
    public static function networks(): array
    {
        $networks = [];
        foreach (self::NETWORKS as $network => $definition) {
            $networks[$network] = ['label' => $definition['label']];
        }

        return $networks;
    }

    public static function isKnownNetwork(string $network): bool
    {
        return array_key_exists($network, self::NETWORKS);
    }

    public static function label(string $network): ?string
    {
        return self::NETWORKS[$network]['label'] ?? null;
    }

    /** Forgets the per-request read; every endpoint that writes a row calls this. */
    public static function clearCache(): void
    {
        self::$visibleRows = null;
    }

    /**
     * Installs `footer_social_links` rows (the visible ones, in order) so the
     * footer, PageSeo and anything else consuming forFooter() can be tested
     * without a database. Null goes back to reading the table. Same shape as
     * App\Service\Media\MediaService::overrideForTests(): forFooter() answers
     * from the same cache either way, so there is no second code path.
     *
     * @param list<array<string, mixed>>|null $rows
     */
    public static function overrideForTests(?array $rows): void
    {
        self::$visibleRows = $rows === null ? null : array_values($rows);
    }

    /**
     * The profiles the footer should render: the rows switched on, in the
     * editor's order. Empty when nothing is configured — and an empty list
     * means the footer draws no social row at all rather than an empty one.
     *
     * A row that does not validate is skipped rather than rendered, the same
     * "a broken row behaves like an absent row" rule as
     * App\Service\LinkResolver. The admin endpoints refuse such an address at
     * write time, but a row can also come from the migration of an older,
     * looser check, or from another tool; this is the last gate before an
     * href reaches a page. A database problem renders no social row rather
     * than breaking every public page (same convention as FooterService).
     *
     * `number` tells two profiles on the same network apart for a screen
     * reader: 1, 2, … for each of them, null for a network that appears once.
     *
     * @return list<array{network: string, label: string, url: string, icon: string, number: int|null}>
     */
    public static function forFooter(): array
    {
        $profiles = [];
        $perNetwork = [];

        foreach (self::visibleRows() as $row) {
            $network = (string) ($row['network'] ?? '');
            $url = trim((string) ($row['url'] ?? ''));

            if (!self::isValidProfileUrl($network, $url)) {
                continue;
            }

            $perNetwork[$network] = ($perNetwork[$network] ?? 0) + 1;

            $profiles[] = [
                'network' => $network,
                'label' => self::NETWORKS[$network]['label'],
                'url' => $url,
                'icon' => self::NETWORKS[$network]['icon'],
                'number' => $perNetwork[$network],
            ];
        }

        foreach ($profiles as $index => $profile) {
            if ($perNetwork[$profile['network']] === 1) {
                $profiles[$index]['number'] = null;
            }
        }

        return $profiles;
    }

    /**
     * Strict enough that an icon never points somewhere its network does not
     * own, loose enough not to refuse an address copied from the network
     * itself:
     *
     *   - the network key must be one of ours;
     *   - https only. Every one of these platforms serves https, so there is
     *     no reason to accept the scheme that can be downgraded — and it
     *     rules out javascript:, data:, and a relative path in one line;
     *   - a parseable absolute URL with a host and no credentials in it, at
     *     most MAX_URL_LENGTH long;
     *   - the host belongs to the network: its brand name on .com or a country
     *     domain, or one of its own short domains, with any subdomain.
     *
     * Letters like é or ü in the path are allowed (a profile address copied
     * from a browser may carry them); PHP's URL filter only knows ASCII, so
     * they are percent-encoded for the check alone. The stored address is
     * never rewritten.
     */
    public static function isValidProfileUrl(string $network, string $url): bool
    {
        $definition = self::NETWORKS[$network] ?? null;
        if ($definition === null) {
            return false;
        }

        $url = trim($url);

        if ($url === '' || mb_strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }

        if (!str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        $ascii = (string) preg_replace_callback('/[\x80-\xFF]/', static fn (array $byte): string => rawurlencode($byte[0]), $url);

        if (filter_var($ascii, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($ascii);
        if ($parts === false || !isset($parts['host'])) {
            return false;
        }

        // "https://facebook.com@evil.example" parses with host evil.example,
        // but a profile URL never needs credentials at all — refuse them
        // rather than reason about what the browser will do with them.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return self::hostBelongsTo(strtolower(rtrim($parts['host'], '.')), $definition);
    }

    /** @param array{brands: list<string>, domains: list<string>} $definition */
    private static function hostBelongsTo(string $host, array $definition): bool
    {
        foreach ($definition['domains'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        foreach ($definition['brands'] as $brand) {
            // brand.com, brand.nl, brand.co.uk, brand.com.au — optionally
            // behind a subdomain, and nothing after it.
            if (preg_match('/(?:^|\.)' . preg_quote($brand, '/') . '\.(?:com|(?:co\.|com\.)?[a-z]{2})$/', $host) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private static function visibleRows(): array
    {
        if (self::$visibleRows !== null) {
            return self::$visibleRows;
        }

        try {
            self::$visibleRows = (new FooterSocialLinkRepository())->findVisible();
        } catch (\Throwable $e) {
            error_log('[SocialProfiles] falling back to no social profiles: ' . $e->getMessage());

            // Not cached: a later call in the same request may find the
            // table again (a test that creates it, a reconnect).
            return [];
        }

        return self::$visibleRows;
    }
}
