<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\LanguageRegistry;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The Pages localization API of Multilingual 2.0 phase 2
 * (docs/multilingual/ARCHITECTURE.md): the field fallback, what an editor
 * reads, what the CMS calls a page, the temporary NL/EN output adapter, and a
 * third language that needs no code.
 *
 * No database: the registry comes from Tests\Support\SiteLanguageFixture and
 * the text from PageLocalization::overrideForTests(). What the SQL does is
 * Tests\Repository\PageTranslationRepositoryTest's subject.
 */
final class PageLocalizationTest extends TestCase
{
    private const PAGE = 41;

    protected function tearDown(): void
    {
        PageLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    // ------------------------------------------------------------ the fallback

    public function testTheAskedForLanguageKeepsItsOwnWords(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->page(['nl' => ['Over ons', 'SEO nl', 'Omschrijving'], 'en' => ['About us', 'SEO en', 'Description']]);

        self::assertSame('About us', PageLocalization::value(self::PAGE, PageTranslation::TITLE, 'en'));
        self::assertSame('SEO en', PageLocalization::value(self::PAGE, PageTranslation::META_TITLE, 'en'));
        self::assertSame('Omschrijving', PageLocalization::value(self::PAGE, PageTranslation::META_DESCRIPTION, 'nl'));
    }

    public function testAnEmptyFieldFallsBackToTheDefaultLanguageFieldByField(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->page(['nl' => ['Over ons', 'SEO nl', 'Omschrijving'], 'en' => ['About us', null, null]]);

        self::assertSame('About us', PageLocalization::value(self::PAGE, PageTranslation::TITLE, 'en'));
        self::assertSame('SEO nl', PageLocalization::value(self::PAGE, PageTranslation::META_TITLE, 'en'));
        self::assertSame('Omschrijving', PageLocalization::value(self::PAGE, PageTranslation::META_DESCRIPTION, 'en'));
    }

    public function testAMissingTranslationFallsBackToTheDefaultLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->page(['nl' => ['Over ons', null, null]]);

        self::assertFalse(PageLocalization::has(self::PAGE, 'en'));
        self::assertSame('Over ons', PageLocalization::title(self::PAGE, 'en'));
    }

    public function testNothingInEitherLanguageIsEmptyAndNeverAnotherLanguage(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->page(['en' => [null, 'Only English SEO', null]]);

        // The default language has no title and English has none either: the
        // contract stops at the default, it does not go looking elsewhere.
        self::assertSame('', PageLocalization::title(self::PAGE, 'nl'));
        self::assertSame('', PageLocalization::title(self::PAGE, 'en'));
        self::assertSame('', PageLocalization::value(self::PAGE, PageTranslation::META_TITLE, 'nl'));
        self::assertSame('Only English SEO', PageLocalization::value(self::PAGE, PageTranslation::META_TITLE, 'en'));
    }

    public function testTheFallbackFollowsTheDefaultLanguageNotDutch(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->page(['nl' => [null, null, 'Alleen Nederlands'], 'en' => ['About us', null, null]]);

        self::assertSame('About us', PageLocalization::title(self::PAGE, 'nl'));
        self::assertSame('', PageLocalization::value(self::PAGE, PageTranslation::META_DESCRIPTION, 'en'));
        self::assertSame('Alleen Nederlands', PageLocalization::value(self::PAGE, PageTranslation::META_DESCRIPTION, 'nl'));
    }

    public function testAnEditorReadsTheStoredWordsWithoutAFallback(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->page(['nl' => ['Over ons', 'SEO nl', null]]);

        self::assertSame('', PageLocalization::raw(self::PAGE, PageTranslation::TITLE, 'en'));
        self::assertSame('', PageLocalization::raw(self::PAGE, PageTranslation::META_TITLE, 'en'));
        self::assertSame('Over ons', PageLocalization::raw(self::PAGE, PageTranslation::TITLE, 'nl'));
    }

    public function testAPageWithNoTextAtAllReadsAsEmpty(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->page([]);

        self::assertSame([], PageLocalization::translations(self::PAGE));
        self::assertSame('', PageLocalization::title(self::PAGE, 'nl'));
        self::assertSame('', PageLocalization::name(self::PAGE));
    }

    public function testAnUnknownFieldIsAProgrammingError(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        $this->page(['nl' => ['Over ons', null, null]]);

        $this->expectException(\InvalidArgumentException::class);
        PageLocalization::value(self::PAGE, 'slug', 'nl');
    }

    // ------------------------------------------------------------ a third language

    public function testAThirdLanguageIsARowAndNotCode(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
        $this->page([
            'nl' => ['Over ons', 'SEO nl', 'Omschrijving'],
            'de' => ['Über uns', null, 'Beschreibung'],
        ]);

        self::assertTrue(PageLocalization::has(self::PAGE, 'de'));
        self::assertSame('Über uns', PageLocalization::title(self::PAGE, 'de'));
        self::assertSame('SEO nl', PageLocalization::value(self::PAGE, PageTranslation::META_TITLE, 'de'));
        self::assertSame('Beschreibung', PageLocalization::value(self::PAGE, PageTranslation::META_DESCRIPTION, 'de'));
        self::assertSame('Over ons', PageLocalization::title(self::PAGE, 'en'));
    }

    // ------------------------------------------------------------ the CMS name

    public function testTheCmsNamesAPageInTheDefaultLanguage(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $this->page(['nl' => ['Over ons', null, null], 'en' => ['About us', null, null]]);

        self::assertSame('About us', PageLocalization::name(self::PAGE));
    }

    public function testAPageWithoutADefaultLanguageNameIsNamedByTheFirstLanguageThatHasOne(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('de', sortOrder: 1),
            SiteLanguageFixture::language('en', sortOrder: 2),
        ]);
        $this->page(['en' => ['About us', null, null], 'de' => ['Über uns', null, null]]);

        self::assertSame('Über uns', PageLocalization::name(self::PAGE), 'registry order, not storage order');
        self::assertSame('', PageLocalization::title(self::PAGE, 'nl'), 'a visitor never gets that step');
    }

    // ------------------------------------------------------------ writing

    public function testTextCannotBeStoredForALanguageTheSiteDoesNotHave(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        // Refused before any SQL: this test has no database to reach.
        $this->expectException(\InvalidArgumentException::class);
        PageLocalization::save(self::PAGE, 'de', [PageTranslation::TITLE => 'Über uns']);
    }

    public function testTextCannotBeStoredUnderAMalformedCode(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        $this->expectException(\InvalidArgumentException::class);
        PageLocalization::save(self::PAGE, "nl'; DROP TABLE pages", [PageTranslation::TITLE => 'x']);
    }

    public function testTextCannotBeStoredUnderAFieldThePageDoesNotHave(): void
    {
        SiteLanguageFixture::useBilingual('nl');

        $this->expectException(\InvalidArgumentException::class);
        PageLocalization::save(self::PAGE, 'nl', ['slug' => 'over-ons']);
    }

    public function testAnUnreadableRegistryStillHasADefaultToFallBackTo(): void
    {
        SiteLanguageFixture::useLanguages([]);

        self::assertSame(LanguageRegistry::DEFAULT_LANGUAGE, PageLocalization::defaultLanguage());
    }

    // ------------------------------------------------------------ the value object

    public function testARowStoresNullForNoWordsAndRejectsAForeignCode(): void
    {
        $translation = PageTranslation::fromRow([
            'page_id' => '7', 'language_code' => 'en', 'title' => '  About  ', 'meta_title' => '   ', 'meta_description' => null,
        ]);

        self::assertNotNull($translation);
        self::assertSame('About', $translation->title);
        self::assertNull($translation->metaTitle);
        self::assertSame('', $translation->value(PageTranslation::META_DESCRIPTION));
        self::assertFalse($translation->isEmpty());

        self::assertNull(PageTranslation::fromRow(['page_id' => 7, 'language_code' => 'EN', 'title' => 'x']));
        self::assertNull(PageTranslation::fromRow(['page_id' => 7, 'language_code' => 'pt-BR', 'title' => 'x']));
    }

    /**
     * @param array<string, array{0: ?string, 1: ?string, 2: ?string}> $languages code => [title, meta title, meta description]
     */
    private function page(array $languages): void
    {
        $translations = [];
        foreach ($languages as $code => [$title, $metaTitle, $metaDescription]) {
            $translations[] = new PageTranslation(self::PAGE, $code, $title, $metaTitle, $metaDescription);
        }

        PageLocalization::overrideForTests(self::PAGE, $translations);
    }
}
