<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminLocale;
use App\Service\Language\SiteLanguage;
use App\Service\Language\SiteLanguages;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The Core API of the website language registry (docs/multilingual/ARCHITECTURE.md):
 * the default, the active languages, lookup by code, and the order.
 *
 * No database: the registry is replaced in memory. What the SQL itself
 * guarantees is Tests\Repository\SiteLanguageRepositoryTest's subject, and
 * what an installation gets is Tests\Install\SiteLanguageRegistryMigrationTest's.
 */
final class SiteLanguagesTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
        AdminLocale::overrideForTests(null);
    }

    // ------------------------------------------------------------ the default

    public function testTheDefaultIsTheOneRowMarkedDefault(): void
    {
        SiteLanguageFixture::useBilingual('en');

        self::assertSame('en', SiteLanguages::defaultCode());
        self::assertSame('English', SiteLanguages::defaultLanguage()->nativeName);
        self::assertTrue(SiteLanguages::defaultLanguage()->isDefault);
    }

    public function testARegistryWithoutADefaultIsAnErrorAndNotAChoice(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl'),
            SiteLanguageFixture::language('en', sortOrder: 1),
        ]);

        $this->expectException(\RuntimeException::class);
        SiteLanguages::defaultLanguage();
    }

    public function testAnEmptyRegistryIsAnErrorToo(): void
    {
        // Also what an unreadable registry looks like to a caller.
        SiteLanguageFixture::useLanguages([]);

        $this->expectException(\RuntimeException::class);
        SiteLanguages::defaultCode();
    }

    public function testTwoDefaultsAreAnError(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', isDefault: true, sortOrder: 1),
        ]);

        $this->expectException(\RuntimeException::class);
        SiteLanguages::defaultLanguage();
    }

    public function testAnInactiveDefaultIsAnError(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, isActive: false),
            SiteLanguageFixture::language('en', sortOrder: 1),
        ]);

        $this->expectException(\RuntimeException::class);
        SiteLanguages::defaultLanguage();
    }

    // -------------------------------------------------------- active and order

    public function testLanguagesComeInSortOrder(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('it', sortOrder: 3),
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('fr', sortOrder: 2),
            SiteLanguageFixture::language('de', sortOrder: 1),
        ]);

        self::assertSame(['nl', 'de', 'fr', 'it'], self::codes(SiteLanguages::all()));
    }

    public function testTiesKeepTheOrderTheRowsArrivedIn(): void
    {
        // Storage breaks ties by id; the sort must not reshuffle them.
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('fr', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 1),
        ]);

        self::assertSame(['nl', 'fr', 'de', 'en'], self::codes(SiteLanguages::all()));
        self::assertSame(['nl', 'fr', 'de', 'en'], self::codes(SiteLanguages::all()), 'and the same again');
    }

    public function testActiveLeavesOutInactiveLanguagesAndKeepsTheOrder(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('de', isActive: false, sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);

        self::assertSame(['nl', 'en'], SiteLanguages::activeCodes());
        self::assertSame(['nl', 'en'], self::codes(SiteLanguages::active()));
        self::assertCount(3, SiteLanguages::all(), 'an inactive language is still registered');
    }

    // ------------------------------------------------------------------ lookup

    public function testALanguageIsFoundByItsCodeInAnyCase(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        self::assertSame('en', SiteLanguages::find(' EN ')?->code);
        self::assertTrue(SiteLanguages::exists('nl'));
        self::assertTrue(SiteLanguages::isActive('en'));
    }

    public function testUnknownAndInvalidCodesAreSimplyNotThere(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        self::assertNull(SiteLanguages::find('de'));
        self::assertNull(SiteLanguages::find('en-gb'));
        self::assertNull(SiteLanguages::find('../nl'));
        self::assertFalse(SiteLanguages::exists(''));
        self::assertFalse(SiteLanguages::isActive('de'));
    }

    public function testAnInactiveLanguageExistsButIsNotActive(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('de', isActive: false, sortOrder: 1),
        ]);

        self::assertTrue(SiteLanguages::exists('de'));
        self::assertFalse(SiteLanguages::isActive('de'));
    }

    public function testNothingAssumesDutchOrEnglish(): void
    {
        // The core is dynamic: a registry of languages the V1 columns cannot
        // even store answers every question the same way.
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('fr', sortOrder: 1),
            SiteLanguageFixture::language('de', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('it', sortOrder: 2),
        ]);

        self::assertSame('de', SiteLanguages::defaultCode());
        self::assertSame(['de', 'fr', 'it'], SiteLanguages::activeCodes());
        self::assertFalse(SiteLanguages::exists('nl'));
    }

    public function testLanguagesThatNoCodeNamesWorkLikeAnyOther(): void
    {
        // Nothing in src/ names these six. A new language is a row, so every
        // question is answered for them exactly as it is for Dutch.
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('pt', sortOrder: 1),
            SiteLanguageFixture::language('es', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('pl', sortOrder: 2),
            SiteLanguageFixture::language('sv', sortOrder: 3),
            SiteLanguageFixture::language('da', isActive: false, sortOrder: 4),
            SiteLanguageFixture::language('cs', sortOrder: 5),
        ]);

        self::assertSame('es', SiteLanguages::defaultCode());
        self::assertSame(['es', 'pt', 'pl', 'sv', 'da', 'cs'], self::codes(SiteLanguages::all()));
        self::assertSame(['es', 'pt', 'pl', 'sv', 'cs'], SiteLanguages::activeCodes());
        self::assertSame('pl', SiteLanguages::find('PL')?->code);
        self::assertTrue(SiteLanguages::exists('da'));
        self::assertFalse(SiteLanguages::isActive('da'));
        self::assertNull(SiteLanguages::find('pt-BR'), 'a region variant is outside V1, also for a registered language');

        foreach (['es', 'pt', 'pl', 'sv', 'da', 'cs'] as $code) {
            self::assertSame($code, SiteLanguage::fromRow(['code' => $code, 'is_active' => '1'])?->code, $code . ' as a stored row');
        }
    }

    public function testSetDefaultRefusesAnInvalidCodeBeforeReachingStorage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteLanguages::setDefault("nl'; --");
    }

    // ------------------------------------------------------------- from a row

    public function testARowBecomesALanguage(): void
    {
        $language = SiteLanguage::fromRow([
            'id' => '7', 'code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch',
            'is_default' => null, 'is_active' => '1', 'sort_order' => '2',
        ]);

        self::assertNotNull($language);
        self::assertSame(['de', 'German', 'Deutsch', false, true, 2], [
            $language->code, $language->name, $language->nativeName,
            $language->isDefault, $language->isActive, $language->sortOrder,
        ]);
        self::assertTrue(SiteLanguage::fromRow(['code' => 'nl', 'is_default' => '1', 'is_active' => '1'])?->isDefault);
    }

    public function testARowWithACodeThisApplicationWouldNeverStoreIsSkipped(): void
    {
        self::assertNull(SiteLanguage::fromRow(['code' => 'NL']));
        self::assertNull(SiteLanguage::fromRow(['code' => 'en-gb']));
        self::assertNull(SiteLanguage::fromRow(['code' => '']));
        self::assertNull(SiteLanguage::fromRow([]));
    }

    // ------------------------------------ the CMS interface language is apart

    public function testTheCmsLanguageDoesNotMoveTheWebsiteDefault(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        AdminLocale::overrideForTests('en');

        self::assertSame('en', AdminLocale::current());
        self::assertSame('nl', SiteLanguages::defaultCode());
    }

    public function testTheWebsiteDefaultDoesNotMoveTheCmsLanguage(): void
    {
        SiteLanguageFixture::useBilingual('en');

        AdminLocale::overrideForTests('nl');

        self::assertSame('nl', AdminLocale::current());
        self::assertSame('en', SiteLanguages::defaultCode());
    }

    public function testAWebsiteLanguageIsNotACmsLanguage(): void
    {
        // German on the website does not give the CMS a German interface:
        // the CMS keeps its own list, and nothing here feeds it.
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true),
            SiteLanguageFixture::language('nl', sortOrder: 1),
        ]);

        self::assertSame(['nl', 'en'], array_keys(AdminLocale::choices()));
        self::assertSame('nl', AdminLocale::normalise('de'));
    }

    /**
     * @param list<SiteLanguage> $languages
     * @return list<string>
     */
    private static function codes(array $languages): array
    {
        return array_map(static fn (SiteLanguage $l): string => $l->code, $languages);
    }
}
