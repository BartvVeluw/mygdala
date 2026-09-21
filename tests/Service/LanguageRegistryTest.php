<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\LanguageFallback;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\SiteLanguages;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The languages the CMS itself speaks (App\Service\Language\LanguageRegistry),
 * and that they no longer decide anything about the WEBSITE's languages.
 *
 * Until Multilingual 2.0 phase 7 a V1 adapter narrowed the website to what
 * this closed list could describe: Dutch and English, whatever
 * `site_languages` said. That adapter is gone. The website's languages are
 * the rows of `site_languages` (App\Service\Language\SiteLanguages) and
 * nothing else, and when that registry cannot answer, the one fallback is
 * App\Service\Language\LanguageFallback::defaultLanguage().
 *
 * No database and no webserver: the registry is replaced in memory by
 * Tests\Support\SiteLanguageFixture, which is what keeps this file in the
 * `fast` tier (TESTING.md).
 */
final class LanguageRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
    }

    public function testRegistersDutchAndEnglish(): void
    {
        self::assertSame(['nl', 'en'], LanguageRegistry::codes());
        self::assertTrue(LanguageRegistry::has('nl'));
        self::assertTrue(LanguageRegistry::has('en'));
    }

    public function testAnUnknownLanguageIsSimplyUnknown(): void
    {
        // The whole point of a closed registry: a code from a request, a
        // settings row or a future version can only hit a key or miss it.
        self::assertFalse(LanguageRegistry::has('de'));
        self::assertNull(LanguageRegistry::get('de'));
        self::assertFalse(LanguageRegistry::has('nl; DROP TABLE pages'));
        self::assertFalse(LanguageRegistry::has(''));
    }

    public function testEveryDefinitionCarriesWhatTheCmsNeedsFromIt(): void
    {
        foreach (LanguageRegistry::all() as $code => $definition) {
            self::assertSame($code, $definition->code, 'the map key is the code');
            self::assertNotSame('', $definition->nativeLabel);
            self::assertNotSame('', $definition->dutchLabel);
            self::assertNotSame('', $definition->englishLabel);
        }
    }

    public function testLabelsFollowTheReadersLanguage(): void
    {
        self::assertSame('Engels', LanguageRegistry::label('en', 'nl'));
        self::assertSame('English', LanguageRegistry::label('en', 'en'));
        self::assertSame('Dutch', LanguageRegistry::label('nl', 'en'));
    }

    // ---------------------------------------------- the website is not this list

    /**
     * A German-default website with French beside it: two languages this
     * list does not know, published exactly as the website registry says.
     * Before phase 7 the V1 adapter read Dutch here.
     */
    public function testTheWebsitesLanguagesAreTheRegistrysRowsNotThisList(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true),
            SiteLanguageFixture::language('fr', sortOrder: 1),
        ]);

        self::assertSame('de', LanguageFallback::defaultLanguage());
        self::assertSame(['de', 'fr'], SiteLanguages::activeCodes());
        self::assertFalse(LanguageRegistry::has('de'), 'and the CMS still speaks only its own languages');
    }

    /** A language switched off in the registry is off for the website too — no list keeps it alive. */
    public function testAnInactiveLanguageIsNotPublished(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', isActive: false, sortOrder: 1),
        ]);

        self::assertSame(['nl'], SiteLanguages::activeCodes());
    }

    public function testEnglishMayBeTheDefaultLanguage(): void
    {
        SiteLanguageFixture::useBilingual('en');

        self::assertSame('en', LanguageFallback::defaultLanguage());
    }

    /**
     * What a page falls back to when the registry cannot say anything: the
     * project's own default, from one place, so a page still renders.
     */
    public function testAnUnreadableRegistryFallsBackToTheProjectDefaultInsteadOfBreaking(): void
    {
        SiteLanguageFixture::useLanguages([]);

        self::assertSame(LanguageRegistry::DEFAULT_LANGUAGE, LanguageFallback::defaultLanguage());
    }
}
