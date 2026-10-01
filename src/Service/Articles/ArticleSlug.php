<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Service\Language\AdminTranslator;
use App\Service\PageService;
use App\Service\Routing\RouteSegments;

/**
 * The address of an article or a topic in one language: the last segment of
 * /artikelen/<slug> or /artikelen/onderwerp/<slug> (ARTICLES.md).
 *
 * Normalised like a page's slug (PageService::sanitizeSlug()), unique PER
 * LANGUAGE inside its own table (UNIQUE(language_code, slug)), because
 * /artikelen/x and /en/articles/x are different URLs. The module's own
 * namespace keeps the site's reserved words out of the way; only the topic
 * segment ("onderwerp", "topic") and the pager word may not be an article.
 */
final class ArticleSlug
{
    /** The width of both `slug` columns (db/migrations/20261012100000). */
    public const MAX_LENGTH = 170;

    private const RESERVED = ['pagina'];

    public static function sanitize(string $raw): string
    {
        return substr(PageService::sanitizeSlug($raw), 0, self::MAX_LENGTH);
    }

    public static function isReserved(string $slug): bool
    {
        $words = self::RESERVED;
        foreach (RouteSegments::words(ArticleUrls::TOPIC_SEGMENT_KEY) as $word) {
            $words[] = $word;
        }

        return in_array(strtolower(trim($slug)), $words, true);
    }

    /**
     * A usable, unique slug from what was typed, else from the title, with
     * -2, -3 … until $taken says no.
     *
     * @param callable(string): bool $taken
     */
    public static function unique(string $preferred, string $title, callable $taken): string
    {
        $base = self::sanitize($preferred);

        if ($base === '') {
            $base = self::sanitize($title);
        }

        if ($base === '' || self::isReserved($base)) {
            $base = 'artikel';
        }

        $base = substr($base, 0, self::MAX_LENGTH - 8);
        $slug = $base;
        $suffix = 2;

        while ($taken($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Why a typed slug cannot be used, for the editor, or null.
     *
     * @param callable(string): bool $taken
     */
    public static function problem(string $slug, callable $taken): ?string
    {
        if ($slug === '') {
            return AdminTranslator::trans('articles.error.slug_empty');
        }

        if (self::isReserved($slug)) {
            return AdminTranslator::trans('articles.error.slug_reserved');
        }

        if ($taken($slug)) {
            return AdminTranslator::trans('articles.error.slug_taken');
        }

        return null;
    }
}
