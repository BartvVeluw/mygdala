<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\ReservedRoutes;
use App\Service\Routing\ReservedPaths;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * Which single words a page slug may never claim, now that a URL can start
 * with a language (docs/multilingual/ROUTING.md).
 *
 * Tests\Service\ReservedRoutesTest owns the build-time half of the list; this
 * one owns the two runtime additions — language codes and the words a fixed
 * route segment can spell.
 */
final class ReservedPathsTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', isActive: false, sortOrder: 2),
        ]);
    }

    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
    }

    public function testEverythingReservedRoutesReservesIsStillReserved(): void
    {
        foreach (ReservedRoutes::all() as $word) {
            self::assertTrue(ReservedPaths::isReserved($word), $word);
        }
    }

    public function testALanguageCodeCanNeverBeAPageSlug(): void
    {
        // A page called "en" would be indistinguishable from the English
        // prefix, and the dispatcher peels the prefix first — so the page
        // would simply never be reachable.
        self::assertTrue(ReservedPaths::isReserved('nl'));
        self::assertTrue(ReservedPaths::isReserved('en'));
    }

    public function testAnINACTIVELanguageIsReservedToo(): void
    {
        // Handing out "de" as a slug and then switching German on would make
        // that page permanently unreachable. Reserving a word costs nothing.
        self::assertTrue(ReservedPaths::isReserved('de'));
    }

    public function testALanguageThisSiteDoesNotHaveIsNotReserved(): void
    {
        self::assertFalse(ReservedPaths::isReserved('fr'));
    }

    public function testEveryWordAFixedSegmentCanSpellIsReserved(): void
    {
        self::assertTrue(ReservedPaths::isReserved('collecties'));
        self::assertTrue(ReservedPaths::isReserved('collections'));
        self::assertTrue(ReservedPaths::isReserved('blog'));
        self::assertTrue(ReservedPaths::isReserved('portfolio'));
    }

    public function testTheDispatcherItselfIsReserved(): void
    {
        self::assertTrue(ReservedPaths::isReserved('dispatcher'));
    }

    public function testAnOrdinaryWordIsFree(): void
    {
        self::assertFalse(ReservedPaths::isReserved('over-ons'));
        self::assertFalse(ReservedPaths::isReserved('garantie'));
    }

    public function testAnEmptySlugIsRefused(): void
    {
        self::assertTrue(ReservedPaths::isReserved(''));
        self::assertTrue(ReservedPaths::isReserved('   '));
    }
}
