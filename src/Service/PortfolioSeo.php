<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\SiteLanguages;
use App\Service\Language\SiteText;
use App\Service\Routing\LocalizedUrl;

/**
 * The metadata of a Portfolio project page (portfolio-detail.php), the
 * counterpart of App\Service\ProductSeo and App\Service\Blog\BlogSeo: resolved
 * here, rendered by the one shared partial (partials/seo-head.php) through
 * App\Service\SeoMetadata, so nothing about a project's <head> is written by
 * hand in the template.
 *
 *   title        "<project> | Portfolio — <site name>", the convention this
 *                page has always had
 *   description  the project's short text, else the opening of its intro,
 *                else of its description (Seo::excerpt())
 *   canonical    its own address in the language being read
 *                (docs/multilingual/ROUTING.md, §10)
 *   social image its main picture, which is what a visitor sees first; a
 *                project without one falls back to the site-wide default,
 *                the chain a product follows
 *
 * A project page is not a CMS page, so it has no meta fields of its own to
 * override these with; its words are the item's (MODULES.md, "Portfolio").
 */
final class PortfolioSeo
{
    /**
     * @param array<string, mixed> $project PortfolioGalleryContent::itemForDetailPage()
     */
    public static function forProject(array $project): SeoMetadata
    {
        $title = trim((string) $project['title']);
        if ($title === '') {
            $title = SiteText::pick(['nl' => 'Project', 'en' => 'Project']);
        }

        return SeoMetadata::create(
            title: $title . ' | Portfolio — ' . SeoDefaults::siteName(),
            description: self::description($project),
            canonical: PortfolioGalleryContent::canonicalUrlForSlug((string) $project['slug']),
            // og:type stays "website", the value this page has always emitted —
            // the same call product.php makes, for the same reason (SEO.md).
            socialImage: (string) ($project['image_path'] ?? ''),
        );
    }

    /** The metadata of an address no project answers. */
    public static function notFound(): SeoMetadata
    {
        return SeoMetadata::notFound(
            SiteText::pick(['nl' => 'Project niet gevonden', 'en' => 'Project not found']) . ' — ' . SeoDefaults::siteName()
        );
    }

    /**
     * Every language version of a project page: one neutral slug, answered
     * under every active language's prefix, each naming the others for
     * hreflang and the language switch.
     *
     * @return array<string, string> language code => site-relative path
     */
    public static function alternates(string $slug): array
    {
        $versions = [];
        foreach (SiteLanguages::activeCodes() as $language) {
            $versions[$language] = LocalizedUrl::path(PortfolioGalleryContent::publicPath($slug), $language);
        }

        return $versions;
    }

    /**
     * @param array<string, mixed> $project
     */
    private static function description(array $project): string
    {
        $short = trim((string) ($project['subtitle'] ?? ''));
        if ($short !== '') {
            return $short;
        }

        foreach (['intro', 'description'] as $field) {
            $excerpt = Seo::excerpt((string) ($project[$field] ?? ''));
            if ($excerpt !== '') {
                return $excerpt;
            }
        }

        return '';
    }
}
