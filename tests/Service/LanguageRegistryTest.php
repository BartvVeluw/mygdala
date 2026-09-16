<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The closed V1 language registry and the site's content-language
 * configuration as the V1 bilingual code sees it.
 *
 * Since Multilingual 2.0 phase 1 the site's primary language is the default
 * of the website language registry (App\Service\Language\SiteLanguages), and
 * ContentLanguages is the adapter that narrows it to what the `_nl`/`_en`
 * columns can store. These tests pin that the adapter answers exactly what
 * V1 answered.
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

    public function testFilterKeepsRegistryOrderAndDropsUnknownCodes(): void
    {
        self::assertSame(['nl', 'en'], LanguageRegistry::filter(['en', 'de', 'nl', 'en']));
        self::assertSame([], LanguageRegistry::filter(['de', 'fr']));
    }

    public function testLabelsFollowTheReadersLanguage(): void
    {
        self::assertSame('Engels', LanguageRegistry::label('en', 'nl'));
        self::assertSame('English', LanguageRegistry::label('en', 'en'));
        self::assertSame('Dutch', LanguageRegistry::label('nl', 'en'));
    }

    // ------------------------------------------------------------ the site

    public function testADutchDefaultPublishesBothLanguages(): void
    {
        // The product is bilingual: the registry every existing installation
        // got is Dutch and English, and the site stays readable in both.
        SiteLanguageFixture::useBilingual('nl');

        self::assertSame('nl', ContentLanguages::primary());
        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
        self::assertSame('en', ContentLanguages::secondary());
        self::assertTrue(ContentLanguages::isMultilingual());
    }

    public function testAnUnreadableRegistryFallsBackToDutchInsteadOfBreaking(): void
    {
        // What an unknown or missing settings row used to answer: a page must
        // still render when the registry cannot say anything.
        SiteLanguageFixture::useLanguages([]);

        self::assertSame('nl', ContentLanguages::primary());
        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
    }

    public function testAnInactiveLanguageInTheRegistryDoesNotTakeEnglishAway(): void
    {
        // THE V1 REGRESSION GUARD, carried over. A stored "this site
        // publishes Dutch only" used to hide the public language switch and
        // every English field in the CMS. Until the frontend flip nothing the
        // registry stores about activity may do that either.
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', isActive: false, sortOrder: 1),
        ]);

        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
        self::assertTrue(ContentLanguages::isMultilingual());
    }

    public function testEnglishMayBeThePrimaryLanguage(): void
    {
        SiteLanguageFixture::useBilingual('en');

        self::assertSame('en', ContentLanguages::primary());
        self::assertSame(['en', 'nl'], ContentLanguages::enabled(), 'primary comes first');
        self::assertSame('nl', ContentLanguages::secondary());
    }

    public function testThePrimaryComesFirstWhateverTheRegistryOrderIs(): void
    {
        // The registry keeps its own order; "first" in V1 always means the
        // primary language.
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', sortOrder: 0),
            SiteLanguageFixture::language('en', isDefault: true, sortOrder: 1),
        ]);

        self::assertSame(['en', 'nl'], ContentLanguages::enabled());
    }

    public function testADefaultTheV1ColumnsCannotStoreFallsBackToDutch(): void
    {
        // A registry may later hold German as its default. Until the storage
        // moves, the `_nl`/`_en` columns cannot hold German, so the V1 code
        // keeps reading Dutch rather than reaching for a column that does not
        // exist.
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true),
            SiteLanguageFixture::language('fr', sortOrder: 1),
        ]);

        self::assertSame('nl', ContentLanguages::primary());
        self::assertSame(['nl', 'en'], ContentLanguages::enabled());
    }

    public function testV1AcceptsAtMostOneSecondaryLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        self::assertLessThanOrEqual(ContentLanguages::MAX_ENABLED, count(ContentLanguages::enabled()));
    }

    // ------------------------------------------------------ normalisePrimary

    public function testNormalisePrimaryKeepsAStorableLanguage(): void
    {
        self::assertSame('en', ContentLanguages::normalisePrimary('en'));
        self::assertSame('nl', ContentLanguages::normalisePrimary(' nl '));
    }

    public function testNormalisePrimaryCannotBeTalkedIntoAnUnsupportedPrimary(): void
    {
        self::assertSame('nl', ContentLanguages::normalisePrimary('../../etc/passwd'));
        self::assertSame('nl', ContentLanguages::normalisePrimary('de'));
        self::assertSame('nl', ContentLanguages::normalisePrimary(''));
    }
}
