<?php

declare(strict_types=1);

namespace App\Service\Redirects;

/**
 * The closed vocabularies of the Redirect Manager: which status codes exist,
 * which kinds of destination exist, and why a row exists. Same convention as
 * App\Service\PageContent::STATUSES and App\Service\LinkResolver::LINK_TYPES —
 * a value that is not in one of these lists never reaches the database, and
 * nothing outside this class decides what a valid value is.
 *
 * WHY ONLY 301 AND 302. Those are the two an editor can reason about: "this
 * URL moved for good" and "this URL is borrowed for now". 307 and 308 differ
 * only in whether the browser is allowed to turn a POST into a GET, and this
 * site has no POST endpoint sitting behind a legacy path — every form posts to
 * /api/..., which is a reserved namespace a redirect source can never claim.
 * Adding them would be two more options in a dropdown and no behaviour.
 *
 * WHY ORIGIN EXISTS. A redirect created by renaming a CMS page is not hidden
 * plumbing: it shows up in the same list, labelled, so an editor who wonders
 * where /diensten went can see that it was a rename and not somebody's typo.
 * It is also the flag that decides whether a later rename may rewrite a row —
 * App\Service\Redirects\SlugChangeRedirects never overwrites a destination a
 * human chose.
 */
final class Redirect
{
    public const STATUS_PERMANENT = 301;
    public const STATUS_TEMPORARY = 302;

    /** @var list<int> */
    public const STATUS_CODES = [self::STATUS_PERMANENT, self::STATUS_TEMPORARY];

    /** Manual redirects default to permanent; a rename is always permanent. */
    public const DEFAULT_STATUS = self::STATUS_PERMANENT;

    /** @var array<int, string> Admin-facing labels, Dutch like the rest of the CMS. */
    public const STATUS_LABELS = [
        self::STATUS_PERMANENT => '301 — permanent',
        self::STATUS_TEMPORARY => '302 — tijdelijk',
    ];

    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_SLUG_CHANGE = 'slug_change';

    /** @var list<string> */
    public const ORIGINS = [self::ORIGIN_MANUAL, self::ORIGIN_SLUG_CHANGE];

    /** @var array<string, string> */
    public const ORIGIN_LABELS = [
        self::ORIGIN_MANUAL => 'Handmatig',
        self::ORIGIN_SLUG_CHANGE => 'Automatisch (slug gewijzigd)',
    ];

    /**
     * How many hops a chain may be followed before the resolver gives up.
     * Small on purpose: a legitimate chain is one or two long (a page renamed
     * twice), and anything deeper is either a mistake or a loop somebody built
     * one row at a time. See RedirectResolver.
     */
    public const MAX_CHAIN_DEPTH = 5;

    public static function isValidStatusCode(int $status): bool
    {
        return in_array($status, self::STATUS_CODES, true);
    }

    public static function isValidOrigin(string $origin): bool
    {
        return in_array($origin, self::ORIGINS, true);
    }

    public static function statusLabel(int $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    public static function originLabel(string $origin): string
    {
        return self::ORIGIN_LABELS[$origin] ?? $origin;
    }
}
