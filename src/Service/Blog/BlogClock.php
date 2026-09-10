<?php

declare(strict_types=1);

namespace App\Service\Blog;

/**
 * The Blog's one reading of "now", and the one place a stored datetime is
 * parsed.
 *
 * Two reasons this is a class rather than a call to time() spread over ten
 * files:
 *
 *   1. ONE CLOCK. Publication is a comparison between a moment an editor
 *      typed and the current moment. The admin form writes `published_at`
 *      from PHP, so PHP must also be what reads it back — MySQL's NOW() is a
 *      different clock with its own timezone on shared hosting, and a
 *      scheduled post that goes out an hour early or late is exactly the bug
 *      that would cause. Every query therefore binds this value as a
 *      parameter instead of calling NOW().
 *   2. TESTABLE BOUNDARIES. The interesting cases of a scheduling feature are
 *      one second before and one second after the moment. freezeForTests()
 *      makes those two assertions instead of a sleep().
 *
 * There is deliberately no timezone configuration here: the application uses
 * whatever PHP is configured with, which is the timezone the editor's own
 * form values are in. Introducing a second one would create the very
 * mismatch this class exists to avoid.
 */
final class BlogClock
{
    /** The format `published_at` is stored and compared in. */
    public const SQL_FORMAT = 'Y-m-d H:i:s';

    private static ?\DateTimeImmutable $frozen = null;

    /** The current moment, or whatever a test pinned it to. */
    public static function now(): \DateTimeImmutable
    {
        return self::$frozen ?? new \DateTimeImmutable('now');
    }

    /** The current moment as MySQL sees it in a bound parameter. */
    public static function nowForSql(): string
    {
        return self::now()->format(self::SQL_FORMAT);
    }

    /**
     * A stored datetime as an object, or null when there is nothing usable:
     * NULL, an empty string, MySQL's zero date, or anything that does not
     * parse. Null always means "no publication moment", which means not
     * public — the safe direction.
     */
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A datetime an editor typed in the admin form (`<input type="datetime-local">`
     * sends "Y-m-d\TH:i"), normalised for storage — or null when the field was
     * left empty or holds something unusable.
     */
    public static function fromFormInput(mixed $value): ?string
    {
        $parsed = self::parse(is_string($value) ? str_replace('T', ' ', trim($value)) : $value);

        return $parsed?->format(self::SQL_FORMAT);
    }

    /** The value a `datetime-local` input needs to show a stored moment. */
    public static function forFormInput(mixed $value): string
    {
        return self::parse($value)?->format('Y-m-d\TH:i') ?? '';
    }

    /** A stored moment as Dutch date + time for the admin, or ''. */
    public static function forAdmin(mixed $value): string
    {
        return self::parse($value)?->format('d-m-Y H:i') ?? '';
    }

    /** RFC 2822, which is what an RSS <pubDate> is. */
    public static function forRss(mixed $value): ?string
    {
        return self::parse($value)?->format(\DateTimeInterface::RSS);
    }

    /**
     * Test seam: pin "now". Always reset it in tearDown() by passing null —
     * the value is static and outlives one test.
     */
    public static function freezeForTests(?\DateTimeImmutable $moment): void
    {
        self::$frozen = $moment;
    }
}
