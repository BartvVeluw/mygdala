<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * THE URL contract of Multilingual 2.0 (docs/multilingual/ROUTING.md):
 *
 *     default language      /            /over-ons
 *     any other language    /en/         /en/about-us
 *
 * The test that matters most is the DEFAULT-LANGUAGE FLIP: nothing in this
 * class may believe that "unprefixed" means Dutch. Make English the default
 * and the prefixes swap, with no code change and no pair of hardcoded codes.
 */
final class LocalizedUrlTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        RequestLanguage::reset();
    }

    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
        RequestLanguage::reset();
    }

    // ------------------------------------------------------------- the prefix

    public function testTheDefaultLanguageHasNoPrefix(): void
    {
        self::assertSame('', LocalizedUrl::prefix('nl'));
    }

    public function testEveryOtherLanguageHasOne(): void
    {
        self::assertSame('/en', LocalizedUrl::prefix('en'));
    }

    public function testALanguageThisSiteDoesNotPublishGetsNoInventedPrefix(): void
    {
        // A link is better pointing at the default language than at a URL
        // that cannot resolve.
        self::assertSame('', LocalizedUrl::prefix('de'));
        self::assertSame('', LocalizedUrl::prefix('zz'));
    }

    // ---------------------------------------------------------------- paths

    public function testAPathInTheDefaultLanguageIsUnchanged(): void
    {
        self::assertSame('/over-ons', LocalizedUrl::path('/over-ons', 'nl'));
        self::assertSame('/', LocalizedUrl::path('/', 'nl'));
        self::assertSame('/shop.php', LocalizedUrl::path('/shop.php', 'nl'));
    }

    public function testAPathInAnotherLanguageGainsThePrefix(): void
    {
        self::assertSame('/en/about-us', LocalizedUrl::path('/about-us', 'en'));
        self::assertSame('/en/shop.php', LocalizedUrl::path('/shop.php', 'en'));
    }

    public function testALanguageHomeKeepsItsTrailingSlash(): void
    {
        self::assertSame('/en/', LocalizedUrl::path('/', 'en'));
        self::assertSame('/en/', LocalizedUrl::home('en'));
        self::assertSame('/', LocalizedUrl::home('nl'));
    }

    public function testAPathWithoutALeadingSlashStillBecomesRootRelative(): void
    {
        self::assertSame('/shop.php', LocalizedUrl::path('shop.php', 'nl'));
        self::assertSame('/en/shop.php', LocalizedUrl::path('shop.php', 'en'));
    }

    public function testAQueryStringSurvivesAndTheePrefixGoesOnThePath(): void
    {
        self::assertSame('/en/blog?pagina=2', LocalizedUrl::path('/blog?pagina=2', 'en'));
        self::assertSame('/blog?pagina=2', LocalizedUrl::path('/blog?pagina=2', 'nl'));
        self::assertSame('/en/?x=1', LocalizedUrl::path('/?x=1', 'en'));
    }

    public function testAFragmentSurvivesToo(): void
    {
        self::assertSame('/en/diensten.php#hout', LocalizedUrl::path('/diensten.php#hout', 'en'));
    }

    public function testTheRequestLanguageIsUsedWhenNoneIsNamed(): void
    {
        RequestLanguage::set('en', true);

        self::assertSame('/en/about-us', LocalizedUrl::path('/about-us'));
    }

    // -------------------------------------------------- the default-language flip

    public function testMakingEnglishTheDefaultSwapsEveryPrefix(): void
    {
        SiteLanguageFixture::useBilingual('en');

        self::assertSame('', LocalizedUrl::prefix('en'));
        self::assertSame('/nl', LocalizedUrl::prefix('nl'));

        self::assertSame('/about-us', LocalizedUrl::path('/about-us', 'en'));
        self::assertSame('/nl/over-ons', LocalizedUrl::path('/over-ons', 'nl'));
        self::assertSame('/', LocalizedUrl::home('en'));
        self::assertSame('/nl/', LocalizedUrl::home('nl'));
    }

    // -------------------------------------------------------------- stripping

    public function testAPrefixCanBeTakenBackOff(): void
    {
        self::assertSame(['/about-us', 'en'], LocalizedUrl::strip('/en/about-us'));
        self::assertSame(['/', 'en'], LocalizedUrl::strip('/en/'));
        self::assertSame(['/', 'en'], LocalizedUrl::strip('/en'));
    }

    public function testAPathWithoutAPrefixIsLeftAlone(): void
    {
        self::assertSame(['/over-ons', null], LocalizedUrl::strip('/over-ons'));
        self::assertSame(['/', null], LocalizedUrl::strip('/'));
    }

    public function testASegmentThatIsNotAnACTIVELanguageIsNotAPrefix(): void
    {
        self::assertSame(['/de/kontakt', null], LocalizedUrl::strip('/de/kontakt'));
    }

    public function testStrippingKeepsTheQueryString(): void
    {
        self::assertSame(['/blog?pagina=2', 'en'], LocalizedUrl::strip('/en/blog?pagina=2'));
    }

    public function testStrippingAndBuildingAreEachOthersInverse(): void
    {
        foreach (['/', '/over-ons', '/blog/mijn-bericht', '/shop.php'] as $bare) {
            foreach (['nl', 'en'] as $code) {
                [$back] = LocalizedUrl::strip(LocalizedUrl::path($bare, $code));

                self::assertSame($bare, $back, $bare . ' in ' . $code);
            }
        }
    }
}
