<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AppUrl;
use App\Service\Seo;
use App\Service\SeoDefaults;
use App\Service\SeoMetadata;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The effective-metadata layer: the fallback hierarchy in
 * App\Service\SeoMetadata::create(), the global defaults it falls back to,
 * and what partials/seo-head.php makes of the result.
 *
 * Neither a database nor a web server: SiteSettings::overrideForTests()
 * stands in for the settings table and $_ENV['APP_URL'] for the .env, which
 * is also what lets the last group of tests below run the whole thing as a
 * DIFFERENT site — a different name, a different domain, a different default
 * description — and prove that not one Van Veluw literal comes out.
 */
final class SeoMetadataTest extends TestCase
{
    private ?string $originalAppUrl = null;

    protected function setUp(): void
    {
        $this->originalAppUrl = $_ENV['APP_URL'] ?? null;
        SiteSettings::overrideForTests(['site_name' => 'Testbedrijf']);
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);

        if ($this->originalAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->originalAppUrl;
        }
    }

    // ----------------------------------------------------------- the title

    public function testAnEmptyTitleFallsBackToTheSiteName(): void
    {
        $this->assertSame('Testbedrijf', SeoMetadata::create(title: '')->title());
    }

    public function testAResolverHandsOverOneTitleAndOneDescriptionAndNoPair(): void
    {
        $metadata = SeoMetadata::create(title: 'About us', description: 'Who we are.');

        $this->assertSame('About us', $metadata->title());
        $this->assertSame('Who we are.', $metadata->description());

        foreach (['titleNl', 'titleEn', 'descriptionNl', 'descriptionEn'] as $v1Property) {
            $this->assertFalse(property_exists($metadata, $v1Property), $v1Property . ' is the removed V1 pair');
        }
    }

    public function testTheRouteTitleConventionAppendsTheSiteNameOnce(): void
    {
        $this->assertSame('Winkelwagen | Testbedrijf', Seo::routeTitle('Winkelwagen'));
    }

    public function testARouteTitleThatIsAlreadyTheSiteNameIsNotRepeated(): void
    {
        // The homepage-title bug in its general form: "Testbedrijf |
        // Testbedrijf" is never produced.
        $this->assertSame('Testbedrijf', Seo::routeTitle('Testbedrijf'));
    }

    public function testARouteTitleSurvivesAnInstallWithoutASiteName(): void
    {
        SiteSettings::overrideForTests(['site_name' => '']);

        $this->assertSame('Winkelwagen', Seo::routeTitle('Winkelwagen'));
    }

    // ----------------------------------------------------- the description

    public function testAMissingDescriptionFallsBackToTheGlobalDefault(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'seo_default_description' => 'Wat dit bedrijf doet, in één zin.',
        ]);

        $metadata = SeoMetadata::create(title: 'Een pagina');

        $this->assertTrue($metadata->hasDescription());
        $this->assertSame('Wat dit bedrijf doet, in één zin.', $metadata->description());
    }

    public function testWithoutAGlobalDefaultThereIsNoDescriptionAtAll(): void
    {
        // Better than a generic sentence on every page: no tag.
        $this->assertFalse(SeoMetadata::create(title: 'Een pagina')->hasDescription());
    }

    public function testAnOwnDescriptionIsNeverReplacedByTheGlobalDefault(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'seo_default_description' => 'De standaardzin.',
        ]);

        $metadata = SeoMetadata::create(title: 'Een pagina', description: 'De eigen zin.');

        $this->assertSame('De eigen zin.', $metadata->description());
    }

    // -------------------------------------------------------- social image

    public function testTheSocialImageFallsBackToTheSiteWideOne(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'og_image_path' => 'assets/images/site-default.png',
        ]);

        $metadata = SeoMetadata::create(title: 'T', canonical: AppUrl::canonical('/'));

        $this->assertSame(AppUrl::canonical('assets/images/site-default.png'), $metadata->ogImageUrl);
    }

    public function testAnOwnSocialImageWinsOverTheSiteWideOne(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'og_image_path' => 'assets/images/site-default.png',
        ]);

        $metadata = SeoMetadata::create(
            title: 'T',
            canonical: AppUrl::canonical('/'),
            socialImage: 'assets/images/eigen.png'
        );

        $this->assertSame(AppUrl::canonical('assets/images/eigen.png'), $metadata->ogImageUrl);
    }

    public function testAnInstallWithNoImageAtAllGetsNoSocialImage(): void
    {
        $this->assertNull(SeoMetadata::create(title: 'T')->ogImageUrl);
        $this->assertNull(SeoMetadata::create(title: 'T')->twitterCard());
    }

    public function testTheTwitterCardIsLargeImageWheneverThereIsAnImage(): void
    {
        $metadata = SeoMetadata::create(title: 'T', socialImage: 'assets/images/eigen.png');

        $this->assertSame('summary_large_image', $metadata->twitterCard());
    }

    // ------------------------------------------------------------ canonical

    public function testTheCanonicalIsKeptWhenItIsAbsolute(): void
    {
        $canonical = AppUrl::canonical('contact.php');

        $this->assertSame($canonical, SeoMetadata::create(title: 'T', canonical: $canonical)->canonical);
    }

    public function testARelativeOrHostileCanonicalIsDroppedRatherThanRendered(): void
    {
        foreach (['/contact.php', 'javascript:alert(1)', 'not a url', ''] as $value) {
            $this->assertNull(
                SeoMetadata::create(title: 'T', canonical: $value)->canonical,
                $value . ' must not become a canonical URL'
            );
        }
    }

    // --------------------------------------------------------------- robots

    public function testPublicContentIsIndexableByDefault(): void
    {
        $metadata = SeoMetadata::create(title: 'T');

        $this->assertSame('index,follow', $metadata->robots);
        $this->assertTrue($metadata->isIndexable());
    }

    public function testAPageCanOptOutOfIndexing(): void
    {
        $this->assertSame('noindex,follow', SeoMetadata::create(title: 'T', indexable: false)->robots);
    }

    public function testTheWholeInstallCanBeSetToNoindex(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'seo_robots_index_default' => '0',
        ]);

        $this->assertSame('noindex,follow', SeoMetadata::create(title: 'T')->robots);
    }

    public function testOnlyAnExplicitOffValueDeindexesTheSite(): void
    {
        // A typo must never take a live site out of the index.
        foreach (['1', 'ja', 'yes', 'kaas', ''] as $value) {
            SiteSettings::overrideForTests([
                'site_name' => 'Testbedrijf',
                'seo_robots_index_default' => $value,
            ]);

            $this->assertSame(
                SeoDefaults::ROBOTS_INDEX,
                SeoDefaults::robots(),
                '"' . $value . '" must not be read as "do not index"'
            );
        }
    }

    public function testANotFoundPageClaimsNothing(): void
    {
        $metadata = SeoMetadata::notFound('Pagina niet gevonden — Testbedrijf');

        $this->assertSame('noindex,follow', $metadata->robots);
        $this->assertNull($metadata->canonical);
        $this->assertNull($metadata->ogImageUrl);
        $this->assertFalse($metadata->hasDescription());
    }

    // ------------------------------------------------------------ rendering

    public function testTheRendererEscapesEverythingItPrints(): void
    {
        $html = $this->render(SeoMetadata::create(
            title: 'Groot "en" <b>vet</b> & zo',
            description: 'Een "citaat" & <script>alert(1)</script>',
            canonical: AppUrl::canonical('contact.php')
        ));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>vet</b>', $html);
        $this->assertStringContainsString('&quot;en&quot;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function testTheRendererOmitsEveryTagItHasNoValueFor(): void
    {
        $html = $this->render(SeoMetadata::create(title: 'Kaal'));

        $this->assertStringNotContainsString('name="description"', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('twitter:', $html);
        // ...but never an empty one.
        $this->assertStringNotContainsString('content=""', $html);
    }

    public function testTheRendererPrintsTheFullSetForACompletePage(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'og_image_path' => 'assets/images/site-default.png',
        ]);

        $html = $this->render(SeoMetadata::create(
            title: 'Contact',
            description: 'Neem contact op.',
            canonical: AppUrl::canonical('contact.php')
        ));

        $this->assertStringNotContainsString('data-nl', $html, 'one language per document: no V1 pair');
        $this->assertStringNotContainsString('data-en', $html);

        foreach ([
            '<title>Contact</title>',
            'name="description" content="Neem contact op."',
            'name="robots" content="index,follow"',
            '<link rel="canonical" href="' . AppUrl::canonical('contact.php') . '">',
            'property="og:title" content="Contact"',
            'property="og:description" content="Neem contact op."',
            'property="og:url" content="' . AppUrl::canonical('contact.php') . '"',
            'property="og:type" content="website"',
            'property="og:image" content="' . AppUrl::canonical('assets/images/site-default.png') . '"',
            'property="og:site_name" content="Testbedrijf"',
            'name="twitter:card" content="summary_large_image"',
            'name="twitter:title" content="Contact"',
            'name="twitter:image" content="' . AppUrl::canonical('assets/images/site-default.png') . '"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected . ' is missing');
        }
    }

    public function testAPageWithoutACanonicalGetsNoSharePreview(): void
    {
        SiteSettings::overrideForTests([
            'site_name' => 'Testbedrijf',
            'og_image_path' => 'assets/images/site-default.png',
        ]);

        $html = $this->render(SeoMetadata::notFound('Pagina niet gevonden'));

        $this->assertStringContainsString('name="robots" content="noindex,follow"', $html);
        $this->assertStringNotContainsString('og:', $html);
        $this->assertStringNotContainsString('twitter:', $html);
    }

    public function testStructuredDataCannotBreakOutOfItsScriptElement(): void
    {
        $html = $this->render(SeoMetadata::create(
            title: 'T',
            canonical: AppUrl::canonical('/'),
            jsonLd: ['@type' => 'Organization', 'name' => 'Boze </script><script>alert(1)</script>']
        ));

        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('</script><script>', $html);
        $this->assertStringContainsString('<', $html);
    }

    // ------------------------------------- the same code as a different site

    public function testAnotherSiteProducesItsOwnMetadataAndNoVanVeluwLiterals(): void
    {
        $_ENV['APP_URL'] = 'https://www.een-andere-site.example';
        SiteSettings::overrideForTests([
            'site_name' => 'Andere Site',
            'seo_default_description' => 'Een heel andere onderneming.',
            'og_image_path' => 'assets/images/andere-deelafbeelding.png',
        ]);

        $html = $this->render(SeoMetadata::create(
            title: Seo::routeTitle('Winkelwagen'),
            canonical: AppUrl::canonical('cart.php')
        ));

        $this->assertStringContainsString('Winkelwagen | Andere Site', $html);
        $this->assertStringContainsString('https://www.een-andere-site.example/cart.php', $html);
        $this->assertStringContainsString('Een heel andere onderneming.', $html);
        $this->assertStringContainsString(
            'https://www.een-andere-site.example/assets/images/andere-deelafbeelding.png',
            $html
        );

        $this->assertStringNotContainsString('vanveluwlaserdesign', strtolower($html));
        $this->assertStringNotContainsString('van veluw', strtolower($html));
    }

    /** The real partial, rendered into a string. */
    private function render(SeoMetadata $metadata): string
    {
        $seoMetadata = $metadata;

        ob_start();
        require dirname(__DIR__, 2) . '/partials/seo-head.php';

        return (string) ob_get_clean();
    }
}
