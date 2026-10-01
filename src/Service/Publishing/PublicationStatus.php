<?php

declare(strict_types=1);

namespace App\Service\Publishing;

/**
 * The closed vocabulary of publication states, shared by every kind of
 * content that publishes (docs/publishing/ARCHITECTURE.md, "Status").
 *
 *   draft      never public, whatever the date. A date may be remembered.
 *   scheduled  listed and reachable from `published_at` onwards, not one
 *              second before. Nothing runs at that moment: the clock moves.
 *   published  listed and reachable once `published_at` has passed (which
 *              it normally has: publishing without a date means "now").
 *   archived   no longer listed anywhere — overview, archives, sitemap,
 *              feed, search, related items — but still REACHABLE at its own
 *              address, as noindex. Archiving stops promoting something; it
 *              does not break the links people already have. Taking it
 *              offline is draft, and removing it is deleting it.
 *
 * Stored as these exact strings in the owner's own `status` column. A value
 * that is not one of them READS as a draft, so a hand-edited row or a forged
 * request can only ever make content less visible. An incoming value that is
 * not one of them is REFUSED (PublicationRules), never quietly saved as one.
 *
 * Not every kind offers every state: a provider says which it supports
 * (Publishable::statuses()). The Blog, for one, has no archive yet.
 */
final class PublicationStatus
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const SCHEDULED = 'scheduled';
    public const ARCHIVED = 'archived';

    /** @var list<string> in the order an editor is offered them */
    public const ALL = [self::DRAFT, self::PUBLISHED, self::SCHEDULED, self::ARCHIVED];

    /** The states that list content: in overviews, the sitemap, feeds and search. */
    public const LISTED = [self::PUBLISHED, self::SCHEDULED];

    /** The states that may answer at their own address once their moment has passed. */
    public const REACHABLE = [self::PUBLISHED, self::SCHEDULED, self::ARCHIVED];

    /** Any value that is not a known status IS a draft. */
    public static function normalize(mixed $status): string
    {
        $value = is_string($status) ? strtolower(trim($status)) : '';

        return in_array($value, self::ALL, true) ? $value : self::DRAFT;
    }

    public static function isValid(mixed $status): bool
    {
        return is_string($status) && in_array(strtolower(trim($status)), self::ALL, true);
    }

    /** The admin's word for a status (catalog key `status.publication_<status>`). */
    public static function labelKey(mixed $status): string
    {
        return 'status.publication_' . self::normalize($status);
    }
}
