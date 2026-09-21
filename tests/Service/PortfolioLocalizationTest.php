<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\LanguageFallback;
use App\Service\PortfolioLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The Portfolio's words per website language (Multilingual 2.0 phase 5 wave A,
 * docs/multilingual/ARCHITECTURE.md), without a database: the three stores are
 * App\Service\Language\EntityTranslations, so this file asks what
 * App\Service\PortfolioLocalization adds on top of it — which field belongs to
 * which store, the fallback a card and an old project page get, the sanitizing
 * of the two rich fields, and the refusal of a field that is not this
 * domain's.
 *
 * Reading through overrideForTests() rather than SQL is the seam
 * Tests\Service\EntityTranslationsTest uses for the phase 4 stores; the real
 * SQL of these three tables is Tests\Repository\PortfolioTranslationTest's,
 * and the backfill Tests\Install\PortfolioWordsMigrationTest's.
 */
final class PortfolioLocalizationTest extends TestCase
{
    private const CATEGORY = 41;
    private const ITEM = 42;
    private const IMAGE = 43;

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        PortfolioLocalization::clearCache();
    }

    protected function tearDown(): void
    {
        PortfolioLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    /* ------------------------------------------------------------------ */
    /* Which field lives where                                             */
    /* ------------------------------------------------------------------ */

    public function testEachStoreDeclaresItsOwnTableAndFields(): void
    {
        $categories = PortfolioLocalization::categories()->table();
        $items = PortfolioLocalization::items()->table();
        $images = PortfolioLocalization::images()->table();

        self::assertSame('portfolio_category_translations', $categories->name);
        self::assertSame('portfolio_category_id', $categories->ownerColumn);
        self::assertSame(['name'], $categories->fieldNames());

        self::assertSame('portfolio_item_translations', $items->name);
        self::assertSame('portfolio_item_id', $items->ownerColumn);
        self::assertSame(['title', 'subtitle', 'alt', 'intro', 'description'], $items->fieldNames());

        self::assertSame('portfolio_item_image_translations', $images->name);
        self::assertSame('portfolio_item_image_id', $images->ownerColumn);
        self::assertSame(['alt'], $images->fieldNames());
    }

    /** A length is the length of the column it replaced, so no word can stop fitting. */
    public function testTheLengthsAreTheOnesTheColumnsHad(): void
    {
        $items = PortfolioLocalization::items()->table();

        self::assertSame(100, PortfolioLocalization::categories()->table()->maxLength('name'));
        self::assertSame(150, $items->maxLength('title'));
        self::assertSame(150, $items->maxLength('subtitle'));
        self::assertSame(255, $items->maxLength('alt'));
        self::assertSame(255, PortfolioLocalization::images()->table()->maxLength('alt'));
    }

    public function testAFieldOfAnotherKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PortfolioLocalization::item(self::ITEM, 'intro', 'nl');
    }

    public function testAPlainFieldIsNotARichField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PortfolioLocalization::itemRich(self::ITEM, 'title', 'nl');
    }

    /* ------------------------------------------------------------------ */
    /* The fallback                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The one rule of phase 4 and 5: the asked-for language, the default
     * language, ''. A card in English on a Dutch-default site shows the Dutch
     * title when there is no English one, and nothing at all when there is
     * neither.
     */
    public function testACardFallsBackToTheDefaultLanguageAndThenToNothing(): void
    {
        $this->itemWords([
            'nl' => ['title' => 'Houten bord', 'subtitle' => 'Eiken', 'alt' => 'Een houten bord'],
            'en' => ['title' => 'Wooden sign'],
        ]);

        self::assertSame('Houten bord', PortfolioLocalization::items()->value(self::ITEM, 'title', 'nl'));
        self::assertSame('Wooden sign', PortfolioLocalization::items()->value(self::ITEM, 'title', 'en'));
        self::assertSame('Eiken', PortfolioLocalization::items()->value(self::ITEM, 'subtitle', 'en'), 'no English subtitle falls back');
        self::assertSame('', PortfolioLocalization::items()->value(self::ITEM, 'intro', 'en'), 'nothing in either language is nothing');
    }

    /**
     * THE DEFAULT LANGUAGE DECIDES WHETHER A CARD SHOWS ITS WORDS, the same
     * contract the blocks got in phase 3A: it is the end of the chain and
     * falls back to nothing, so a word that exists ONLY in a translation is
     * not shown. On an English-default site an item with only Dutch words
     * therefore has no title — where the fixed columns used to copy the Dutch
     * half into the English one before printing it.
     *
     * Every existing installation has Dutch as its default (migration
     * 20260917120000 reads primary_content_language), and there the output is
     * byte-identical to before: English falls back to Dutch exactly as the
     * columns did.
     */
    public function testOnlyTheDefaultLanguageDecidesWhetherACardHasWords(): void
    {
        SiteLanguageFixture::useBilingual('en');
        PortfolioLocalization::clearCache();
        $this->itemWords(['nl' => ['title' => 'Alleen Nederlands']]);

        self::assertSame('en', PortfolioLocalization::defaultLanguage());
        self::assertSame('', PortfolioLocalization::items()->value(self::ITEM, 'title', 'en'));
        self::assertSame('Alleen Nederlands', PortfolioLocalization::items()->value(self::ITEM, 'title', 'nl'));
        self::assertSame('', PortfolioLocalization::item(self::ITEM, 'title', 'en'));
    }

    /** raw() is for an editor: what is stored in THIS language, with no fallback. */
    public function testAnEditorSeesOnlyWhatIsStoredInTheLanguageOnScreen(): void
    {
        $this->itemWords(['nl' => ['title' => 'Houten bord']]);

        self::assertSame('Houten bord', PortfolioLocalization::rawItemValue(self::ITEM, 'title', 'nl'));
        self::assertSame('', PortfolioLocalization::rawItemValue(self::ITEM, 'title', 'en'), 'an empty field means "not translated yet"');
    }

    /* ------------------------------------------------------------------ */
    /* One language per request                                            */
    /* ------------------------------------------------------------------ */

    public function testACardReadsTheLanguageItIsAskedFor(): void
    {
        $this->itemWords([
            'nl' => ['title' => 'Houten bord', 'alt' => 'Een houten bord'],
            'en' => ['title' => 'Wooden sign'],
        ]);

        self::assertSame('Houten bord', PortfolioLocalization::item(self::ITEM, 'title', 'nl'));
        self::assertSame('Wooden sign', PortfolioLocalization::item(self::ITEM, 'title', 'en'));
        self::assertSame('Een houten bord', PortfolioLocalization::item(self::ITEM, 'alt', 'en'), 'an untranslated alt text is the default language, never empty');
    }

    public function testACategoryAndAPhotoReadTheLanguageTheyAreAskedFor(): void
    {
        PortfolioLocalization::categories()->overrideForTests(self::CATEGORY, [
            'nl' => ['name' => 'Hout'],
            'en' => ['name' => 'Wood'],
        ]);
        PortfolioLocalization::images()->overrideForTests(self::IMAGE, ['nl' => ['alt' => 'Detailfoto']]);

        self::assertSame('Hout', PortfolioLocalization::categoryName(self::CATEGORY, 'nl'));
        self::assertSame('Wood', PortfolioLocalization::categoryName(self::CATEGORY, 'en'));
        self::assertSame('Detailfoto', PortfolioLocalization::imageAlt(self::IMAGE, 'en'));
    }

    /* ------------------------------------------------------------------ */
    /* Rich text                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * The old project page's two rich fields are sanitized PER LANGUAGE on the
     * way out, because the editor that wrote them is gone. A language whose
     * markup sanitizes away to nothing has no words, so the fallback takes
     * over — that is why the sanitizing happens before the fallback and not
     * after it.
     */
    public function testRichTextIsSanitizedInEveryLanguageBeforeTheFallbackRuns(): void
    {
        $this->itemWords([
            'nl' => ['intro' => '<p>Van eiken<script>alert(1)</script></p>'],
            'en' => ['intro' => '<script>alert(2)</script>'],
        ]);

        self::assertSame('<p>Van eiken</p>', PortfolioLocalization::itemRich(self::ITEM, 'intro', 'nl'));
        self::assertStringNotContainsString('script', PortfolioLocalization::itemRich(self::ITEM, 'intro', 'en'));
        self::assertSame('<p>Van eiken</p>', PortfolioLocalization::itemRich(self::ITEM, 'intro', 'en'), 'markup that sanitizes away to nothing is no translation');
    }

    /* ------------------------------------------------------------------ */
    /* A third language                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * German is a row in site_languages and nothing else: no column, no
     * declaration, no line of PHP. A German card reads German where it has
     * words and the default language where it does not, exactly like English.
     */
    public function testAThirdLanguageNeedsNoSchemaAndNoCode(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
        PortfolioLocalization::clearCache();

        $this->itemWords([
            'nl' => ['title' => 'Houten bord', 'subtitle' => 'Eiken'],
            'de' => ['title' => 'Holzschild'],
        ]);

        self::assertSame('Holzschild', PortfolioLocalization::items()->value(self::ITEM, 'title', 'de'));
        self::assertSame('Eiken', PortfolioLocalization::items()->value(self::ITEM, 'subtitle', 'de'), 'German falls back to the default language');
        self::assertSame('nl', LanguageFallback::defaultLanguage());
    }

    /** The CMS names an item by its title, and a nameless one by the first language that has words. */
    public function testTheCmsNameTakesOneStepMoreThanAVisitorEverDoes(): void
    {
        $this->itemWords(['en' => ['title' => 'Only English']]);

        self::assertSame('', PortfolioLocalization::items()->value(self::ITEM, 'title', 'nl'), 'a visitor never sees this step');
        self::assertSame('Only English', PortfolioLocalization::itemLabel(self::ITEM));
    }

    /** @param array<string, array<string, string>> $words */
    private function itemWords(array $words): void
    {
        PortfolioLocalization::items()->overrideForTests(self::ITEM, $words);
    }
}
