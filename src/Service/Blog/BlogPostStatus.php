<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\Language\AdminTranslator;

/**
 * The three states a blog post can be in, and the one rule that turns a
 * state plus a moment into "is this public right now".
 *
 * A CLOSED SET, like every other vocabulary in this project: a stored value
 * that is not one of these is treated as a draft, so a hand-edited row or a
 * crafted POST can only ever make a post less visible, never more.
 *
 *   draft      never public. No publication date is required, and one that is
 *              set is simply remembered for later.
 *   scheduled  public from `published_at` onwards, and not one second before.
 *   published  public, with `published_at` recording when it went out.
 *
 * WHY SCHEDULED AND PUBLISHED SHARE ONE PREDICATE. isPublic() asks the same
 * two questions of both: not a draft, and a publication moment that has
 * passed. That is what makes scheduling work without a cron job, a queue or a
 * background worker — the post becomes visible because the clock moved, not
 * because something ran. The distinction between the two states is editorial:
 * "I published this" versus "I set this to go out", and the admin overview
 * shows them apart.
 *
 * WHOSE CLOCK. PHP's, always — never MySQL's NOW(). The publication moment is
 * written by the admin form from PHP's clock, and on shared hosting PHP and
 * MySQL are configured with their own timezones (the same reason
 * App\Service\Sitemap publishes dates rather than timestamps). Comparing a
 * value against the clock that wrote it is the only comparison that cannot be
 * an hour wrong, so every query takes `now` as a bound parameter from
 * App\Service\Blog\BlogClock.
 */
final class BlogPostStatus
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const SCHEDULED = 'scheduled';

    /** @var list<string> */
    public const ALL = [self::DRAFT, self::PUBLISHED, self::SCHEDULED];

    /** @var array<string, string> Dutch labels, in the order the admin lists them */
    public const LABELS = [
        self::DRAFT => 'Concept',
        self::PUBLISHED => 'Gepubliceerd',
        self::SCHEDULED => 'Ingepland',
    ];

    /** Any value that is not a known status IS a draft. */
    public static function normalize(mixed $status): string
    {
        $value = strtolower(trim((string) $status));

        return in_array($value, self::ALL, true) ? $value : self::DRAFT;
    }

    public static function isValid(mixed $status): bool
    {
        $value = strtolower(trim((string) $status));

        return in_array($value, self::ALL, true);
    }

    public static function label(mixed $status): string
    {
        // The DISPLAY word only; the stored value stays 'draft'.
        return AdminTranslator::trans('status.blog_' . self::normalize($status));
    }

    /**
     * Whether a post row is public at $now.
     *
     * The single visibility rule of this whole module: the listing, the detail
     * route, the archives, the related posts, the sitemap and the RSS feed all
     * ask it (through the repository's SQL twin of it), so none of them can
     * disagree about whether a post is out.
     *
     * @param array<string, mixed> $post
     */
    public static function isPublic(array $post, ?\DateTimeImmutable $now = null): bool
    {
        if (self::normalize($post['status'] ?? null) === self::DRAFT) {
            return false;
        }

        $publishedAt = BlogClock::parse($post['published_at'] ?? null);

        if ($publishedAt === null) {
            return false;
        }

        return $publishedAt <= ($now ?? BlogClock::now());
    }

    /**
     * Whether this post is waiting for its moment: not a draft, but its
     * publication date has not arrived. What the admin overview shows as
     * "Ingepland — gaat live op …".
     *
     * @param array<string, mixed> $post
     */
    public static function isPending(array $post, ?\DateTimeImmutable $now = null): bool
    {
        if (self::normalize($post['status'] ?? null) === self::DRAFT) {
            return false;
        }

        return !self::isPublic($post, $now);
    }
}
