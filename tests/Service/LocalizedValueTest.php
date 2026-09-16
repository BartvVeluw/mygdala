<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\LocalizedValue;
use App\Service\Language\SiteText;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The fallback rule, now stated in terms of the site's primary language
 * instead of "Dutch" (Part F of Multilingual V1).
 *
 * The case that matters most is the last group: the SAME stored row has to
 * read differently on a Dutch site and on an English one, and a visitor must
 * never get a blank heading because one language was left empty.
 */
final class LocalizedValueTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
    }

    private function dutchSite(): void
    {
        SiteLanguageFixture::useBilingual('nl');
    }

    private function englishSite(): void
    {
        SiteLanguageFixture::useBilingual('en');
    }

    public function testATranslationIsUsedWhenThereIsOne(): void
    {
        $this->dutchSite();
        $value = LocalizedValue::ofDutchEnglish('Hallo', 'Hello');

        self::assertSame('Hallo', $value->in('nl'));
        self::assertSame('Hello', $value->in('en'));
        self::assertSame('Hallo', $value->primaryValue());
    }

    public function testAnEmptyTranslationFallsBackToThePrimaryLanguage(): void
    {
        $this->dutchSite();
        $value = LocalizedValue::ofDutchEnglish('Hallo', '');

        self::assertSame('Hallo', $value->in('en'), 'a visitor sees words, not a blank');
        self::assertFalse($value->isTranslated('en'));
    }

    public function testTheFallbackRunsTheOtherWayOnAnEnglishPrimarySite(): void
    {
        // The whole reason this class exists. Same row, opposite direction.
        $this->englishSite();
        $value = LocalizedValue::ofDutchEnglish('', 'Hello');

        self::assertSame('Hello', $value->in('nl'));
        self::assertSame('Hello', $value->primaryValue());
    }

    public function testRawNeverAppliesTheFallback(): void
    {
        // What an EDITOR form must show. A translation field that quietly
        // displayed the primary language's words would be saved back as a
        // real translation the moment somebody pressed Save.
        $this->dutchSite();
        $value = LocalizedValue::ofDutchEnglish('Hallo', '');

        self::assertSame('', $value->raw('en'));
        self::assertSame('Hallo', $value->in('en'));
    }

    public function testWhitespaceOnlyCountsAsEmpty(): void
    {
        $this->dutchSite();
        $value = LocalizedValue::ofDutchEnglish('Hallo', "  \n ");

        self::assertSame('Hallo', $value->in('en'));
        self::assertFalse($value->isTranslated('en'));
    }

    public function testBothEmptyIsEmpty(): void
    {
        $this->dutchSite();

        self::assertTrue(LocalizedValue::ofDutchEnglish('', '')->isEmpty());
        self::assertFalse(LocalizedValue::ofDutchEnglish('Hallo', '')->isEmpty());
    }

    public function testThePrimaryLanguageIsAlwaysConsideredTranslated(): void
    {
        $this->dutchSite();

        self::assertTrue(LocalizedValue::ofDutchEnglish('Hallo', '')->isTranslated('nl'));
    }

    // --------------------------------------------------------- SiteText

    public function testVisibleTextIsThePrimaryLanguage(): void
    {
        $this->dutchSite();
        self::assertSame('Hallo', SiteText::visible('Hallo', 'Hello'));

        $this->englishSite();
        self::assertSame('Hello', SiteText::visible('Hallo', 'Hello'));
    }

    public function testAttributesCarryBothLanguagesEscaped(): void
    {
        $this->dutchSite();
        $attrs = SiteText::attrs('Ha & llo', 'He "llo"');

        self::assertStringContainsString('data-nl="Ha &amp; llo"', $attrs);
        self::assertStringContainsString('data-en="He &quot;llo&quot;"', $attrs);
    }

    public function testAttributesAreNeverHalfEmptyWhenTheOtherHasText(): void
    {
        // This is what stops the language switch blanking a heading that has
        // no translation yet.
        $this->dutchSite();
        $attrs = SiteText::attrs('Hallo', '');

        self::assertStringContainsString('data-nl="Hallo"', $attrs);
        self::assertStringContainsString('data-en="Hallo"', $attrs);
    }

    public function testTheDocumentLanguageFollowsTheSite(): void
    {
        $this->dutchSite();
        self::assertSame('nl', SiteText::documentLanguage());

        $this->englishSite();
        self::assertSame('en', SiteText::documentLanguage());
    }

    public function testTheLanguageSwitchIsOfferedEvenWhenTheRegistryHasEnglishSwitchedOff(): void
    {
        // THE REGRESSION THIS STEP EXISTS FOR. A visitor of a site whose
        // owner never turned English "on" was shown no way to ask for it,
        // including on sites with English sitting in their `_en` columns.
        // Until the frontend flip, the registry's active flag cannot do that
        // either.
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', isActive: false, sortOrder: 1),
        ]);

        self::assertTrue(SiteText::showsLanguageSwitch());
        self::assertSame(['nl', 'en'], SiteText::switchableLanguages());
    }

    public function testAnEnglishPrimarySiteStillOffersDutch(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('en', isDefault: true),
            SiteLanguageFixture::language('nl', isActive: false, sortOrder: 1),
        ]);

        self::assertTrue(SiteText::showsLanguageSwitch());
        self::assertSame(['en', 'nl'], SiteText::switchableLanguages(), 'the default language comes first');
    }

    public function testAMissingTranslationFallsBackForAVisitorButNotForAnEditor(): void
    {
        // The distinction the whole editing model rests on: the public site
        // must never show a blank heading, and the editor must never be shown
        // words they did not write as if they were a translation.
        SiteLanguageFixture::useBilingual('nl');

        $value = LocalizedValue::ofDutchEnglish('Neem contact op', '');

        self::assertSame('Neem contact op', $value->in('en'), 'a visitor sees the Dutch words');
        self::assertSame('', $value->raw('en'), 'the editor sees an empty field');
        self::assertFalse($value->isTranslated('en'));
    }
}
