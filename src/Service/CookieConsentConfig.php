<?php

namespace App\Service;

use App\Service\Language\SiteText;
use App\Service\Routing\LanguagePreference;
use App\Service\Routing\LocalizedUrl;

/**
 * Centralized cookie-consent configuration: categories, banner/modal copy
 * and the cookie/storage policy table (see cookiebeleid.php). Single source
 * of truth consumed by:
 *  - partials/cookie-consent.php (banner + preferences modal markup)
 *  - assets/js/cookie-consent.js (via the small inline VVL_CONSENT_CONFIG
 *    blob each page prints from jsConfig() — version + category keys must
 *    reach the browser before any consent check runs, including before the
 *    DOM has parsed, so it cannot rely on reading this class over an API)
 *  - cookiebeleid.php (the cookie policy page)
 *
 * THE WORDS ARE A CODE CATALOGUE, keyed by language code: website text that
 * the application owns, not an editor. Every accessor hands out the words of
 * the request's language through App\Service\Language\SiteText::pick() —
 * that language's, else the site's default language's, else the first — so
 * a partial prints one string and never a pair. Adding a language is adding
 * a key; there is no `_de` field.
 *
 * CONSENT IS LANGUAGE-NEUTRAL. The stored decision (`vvl_cookie_consent`)
 * holds a version and category keys only, and the same origin serves every
 * language, so switching from /en/… to /… never asks again.
 *
 * Deliberately plain PHP constants, NOT the DB-backed SiteSettings/CMS
 * section-content system. These arrays can move into a CMS-editable table
 * later without changing anything that consumes them here.
 */
class CookieConsentConfig
{
    /**
     * Bump this when a category is added/removed or the policy changes in a
     * way that should make visitors reconfirm their choice. It travels with
     * the stored consent decision (see assets/js/cookie-consent.js); a
     * mismatch is treated as "not decided yet" and the banner reappears.
     */
    public const CONSENT_VERSION = 1;

    public const CATEGORY_NECESSARY = 'necessary';
    public const CATEGORY_PREFERENCES = 'preferences';
    public const CATEGORY_ANALYTICS = 'analytics';
    public const CATEGORY_MARKETING = 'marketing';

    /** Where the policy lives; every link to it is built from this, in the language being read. */
    public const POLICY_PATH = '/cookiebeleid.php';

    /**
     * Order here is the display order in the preferences modal.
     * 'required' => true means: always on, checkbox rendered
     * checked+disabled, never sent as "off" regardless of user input.
     */
    private const CATEGORIES = [
        self::CATEGORY_NECESSARY => [
            'required' => true,
            'label' => ['nl' => 'Noodzakelijk', 'en' => 'Necessary'],
            'description' => [
                'nl' => 'Nodig om de site te laten werken: je taalkeuze, de inhoud van je winkelwagen en het onthouden van je cookiekeuze zelf. Kan niet worden uitgezet.',
                'en' => 'Required for the site to work: your language choice, the contents of your shopping cart, and remembering your cookie choice itself. Cannot be turned off.',
            ],
        ],
        self::CATEGORY_PREFERENCES => [
            'required' => false,
            'label' => ['nl' => 'Voorkeuren', 'en' => 'Preferences'],
            'description' => [
                'nl' => 'Onthoudt keuzes die je bezoek prettiger maken, los van de noodzakelijke basisfuncties. Nog niet actief in gebruik op deze site.',
                'en' => 'Remembers choices that make your visit nicer, beyond the essential basics. Not yet in active use on this site.',
            ],
        ],
        self::CATEGORY_ANALYTICS => [
            'required' => false,
            'label' => ['nl' => 'Analytisch', 'en' => 'Analytics'],
            'description' => [
                'nl' => 'Zou helpen begrijpen hoe bezoekers de site gebruiken, om deze te kunnen verbeteren. Nog niet actief in gebruik op deze site.',
                'en' => 'Would help understand how visitors use the site, so it can be improved. Not yet in active use on this site.',
            ],
        ],
        self::CATEGORY_MARKETING => [
            'required' => false,
            'label' => ['nl' => 'Marketing', 'en' => 'Marketing'],
            'description' => [
                'nl' => 'Zou gebruikt worden voor gepersonaliseerde advertenties en het meten van campagnes. Nog niet actief in gebruik op deze site.',
                'en' => 'Would be used for personalised advertising and measuring campaigns. Not yet in active use on this site.',
            ],
        ],
    ];

    /**
     * The banner. Its description ends in a link to the policy, held as
     * three plain pieces — before, link text, after — so the link is BUILT
     * here, from POLICY_PATH in the language being read, and never typed as
     * markup. A relative href inside a sentence used to 404 under every
     * nested path (/blog/categorie/…).
     */
    private const BANNER = [
        'title' => ['nl' => 'Deze site gebruikt cookies', 'en' => 'This site uses cookies'],
        'description_before' => [
            'nl' => 'We gebruiken noodzakelijke cookies om de site te laten werken. Optionele cookies (voorkeuren, analyse, marketing) zetten we pas aan als je daarvoor kiest. Lees meer in ons ',
            'en' => 'We use necessary cookies to make the site work. Optional cookies (preferences, analytics, marketing) are only switched on if you choose to. Read more in our ',
        ],
        'description_link' => ['nl' => 'cookiebeleid', 'en' => 'cookie policy'],
        'description_after' => ['nl' => '.', 'en' => '.'],
        'accept_all' => ['nl' => 'Alles accepteren', 'en' => 'Accept all'],
        'reject_optional' => ['nl' => 'Optionele weigeren', 'en' => 'Reject optional'],
        'manage' => ['nl' => 'Voorkeuren beheren', 'en' => 'Manage preferences'],
        'region' => ['nl' => 'Cookiemelding', 'en' => 'Cookie notice'],
    ];

    private const MODAL = [
        'title' => ['nl' => 'Cookievoorkeuren', 'en' => 'Cookie preferences'],
        'description' => [
            'nl' => 'Kies per categorie of je dit toestaat. Noodzakelijke cookies staan altijd aan omdat de site daar niet zonder kan. Je kunt je keuze later altijd wijzigen via "Cookie-instellingen" onderaan de site.',
            'en' => 'Choose per category whether you allow it. Necessary cookies are always on because the site cannot function without them. You can always change your choice later via "Cookie settings" at the bottom of the site.',
        ],
        'always_on' => ['nl' => 'Altijd aan', 'en' => 'Always on'],
        'policy_link' => ['nl' => 'Bekijk het volledige cookiebeleid', 'en' => 'View the full cookie policy'],
        'save' => ['nl' => 'Voorkeuren opslaan', 'en' => 'Save preferences'],
        'accept_all' => ['nl' => 'Alles accepteren', 'en' => 'Accept all'],
        'close' => ['nl' => 'Sluiten', 'en' => 'Close'],
    ];

    private const FOOTER_LINK = [
        'label' => ['nl' => 'Cookie-instellingen', 'en' => 'Cookie settings'],
        'policy_label' => ['nl' => 'Cookiebeleid', 'en' => 'Cookie policy'],
    ];

    /**
     * "This site itself", as opposed to a third party. Resolved to the
     * configured site name by policyEntries(), because a const array cannot
     * call SiteSettings and because a cookie policy that names the wrong
     * company is worse than useless.
     */
    private const PROVIDER_SELF = ['nl' => '{{site_name}} (eigen site)', 'en' => '{{site_name}} (this site)'];

    private const LOCAL_STORAGE = ['nl' => 'Lokale opslag (localStorage)', 'en' => 'Local storage (localStorage)'];

    /**
     * The actual cookies/local storage this site currently sets. Keep this
     * in sync with reality — do not list anything speculative here. If a
     * future analytics/marketing script is registered (see
     * assets/js/cookie-consent.js registerScript()), add its cookie(s) here
     * too so the policy page stays accurate.
     */
    private const POLICY_ENTRIES = [
        [
            'name' => 'vvl_cookie_consent',
            'type' => self::LOCAL_STORAGE,
            'category' => self::CATEGORY_NECESSARY,
            'purpose' => [
                'nl' => 'Onthoudt welke cookiecategorieën je hebt geaccepteerd of geweigerd, zodat de melding niet steeds opnieuw verschijnt.',
                'en' => 'Remembers which cookie categories you accepted or rejected, so the banner does not keep reappearing.',
            ],
            'retention' => [
                'nl' => 'Tot je je keuze wijzigt of je browsergegevens wist',
                'en' => 'Until you change your choice or clear your browser data',
            ],
        ],
        [
            // App\Service\Routing\LanguagePreference: written only when a
            // visitor opens a page in another language than the one stored,
            // so the site root can greet them in the language they chose.
            'name' => LanguagePreference::COOKIE_NAME,
            'type' => ['nl' => 'Cookie', 'en' => 'Cookie'],
            'category' => self::CATEGORY_NECESSARY,
            'purpose' => [
                'nl' => 'Onthoudt in welke taal je de site het laatst las, zodat de homepage je in die taal verwelkomt.',
                'en' => 'Remembers the language you last read the site in, so the homepage greets you in that language.',
            ],
            'retention' => ['nl' => '1 jaar', 'en' => '1 year'],
        ],
        [
            'name' => 'vvl-cart',
            'type' => self::LOCAL_STORAGE,
            'category' => self::CATEGORY_NECESSARY,
            'purpose' => [
                'nl' => 'Onthoudt de inhoud van je winkelwagen tussen paginabezoeken, zodat deze niet leeg raakt tijdens het bladeren.',
                'en' => 'Remembers the contents of your shopping cart between page visits, so it does not empty out while browsing.',
            ],
            'retention' => [
                'nl' => 'Tot je afrekent, de winkelwagen leegt of je browsergegevens wist',
                'en' => 'Until you check out, empty the cart, or clear your browser data',
            ],
        ],
        [
            'name' => 'vvl_admin_session',
            'type' => ['nl' => 'Cookie (sessie)', 'en' => 'Cookie (session)'],
            'category' => self::CATEGORY_NECESSARY,
            'purpose' => [
                'nl' => 'Houdt de eigenaar ingelogd in het besloten adminpaneel. Wordt uitsluitend geplaatst ná inloggen op /admin — nooit voor gewone bezoekers.',
                'en' => 'Keeps the owner logged into the private admin panel. Only ever set after logging in at /admin — never for regular visitors.',
            ],
            'retention' => [
                'nl' => 'Sessie (tot uitloggen of het sluiten van de browser)',
                'en' => 'Session (until logout or the browser is closed)',
            ],
        ],
    ];

    /**
     * The categories in display order, their words in the request's language.
     *
     * @return array<string, array{required: bool, label: string, description: string}>
     */
    public static function categories(): array
    {
        return array_map(static fn (array $category): array => [
            'required' => $category['required'],
            'label' => SiteText::pick($category['label']),
            'description' => SiteText::pick($category['description']),
        ], self::CATEGORIES);
    }

    /**
     * @return array<int, string>
     */
    public static function categoryKeys(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /**
     * The banner's words in the request's language. `description_html` is
     * the one value that is markup: escaped text around one link to the
     * policy page in the language being read.
     *
     * @return array{title: string, description_html: string, accept_all: string, reject_optional: string, manage: string, region: string}
     */
    public static function banner(): array
    {
        return [
            'title' => SiteText::pick(self::BANNER['title']),
            'description_html' => SiteText::escaped(self::BANNER['description_before'])
                . '<a href="' . htmlspecialchars(self::policyUrl(), ENT_QUOTES, 'UTF-8') . '">'
                . SiteText::escaped(self::BANNER['description_link']) . '</a>'
                . SiteText::escaped(self::BANNER['description_after']),
            'accept_all' => SiteText::pick(self::BANNER['accept_all']),
            'reject_optional' => SiteText::pick(self::BANNER['reject_optional']),
            'manage' => SiteText::pick(self::BANNER['manage']),
            'region' => SiteText::pick(self::BANNER['region']),
        ];
    }

    /**
     * The preferences dialog's words in the request's language. All plain text.
     *
     * @return array<string, string>
     */
    public static function modal(): array
    {
        return array_map(static fn (array $text): string => SiteText::pick($text), self::MODAL);
    }

    /**
     * The two footer links' words in the request's language.
     *
     * @return array{label: string, policy_label: string}
     */
    public static function footerLink(): array
    {
        return array_map(static fn (array $text): string => SiteText::pick($text), self::FOOTER_LINK);
    }

    /** The policy page's address in the language being read (App\Service\Routing\LocalizedUrl). */
    public static function policyUrl(): string
    {
        return LocalizedUrl::path(self::POLICY_PATH);
    }

    /**
     * The policy table, its words in the request's language.
     *
     * @return array<int, array{name: string, type: string, category: string, purpose: string, provider: string, retention: string}>
     */
    public static function policyEntries(): array
    {
        $siteName = SiteSettings::get('site_name');
        $provider = str_replace('{{site_name}}', $siteName, SiteText::pick(self::PROVIDER_SELF));

        return array_map(
            static fn (array $entry): array => [
                'name' => $entry['name'],
                'type' => SiteText::pick($entry['type']),
                'category' => $entry['category'],
                'purpose' => SiteText::pick($entry['purpose']),
                'provider' => $provider,
                'retention' => SiteText::pick($entry['retention']),
            ],
            self::POLICY_ENTRIES
        );
    }

    /**
     * Whether any optional (non-necessary) cookie/storage entry is actually
     * in use yet. Drives the "no optional cookies in use yet" notice on the
     * policy page — stays true until a real analytics/marketing entry is
     * added to POLICY_ENTRIES above.
     */
    public static function hasOptionalEntriesInUse(): bool
    {
        foreach (self::POLICY_ENTRIES as $entry) {
            if ($entry['category'] !== self::CATEGORY_NECESSARY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minimal config handed to the browser as an inline JSON blob (see
     * partials/header.php / every public page's <head>), read synchronously
     * by assets/js/cookie-consent.js before the DOM has necessarily parsed —
     * so it cannot depend on markup, only on this small, embeddable array.
     * Language-neutral on purpose: consent is a decision about categories,
     * not about words.
     *
     * @return array{version: int, categories: array<int, string>}
     */
    public static function jsConfig(): array
    {
        return [
            'version' => self::CONSENT_VERSION,
            'categories' => self::categoryKeys(),
        ];
    }
}
