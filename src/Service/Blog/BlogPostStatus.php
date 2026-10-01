<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\Language\AdminTranslator;
use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\PublicationVisibility;

/**
 * The four states a blog post can be in, and the rules that turn a state
 * plus a moment into "is this listed" and "does its address answer".
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
 *
 * SINCE v0.1.15 the Blog is the first kind on the Publishing Engine
 * (docs/publishing/ARCHITECTURE.md): these values are App\Service\Publishing\PublicationStatus's,
 * and isPublic()/isPending() are PublicationVisibility's "listed" rule.
 *
 * ARCHIVED, since Blog 2.0: the post's own address keeps answering, as
 * noindex (BlogSeo::isIndexable() follows isPublic()), but it is listed
 * nowhere — not in the overview, an archive, the feed, the sitemap, search or
 * related posts. isReachable() is the detail route's question; isPublic() is
 * every listing's. Archiving is not deleting and never redirects.
 */
final class BlogPostStatus
{
    public const DRAFT = PublicationStatus::DRAFT;
    public const PUBLISHED = PublicationStatus::PUBLISHED;
    public const SCHEDULED = PublicationStatus::SCHEDULED;
    public const ARCHIVED = PublicationStatus::ARCHIVED;

    /** @var list<string> */
    public const ALL = [self::DRAFT, self::PUBLISHED, self::SCHEDULED, self::ARCHIVED];

    /** @var array<string, string> Dutch labels, in the order the admin lists them */
    public const LABELS = [
        self::DRAFT => 'Concept',
        self::PUBLISHED => 'Gepubliceerd',
        self::SCHEDULED => 'Ingepland',
        self::ARCHIVED => 'Gearchiveerd',
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
        return PublicationVisibility::isListed(self::normalize($post['status'] ?? null), $post['published_at'] ?? null, $now);
    }

    /**
     * Whether this post's own address answers at $now: listed, or archived
     * once it had gone out. The detail route, a slug redirect and a block's
     * button ask this; nothing that LISTS posts does.
     *
     * @param array<string, mixed> $post
     */
    public static function isReachable(array $post, ?\DateTimeImmutable $now = null): bool
    {
        return PublicationVisibility::isReachable(self::normalize($post['status'] ?? null), $post['published_at'] ?? null, $now);
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
        return PublicationVisibility::isPending(self::normalize($post['status'] ?? null), $post['published_at'] ?? null, $now);
    }
}
