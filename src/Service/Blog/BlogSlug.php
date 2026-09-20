<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\PageService;
use App\Service\Routing\RouteSegments;

/**
 * Slugs for posts, categories and tags.
 *
 * IT REUSES CORE'S SANITISER rather than writing a second one:
 * App\Service\PageService::sanitizeSlug() is the rule every CMS page slug in
 * this project has always been through (lowercase, ASCII-transliterated,
 * runs of anything else collapsed to a single "-", trimmed). A blog URL that
 * normalised differently from a page URL would be a second convention for the
 * same problem, and .htaccess matches both with the same `[a-z0-9-]` pattern.
 *
 * WHAT IS DIFFERENT FROM A PAGE SLUG. A page slug competes with every
 * application route at the root of the site, which is why
 * App\Service\ReservedRoutes exists. Every blog URL is namespaced under
 * /blog/, so a post slug can only ever collide with another post — the
 * database's unique index is the real enforcement, and uniqueness here is a
 * helpful suffix rather than a security rule.
 *
 * The one namespace collision that IS possible is with the Blog's own two
 * sub-paths: /blog/categorie/<slug> and /blog/tag/<slug>. A post slugged
 * "categorie" would still resolve (a two-segment URL never matches the
 * three-segment rewrite), but /blog/categorie would then mean two things
 * depending on how many segments followed it, which is exactly the kind of
 * URL nobody should have to reason about. Those words are therefore refused.
 *
 * SINCE MULTILINGUAL 2.0 PHASE 6 those sub-paths are words per language: the
 * category archive is /blog/categorie/… in Dutch and /en/blog/category/… in
 * English (App\Service\Routing\RouteSegments). Both spellings have to be
 * refused for exactly the same reason, so the list below is joined with every
 * word the Blog's own segments can take — asked of the one catalogue the
 * router matches against, never spelled a second time here.
 */
final class BlogSlug
{
    /** Matches the `slug` column width on `blog_posts` and `blog_categories`. */
    public const MAX_LENGTH = 170;

    /** `blog_tags.slug` and `blog_tag_translations.slug` are narrower. */
    public const TAG_MAX_LENGTH = 120;

    /**
     * Words the Blog's own routing owns inside /blog/. Not a security list:
     * refusing them keeps one URL from meaning two things.
     *
     * The Dutch spellings are written out because two of them are not route
     * segments at all: `feed`/`feed.xml` is a fixed file name and `pagina` is
     * the pagination parameter. The localized ones come from the catalogue;
     * see reservedSegments().
     *
     * @var list<string>
     */
    public const RESERVED_SEGMENTS = ['categorie', 'tag', 'feed', 'feed.xml', 'pagina'];

    /**
     * The canonical form of whatever an editor typed, or '' when nothing
     * survives.
     *
     * $maxLength is the width of the column this slug is going into: 170 for
     * a post or a category, App\Service\Blog\BlogSlug::TAG_MAX_LENGTH for a
     * tag. Cutting here rather than at the database means a too-long slug is
     * shortened the same way in every language, instead of being refused in
     * one and truncated in another.
     */
    public static function sanitize(string $raw, int $maxLength = self::MAX_LENGTH): string
    {
        return substr(PageService::sanitizeSlug($raw), 0, $maxLength);
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::reservedSegments(), true);
    }

    /**
     * Every word a blog slug may not be: the fixed ones above plus each
     * language's spelling of the Blog's own sub-paths.
     *
     * @return list<string>
     */
    public static function reservedSegments(): array
    {
        $words = self::RESERVED_SEGMENTS;

        foreach (['blog.category', 'blog.tag'] as $key) {
            foreach (RouteSegments::words($key) as $word) {
                $words[] = $word;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * A usable, unique slug: the editor's own if it survives sanitising, the
     * title otherwise, with -2, -3 … appended until nothing else claims it.
     *
     * $exists answers "is this slug taken by somebody other than $excludeId",
     * and is the repository's own uniqueness query — this class does no SQL.
     *
     * @param callable(string): bool $exists
     */
    public static function unique(
        string $preferred,
        string $fallbackTitle,
        callable $exists,
        int $maxLength = self::MAX_LENGTH
    ): string {
        $base = self::sanitize($preferred, $maxLength);

        if ($base === '') {
            $base = self::sanitize($fallbackTitle, $maxLength);
        }

        if ($base === '' || self::isReserved($base)) {
            // A post has to be reachable, so an unusable slug becomes a
            // stable, obviously-generated one rather than an error the editor
            // cannot act on. They can always type a better one.
            $base = 'bericht';
        }

        $base = substr($base, 0, $maxLength - 8);

        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * The admin-facing reason a hand-typed slug cannot be used, or null when
     * it is fine.
     *
     * @param callable(string): bool $exists
     */
    public static function validationError(string $slug, callable $exists): ?string
    {
        if ($slug === '') {
            return 'De URL (slug) kan niet leeg zijn.';
        }

        if (self::isReserved($slug)) {
            return 'Deze slug is gereserveerd voor de blogindeling zelf (' . implode(', ', self::reservedSegments()) . ').';
        }

        if ($exists($slug)) {
            return 'Deze slug is al in gebruik.';
        }

        return null;
    }
}
