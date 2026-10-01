<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Service\AppUrl;
use App\Service\Branding;
use App\Service\Language\SiteText;
use App\Service\Media\MediaService;
use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\PublicationVisibility;
use App\Service\Publishing\PublishingClock;
use App\Service\Seo;
use App\Service\SeoDefaults;
use App\Service\SeoMetadata;

/**
 * The SeoMetadata of the Articles pages (SEO.md), rendered by
 * partials/seo-head.php like every other page. No second SEO model.
 *
 *   an article   its own SEO title, else "<title> | Artikelen — <site>";
 *                its own description, else its intro; canonical = its own
 *                address in the language read; noindex when the editor
 *                ticked it OR the article is archived (reachable, not
 *                listed — docs/publishing/ARCHITECTURE.md); og:type
 *                "article" and an Article JSON-LD node
 *   the listing  "Artikelen — <site>", indexable
 *   a topic      "<topic> | Artikelen — <site>", its description, indexable
 */
final class ArticleSeo
{
    /** The listing's name, also its heading (no settings screen in 1.0). */
    public const LISTING_TITLE = ['nl' => 'Artikelen', 'en' => 'Articles'];

    /** @param array<string, mixed> $article a decorated article (ArticleContent) */
    public static function forArticle(array $article, string $language): SeoMetadata
    {
        $id = (int) $article['id'];
        $title = trim(ArticleLocalization::word($id, ArticleLocalization::META_TITLE, $language));
        if ($title === '') {
            $title = self::titled((string) ($article['title'] ?? ''), $language);
        }

        $description = trim(ArticleLocalization::word($id, ArticleLocalization::META_DESCRIPTION, $language));
        if ($description === '') {
            $description = Seo::plainText((string) ($article['excerpt'] ?? ''));
        }

        return SeoMetadata::create(
            title: $title,
            description: $description,
            canonical: (string) ($article['canonical_url'] ?? '') ?: null,
            indexable: self::isIndexable($article),
            ogType: 'article',
            socialImage: MediaService::find((int) ($article['featured_media_id'] ?? 0))?->path,
            jsonLd: self::jsonLd($article, $language),
        );
    }

    public static function forListing(int $page, string $language): SeoMetadata
    {
        $title = Seo::routeTitle(SiteText::pick(self::LISTING_TITLE, $language));

        return SeoMetadata::create(
            title: $page > 1 ? $title . self::pageSuffix($page, $language) : $title,
            canonical: AppUrl::canonical(ArticleUrls::indexPath($page, $language)),
        );
    }

    /** @param array<string, mixed> $topic a decorated topic (ArticleContent) */
    public static function forTopic(array $topic, int $page, string $language): SeoMetadata
    {
        $title = self::titled((string) $topic['name'], $language);
        $paths = ArticleContent::topicAlternates((int) $topic['id'], $page);

        return SeoMetadata::create(
            title: $page > 1 ? $title . self::pageSuffix($page, $language) : $title,
            description: Seo::plainText((string) ($topic['description'] ?? '')),
            canonical: isset($paths[$language]) ? AppUrl::canonical($paths[$language]) : null,
        );
    }

    /**
     * In the sitemap and indexable: listed (not archived, not waiting) and
     * not marked noindex. The robots tag of the page asks the same.
     *
     * @param array<string, mixed> $article an `articles` row
     */
    public static function isIndexable(array $article): bool
    {
        return (int) ($article['noindex'] ?? 0) !== 1
            && PublicationStatus::normalize($article['status'] ?? null) !== PublicationStatus::ARCHIVED
            && PublicationVisibility::isListed($article['status'] ?? null, $article['published_at'] ?? null);
    }

    private static function titled(string $name, string $language): string
    {
        $name = trim($name);
        $listing = SiteText::pick(self::LISTING_TITLE, $language);
        $siteName = SeoDefaults::siteName();

        if ($name === '') {
            return Seo::routeTitle($listing);
        }

        return $name . ' | ' . $listing . ($siteName === '' ? '' : ' — ' . $siteName);
    }

    private static function pageSuffix(int $page, string $language): string
    {
        return ' — ' . SiteText::pick(['nl' => 'pagina', 'en' => 'page'], $language) . ' ' . $page;
    }

    /**
     * An Article node with only what the row really holds. No author when
     * the byline is empty: a Person nobody named is an invented fact.
     *
     * @param array<string, mixed> $article
     * @return array<string, mixed>|null
     */
    private static function jsonLd(array $article, string $language): ?array
    {
        $headline = trim((string) ($article['title'] ?? ''));
        $url = (string) ($article['canonical_url'] ?? '');

        if (!self::isIndexable($article) || $headline === '' || $url === '') {
            return null;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $headline,
            'url' => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'inLanguage' => $language,
        ];

        $published = PublishingClock::forAtom($article['published_at'] ?? null);
        if ($published !== '') {
            $data['datePublished'] = $published;
        }

        $updated = PublishingClock::forAtom($article['updated_at'] ?? null);
        if ($updated !== '') {
            $data['dateModified'] = $updated;
        }

        $description = Seo::plainText((string) ($article['excerpt'] ?? ''));
        if ($description !== '') {
            $data['description'] = $description;
        }

        $image = Seo::absoluteImageUrl(MediaService::find((int) ($article['featured_media_id'] ?? 0))?->path);
        if ($image !== null) {
            $data['image'] = $image;
        }

        $author = trim((string) ($article['author_name'] ?? ''));
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
