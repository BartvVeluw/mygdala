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
 * BILINGUAL, like every other text in this project: the Dutch value is the
 * real tag content and the English one rides along in data-nl/data-en, which
 * assets/js/core.js's applyLang() swaps on the language toggle. An empty
 * English value falls back to the Dutch one — the rule App\Service\Seo::pick()
 * applies everywhere else. Open Graph and Twitter tags carry the Dutch copy
 * only: a crawler fetches one document and gets the document's own language,
 * and there are no separate localized URLs to point it at (see SEO.md on why
 * this project emits no hreflang).
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
    ) {
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
