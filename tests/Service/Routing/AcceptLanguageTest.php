<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\Routing\AcceptLanguage;
use PHPUnit\Framework\TestCase;

/**
 * The `Accept-Language` parser (docs/multilingual/ROUTING.md, step 3 of the
 * resolver chain).
 *
 * A pure function of a header and a list of supported codes, so these are the
 * headers browsers actually send, asserted without a site, a registry or a
 * database anywhere near them.
 */
final class AcceptLanguageTest extends TestCase
{
    // ------------------------------------------------------------- the basics

    public function testASingleLanguageIsTheAnswer(): void
    {
        self::assertSame('nl', AcceptLanguage::best('nl', ['nl', 'en']));
    }

    public function testTheFirstSUPPORTEDLanguageWinsWhenNoQualityIsGiven(): void
    {
        // Without q values the order in the header IS the preference.
        self::assertSame('en', AcceptLanguage::best('fr,en,nl', ['nl', 'en']));
    }

    public function testAnUnsupportedHeaderAnswersNothing(): void
    {
        self::assertNull(AcceptLanguage::best('fr,es', ['nl', 'en']));
    }

    public function testAnEmptyHeaderAnswersNothing(): void
    {
        self::assertNull(AcceptLanguage::best('', ['nl', 'en']));
        self::assertNull(AcceptLanguage::best('   ', ['nl', 'en']));
    }

    public function testNothingIsSupportedSoNothingIsAnswered(): void
    {
        self::assertNull(AcceptLanguage::best('nl,en', []));
    }

    // ------------------------------------------------------------- q values

    public function testTheHighestQualityWinsRegardlessOfPosition(): void
    {
        self::assertSame('de', AcceptLanguage::ranked('en;q=0.5,de;q=0.9')[0]);
    }

    public function testAMissingQualityCountsAsOne(): void
    {
        // "nl" has no q, so it is 1.0 and beats the explicit 0.9.
        self::assertSame(['nl', 'en'], AcceptLanguage::ranked('en;q=0.9,nl'));
    }

    public function testQualityZeroIsARefusalAndNotALowPreference(): void
    {
        self::assertSame(['en'], AcceptLanguage::ranked('nl;q=0,en;q=0.1'));
        self::assertSame('en', AcceptLanguage::best('nl;q=0,en;q=0.1', ['nl', 'en']));
    }

    public function testAnUnreadableQualityKeepsTheLanguageAtTheDefaultQuality(): void
    {
        self::assertSame(['nl'], AcceptLanguage::ranked('nl;q=banaan'));
    }

    public function testEqualQualitiesKeepTheOrderTheHeaderListedThemIn(): void
    {
        self::assertSame(['nl', 'en'], AcceptLanguage::ranked('nl;q=0.8,en;q=0.8'));
        self::assertSame(['en', 'nl'], AcceptLanguage::ranked('en;q=0.8,nl;q=0.8'));
    }

    // --------------------------------------------------------- region subtags

    public function testARegionSubtagIsMatchedOnItsBaseLanguage(): void
    {
        self::assertSame('de', AcceptLanguage::best('de-DE', ['nl', 'de']));
        self::assertSame('pt', AcceptLanguage::best('pt-BR;q=0.9', ['pt']));
    }

    public function testAnUnderscoreSubtagIsNarrowedToo(): void
    {
        self::assertSame('en', AcceptLanguage::best('en_GB', ['nl', 'en']));
    }

    public function testAScriptSubtagIsNarrowedToItsBaseLanguage(): void
    {
        self::assertSame('zh', AcceptLanguage::best('zh-Hans', ['zh']));
    }

    public function testCaseAndWhitespaceAreForgiven(): void
    {
        self::assertSame('en', AcceptLanguage::best('  NL ;q=0 ,  EN-gb ; q=0.4 ', ['nl', 'en']));
    }

    // ------------------------------------------------------------- the wildcard

    public function testTheWildcardIsIgnoredRatherThanHonoured(): void
    {
        // "*" means "anything will do", which is not a preference — the site
        // default already answers that, and honouring it here would hand a
        // visitor a language they never asked for.
        self::assertSame([], AcceptLanguage::ranked('*'));
        self::assertNull(AcceptLanguage::best('*;q=1.0', ['nl', 'en']));
    }

    // --------------------------------------------------------------- refusals

    public function testALanguageNamedTwiceKeepsItsBestQualityAndFirstPosition(): void
    {
        self::assertSame(['nl', 'en'], AcceptLanguage::ranked('nl;q=0.2,en;q=0.5,nl;q=0.9'));
    }

    public function testAnAbsurdlyLongHeaderIsRefusedOutright(): void
    {
        self::assertSame([], AcceptLanguage::ranked(str_repeat('nl,', 400)));
    }

    public function testSomethingThatIsNoLanguageCodeAtAllIsSkipped(): void
    {
        self::assertSame(['nl'], AcceptLanguage::ranked('x,123,../etc,nl'));
    }

    public function testARealChromeHeaderResolvesTheWayAVisitorWouldExpect(): void
    {
        $header = 'nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7';

        self::assertSame('nl', AcceptLanguage::best($header, ['nl', 'en']));
        self::assertSame('en', AcceptLanguage::best($header, ['en', 'de']));
    }
}
