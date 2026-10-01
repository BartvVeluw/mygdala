<?php

declare(strict_types=1);

namespace App\Service\Publishing;

/**
 * The one reading of "now" for everything that publishes, and the one place
 * a publication moment is parsed or formatted (docs/publishing/ARCHITECTURE.md, "Tijd").
 *
 * Grown out of App\Service\Blog\BlogClock, which now delegates here, so a
 * blog post and a future article can never disagree about what time it is.
 *
 * THE TIMEZONE CONTRACT. A publication moment is stored as a naive
 * `Y-m-d H:i:s` in PHP's configured timezone (date_default_timezone_get()),
 * and compared against this clock, never against MySQL's NOW(): on shared
 * hosting PHP and MySQL have their own timezones, and comparing a value with
 * the clock that wrote it is the only comparison that cannot be an hour off.
 * Every query therefore binds nowForSql() as a parameter. The editor's
 * `datetime-local` input is read in that same zone. There is no second
 * timezone setting, on purpose.
 *
 * Daylight saving, in a zone that has it: a local time that occurs twice
 * (the autumn hour) is the SECOND of the two (standard time, as PHP 8.2
 * reads it), and a local time that does not
 * exist (the spring hour) moves forward by the gap — PHP's own rules, and the
 * same for writing and reading, so nothing ever compares two readings of one
 * typed value.
 *
 * freezeForTests() pins "now", so the boundary — one second before and after
 * a moment — is an assertion instead of a sleep().
 */
final class PublishingClock
{
    /** The format a publication moment is stored and compared in. */
    public const SQL_FORMAT = 'Y-m-d H:i:s';

    /**
     * What an editor's date field may send: the `datetime-local` shape, with
     * or without seconds, or the stored shape. Nothing relative ("tomorrow"),
     * nothing with a zone of its own.
     */
    private const INPUT_FORMATS = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'];

    private static ?\DateTimeImmutable $frozen = null;

    /** The current moment in PHP's timezone, or whatever a test pinned it to. */
    public static function now(): \DateTimeImmutable
    {
        $now = self::$frozen ?? new \DateTimeImmutable('now');

        return $now->setTimezone(self::zone());
    }

    /** The current moment as a bound SQL parameter. */
    public static function nowForSql(): string
    {
        return self::now()->format(self::SQL_FORMAT);
    }

    /**
     * A STORED moment as an object, or null when there is none: NULL, '',
     * MySQL's zero date, or anything that does not parse. Null always means
     * "no publication moment", which means not public — the safe direction.
     */
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(self::zone());
        }

        $raw = trim((string) ($value ?? ''));

        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw, self::zone());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What an editor TYPED, strictly: one of INPUT_FORMATS and a real
     * calendar moment (no 30 February), normalised for storage. Null for an
     * empty field. False for anything else — the caller refuses the save
     * rather than guess (PublicationRules).
     */
    public static function fromInput(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return false;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        foreach (self::INPUT_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw, self::zone());
            $problems = \DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $parsed->format(self::SQL_FORMAT);
            }
        }

        return false;
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
     * A stored moment as a visitor reads a date ("3 maart 2026", "3 March
     * 2026"), or ''. Month names are spelled out here rather than left to
     * strftime(), which is deprecated and needs a locale shared hosting may
     * not have; a language without its own names gets SiteText's fallback.
     */
    public static function forReader(mixed $value, ?string $language = null): string
    {
        $moment = self::parse($value);

        if ($moment === null) {
            return '';
        }

        $month = (int) $moment->format('n');
        $names = \App\Service\Language\SiteText::pick(array_map(
            static fn (array $months): string => $months[$month],
            self::MONTHS
        ), $language);

        return $moment->format('j') . ' ' . $names . ' ' . $moment->format('Y');
    }

    private const MONTHS = [
        'nl' => [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    /** ISO 8601 with offset, for structured data and a future search index. */
    public static function forAtom(mixed $value): string
    {
        return self::parse($value)?->format(\DateTimeInterface::ATOM) ?? '';
    }

    /**
     * Test seam: pin "now". Always reset it in tearDown() by passing null —
     * the value is static and outlives one test.
     */
    public static function freezeForTests(?\DateTimeImmutable $moment): void
    {
        self::$frozen = $moment;
    }

    private static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(date_default_timezone_get());
    }
}
