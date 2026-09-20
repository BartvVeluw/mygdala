<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\PageRepository;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * Which URL answers a page, per language (docs/multilingual/ROUTING.md).
 *
 * The rule this defends is the one the whole phase turns on: a language URL
 * exists only when that language version is really routable, and an address
 * never falls back the way words do. A test with one language cannot see the
 * difference, so every case here names its language explicitly.
 *
 * Works on the test database, like the other page tests: the addresses live
 * in `page_translations` and the fallback reads `pages.slug`, and mocking
 * either would be mocking the thing under test.
 */
final class PageLocalizedRoutingTest extends TestCase
{
    private const PREFIX = 'zz-localized-routing';

    private PageRepository $pages;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $this->pages->delete($id);
        }
        $this->created = [];

        SiteLanguageFixture::reset();
        PageLocalization::clearCache();
        PageContent::clearCache();
        RequestLanguage::reset();
    }

    /**
     * @param array<string, string> $slugs language code => that language's address
     */
    private function page(string $suffix, array $slugs, bool $withNeutralOnly = false): array
    {
        $neutral = self::PREFIX . $suffix;

        $id = $this->pages->create([
            'content_key' => $neutral,
            'slug' => $neutral,
            'status' => PageContent::STATUS_PUBLISHED,
        ]);
        $this->created[] = $id;

        if (!$withNeutralOnly) {
            foreach ($slugs as $code => $slug) {
                PageLocalization::save($id, (string) $code, [PageTranslation::TITLE => 'ZZ ' . $code], $slug);
            }
        }

        PageLocalization::clearCache();
        PageContent::clearCache();

        return $this->pages->findById($id);
    }

    // ------------------------------------------------- one address per language

    public function testEachLanguageIsFoundByItsOwnAddress(): void
    {
        $neutral = self::PREFIX . '-both';
        $this->page('-both', ['nl' => $neutral, 'en' => $neutral . '-en']);

        self::assertNotNull(PageContent::forSlug($neutral, 'nl'));
        self::assertNotNull(PageContent::forSlug($neutral . '-en', 'en'));
    }

    public function testOneLanguagesAddressNeverAnswersInAnother(): void
    {
        $neutral = self::PREFIX . '-strict';
        $this->page('-strict', ['nl' => $neutral, 'en' => $neutral . '-en']);

        // THE rule of this phase: /en/<dutch-slug> is not the English version
        // of anything, and /<english-slug> is not a Dutch URL.
        self::assertNull(PageContent::forSlug($neutral, 'en'), 'the Dutch address is not an English URL');
        self::assertNull(PageContent::forSlug($neutral . '-en', 'nl'), 'the English address is not a Dutch URL');
    }

    public function testALanguageWithoutAnAddressHasNoRouteAtAll(): void
    {
        $neutral = self::PREFIX . '-no-german';
        $page = $this->page('-no-german', ['nl' => $neutral, 'en' => $neutral . '-en']);

        self::assertNull(PageContent::localizedPath($page, 'de'), 'no German address is no German URL');
        self::assertNull(PageContent::forSlug($neutral, 'de'));
        self::assertSame(
            ['nl', 'en'],
            array_keys(PageContent::localizedPaths($page)),
            'and German is not offered as a version that exists'
        );
    }

    // ----------------------------------------------------------- the paths

    public function testThePathCarriesThePrefixOfItsLanguage(): void
    {
        $neutral = self::PREFIX . '-paths';
        $page = $this->page('-paths', ['nl' => $neutral, 'en' => $neutral . '-en']);

        self::assertSame('/' . $neutral, PageContent::localizedPath($page, 'nl'));
        self::assertSame('/en/' . $neutral . '-en', PageContent::localizedPath($page, 'en'));
    }

    /**
     * A link to a page that has no version in the language being read goes to
     * the DEFAULT language's URL rather than nowhere — the opposite of what
     * the language switch does, and on purpose
     * (docs/multilingual/ROUTING.md).
     */
    public function testALinkFallsBackToTheDefaultLanguageWhereTheSwitchWouldNot(): void
    {
        $neutral = self::PREFIX . '-link';
        $page = $this->page('-link', ['nl' => $neutral]);

        self::assertSame('/' . $neutral, PageContent::publicUrl($page, 'de'));
        self::assertNull(PageContent::localizedPath($page, 'de'));
    }

    // ------------------------------------------- the neutral column still counts

    public function testAPageWithOnlyItsNeutralSlugIsStillReachableInTheDefaultLanguage(): void
    {
        // Exactly what a page created by something that never wrote a
        // localized row looks like — a fixture, an import, an older script.
        $neutral = self::PREFIX . '-neutral';
        $page = $this->page('-neutral', [], withNeutralOnly: true);

        self::assertNotNull(PageContent::forSlug($neutral, 'nl'), 'the neutral column is the default language address');
        self::assertSame('/' . $neutral, PageContent::localizedPath($page, 'nl'));
    }

    public function testTheNeutralSlugIsNeverAnotherLanguagesAddress(): void
    {
        $neutral = self::PREFIX . '-neutral-strict';
        $page = $this->page('-neutral-strict', [], withNeutralOnly: true);

        self::assertNull(PageContent::forSlug($neutral, 'en'));
        self::assertNull(PageContent::localizedPath($page, 'en'));
    }

    // -------------------------------------------------- the default-language flip

    public function testFlippingTheDefaultLanguageMovesThePrefixAndNotTheSlugs(): void
    {
        $neutral = self::PREFIX . '-flip';
        $page = $this->page('-flip', ['nl' => $neutral, 'en' => $neutral . '-en']);

        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('en', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('nl', sortOrder: 1),
        ]);

        // Same slugs, same rows, other prefixes — nothing about the content
        // moved, and both versions are still reachable.
        self::assertSame('/' . $neutral . '-en', PageContent::localizedPath($page, 'en'));
        self::assertSame('/nl/' . $neutral, PageContent::localizedPath($page, 'nl'));
    }
}
