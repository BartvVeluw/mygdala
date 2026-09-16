<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AppUrl;
use App\Service\PageSeo;
use App\Service\SiteSettings;
use App\Service\SocialProfiles;
use PHPUnit\Framework\TestCase;

/**
 * How a `pages` row becomes effective SEO metadata: the title and
 * description hierarchies, the two new fields (indexability and the page's
 * own social image), and the Organization node the site root publishes.
 *
 * Plain arrays rather than database rows, on purpose — these are resolution
 * rules, and they are the same rules whatever wrote the row. Neither a
 * database nor a web server.
 */
final class PageSeoTest extends TestCase
{
    protected function setUp(): void
    {
        SiteSettings::overrideForTests(['site_name' => 'Testbedrijf']);
        // No footer_social_links rows unless a test installs some.
        SocialProfiles::overrideForTests([]);
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
        SocialProfiles::overrideForTests(null);
    }

    /** @param array<string, mixed> $overrides */
    private function page(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'content_key' => 'zz-test',
            'slug' => 'zz-test',
            'title' => 'Een pagina',
            'status' => 'published',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
            'og_image_path' => null,
            'noindex' => 0,
            'is_system' => 0,
            'route_path' => null,
        ], $overrides);
    }

    // ----------------------------------------------------------- the title

    public function testWithoutAnSeoTitleThePageTitleGetsTheSiteNameAppended(): void
    {
        $this->assertSame('Een pagina — Testbedrijf', PageSeo::forPage($this->page())->titleNl);
    }

    public function testAnSeoTitleIsUsedExactlyAsTyped(): void
    {
        $metadata = PageSeo::forPage($this->page(['meta_title' => 'Iets heel anders']));

        $this->assertSame('Iets heel anders', $metadata->titleNl);
    }

    public function testAPageTitledAfterTheSiteDoesNotRepeatIt(): void
    {
        // The homepage case: a page called "Testbedrijf" must not become
        // "Testbedrijf — Testbedrijf".
        $this->assertSame('Testbedrijf', PageSeo::forPage($this->page(['title' => 'Testbedrijf']))->titleNl);
    }

    public function testTheEnglishTitleFallsBackToTheDutchOne(): void
    {
        $metadata = PageSeo::forPage($this->page(['meta_title' => 'Alleen Nederlands']));

        $this->assertSame('Alleen Nederlands', $metadata->titleEn);
    }

    public function testAMissingPageRowStillProducesAValidHead(): void
    {
        $metadata = PageSeo::forPage(null);

        $this->assertSame('Testbedrijf', $metadata->titleNl);
        $this->assertNull($metadata->canonical);
    }

    // ----------------------------------------------------- the description

    public function testTheOwnMetaDescriptionWins(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'seo_default_description' => 'De standaardzin.',
        ]);

        $metadata = PageSeo::forPage($this->page(['meta_description' => 'De eigen zin.']));

        $this->assertSame('De eigen zin.', $metadata->descriptionNl);
    }

    public function testAPageWithoutOneFallsBackToTheGlobalDefault(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'seo_default_description' => 'De standaardzin.',
        ]);

        $this->assertSame('De standaardzin.', PageSeo::forPage($this->page())->descriptionNl);
    }

    public function testWithoutEitherThereIsNoDescription(): void
    {
        $this->assertFalse(PageSeo::forPage($this->page())->hasDescription());
    }

    // ------------------------------------------------------------ canonical

    public function testTheCanonicalIsThePagesOwnPublicUrl(): void
    {
        $metadata = PageSeo::forPage($this->page(['slug' => 'zz-canoniek']));

        $this->assertSame(AppUrl::canonical('zz-canoniek'), $metadata->canonical);
    }

    public function testTheSiteRootCanonicalsToTheBareBaseUrl(): void
    {
        $metadata = PageSeo::forPage($this->page(['route_path' => '/', 'is_system' => 1]));

        $this->assertSame(AppUrl::canonical('/'), $metadata->canonical);
    }

    // -------------------------------------------------------- indexability

    public function testAnOrdinaryPublishedPageIsIndexable(): void
    {
        $this->assertTrue(PageSeo::isIndexable($this->page()));
        $this->assertSame('index,follow', PageSeo::forPage($this->page())->robots);
    }

    public function testANoindexPageSaysSo(): void
    {
        $page = $this->page(['noindex' => 1]);

        $this->assertFalse(PageSeo::isIndexable($page));
        $this->assertSame('noindex,follow', PageSeo::forPage($page)->robots);
    }

    public function testADraftPageIsNeverIndexable(): void
    {
        // The six system pages keep answering on their own template whatever
        // their status says, so this is not hypothetical.
        $this->assertFalse(PageSeo::isIndexable($this->page(['status' => 'draft'])));
    }

    // -------------------------------------------------------- social image

    public function testAPageWithoutAnImageFallsBackToTheSiteWideOne(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'og_image_path' => 'assets/images/site-default.png',
        ]);

        $metadata = PageSeo::forPage($this->page());

        $this->assertSame(AppUrl::canonical('assets/images/site-default.png'), $metadata->ogImageUrl);
    }

    public function testAPagesOwnImageWins(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'og_image_path' => 'assets/images/site-default.png',
        ]);

        $metadata = PageSeo::forPage($this->page(['og_image_path' => 'assets/images/sections/eigen.png']));

        $this->assertSame(AppUrl::canonical('assets/images/sections/eigen.png'), $metadata->ogImageUrl);
    }

    // ------------------------------------------------------ structured data

    public function testOnlyTheSiteRootPublishesStructuredData(): void
    {
        $this->assertNull(PageSeo::forPage($this->page())->jsonLd);

        $root = PageSeo::forPage($this->page(['route_path' => '/', 'is_system' => 1]));
        $this->assertNotNull($root->jsonLd);
        $this->assertSame('Organization', $root->jsonLd['@type']);
        $this->assertSame('Testbedrijf', $root->jsonLd['name']);
        $this->assertSame(AppUrl::canonical('/'), $root->jsonLd['url']);
    }

    public function testTheOrganizationNodeClaimsOnlyWhatIsConfigured(): void
    {
        // No logo and no social profiles configured: neither key is invented.
        $jsonLd = PageSeo::forPage($this->page(['route_path' => '/', 'is_system' => 1]))->jsonLd;

        $this->assertArrayNotHasKey('logo', (array) $jsonLd);
        $this->assertArrayNotHasKey('sameAs', (array) $jsonLd);
    }

    public function testTheOrganizationNodeCarriesTheConfiguredLogoAndProfiles(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'logo_path' => 'assets/images/logo.svg',
        ]);
        // The visible footer_social_links rows, in the editor's order: the
        // same address listed twice is claimed once, and a row the footer
        // would not render (a foreign domain) is not claimed at all.
        SocialProfiles::overrideForTests([
            ['id' => 1, 'network' => 'linkedin', 'url' => 'https://www.linkedin.com/company/testbedrijf', 'sort_order' => 0, 'is_visible' => 1],
            ['id' => 2, 'network' => 'instagram', 'url' => 'https://www.instagram.com/testbedrijf', 'sort_order' => 1, 'is_visible' => 1],
            ['id' => 3, 'network' => 'pinterest', 'url' => 'https://pin.nl/testbedrijf', 'sort_order' => 2, 'is_visible' => 1],
            ['id' => 4, 'network' => 'instagram', 'url' => 'https://www.instagram.com/testbedrijf', 'sort_order' => 3, 'is_visible' => 1],
        ]);

        $jsonLd = (array) PageSeo::forPage($this->page(['route_path' => '/', 'is_system' => 1]))->jsonLd;

        $this->assertSame(AppUrl::canonical('assets/images/logo.svg'), $jsonLd['logo']);
        $this->assertSame(
            ['https://www.linkedin.com/company/testbedrijf', 'https://www.instagram.com/testbedrijf'],
            $jsonLd['sameAs']
        );
    }

    public function testANoindexSiteRootPublishesNoStructuredData(): void
    {
        $metadata = PageSeo::forPage($this->page(['route_path' => '/', 'is_system' => 1, 'noindex' => 1]));

        $this->assertNull($metadata->jsonLd);
    }
}
