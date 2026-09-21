<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * What a brand-new installation SHOWS, as opposed to what it stores.
 *
 * Tests\Install\FreshInstallTest already proves the database of a from-zero
 * installation carries no company's details. That turned out not to be the
 * same claim: the homepage hero row stored an empty image and the renderer
 * read empty as missing, so it filled the gap with this site's photograph and
 * its alt text; the shared header shipped a placeholder line item naming a
 * product at a price; and the checkout offered pickup in this site's town. A
 * clean database is not a clean page.
 *
 * So this test renders the public documents a fresh installation serves
 * before anybody has edited anything — the homepage, the storefront,
 * robots.txt and sitemap.xml — and reads them the way a stranger would.
 *
 * WHAT IT ASSERTS, and only this: the output is sensible, and this site's
 * identity is not in it. It deliberately does NOT try to catch every sentence
 * ever written about this company. Plenty of that copy is still in the code
 * as a per-section default for content that only the existing installation
 * has rows for, unreachable here by construction; chasing all of it would
 * make this test a list of historical strings rather than a guard on public
 * output. The needles below are the identity itself — the name, the domain —
 * plus the hero image that actually did leak.
 *
 * The render happens in its own process against a throwaway database, for the
 * reason Tests\Support\ScratchInstall's docblock gives.
 */
#[Group('migration-backfill')]
final class FreshInstallRenderTest extends TestCase
{
    private const DATABASE = 'mygdala_scratch_render';

    /**
     * The current site's identity, matched case-insensitively. A brand-new
     * installation must not publish any of it.
     */
    private const IDENTITY_NEEDLES = [
        'Van Veluw',
        'vanveluwlaserdesign',
    ];

    /**
     * The specific asset that leaked. It is the legacy hero default in
     * App\Service\HomepageHeroContent, and a downstream deployment's own hero
     * row still points at it, so a fresh page naming it means the renderer
     * reached for a default it should not have. Mygdala itself no longer
     * carries the file; the path in the markup is what gives the leak away.
     */
    private const LEGACY_HERO_IMAGE = 'hero-collage-a.webp';

    private static ?ScratchInstall $install = null;

    /** @var array<string, string> route => rendered output */
    private static array $rendered = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$install = ScratchInstall::fresh(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$install?->drop();
        self::$install = null;
        self::$rendered = [];
    }

    public function testTheHomepageRendersAWholePage(): void
    {
        $html = $this->render('index.php');

        $this->assertStringContainsString('<html', $html, 'The homepage rendered no document at all.');
        $this->assertStringContainsString('</html>', $html, 'The homepage rendering stopped part-way.');
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $html);
        // The bootstrap's own placeholder headline: proof the page really is
        // the generic homepage and not an error page that happens to be clean.
        $this->assertStringContainsString('Nieuwe website', $html);
    }

    public function testTheHomepageCarriesNoneOfThisSitesIdentity(): void
    {
        $this->assertNoIdentityIn($this->render('index.php'), 'the homepage');
    }

    public function testTheHomepageDoesNotFallBackToTheLegacyHeroImage(): void
    {
        $html = $this->render('index.php');

        $this->assertStringNotContainsString(
            self::LEGACY_HERO_IMAGE,
            $html,
            'The fresh homepage rendered the existing site\'s hero photograph. '
            . 'Its hero row stores an empty image on purpose; empty means "no image", not "use the default".'
        );
    }

    public function testTheHomepageRendersNoEmptyImageElement(): void
    {
        $html = $this->render('index.php');

        // src="" resolves to the page itself and draws the browser's broken
        // image icon — the failure mode a naive "just blank the path" fix has.
        $this->assertStringNotContainsString('src=""', $html);
        $this->assertDoesNotMatchRegularExpression('/<img[^>]*\ssrc=(""|\'\')/i', $html);
    }

    public function testTheHomepageShowsAnEmptyCartRatherThanADemoProduct(): void
    {
        $html = $this->render('index.php');

        // The Shop is on by default, so a fresh install renders the mini-cart
        // with nothing in it. It used to render a placeholder line item.
        $this->assertStringContainsString('Je winkelwagen is leeg.', $html);
        $this->assertStringNotContainsStringIgnoringCase('sleutelhanger', $html);
        $this->assertStringNotContainsStringIgnoringCase('Berkenhout', $html);
        $this->assertStringNotContainsString('14,95', $html);
    }

    /**
     * A fresh install has no CMS page for the Shop (INSTALL-BOOTSTRAP.md), and
     * /shop.php is still where the cart, the checkout and every product page
     * send a visitor back to. So it must be a whole page with the product
     * overview on it — not a header and a footer around nothing, and not a 404.
     */
    public function testTheStorefrontRendersTheProductOverviewWithoutACmsPage(): void
    {
        $html = $this->render('shop.php');

        $this->assertStringContainsString('</html>', $html, 'The storefront rendering stopped part-way.');
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $html);
        $this->assertStringNotContainsString('Pagina niet gevonden', $html);
        $this->assertStringContainsString('data-products-grid', $html, 'The storefront rendered no product overview.');
        $this->assertMatchesRegularExpression('#<h1[^>]*>Shop</h1>#', $html);
        $this->assertStringContainsString(
            'assets/js/shop/shop.js',
            $html,
            'The product grid must still bring its own script, through its own block definition.'
        );
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="https?://[^"]+/shop\.php">#', $html);
        $this->assertMatchesRegularExpression('#<meta name="robots" content="index,follow">#', $html);
        $this->assertNoIdentityIn($html, 'the storefront');
    }

    /**
     * ONCE PER LANGUAGE since Multilingual 2.0 phase 6: the storefront is a
     * listing at a fixed path, so it exists in every active language
     * (docs/multilingual/ROUTING.md). What must still never happen is the
     * same URL being contributed TWICE — by Core's pages collector and by
     * the Shop's own — which is what this test has always been about.
     */
    public function testTheSitemapListsTheStorefrontOncePerLanguage(): void
    {
        $sitemap = $this->render('sitemap.php');

        // How many languages the fresh installation publishes is its own
        // business (Meertaligheid starts off on a new site); the default
        // language's storefront is there exactly once either way.
        $this->assertSame(
            1,
            preg_match_all('#<loc>https?://[^/<]+/shop\.php</loc>#', $sitemap),
            'Without a Shop page the Shop module lists its storefront itself, once per language.'
        );

        preg_match_all('#<loc>([^<]*/shop\\.php)</loc>#', $sitemap, $matches);
        $this->assertSame(
            $matches[1],
            array_values(array_unique($matches[1])),
            'and never the same URL twice'
        );
    }

    public function testRobotsTxtIsGenericAndNamesNoDomainOfThisSite(): void
    {
        $robots = $this->render('robots.php');

        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Disallow: /admin/', $robots);
        $this->assertStringContainsString('Sitemap: ', $robots);
        $this->assertNoIdentityIn($robots, 'robots.txt');
    }

    public function testTheSitemapIsWellFormedAndNamesNoDomainOfThisSite(): void
    {
        $sitemap = $this->render('sitemap.php');

        $this->assertStringContainsString('<?xml', $sitemap);
        $this->assertStringContainsString('<urlset', $sitemap);
        $this->assertStringContainsString('<loc>', $sitemap);
        $this->assertNoIdentityIn($sitemap, 'sitemap.xml');
    }

    public function testNoPublicDocumentEchoesTheRequestsOwnHostname(): void
    {
        // App\Service\AppUrl never consults the Host header (SEO.md). The
        // render harness sends an impossible one so a regression would show
        // up as that domain in a canonical, an og:url or a <loc>.
        foreach (['index.php', 'shop.php', 'robots.php', 'sitemap.php'] as $route) {
            $this->assertStringNotContainsString(
                'request-host.invalid',
                $this->render($route),
                $route . ' built a URL from the request instead of from configuration.'
            );
        }
    }

    // ----------------------------------------------------------- assertions

    private function assertNoIdentityIn(string $output, string $what): void
    {
        foreach (self::IDENTITY_NEEDLES as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $output,
                "A brand-new installation published \"{$needle}\" in {$what}."
            );
        }
    }

    // ------------------------------------------------------------- fixtures

    private function render(string $template): string
    {
        if (isset(self::$rendered[$template])) {
            return self::$rendered[$template];
        }

        $install = $this->install();

        [$status, $output] = $install->runScript('tests/Support/render-public-route.php', [$template]);

        $this->assertSame(0, $status, "Rendering {$template} failed:\n" . $output);
        $this->assertNotSame('', trim($output), "Rendering {$template} produced nothing.");

        return self::$rendered[$template] = $output;
    }

    private function install(): ScratchInstall
    {
        if (self::$install === null) {
            $this->markTestSkipped(
                'A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env), like scripts/test-db.php.'
            );
        }

        return self::$install;
    }
}
