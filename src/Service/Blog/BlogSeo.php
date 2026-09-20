<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\Branding;
use App\Service\Language\LanguageRegistry;
use App\Service\Media\MediaService;
use App\Service\AppUrl;
use App\Service\Routing\RequestLanguage;
use App\Service\Seo;
use App\Service\SeoDefaults;
use App\Service\SeoMetadata;

/**
 * The Blog's SEO read model: one App\Service\SeoMetadata per public Blog URL.
 *
 * IT ADDS NO SEO MACHINERY. There is one renderer in this project
 * (partials/seo-head.php) and one resolved-metadata object, and a module
 * plugs into them by producing that object — the same way
 * App\Service\PageSeo does for CMS pages (SEO.md, "Een module en SEO"). No
 * second head partial, no second title convention, no second fallback chain.
 *
 * THE HIERARCHY, per post:
 *
 *   title        meta_title (verbatim)
 *                -> "<post title> | <blog title> — <site name>"
 *   description  meta_description
 *                -> the post's own excerpt, as plain text
 *                -> the global seo_default_description
 *                -> no tag
 *   canonical    /blog/<slug>, from App\Service\Blog\BlogUrls
 *   robots       noindex when the post says so; index otherwise
 *   image        the post's own social image (og_media_id)
 *                -> its featured image
 *                -> the site-wide Standaard deel-afbeelding
 *
 * The title convention follows the shop's ("<name> | Shop — <site>") rather
 * than inventing a fourth one: a reader scanning search results should be
 * able to see which section of the site a result is from.
 *
 * ARCHIVES. A category archive is indexable — it is a real, editorially
 * curated collection with its own description — and it appears in the
 * sitemap once it holds a public post. A TAG archive is noindex,follow and
 * stays out of the sitemap: tags are free-form, they multiply, and a dozen
 * near-identical thin listings is exactly the kind of page a search engine
 * should not be offered. Both remain perfectly linkable and crawlable; only
 * the invitation to index is withheld. Paginated listings are canonical to
 * themselves, so page 2 is not a duplicate of page 1.
 *
 * STRUCTURED DATA. A post emits a BlogPosting node and nothing else, built
 * only from what the row actually holds: headline, its URL, publication and
 * modification dates, the image if there is one, the description if there is
 * one, and an author only when a byline was typed. No rating, no invented
 * publisher facts beyond the site's own name and logo, no breadcrumb list
 * this site does not render. The listing and the archives emit nothing: they
 * are listings, exactly like a shop collection page (SEO.md).
 */
final class BlogSeo
{
    /** The og:type of a post. A listing stays "website". */
    public const POST_OG_TYPE = 'article';

    /**
     * The Blog index, or one of its later pages.
     */
    public static function forIndex(int $page = 1): SeoMetadata
    {
        $titleNl = self::listingTitle(BlogLocalizedSettings::title(LanguageRegistry::DUTCH), $page);
        $titleEn = self::listingTitle(BlogLocalizedSettings::title(LanguageRegistry::ENGLISH), $page);

        return SeoMetadata::create(
            titleNl: $titleNl,
            titleEn: $titleEn,
            descriptionNl: Seo::plainText(BlogLocalizedSettings::intro(LanguageRegistry::DUTCH)),
            descriptionEn: Seo::plainText(BlogLocalizedSettings::intro(LanguageRegistry::ENGLISH)),
            // This language's own index URL (docs/multilingual/ROUTING.md).
            canonical: BlogUrls::index($page, RequestLanguage::current()),
            indexable: true,
            ogType: 'website',
            socialImage: null,
            jsonLd: null,
        );
    }

    /**
     * One post.
     *
     * @param array<string, mixed> $post a decorated row from BlogContent
     */
    public static function forPost(array $post): SeoMetadata
    {
        return SeoMetadata::create(
            titleNl: self::postTitle($post, LanguageRegistry::DUTCH),
            titleEn: self::postTitle($post, LanguageRegistry::ENGLISH),
            descriptionNl: self::postDescription($post, LanguageRegistry::DUTCH),
            descriptionEn: self::postDescription($post, LanguageRegistry::ENGLISH),
            // The URL this post is actually being read at. A decorated row
            // carries it already; a raw one is resolved on the spot, so this
            // works for both and the canonical tag can never disagree with
            // the link that got the visitor here.
            canonical: BlogContent::postCanonical($post),
            indexable: (int) ($post['noindex'] ?? 0) !== 1,
            ogType: self::POST_OG_TYPE,
            socialImage: self::socialImagePath($post),
            jsonLd: self::postJsonLd($post),
        );
    }

    /**
     * A category archive.
     *
     * @param array<string, mixed> $category
     */
    public static function forCategory(array $category, int $page = 1): SeoMetadata
    {
        $id = (int) ($category['id'] ?? 0);

        return SeoMetadata::create(
            titleNl: self::archiveTitle(BlogContent::categoryName($category, LanguageRegistry::DUTCH), $page),
            titleEn: self::archiveTitle(BlogContent::categoryName($category, LanguageRegistry::ENGLISH), $page),
            // The head still carries the V1 pair for the client-side switch;
            // each half is one language of the category's own introduction,
            // read through BlogLocalization with its fallback.
            descriptionNl: Seo::plainText(BlogLocalization::categoryDescription($id, LanguageRegistry::DUTCH)),
            descriptionEn: Seo::plainText(BlogLocalization::categoryDescription($id, LanguageRegistry::ENGLISH)),
            canonical: AppUrl::canonical(BlogContent::categoryUrl($category, $page)),
            indexable: true,
            ogType: 'website',
            socialImage: null,
            jsonLd: null,
        );
    }

    /**
     * A tag archive: linkable, crawlable, deliberately not indexable.
     *
     * @param array<string, mixed> $tag
     */
    public static function forTag(array $tag, int $page = 1): SeoMetadata
    {
        return SeoMetadata::create(
            titleNl: self::archiveTitle(BlogContent::tagName($tag, LanguageRegistry::DUTCH), $page),
            titleEn: self::archiveTitle(BlogContent::tagName($tag, LanguageRegistry::ENGLISH), $page),
            canonical: AppUrl::canonical(BlogContent::tagUrl($tag, $page)),
            indexable: false,
            ogType: 'website',
        );
    }

    /**
     * Whether this post belongs in the sitemap: public, and not marked
     * noindex. The same decision its own robots tag makes, asked through one
     * method so the two cannot drift — the rule
     * App\Service\PageSeo::isIndexable() already sets for CMS pages.
     *
     * @param array<string, mixed> $post
     */
    public static function isIndexable(array $post): bool
    {
        if ((int) ($post['noindex'] ?? 0) === 1) {
            return false;
        }

        return BlogPostStatus::isPublic($post);
    }

    /* ------------------------------------------------------------------ */

    /** "<blog title> — <site name>", with the page number when there is one. */
    private static function listingTitle(string $blogTitle, int $page): string
    {
        $title = Seo::routeTitle($blogTitle);

        return $page > 1 ? $title . ' — pagina ' . $page : $title;
    }

    /** "<archive name> | <blog title> — <site name>". */
    private static function archiveTitle(string $name, int $page): string
    {
        $siteName = SeoDefaults::siteName();
        $blogTitle = BlogLocalizedSettings::title(LanguageRegistry::DUTCH);
        $name = trim($name);

        $title = $name === ''
            ? Seo::routeTitle($blogTitle)
            : $name . ' | ' . $blogTitle . ($siteName === '' ? '' : ' — ' . $siteName);

        return $page > 1 ? $title . ' — pagina ' . $page : $title;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function postTitle(array $post, string $lang): string
    {
        $own = BlogLocalization::post((int) ($post['id'] ?? 0), BlogLocalization::META_TITLE, $lang);

        if (trim($own) !== '') {
            return trim($own);
        }

        $siteName = SeoDefaults::siteName();
        $blogTitle = BlogLocalizedSettings::title($lang);
        $title = BlogContent::title($post, $lang);

        if ($title === '') {
            return Seo::routeTitle($blogTitle);
        }

        return $title . ' | ' . $blogTitle . ($siteName === '' ? '' : ' — ' . $siteName);
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function postDescription(array $post, string $lang): string
    {
        $own = BlogLocalization::post((int) ($post['id'] ?? 0), BlogLocalization::META_DESCRIPTION, $lang);

        if (trim($own) !== '') {
            return trim($own);
        }

        // The excerpt is the post's own summary, so it is the natural
        // fallback — the same relationship a product's description has to its
        // meta description. Never the body: a meta description built from the
        // opening of an article is what Seo::excerpt() is for, and
        // BlogContent::excerpt() already applies it.
        return Seo::plainText(BlogContent::excerpt($post, $lang));
    }

    /**
     * The post's own social image, then its featured image, then nothing —
     * SeoMetadata itself adds the site-wide default.
     *
     * @param array<string, mixed> $post
     */
    public static function socialImagePath(array $post): ?string
    {
        $own = MediaService::find(isset($post['og_media_id']) ? (int) $post['og_media_id'] : null);

        if ($own !== null) {
            return $own->path;
        }

        $featured = MediaService::find(isset($post['featured_media_id']) ? (int) $post['featured_media_id'] : null);

        return $featured?->path;
    }

    /**
     * The BlogPosting node, or null for a post that may not be indexed.
     *
     * Every field below is something the row genuinely holds. `author` is
     * omitted entirely rather than filled with the company name when no
     * byline was typed — a Person nobody named is exactly the invented fact
     * this project's SEO rules forbid. `publisher` carries the site's own
     * name and its logo when one is configured, which is the same
     * Organization data App\Service\PageSeo already publishes on the
     * homepage.
     *
     * @param array<string, mixed> $post
     *
     * @return array<string, mixed>|null
     */
    private static function postJsonLd(array $post): ?array
    {
        if (!self::isIndexable($post)) {
            return null;
        }

        $headline = BlogContent::title($post, LanguageRegistry::DUTCH);

        if ($headline === '') {
            return null;
        }

        // The URL this post is being read at, so the structured data, the
        // canonical tag and the link that led here all name one address.
        $url = BlogContent::postCanonical($post);

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $headline,
            'url' => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        ];

        $published = BlogClock::parse($post['published_at'] ?? null);
        if ($published !== null) {
            $data['datePublished'] = $published->format(\DateTimeInterface::ATOM);
        }

        $updated = BlogClock::parse($post['updated_at'] ?? null);
        if ($updated !== null) {
            $data['dateModified'] = $updated->format(\DateTimeInterface::ATOM);
        }

        $description = Seo::plainText(BlogContent::excerpt($post, LanguageRegistry::DUTCH));
        if ($description !== '') {
            $data['description'] = $description;
        }

        $image = Seo::absoluteImageUrl(self::socialImagePath($post));
        if ($image !== null) {
            $data['image'] = $image;
        }

        $author = trim((string) ($post['author_name'] ?? ''));
        if ($author !== '') {
            $data['author'] = ['@type' => 'Person', 'name' => $author];
        }

        $siteName = SeoDefaults::siteName();
        if ($siteName !== '') {
            $publisher = ['@type' => 'Organization', 'name' => $siteName];

            $logo = Seo::absoluteImageUrl(Branding::logoPath());
            if ($logo !== null) {
                $publisher['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
            }

            $data['publisher'] = $publisher;
        }

        return $data;
    }
}
