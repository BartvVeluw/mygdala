<?php

namespace App\Service;

use App\Repository\SiteSettingRepository;

/**
 * Global, cross-page site/business settings (company name, logo, favicon,
 * contact details, KVK number, footer blurb, default social-sharing image,
 * ...) — the single source of truth for values that used to be
 * hardcoded/repeated across partials/header.php, partials/footer.php,
 * contact.php and every page's <head>.
 *
 * Deliberately NOT for page-specific content, products, cart/checkout/order
 * data, or navigation (nav-config.php already owns that).
 *
 * DEFAULTS below is the fallback used whenever a key is missing from the
 * database (including "the database is unreachable") so the public site
 * never breaks because of a settings problem — it just silently falls back
 * to the values that used to be hardcoded.
 */
class SiteSettings
{
    private const DEFAULTS = [
        // BRANDING IDENTITY. These four are deliberately GENERIC rather than
        // Van Veluw's own values: this codebase is installed more than once,
        // and a fresh install must not inherit another company's name or a
        // photograph of its workshop as the default preview for every share.
        // The live site is unaffected — db/migrations/20260909210000 pinned
        // its current values as real rows before these defaults changed, and
        // a stored value always wins over a default. Empty here means "this
        // install has not chosen one yet"; App\Service\Branding turns that
        // into sensible behaviour (the site name instead of a broken image,
        // no <link rel="icon">, no og:image).
        'site_name' => 'Website',
        'logo_path' => '',
        'favicon_path' => '',

        // Optional second logo. EMPTY means "use logo_path": the fallback
        // lives in App\Service\Branding so no template has to know about
        // it. V1 uses it in the footer only, and there is deliberately no
        // automatic light/dark switching behind it.
        'logo_alt_path' => '',

        // BUSINESS IDENTITY, and generic for exactly the same reason as the
        // branding above: this codebase is installed more than once, and a
        // fresh install must not inherit another company's e-mail address,
        // city or Chamber of Commerce number. Empty means "this install has
        // not filled it in yet", which every reader already handles — the
        // footer omits the line, App\Mail\EmailIdentity leaves the part out
        // rather than printing a stray separator, and the invoice renderer
        // skips the row.
        //
        // The live site is unaffected: db/migrations/20260910110000 pinned
        // its current values as real rows before these defaults changed, and
        // a stored value always wins over a default. The Setup Wizard is
        // where a new install fills them in (SETUP.md).
        //
        // The place visitors read in the contact block and the footer's
        // description are website TEXT, one value per website language, and
        // live in App\Service\LocalizedSiteSettings since Multilingual 2.0
        // phase 4 (db/migrations/20260918130000), not here.
        'email' => '',
        'kvk_number' => '',
        'og_image_path' => '',

        // THE PUBLIC BASE URL, when the environment does not name one.
        // APP_URL in .env stays the first source and the one this project
        // documents (App\Service\AppUrl owns the precedence); this row is
        // what an installation uses that cannot edit its .env — the Setup
        // Wizard writes it. Empty means "nothing configured here", not
        // "empty base URL". See SEO.md and SETUP.md.
        'canonical_base_url' => '',

        // WHICH MEDIA ITEM each branding asset is (Media Library V1, see
        // MEDIA.md). The four *_path keys above stay exactly where they are
        // and keep working: a media id wins when it is set, the stored path
        // is the fallback when it is not, and App\Service\Branding is the
        // single place that precedence is written down.
        //
        // The ids live HERE and not in the Media Library because the two
        // answer different questions. The library owns a file's identity —
        // its dimensions, its alt text, where it is used. Which of those
        // files happens to be this site's logo is a fact about the site, and
        // this is where facts about the site live. They are deliberately not
        // in `theme_settings` either: "standaardvormgeving herstellen" must
        // never be able to take the logo with it (THEMING.md).
        //
        // Stored as a numeric string like every other value in this
        // key/value table; '' means "no media item chosen".
        'logo_media_id' => '',
        'logo_alt_media_id' => '',
        'favicon_media_id' => '',
        'og_image_media_id' => '',

        // THE WEBSITE'S LANGUAGES ARE NOT SETTINGS. The default language used
        // to be `primary_content_language` here, beside a deprecated
        // `enabled_content_languages`. Both moved into the website language
        // registry (`site_languages`, App\Service\Language\SiteLanguages) and
        // db/migrations/20260917120000 removed their rows, so neither key is
        // listed: a key listed here can be read, and a second answer to "which
        // language is the default" is exactly what the move ended.

        // GLOBAL SEO DEFAULTS (App\Service\SeoDefaults). Deliberately just
        // two keys: the title suffix is `site_name` above and the default
        // social image is `og_image_path` above, both of which already
        // existed — a second name for the site or a second default image
        // would only be free to drift from the first.
        //
        // seo_default_description is EMPTY by default and stays empty until
        // somebody writes one: a page with no description of its own then
        // renders no <meta name="description"> at all, which is better than
        // a generic sentence repeated across every page.
        //
        // seo_robots_index_default is '1' (index) by default, and anything
        // that is not an explicit off value counts as '1'
        // (App\Service\SeoDefaults::robots()) — a typo or a missing row can
        // never take a live site out of the search index.
        'seo_default_description' => '',
        'seo_robots_index_default' => '1',

        // Company postal address + optional contact/tax fields used on the
        // PDF invoice (App\Service\PdfInvoiceRenderer) — see
        // db/migrations/20260907200000_add_invoicing_and_email_settings.php.
        // Distinct from the localized `city` (site copy per language in
        // App\Service\LocalizedSiteSettings, not a structured postal address).
        // Generic for the same reason as the identity block above, and
        // pinned for the live site by the same migration. company_country
        // keeps a value because it is a format rather than an identity, and
        // an empty country on an invoice is a worse default than a wrong one
        // an owner can change in one field.
        'company_street' => '',
        'company_house_number' => '',
        // The legal name on an invoice, when it is not the site's own name.
        // EMPTY by default, and empty means "use site_name"
        // (App\Service\InvoiceService::buildSellerSnapshot()): a site name is
        // what visitors know, and a personal site or a shop trading under a
        // brand name has no reason to repeat it. Only the Shop's own settings
        // screen shows it (admin/shop-settings.php).
        'company_name' => '',
        'company_postal_code' => '',
        'company_city' => '',
        'company_country' => 'NL',
        'company_phone' => '',
        'company_website' => '',
        'company_vat_id' => '',

        // Invoice-specific settings. invoice_tax_note is deliberately blank
        // by default — MAIN.MD "BTW/KOR" leaves VAT/KOR wording an open,
        // undecided business decision, so no legal text is invented here.
        // Neutral rather than this site's own "VLD-F": an invoice number
        // needs a prefix, so this one is not emptied, it is made generic.
        'invoice_number_prefix' => 'INV',
        'invoice_footer_text' => '',
        'invoice_tax_note' => '',
        'invoice_payment_note' => '',

        // THE ORDER NUMBER PREFIX for orders created from now on. Read once
        // per order by App\Repository\OrderRepository::create(), which stores
        // the finished number on the order; formatOrderNumber() owns the
        // separators: "ORD" becomes "ORD-2026-000127". Changing it therefore
        // never renames an existing order (db/migrations/20260913120000). Its
        // own setting and not a variant of invoice_number_prefix, because an
        // order number and an invoice number are two different sequences for
        // two different readers. Generic and not empty for the same reason as
        // the invoice prefix: a number needs one. The code used to write
        // "VLD-" out by hand, and every installation that issued numbers with
        // it was pinned to "VLD" by db/migrations/20260913100000 before this
        // default existed, so those orders were stored with their VLD- numbers.
        'order_number_prefix' => 'ORD',

        // CMS-editable order-confirmation email copy (customer email only —
        // see App\Mail\OrderConfirmationBuilder / App\Mail\EmailPlaceholders
        // for the small, safe {{placeholder}} substitution supported here).
        'order_email_subject' => 'Bevestiging van je bestelling {{order_number}} — {{site_name}}',
        'order_email_heading' => 'Bedankt voor je bestelling!',
        'order_email_intro' => "Beste {{customer_name}},\n\nJe betaling voor bestelling {{order_number}} is gelukt. We gaan zo snel mogelijk voor je aan de slag.",
        'order_email_before_items' => '',
        'order_email_after_items' => '',
        'order_email_closing' => 'Heb je vragen over je bestelling? Antwoord gerust op deze e-mail.',
        'order_email_signature' => '',

        // Footer "Brand / Company block" visibility toggles + copyright
        // template — see db/migrations/20260907230000_add_footer_settings.php
        // and App\Service\FooterService. The link columns themselves live in
        // footer_columns/footer_links, not here.
        'footer_show_logo' => '1',
        'footer_show_company_name' => '0',
        'footer_show_email' => '1',
        'footer_show_phone' => '0',
        'footer_show_kvk' => '1',
        'footer_copyright_template' => '© {{year}} {{site_name}}',

        // "Gerelateerde producten" on a product detail page — see
        // App\Service\RelatedProductsContent, which owns the reading and
        // validation of these, and
        // db/migrations/20260908170000_add_related_products_settings.php.
        // The per-collection on/off switch lives on the `collections` row
        // itself, not here.
        //
        // Only the two language-NEUTRAL ones are here. The heading is website
        // text in a language and lives in `site_setting_translations` as
        // App\Service\LocalizedSiteSettings::RELATED_PRODUCTS_HEADING
        // (Multilingual 2.0 phase 5 wave C); a collection's own heading is a
        // word of that collection. Note that all() only overrides a default
        // when the stored value is !== '' (a strict comparison), so a stored
        // '0' really does turn related_products_enabled off.
        'related_products_enabled' => '1',
        'related_products_max_items' => '4',

        // The header's former single call-to-action button (header_cta_*)
        // is gone: db/migrations/20260916230000 copied it into nav_items, and
        // 20260918130000 removed its rows once nothing read them.

        // Whether the footer shows its closing line — read by
        // App\Service\FooterService::slogan(). The line's words are website
        // text per language in App\Service\LocalizedSiteSettings.
        'footer_slogan_enabled' => '0',

        // LEGACY since Footer phase B: the seven social profile URLs, one per
        // network. Migration 20260917100000 copied every filled-in one into
        // footer_social_links, which is what App\Service\SocialProfiles and
        // the Footer screen read and write now. Nothing reads or writes
        // these keys; they stay listed so the rows that remain in the
        // database are still known settings.
        'social_instagram_url' => '',
        'social_facebook_url' => '',
        'social_pinterest_url' => '',
        'social_linkedin_url' => '',
        'social_youtube_url' => '',
        'social_tiktok_url' => '',
        'social_etsy_url' => '',
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, string> every known key, DB value where present
     *                                and non-empty, default otherwise
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = [];
        try {
            $stored = (new SiteSettingRepository())->findAll();
        } catch (\Throwable $e) {
            error_log('[SiteSettings] falling back to defaults: ' . $e->getMessage());
        }

        $settings = self::DEFAULTS;
        foreach ($stored as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS) && $value !== '') {
                $settings[$key] = $value;
            }
        }

        return self::$cache = $settings;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Clears the in-process cache — used by the admin save handler right
     * after writing new values, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Test seam: pretend these are the stored values, without a database.
     * Pass null to go back to reading storage. Always reset it in
     * tearDown() — the cache is static and outlives one test.
     *
     * @param array<string, string>|null $values
     */
    public static function overrideForTests(?array $values): void
    {
        if ($values === null) {
            self::$cache = null;

            return;
        }

        self::$cache = array_merge(self::DEFAULTS, array_intersect_key($values, self::DEFAULTS));
    }
}
