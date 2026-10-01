<?php

declare(strict_types=1);

namespace App\Service\Publishing;

/**
 * THE visibility rule of everything that publishes, once in PHP and once in
 * SQL, each the mirror of the other (docs/publishing/ARCHITECTURE.md, "Zichtbaarheid").
 *
 * Two questions, because archived content answers them differently:
 *
 *   listed     may it appear in an overview, an archive, the sitemap, a feed,
 *              search or "related"? Published or scheduled, with a moment
 *              that is set and has passed.
 *   reachable  may its own address answer? Listed, or archived with a moment
 *              that has passed. Never a draft, never something that has not
 *              gone out yet.
 *
 * Nothing else in the project may write `status = 'published' OR …` itself:
 * an owner's repository splices listedSql()/reachableSql() into its query
 * and binds `now` from PublishingClock exactly once. The admin never uses
 * either — an editor sees every row, whatever its state, through the owner's
 * own admin queries. That is what keeps a preview from leaking into the
 * public scope and the public scope from hiding a draft from its editor.
 *
 * SQL fragments contain only this class's own constants and a validated
 * alias; nothing from a request or a row goes into them.
 */
final class PublicationVisibility
{
    public static function isListed(mixed $status, mixed $publishedAt, ?\DateTimeImmutable $now = null): bool
    {
        return in_array(PublicationStatus::normalize($status), PublicationStatus::LISTED, true)
            && self::hasPassed($publishedAt, $now);
    }

    public static function isReachable(mixed $status, mixed $publishedAt, ?\DateTimeImmutable $now = null): bool
    {
        return in_array(PublicationStatus::normalize($status), PublicationStatus::REACHABLE, true)
            && self::hasPassed($publishedAt, $now);
    }

    /**
     * Waiting for its moment: listed or archived by state, but the moment has
     * not come (or was never set). What an admin overview shows as "nog niet
     * zichtbaar".
     */
    public static function isPending(mixed $status, mixed $publishedAt, ?\DateTimeImmutable $now = null): bool
    {
        return PublicationStatus::normalize($status) !== PublicationStatus::DRAFT
            && !self::hasPassed($publishedAt, $now);
    }

    /**
     * The SQL twin of isListed() for a table aliased $alias, with columns
     * `status` and `published_at`, comparing against the named parameter
     * $parameter (bind PublishingClock::nowForSql() to it once).
     */
    public static function listedSql(string $alias, string $parameter = 'now'): string
    {
        return self::sql($alias, $parameter, PublicationStatus::LISTED);
    }

    /** The SQL twin of isReachable(); see listedSql(). */
    public static function reachableSql(string $alias, string $parameter = 'now'): string
    {
        return self::sql($alias, $parameter, PublicationStatus::REACHABLE);
    }

    private static function hasPassed(mixed $publishedAt, ?\DateTimeImmutable $now): bool
    {
        $moment = PublishingClock::parse($publishedAt);

        return $moment !== null && $moment <= ($now ?? PublishingClock::now());
    }

    /**
     * @param list<string> $statuses
     */
    private static function sql(string $alias, string $parameter, array $statuses): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,30}$/', $alias) !== 1 || preg_match('/^[a-z_][a-z0-9_]{0,30}$/', $parameter) !== 1) {
            throw new \InvalidArgumentException('Not an SQL alias or parameter name.');
        }

        return $alias . ".status IN ('" . implode("', '", $statuses) . "')"
            . ' AND ' . $alias . '.published_at IS NOT NULL'
            . ' AND ' . $alias . '.published_at <= :' . $parameter;
    }
}
