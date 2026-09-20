<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The effective SEO metadata of ONE public page, already resolved: the exact
 * title, description, canonical URL, robots directive, Open Graph and
 * Twitter values that partials/seo-head.php will print, and nothing a
 * template still has to decide.
 *
 * This is the single source of truth this feature exists to create. Before
 * it, fifteen templates each assembled their own head: four different title
 * conventions, three different ways of building a canonical URL, an og:image
 * fallback chain written out twice, and two routes (cart, checkout) that
 * were indexable because nobody had remembered to add a robots tag. A
 * template now resolves a SeoMetadata and renders it; it never concatenates
 * a site name, never touches App\Service\AppUrl, and never writes a <meta>
 * of its own.
 *
 * READ-ONLY on purpose. Every value is decided once, by the resolver that
 * knows the content type (App\Service\PageSeo for `pages` rows,
 * App\Service\ProductSeo and App\Service\CollectionContent for the shop),
 * so the <head>, the Open Graph tags, the structured data and the sitemap
 * cannot disagree about a page's URL, title or indexability.
 *
 * ONE LANGUAGE PER DOCUMENT since Multilingual 2.0 phase 6. Every language
 * now has its own URL (docs/multilingual/ROUTING.md), so the tag a crawler
 * reads is the REQUEST's language: ::title() and ::description() answer in
 * it, and that is what partials/seo-head.php prints, what Open Graph carries
 * and what the structured data quotes.
 *
 * The `titleNl` / `titleEn` pair is still here, and still rides along in
 * data-nl/data-en. It is V1 compatibility output that phase 7 removes;
 * nothing acts on it any more, because the language switch became links to
 * those other URLs. A resolver that has a page's text in EVERY language hands
 * it over with ::createLocalized(), and a third language then gets its own
 * words rather than the pair's.
 *
 * THE FALLBACK HIERARCHY lives in create(): what a caller leaves empty is
 * filled in from App\Service\SeoDefaults. What a caller passes is used
 * verbatim — a description an administrator typed is never rewritten,
 * shortened or replaced.
 *
 * Open Graph title/description are deliberately NOT separate fields. They
 * are the effective title and description, so an owner who edits the SEO
 * title cannot end up with a share preview still showing the old one. That
 * also keeps the page editor down to the fields that earn their place.
 */
final class SeoMetadata
{
    /**
     * @param ?array<string, mixed> $jsonLd structured data for this page, or null
     */
    private function __construct(
        public readonly string $titleNl,
        public readonly string $titleEn,
        public readonly string $descriptionNl,
        public readonly string $descriptionEn,
        public readonly ?string $canonical,
        public readonly string $robots,
        public readonly string $ogType,
        public readonly ?string $ogImageUrl,
        public readonly ?array $jsonLd,
        /**
         * The title per language, ALREADY RESOLVED by the resolver that owns
         * the content (App\Service\PageSeo). No fallback is applied to it
         * here: the fallback of a page's text is PageLocalization's, stated
         * once, and a second one in the head could drift from it.
         *
         * @var array<string, string>
         */
        private readonly array $titles = [],
        /** @var array<string, string> the description per language, already resolved */
        private readonly array $descriptions = [],
    ) {
    }

    /**
     * The title this document actually carries: the request language's.
     *
     * Falls back exactly as every other text does — the asked-for language,
     * then the default language — because a page that is routable in German
     * but whose SEO title nobody translated still needs a title, and the
     * words a visitor can read beat an empty tag. That is FIELD fallback, and
     * it never invents a route: the German URL only exists because a German
     * slug does (docs/multilingual/ROUTING.md).
     */
    public function title(): string
    {
        return $this->localized($this->titles, $this->titleNl, $this->titleEn);
    }

    /** The description this document carries, in the request's language. */
    public function description(): string
    {
        return $this->localized($this->descriptions, $this->descriptionNl, $this->descriptionEn);
    }

    /**
     * One value in the request's language.
     *
     * From the resolver's per-language map when there is one — those values
     * have already been through their own domain's fallback and are taken as
     * they are. Otherwise from the V1 pair, which is all a route that still
     * writes its head by hand has to offer; a language outside that pair then
     * reads the pair's own resolution, exactly as it did before phase 6.
     *
     * @param array<string, string> $values
     */
    private function localized(array $values, string $nl, string $en): string
    {
        $language = \App\Service\Routing\RequestLanguage::current();

        if (array_key_exists($language, $values)) {
            return $values[$language];
        }

        return \App\Service\Language\LocalizedValue::ofDutchEnglish($nl, $en)->in($language);
    }

    /**
     * The effective metadata for a page whose text is known in EVERY website
     * language, rather than only in the V1 pair.
     *
     * $title and $description are ALREADY RESOLVED per language by the caller
     * — the owning domain's fallback has run, and nothing here applies a
     * second one. The V1 pair is read out of the same maps, so the
     * compatibility attributes cannot say something else than the tag.
     *
     * @param array<string, string> $title       language code => the title in it
     * @param array<string, string> $description language code => the description in it
     * @param ?array<string, mixed> $jsonLd
     */
    public static function createLocalized(
        array $title,
        array $description,
        ?string $canonical = null,
        bool $indexable = true,
        string $ogType = 'website',
        ?string $socialImage = null,
        ?array $jsonLd = null,
    ): self {
        $base = self::create(
            titleNl: $title[\App\Service\Language\LanguageRegistry::DUTCH] ?? '',
            titleEn: $title[\App\Service\Language\LanguageRegistry::ENGLISH] ?? '',
            descriptionNl: $description[\App\Service\Language\LanguageRegistry::DUTCH] ?? '',
            descriptionEn: $description[\App\Service\Language\LanguageRegistry::ENGLISH] ?? '',
            canonical: $canonical,
            indexable: $indexable,
            ogType: $ogType,
            socialImage: $socialImage,
            jsonLd: $jsonLd,
        );

        return new self(
            $base->titleNl,
            $base->titleEn,
            $base->descriptionNl,
            $base->descriptionEn,
            $base->canonical,
            $base->robots,
            $base->ogType,
            $base->ogImageUrl,
            $base->jsonLd,
            $title,
            $description,
        );
    }

    /**
     * The effective metadata for one public page.
     *
     * Everything but the title is optional, and every omitted value falls
     * back the way SEO.md describes:
     *
     *   description   given -> global default -> omitted entirely
     *   social image  given -> global default -> omitted entirely
     *   robots        indexable unless $indexable is false, and never
     *                 "index" when the install itself is set to noindex
     *   canonical     given, or omitted (a page with no stable public URL
     *                 must not claim one)
     *
     * $socialImage takes a STORED PATH or an absolute http(s) URL — the same
     * shape products, collections and the Site Setting store — and is
     * resolved against App\Service\AppUrl, never against the request host.
     *
     * @param ?array<string, mixed> $jsonLd
     */
    public static function create(
        string $titleNl,
        string $titleEn = '',
        string $descriptionNl = '',
        string $descriptionEn = '',
        ?string $canonical = null,
        bool $indexable = true,
        string $ogType = 'website',
        ?string $socialImage = null,
        ?array $jsonLd = null,
    ): self {
        $titleNl = trim($titleNl);
        $titleEn = trim($titleEn);
        $descriptionNl = trim($descriptionNl);
        $descriptionEn = trim($descriptionEn);

        if ($titleNl === '') {
            $titleNl = SeoDefaults::siteName();
        }
        if ($titleEn === '') {
            $titleEn = $titleNl;
        }

        if ($descriptionNl === '') {
            // The global default stands in for BOTH languages: a site that
            // wrote one sentence about itself has one sentence, and inventing
            // an English translation of it here would be worse than showing
            // the one that exists.
            $descriptionNl = SeoDefaults::description();
            $descriptionEn = $descriptionEn === '' ? $descriptionNl : $descriptionEn;
        }
        if ($descriptionEn === '') {
            $descriptionEn = $descriptionNl;
        }

        $ogImageUrl = Seo::absoluteImageUrl($socialImage) ?? SeoDefaults::socialImageUrl();

        return new self(
            titleNl: $titleNl,
            titleEn: $titleEn,
            descriptionNl: $descriptionNl,
            descriptionEn: $descriptionEn,
            canonical: self::normalizeCanonical($canonical),
            robots: $indexable ? SeoDefaults::robots() : SeoDefaults::ROBOTS_NOINDEX,
            ogType: trim($ogType) === '' ? 'website' : trim($ogType),
            ogImageUrl: $ogImageUrl,
            jsonLd: $jsonLd === [] ? null : $jsonLd,
        );
    }

    /**
     * The metadata of a page that does not exist, or of a route that has
     * decided it cannot answer: a title, `noindex,follow`, and nothing else.
     *
     * No canonical (this URL is not the canonical anything), no description
     * and no social image — not even the global defaults, because a 404 is
     * not a page whose share preview anybody wants. `follow` rather than
     * `none` so a crawler still walks the links back into the working site.
     */
    public static function notFound(string $titleNl, string $titleEn = ''): self
    {
        $titleNl = trim($titleNl) === '' ? SeoDefaults::siteName() : trim($titleNl);
        $titleEn = trim($titleEn) === '' ? $titleNl : trim($titleEn);

        return new self(
            titleNl: $titleNl,
            titleEn: $titleEn,
            descriptionNl: '',
            descriptionEn: '',
            canonical: null,
            robots: SeoDefaults::ROBOTS_NOINDEX,
            ogType: 'website',
            ogImageUrl: null,
            jsonLd: null,
        );
    }

    public function isIndexable(): bool
    {
        return $this->robots === SeoDefaults::ROBOTS_INDEX;
    }

    public function hasDescription(): bool
    {
        return $this->descriptionNl !== '';
    }

    /**
     * The Twitter/X card type, or null when this page has no image — the
     * whole twitter:* block is then omitted rather than emitting a bare
     * `summary` that repeats what Open Graph already says. X reads the OG
     * tags when the Twitter ones are absent, so nothing is lost.
     */
    public function twitterCard(): ?string
    {
        return $this->ogImageUrl !== null ? 'summary_large_image' : null;
    }

    /**
     * A canonical URL, or null. Only an absolute http(s) URL is accepted:
     * everything in this application builds one through App\Service\AppUrl,
     * so anything else is a bug or an injection attempt, and a wrong
     * canonical is worse than none at all.
     */
    private static function normalizeCanonical(?string $canonical): ?string
    {
        $canonical = trim((string) $canonical);

        if ($canonical === '' || preg_match('#^https?://#i', $canonical) !== 1) {
            return null;
        }

        return filter_var($canonical, FILTER_VALIDATE_URL) === false ? null : $canonical;
    }
}
