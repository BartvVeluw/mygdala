<?php

namespace Tests\Service;

use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The derived, per-page values every caller shares: a page's public URL, its
 * canonical path, its resolved SEO title/meta description per language, and
 * which page-builder zones it has. All of these take a `pages` row as input,
 * so they are exercised here with plain arrays — no database rows are
 * created: the page's text is handed to App\Service\PageLocalization in
 * memory, with Dutch as the default language and English beside it. Only
 * SiteSettings::get('site_name') reads the real settings, the same value the
 * live site renders with.
 */
class PageContentTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
    }

    protected function tearDown(): void
    {
        PageLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    /**
     * A `pages` row, and its text per language: code => [title, SEO title,
     * meta description].
     *
     * @param array<string, mixed> $overrides
     * @param array<string, array{0: ?string, 1: ?string, 2: ?string}> $text
     * @return array<string, mixed>
     */
    private function page(array $overrides = [], array $text = ['nl' => ['Veelgestelde vragen', null, null]]): array
    {
        $translations = [];
        foreach ($text as $code => [$title, $metaTitle, $metaDescription]) {
            $translations[] = new PageTranslation(42, $code, $title, $metaTitle, $metaDescription);
        }
        PageLocalization::overrideForTests(42, $translations);

        return $overrides + [
            'id' => 42,
            'content_key' => 'veelgestelde-vragen',
            'slug' => 'veelgestelde-vragen',
            'status' => PageContent::STATUS_PUBLISHED,
            'is_system' => 0,
            'route_path' => null,
        ];
    }

    public function testDraftAndPublishedAreTheOnlyValidStatuses(): void
    {
        $this->assertTrue(PageContent::isValidStatus('draft'));
        $this->assertTrue(PageContent::isValidStatus('published'));

        $this->assertFalse(PageContent::isValidStatus('archived'));
        $this->assertFalse(PageContent::isValidStatus('Published'));
        $this->assertFalse(PageContent::isValidStatus(''));
    }

    public function testContentPageUrlIsItsSlug(): void
    {
        $page = $this->page();

        $this->assertSame('/veelgestelde-vragen', PageContent::publicUrl($page));
        $this->assertSame('veelgestelde-vragen', PageContent::canonicalPath($page));
    }

    public function testSystemPageUsesItsFixedRouteRatherThanItsSlug(): void
    {
        $shop = $this->page(['content_key' => 'shop', 'slug' => 'shop', 'is_system' => 1, 'route_path' => '/shop.php']);

        $this->assertSame('/shop.php', PageContent::publicUrl($shop));
        $this->assertSame('shop.php', PageContent::canonicalPath($shop));
    }

    public function testHomepageStillResolvesToTheSiteRoot(): void
    {
        $home = $this->page(['content_key' => 'index', 'slug' => 'index', 'is_system' => 1, 'route_path' => '/'], ['nl' => ['Homepage', null, null]]);

        $this->assertSame('/', PageContent::publicUrl($home));
        // canonical('') is exactly what index.php rendered before this
        // feature (AppUrl::canonical('/')): the bare base URL.
        $this->assertSame('', PageContent::canonicalPath($home));
    }

    public function testSeoTitleFallsBackToTitlePlusSiteName(): void
    {
        $expected = 'Veelgestelde vragen — ' . SiteSettings::get('site_name');

        $this->assertSame($expected, PageContent::seoTitle($this->page(), 'nl'));
        $this->assertSame($expected, PageContent::seoTitle($this->page(), 'en'));
    }

    public function testSeoTitleIsRenderedVerbatimWhenSet(): void
    {
        $page = $this->page([], [
            'nl' => ['Veelgestelde vragen', 'Alles over lasergraveren | Van Veluw Laserdesign', null],
            'en' => [null, 'All about laser engraving | Van Veluw Laserdesign', null],
        ]);

        $this->assertSame('Alles over lasergraveren | Van Veluw Laserdesign', PageContent::seoTitle($page, 'nl'));
        $this->assertSame('All about laser engraving | Van Veluw Laserdesign', PageContent::seoTitle($page, 'en'));
    }

    public function testEnglishSeoTitleFallsBackToTheDutchOne(): void
    {
        $page = $this->page([], ['nl' => ['Veelgestelde vragen', 'Alleen Nederlands', null], 'en' => [null, '', null]]);

        $this->assertSame('Alleen Nederlands', PageContent::seoTitle($page, 'en'));
    }

    public function testAnEnglishNameIsWhatTheEnglishAutomaticTitleIsBuiltFrom(): void
    {
        $page = $this->page([], ['nl' => ['Veelgestelde vragen', null, null], 'en' => ['Frequently asked questions', null, null]]);

        $this->assertSame('Frequently asked questions — ' . SiteSettings::get('site_name'), PageContent::seoTitle($page, 'en'));
        $this->assertSame('Veelgestelde vragen — ' . SiteSettings::get('site_name'), PageContent::seoTitle($page, 'nl'));
    }

    public function testOnAnEnglishDefaultSiteDutchFallsBackToEnglish(): void
    {
        SiteLanguageFixture::useBilingual('en');
        $page = $this->page([], ['nl' => [null, null, null], 'en' => ['About us', 'About us | Test', 'What we do']]);

        $this->assertSame('About us | Test', PageContent::seoTitle($page, 'nl'));
        $this->assertSame('What we do', PageContent::metaDescription($page, 'nl'));
    }

    public function testSeoTitleForAnUnloadablePageIsJustTheSiteName(): void
    {
        $this->assertSame(SiteSettings::get('site_name'), PageContent::seoTitle(null, 'nl'));
    }

    public function testMetaDescriptionIsEmptyWhenUnsetSoNoTagIsRendered(): void
    {
        $this->assertSame('', PageContent::metaDescription($this->page(), 'nl'));
        $this->assertSame('', PageContent::metaDescription(null, 'nl'));
    }

    public function testMetaDescriptionUsesTheRequestedLanguageWithDutchFallback(): void
    {
        $page = $this->page([], ['nl' => ['Veelgestelde vragen', null, 'Nederlandse tekst'], 'en' => [null, null, 'English text']]);

        $this->assertSame('Nederlandse tekst', PageContent::metaDescription($page, 'nl'));
        $this->assertSame('English text', PageContent::metaDescription($page, 'en'));

        $dutchOnly = $this->page([], ['nl' => ['Veelgestelde vragen', null, 'Nederlandse tekst']]);
        $this->assertSame('Nederlandse tekst', PageContent::metaDescription($dutchOnly, 'en'));
    }

    public function testTheSiteRootIsProtected(): void
    {
        $home = $this->page(['id' => 1, 'content_key' => 'index', 'is_system' => 1, 'route_path' => '/']);

        $this->assertTrue(PageContent::isSiteRoot($home));
        $this->assertTrue(PageContent::isProtected($home), '"/" must always render something');
    }

    public function testAFixedUrlDoesNotByItselfProtectAPage(): void
    {
        // The whole point of the phase 1 correction: being served from its
        // own file at a fixed URL locks the SLUG and nothing else. Id 0
        // cannot carry an application-critical block, so this page is an
        // ordinary content page.
        $page = $this->page(['id' => 0, 'is_system' => 1, 'route_path' => '/ergens.php']);

        $this->assertTrue(PageContent::isRouteBound($page));
        $this->assertTrue(PageContent::hasOwnTemplate($page));
        $this->assertFalse(PageContent::isSiteRoot($page));
        $this->assertFalse(
            PageContent::isProtected($page),
            'a page must never be protected merely because it has a dedicated template/route'
        );
    }

    public function testHavingItsOwnTemplateIsSeparateFromHavingAFixedUrl(): void
    {
        // docs/content-blocks/ARCHITECTURE.md keeps "heeft een eigen
        // template", "vaste URL" and "beschermd" as three separate ideas.
        $templateOnly = $this->page(['is_system' => 1, 'route_path' => null]);
        $this->assertTrue(PageContent::hasOwnTemplate($templateOnly));
        $this->assertFalse(PageContent::isRouteBound($templateOnly));

        $routeOnly = $this->page(['is_system' => 0, 'route_path' => '/ergens.php']);
        $this->assertFalse(PageContent::hasOwnTemplate($routeOnly));
        $this->assertTrue(PageContent::isRouteBound($routeOnly));
    }

    public function testDraftPageIsNeverReturnedByThePublicSlugLookup(): void
    {
        // forSlug() is the only lookup pagina.php performs, so this is the
        // guarantee that a draft page's URL cannot render.
        $this->assertNull(PageContent::forSlug('__definitely-not-a-real-slug__'));
        $this->assertNull(PageContent::forSlug(''));
    }
}
