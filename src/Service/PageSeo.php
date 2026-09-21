<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Media\MediaService;

/**
 * The SEO read model for one CMS page — the Core counterpart of
 * App\Service\ProductSeo and App\Service\CollectionContent, and the only
 * place that turns a `pages` row into the App\Service\SeoMetadata that
 * partials/seo-head.php renders and that App\Service\Sitemap consults.
 *
 * Why here rather than on App\Service\PageContent: PageContent is the page
 * REGISTRY (does this slug exist, is it published, may it be deleted, what
 * is its URL). The individual resolved values it already owned —
 * seoTitle(), metaDescription(), canonicalUrl() — stay exactly where they
 * are, so nothing that reads them had to change; this class is the small
 * layer that assembles them, adds the two new fields (indexability and the
 * page's own social image) and hands the result over as one object.
 *
 * THE HIERARCHY, for a generic CMS page:
 *
 *   title        meta_title (verbatim, whatever it says)
 *                -> "<page title> — <site name>"
 *                -> the site name alone
 *   description  meta_description
 *                -> the global seo_default_description
 *                -> no tag at all
 *   canonical    the page's own public URL, resolved against APP_URL
 *   robots       noindex when the page says so, or when the whole install
 *                is set to noindex; index,follow otherwise
 *   social image the page's own media item (og_media_id), or the
 *                og_image_path it still carries from before the library
 *                -> the site-wide Standaard deel-afbeelding
 *                -> no og:image at all
 *
 * There is no per-page canonical OVERRIDE and no separate Open Graph
 * title/description field. A canonical override is a footgun on a CMS whose
 * every page already has exactly one URL, and OG copy that can differ from
 * the visible title is the drift this whole feature removes. Both are
 * recorded as deliberately deferred in SEO.md.
 *
 * Static and null-tolerant, the convention of every *Content class here: a
 * page row that could not be loaded produces a valid minimal head instead of
 * a fatal error.
 */
class PageSeo
{
    /**
     * The effective metadata for one `pages` row, or the site's own minimal
     * head when there is no row at all (a failed lookup, or a template that
     * ran before its page existed). A null page is NOT a 404 — a route that
     * has decided it cannot answer renders SeoMetadata::notFound() instead,
     * with the noindex that goes with it.
     *
     * @param array<string, mixed>|null $page
     */
    public static function forPage(?array $page): SeoMetadata
    {
        if ($page === null) {
            return SeoMetadata::create(title: SeoDefaults::siteName(), canonical: null);
        }

        // A page's text is stored per language: the head gets the REQUEST's,
        // which is the language a crawler fetched this URL in.
        $language = \App\Service\Routing\RequestLanguage::current();

        return SeoMetadata::create(
            title: PageContent::seoTitle($page, $language),
            description: PageContent::metaDescription($page, $language),
            canonical: PageContent::canonicalUrl($page),
            indexable: self::isIndexable($page),
            // Every CMS page is og:type "website". Nothing here guesses
            // "article" from a page's content: this CMS has no articles, no
            // author and no publication date to back that claim up.
            ogType: 'website',
            socialImage: self::socialImagePath($page),
            jsonLd: self::jsonLd($page),
        );
    }

    /**
     * May search engines index this page?
     *
     * Three independent reasons to say no, all of them facts the application
     * already knows rather than a second switch to keep in sync:
     *
     *   1. the page carries `noindex` — the owner's own choice;
     *   2. the page is not published — a draft that is still reachable
     *      through its own template (the six system pages always are) must
     *      not be offered to a crawler;
     *   3. the page is served from a module's template while that module is
     *      switched off, so its URL 404s (PageContent::isServedByAnEnabledModule()).
     *
     * Exactly the same three conditions decide whether it appears in the
     * sitemap — see App\Service\Sitemap, which calls this method rather than
     * repeating them.
     *
     * @param array<string, mixed> $page
     */
    public static function isIndexable(array $page): bool
    {
        if ((int) ($page['noindex'] ?? 0) === 1) {
            return false;
        }

        if (!PageContent::isPublished($page)) {
            return false;
        }

        return PageContent::isServedByAnEnabledModule($page);
    }

    /**
     * The page's own social sharing image as a stored path, or null to fall
     * back to the site-wide one.
     *
     * @param array<string, mixed> $page
     */
    public static function socialImagePath(array $page): ?string
    {
        // Media Library first, the page's own stored path second — the same
        // precedence App\Service\Branding applies to the site-wide image, so
        // a page and the site it belongs to never disagree about which of two
        // stored values wins. The hierarchy documented above is untouched:
        // this still answers only "the page's own image, or null to fall
        // back", and App\Service\Seo still makes the result absolute.
        $media = MediaService::find(
            isset($page['og_media_id']) ? (int) $page['og_media_id'] : null
        );

        if ($media !== null) {
            return $media->path;
        }

        $own = trim((string) ($page['og_image_path'] ?? ''));

        return $own === '' ? null : $own;
    }

    /**
     * Structured data for a CMS page: an Organization node on the site root
     * and nothing anywhere else.
     *
     * Only what SiteSettings genuinely holds is emitted — the site's name,
     * its canonical URL, its logo if one is configured, and the social
     * profile URLs the owner has actually filled in
     * (App\Service\SocialProfiles). An install that has set none of those
     * beyond a name gets a name and a URL, which is true; nothing is
     * invented. There is deliberately no LocalBusiness node, no opening
     * hours, no rating and no breadcrumb list: this project has no reliable
     * data for any of them. See SEO.md.
     *
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>|null
     */
    private static function jsonLd(array $page): ?array
    {
        if (!PageContent::isSiteRoot($page) || !self::isIndexable($page)) {
            return null;
        }

        $siteName = SeoDefaults::siteName();
        if ($siteName === '') {
            return null;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $siteName,
            // The site root OF THIS LANGUAGE: structured data on /en/ must
            // not point a crawler at the Dutch homepage.
            'url' => AppUrl::canonical(\App\Service\Routing\LocalizedUrl::home()),
        ];

        $logo = Seo::absoluteImageUrl(Branding::logoPath());
        if ($logo !== null) {
            $data['logo'] = $logo;
        }

        // The profiles the footer shows, so a profile an editor hid is not
        // claimed either. Since Footer phase B a site may list the same
        // address twice; sameAs names each one once.
        $sameAs = [];
        foreach (SocialProfiles::forFooter() as $profile) {
            $url = trim((string) ($profile['url'] ?? ''));
            if ($url !== '' && !in_array($url, $sameAs, true)) {
                $sameAs[] = $url;
            }
        }
        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }

        return $data;
    }
}
