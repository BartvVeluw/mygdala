<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\PageService;

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
 */
final class BlogSlug
{
    /** Matches the `slug` column width on `blog_posts` and `blog_categories`. */
    public const MAX_LENGTH = 170;

    /**
     * Words the Blog's own routing owns inside /blog/. Not a security list:
     * refusing them keeps one URL from meaning two things.
     *
     * @var list<string>
     */
    public const RESERVED_SEGMENTS = ['categorie', 'tag', 'feed', 'feed.xml', 'pagina'];

    /** The canonical form of whatever an editor typed, or '' when nothing survives. */
    public static function sanitize(string $raw): string
    {
        return substr(PageService::sanitizeSlug($raw), 0, self::MAX_LENGTH);
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::RESERVED_SEGMENTS, true);
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
    public static function unique(string $preferred, string $fallbackTitle, callable $exists): string
    {
        $base = self::sanitize($preferred);

        if ($base === '') {
            $base = self::sanitize($fallbackTitle);
        }

        if ($base === '' || self::isReserved($base)) {
            // A post has to be reachable, so an unusable slug becomes a
            // stable, obviously-generated one rather than an error the editor
            // cannot act on. They can always type a better one.
            $base = 'bericht';
        }

        $base = substr($base, 0, self::MAX_LENGTH - 8);

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
            return 'Deze slug is gereserveerd voor de blogindeling zelf (' . implode(', ', self::RESERVED_SEGMENTS) . ').';
        }

        if ($exists($slug)) {
            return 'Deze slug is al in gebruik.';
        }

        return null;
    }
}
