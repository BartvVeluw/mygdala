<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AppEnvironment;
use App\Service\AppUrl;
use App\Service\Robots;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;

/**
 * The generated /robots.txt: that the sitemap it advertises comes from the
 * configured application URL rather than from a domain typed into a file,
 * and that a non-production deployment asks not to be crawled.
 *
 * Neither a database nor a web server — the document is built from
 * configuration alone. tests/Service/SeoRoutingTest.php checks that the
 * .htaccess rewrite actually serves it.
 */
final class RobotsTest extends TestCase
{
    private ?string $originalAppUrl = null;

    protected function setUp(): void
    {
        $this->originalAppUrl = $_ENV['APP_URL'] ?? null;
        AppEnvironment::overrideForTests(null);
    }

    protected function tearDown(): void
    {
        AppEnvironment::overrideForTests(null);

        if ($this->originalAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->originalAppUrl;
        }
    }

    public function testItAdvertisesTheSitemapAtTheConfiguredBaseUrl(): void
    {
        $this->assertStringContainsString(
            'Sitemap: ' . AppUrl::canonical(Sitemap::PATH),
            Robots::txt()
        );
    }

    public function testTheSitemapUrlFollowsTheConfiguredDomainRatherThanALiteral(): void
    {
        $_ENV['APP_URL'] = 'https://www.een-andere-site.example';

        $txt = Robots::txt();

        $this->assertStringContainsString('Sitemap: https://www.een-andere-site.example/sitemap.xml', $txt);
        $this->assertStringNotContainsString('vanveluwlaserdesign', $txt);
    }

    public function testTheProductionDocumentKeepsTheDirectivesItAlwaysHad(): void
    {
        $txt = Robots::txt();

        $this->assertStringContainsString('User-agent: *', $txt);
        $this->assertStringContainsString('Allow: /', $txt);
    }

    public function testTheAdminAndTheApiAreNotWorthCrawling(): void
    {
        $txt = Robots::txt();

        $this->assertStringContainsString('Disallow: /admin/', $txt);
        $this->assertStringContainsString('Disallow: /api/', $txt);
    }

    public function testTheTransactionalShopRoutesAreCrawlableSoTheirNoindexCanBeRead(): void
    {
        // Blocking the crawl and refusing the index are different things: a
        // crawler must be allowed to FETCH cart.php to discover that it may
        // not INDEX it.
        $txt = Robots::txt();

        $this->assertStringNotContainsString('cart.php', $txt);
        $this->assertStringNotContainsString('checkout.php', $txt);
    }

    public function testANonProductionDeploymentAsksNotToBeCrawled(): void
    {
        foreach (['staging', 'local', 'development', 'test'] as $environment) {
            AppEnvironment::overrideForTests($environment);

            $txt = Robots::txt();

            $this->assertStringContainsString('Disallow: /', $txt, $environment);
            $this->assertStringNotContainsString('Allow: /', $txt, $environment);
            // Pointing a crawler at a list of staging URLs would defeat the
            // Disallow it was just given.
            $this->assertStringNotContainsString('Sitemap:', $txt, $environment);
        }
    }

    public function testAnythingButAKnownNonProductionValueMeansProduction(): void
    {
        // The safe default: a missing, empty or misspelt APP_ENV can never
        // take a live site out of the search index.
        foreach ([null, '', 'production', 'prod', 'stagng', 'live'] as $environment) {
            AppEnvironment::overrideForTests($environment);

            $this->assertTrue(AppEnvironment::isProduction(), var_export($environment, true));
            $this->assertStringContainsString('Allow: /', Robots::txt());
        }
    }
}
