<?php

namespace App\Service;

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
 * Deliberately plain PHP constants, NOT the DB-backed SiteSettings/CMS
 * section-content system (see MAIN.MD, "CMS-fundament" steps) — that system
 * is under active development in a parallel branch/session. Once it
 * stabilises, these arrays can be moved into a CMS-editable table without
 * changing anything that consumes them here, the same way e.g.
 * FeatureGridContent::forSection() is consumed today — just add a
 * forSlug()-style DB-backed lookup with these arrays as the fallback
 * defaults (mirrors SiteSettings::DEFAULTS).
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

    /**
     * Order here is the display order in the preferences modal.
     * 'required' => true means: always on, checkbox rendered
     * checked+disabled, never sent as "off" regardless of user input.
     */
    private const CATEGORIES = [
        self::CATEGORY_NECESSARY => [
            'required' => true,
            'label_nl' => 'Noodzakelijk',
            'label_en' => 'Necessary',
            'description_nl' => 'Nodig om de site te laten werken: je taalkeuze, de inhoud van je winkelwagen en het onthouden van je cookiekeuze zelf. Kan niet worden uitgezet.',
            'description_en' => 'Required for the site to work: your language choice, the contents of your shopping cart, and remembering your cookie choice itself. Cannot be turned off.',
        ],
        self::CATEGORY_PREFERENCES => [
            'required' => false,
            'label_nl' => 'Voorkeuren',
            'label_en' => 'Preferences',
            'description_nl' => 'Onthoudt keuzes die je bezoek prettiger maken, los van de noodzakelijke basisfuncties. Nog niet actief in gebruik op deze site.',
            'description_en' => 'Remembers choices that make your visit nicer, beyond the essential basics. Not yet in active use on this site.',
        ],
        self::CATEGORY_ANALYTICS => [
            'required' => false,
            'label_nl' => 'Analytisch',
            'label_en' => 'Analytics',
            'description_nl' => 'Zou helpen begrijpen hoe bezoekers de site gebruiken, om deze te kunnen verbeteren. Nog niet actief in gebruik op deze site.',
            'description_en' => 'Would help understand how visitors use the site, so it can be improved. Not yet in active use on this site.',
        ],
        self::CATEGORY_MARKETING => [
            'required' => false,
            'label_nl' => 'Marketing',
            'label_en' => 'Marketing',
            'description_nl' => 'Zou gebruikt worden voor gepersonaliseerde advertenties en het meten van campagnes. Nog niet actief in gebruik op deze site.',
            'description_en' => 'Would be used for personalised advertising and measuring campaigns. Not yet in active use on this site.',
        ],
    ];

    private const BANNER = [
        'title_nl' => 'Deze site gebruikt cookies',
        'title_en' => 'This site uses cookies',
        'description_nl' => 'We gebruiken noodzakelijke cookies om de site te laten werken. Optionele cookies (voorkeuren, analyse, marketing) zetten we pas aan als je daarvoor kiest. Lees meer in ons <a href="cookiebeleid.php">cookiebeleid</a>.',
        'description_en' => 'We use necessary cookies to make the site work. Optional cookies (preferences, analytics, marketing) are only switched on if you choose to. Read more in our <a href="cookiebeleid.php">cookie policy</a>.',
        'accept_all_nl' => 'Alles accepteren',
        'accept_all_en' => 'Accept all',
        'reject_optional_nl' => 'Optionele weigeren',
        'reject_optional_en' => 'Reject optional',
        'manage_nl' => 'Voorkeuren beheren',
        'manage_en' => 'Manage preferences',
    ];

    private const MODAL = [
        'title_nl' => 'Cookievoorkeuren',
        'title_en' => 'Cookie preferences',
        'description_nl' => 'Kies per categorie of je dit toestaat. Noodzakelijke cookies staan altijd aan omdat de site daar niet zonder kan. Je kunt je keuze later altijd wijzigen via "Cookie-instellingen" onderaan de site.',
        'description_en' => 'Choose per category whether you allow it. Necessary cookies are always on because the site cannot function without them. You can always change your choice later via "Cookie settings" at the bottom of the site.',
        'always_on_nl' => 'Altijd aan',
        'always_on_en' => 'Always on',
        'policy_link_nl' => 'Bekijk het volledige cookiebeleid',
        'policy_link_en' => 'View the full cookie policy',
        'save_nl' => 'Voorkeuren opslaan',
        'save_en' => 'Save preferences',
        'accept_all_nl' => 'Alles accepteren',
        'accept_all_en' => 'Accept all',
        'close_nl' => 'Sluiten',
        'close_en' => 'Close',
    ];

    private const FOOTER_LINK = [
        'label_nl' => 'Cookie-instellingen',
        'label_en' => 'Cookie settings',
        'policy_label_nl' => 'Cookiebeleid',
        'policy_label_en' => 'Cookie policy',
    ];

    /**
     * The actual cookies/local storage this site currently sets. Keep this
     * in sync with reality — do not list anything speculative here. If a
     * future analytics/marketing script is registered (see
     * assets/js/cookie-consent.js registerScript()), add its cookie(s) here
     * too so the policy page stays accurate.
     */
    /**
     * "This site itself", as opposed to a third party. Resolved to the
     * configured site name by policyEntries(), because a const array cannot
     * call SiteSettings and because a cookie policy that names the wrong
     * company is worse than useless.
     */
    private const PROVIDER_SELF = '{{site_name}} (eigen site)';

    private const POLICY_ENTRIES = [
        [
            'name' => 'vvl_cookie_consent',
            'type_nl' => 'Lokale opslag (localStorage)',
            'type_en' => 'Local storage (localStorage)',
            'category' => self::CATEGORY_NECESSARY,
            'purpose_nl' => 'Onthoudt welke cookiecategorieën je hebt geaccepteerd of geweigerd, zodat de melding niet steeds opnieuw verschijnt.',
            'purpose_en' => 'Remembers which cookie categories you accepted or rejected, so the banner does not keep reappearing.',
            'provider' => self::PROVIDER_SELF,
            'retention_nl' => 'Tot je je keuze wijzigt of je browsergegevens wist',
            'retention_en' => 'Until you change your choice or clear your browser data',
        ],
        [
            'name' => 'vvl-lang',
            'type_nl' => 'Lokale opslag (localStorage)',
            'type_en' => 'Local storage (localStorage)',
            'category' => self::CATEGORY_NECESSARY,
            'purpose_nl' => 'Onthoudt je taalkeuze (Nederlands/Engels) tussen paginabezoeken.',
            'purpose_en' => 'Remembers your language choice (Dutch/English) between page visits.',
            'provider' => self::PROVIDER_SELF,
            'retention_nl' => 'Tot je je browsergegevens wist',
            'retention_en' => 'Until you clear your browser data',
        ],
        [
            'name' => 'vvl-cart',
            'type_nl' => 'Lokale opslag (localStorage)',
            'type_en' => 'Local storage (localStorage)',
            'category' => self::CATEGORY_NECESSARY,
            'purpose_nl' => 'Onthoudt de inhoud van je winkelwagen tussen paginabezoeken, zodat deze niet leeg raakt tijdens het bladeren.',
            'purpose_en' => 'Remembers the contents of your shopping cart between page visits, so it does not empty out while browsing.',
            'provider' => self::PROVIDER_SELF,
            'retention_nl' => 'Tot je afrekent, de winkelwagen leegt of je browsergegevens wist',
            'retention_en' => 'Until you check out, empty the cart, or clear your browser data',
        ],
        [
            'name' => 'vvl_admin_session',
            'type_nl' => 'Cookie (sessie)',
            'type_en' => 'Cookie (session)',
            'category' => self::CATEGORY_NECESSARY,
            'purpose_nl' => 'Houdt de eigenaar ingelogd in het besloten adminpaneel. Wordt uitsluitend geplaatst ná inloggen op /admin — nooit voor gewone bezoekers.',
            'purpose_en' => 'Keeps the owner logged into the private admin panel. Only ever set after logging in at /admin — never for regular visitors.',
            'provider' => self::PROVIDER_SELF,
            'retention_nl' => 'Sessie (tot uitloggen of het sluiten van de browser)',
            'retention_en' => 'Session (until logout or the browser is closed)',
        ],
    ];

    /**
     * @return array<string, array{required: bool, label_nl: string, label_en: string, description_nl: string, description_en: string}>
     */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    /**
     * @return array<int, string>
     */
    public static function categoryKeys(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public static function banner(): array
    {
        return self::BANNER;
    }

    public static function modal(): array
    {
        return self::MODAL;
    }

    public static function footerLink(): array
    {
        return self::FOOTER_LINK;
    }

    /**
     * @return array<int, array{name: string, type_nl: string, type_en: string, category: string, purpose_nl: string, purpose_en: string, provider: string, retention_nl: string, retention_en: string}>
     */
    public static function policyEntries(): array
    {
        $siteName = SiteSettings::get('site_name');

        return array_map(
            static function (array $entry) use ($siteName): array {
                $entry['provider'] = str_replace('{{site_name}}', $siteName, (string) $entry['provider']);

                return $entry;
            },
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
