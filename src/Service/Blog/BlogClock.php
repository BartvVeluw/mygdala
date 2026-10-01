<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\Publishing\PublishingClock;

/**
 * The Blog's name for the Publishing Engine's clock
 * (App\Service\Publishing\PublishingClock), kept so every existing caller and
 * test reads the same as before. There is ONE clock: freezing this one
 * freezes that one, so a blog post and anything else that publishes can
 * never disagree about what time it is.
 *
 * Why a clock at all — PHP's, never MySQL's NOW(), bound as a parameter, and
 * a test seam for the second before and after a moment — is written down on
 * PublishingClock and in docs/publishing/ARCHITECTURE.md, "Tijd".
 */
final class BlogClock
{
    /** The format `published_at` is stored and compared in. */
    public const SQL_FORMAT = PublishingClock::SQL_FORMAT;

    public static function now(): \DateTimeImmutable
    {
        return PublishingClock::now();
    }

    public static function nowForSql(): string
    {
        return PublishingClock::nowForSql();
    }

    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        return PublishingClock::parse($value);
    }

    /**
     * A datetime an editor typed, normalised for storage — or null when the
     * field was left empty or holds something that is not a moment
     * (PublishingClock::fromInput(); a save refuses the latter first, in
     * App\Service\Publishing\PublicationRules).
     */
    public static function fromFormInput(mixed $value): ?string
    {
        $moment = PublishingClock::fromInput($value);

        return is_string($moment) ? $moment : null;
    }

    public static function forFormInput(mixed $value): string
    {
        return PublishingClock::forFormInput($value);
    }

    public static function forAdmin(mixed $value): string
    {
        return PublishingClock::forAdmin($value);
    }

    public static function forRss(mixed $value): ?string
    {
        return PublishingClock::forRss($value);
    }

    /** Test seam: pins the one shared clock. Reset with null in tearDown(). */
    public static function freezeForTests(?\DateTimeImmutable $moment): void
    {
        PublishingClock::freezeForTests($moment);
    }
}
