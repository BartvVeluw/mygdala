<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;
use Tests\Support\ScratchInstall;

/**
 * /portfolio on the installations it has to work on, over real HTTP through
 * the dispatcher (tests/Support/dispatcher-router.php), each against its own
 * scratch database:
 *
 *   fresh    every migration from zero, as a brand-new installation of this
 *            CMS: no Portfolio page exists, and none may be seeded
 *            (Tests\Install\FreshInstallTest); Portfolio switched on
 *            afterwards, off, and on again
 *   legacy   an installation that predates the install marker, whose history
 *            seeded the Portfolio page at /portfolio.php
 *
 * What must hold (MODULES.md, "Portfolio"): with no page the module shows its
 * own overview at /portfolio, a project page and the old /portfolio.php work
 * beside it, switching the module off answers 404 at all three, and nothing
 * is ever created for it — so switching on again, or running the bootstrap
 * again, can never make a second one. With the page, the page is the
 * overview, under its own id and content, at /portfolio. An ordinary page is
 * never adopted as the overview.
 */
#[Group('migration-backfill')]
final class PortfolioRootInstallTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_portfolio_root_fresh';
    private const LEGACY = 'mygdala_scratch_portfolio_root_legacy';

    private const ROUTER = 'tests/Support/dispatcher-router.php';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $legacy = null;

    /** @var array<string, int> */
    private static array $pagesBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);
        self::$legacy = ScratchInstall::legacy(self::LEGACY);
    }

    protected function setUp(): void
    {
        if (self::$fresh === null || self::$legacy === null) {
            $this->markTestSkipped('scratch databases need DB_ROOT_PASSWORD (TESTING.md)');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$legacy?->drop();
    }

    public function testAFreshInstallHasNoPortfolioPageAndServesTheOverviewOnceSwitchedOn(): void
    {
        $this->assertSame([], self::$fresh->rows("SELECT id FROM pages WHERE content_key = 'portfolio'"), 'a fresh installation seeds no Portfolio page');
        $pagesBefore = self::$fresh->count('pages');
        $slug = $this->projectOnFreshInstall();

        $on = $this->server(self::$fresh, true);

        $root = $on->request('GET', '/portfolio');
        $this->assertSame(200, $root['status'], '/portfolio answers on a fresh installation');
        $this->assertStringContainsString('<h1>Portfolio</h1>', $root['body']);
        $this->assertStringContainsString('ZZ Vers project', $root['body'], 'the module shows its own projects');
        $this->assertStringContainsString('data-lightbox-trigger', $root['body']);
        $this->assertStringContainsString('href="/portfolio/' . $slug . '"', $root['body'], '"Bekijk project" goes to the project page');
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]*/portfolio">#', $root['body'], 'its canonical is the root');

        $project = $on->request('GET', '/portfolio/' . $slug);
        $this->assertSame(200, $project['status'], 'a project page works beside it');
        $this->assertStringContainsString('href="/portfolio"', $project['body'], 'its breadcrumb and back link name the root');

        $old = $on->request('GET', '/portfolio.php');
        $this->assertSame(301, $old['status']);
        $this->assertStringEndsWith('/portfolio', $old['location']);

        $sitemap = (string) $on->request('GET', '/sitemap.xml')['body'];
        $this->assertOverviewListedOncePerLanguage($sitemap, self::$fresh);
        $this->assertStringContainsString('/portfolio/' . $slug . '</loc>', $sitemap);
        $this->assertStringNotContainsString('/portfolio.php</loc>', $sitemap);

        $on->stop();

        $off = $this->server(self::$fresh, false);
        foreach (['/portfolio', '/portfolio/' . $slug, '/portfolio.php'] as $path) {
            $this->assertSame(404, $off->request('GET', $path)['status'], $path . ' answers 404 with Portfolio off');
        }
        $off->stop();

        $again = $this->server(self::$fresh, true);
        $this->assertSame(200, $again->request('GET', '/portfolio')['status'], 'on again, the same overview');
        $again->stop();

        $this->assertSame($pagesBefore, self::$fresh->count('pages'), 'switching on, off and on again created no page');
        $this->assertSame([], self::$fresh->rows("SELECT id FROM pages WHERE content_key = 'portfolio'"), 'and no Portfolio page at all');
    }

    /** Running the bootstrap and the root migration a second time creates nothing either. */
    public function testTheBootstrapTwiceStaysIdempotent(): void
    {
        $before = self::$fresh->count('pages');

        self::$fresh->replay('20260909400000');
        self::$fresh->replay('20260925170000');

        $this->assertSame($before, self::$fresh->count('pages'));
        $this->assertSame([], self::$fresh->rows("SELECT id FROM pages WHERE content_key = 'portfolio'"));
    }

    /**
     * An ordinary page an editor names "Portfolio" is not the overview: it
     * gets its own content key and its own address, and /portfolio keeps
     * showing the module's own overview.
     */
    public function testAnOrdinaryPageIsNeverAdoptedAsTheOverview(): void
    {
        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO pages (admin_group, content_key, slug, status, is_system, created_at, updated_at)
                    VALUES ('website', 'portfolio-2', 'portfolio-2', 'published', 0, NOW(), NOW())");
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO page_translations (page_id, language_code, slug, title, created_at, updated_at)
                       VALUES (?, 'nl', 'portfolio-2', 'ZZ Gewone portfoliopagina', NOW(), NOW())")->execute([$id]);

        $on = $this->server(self::$fresh, true);
        $root = $on->request('GET', '/portfolio');
        $page = $on->request('GET', '/portfolio-2');
        $on->stop();

        $this->assertSame(200, $root['status']);
        $this->assertStringNotContainsString('ZZ Gewone portfoliopagina', $root['body'], 'the ordinary page is not the overview');
        $this->assertStringContainsString('<h1>Portfolio</h1>', $root['body']);
        $this->assertSame(200, $page['status'], 'and it answers at its own address');
    }

    /**
     * An installation with history: its Portfolio page, seeded at
     * /portfolio.php, is the overview at /portfolio — the same row, the same
     * content — and the module's own overview does not appear beside it.
     */
    public function testALegacyInstallKeepsItsOwnPortfolioPageAtTheRoot(): void
    {
        $rows = self::$legacy->rows("SELECT id, route_path, status FROM pages WHERE content_key = 'portfolio'");
        $this->assertCount(1, $rows, 'exactly one Portfolio page');
        $this->assertSame('/portfolio', $rows[0]['route_path'], 'moved from /portfolio.php by 20260925170000');

        $on = $this->server(self::$legacy, true);
        $root = $on->request('GET', '/portfolio');
        $sitemap = (string) $on->request('GET', '/sitemap.xml')['body'];
        $old = $on->request('GET', '/portfolio.php');
        $on->stop();

        $this->assertSame(200, $root['status']);
        $this->assertStringNotContainsString('<h1>Portfolio</h1>', $root['body'], "the page's own blocks, not the module's overview");
        $this->assertStringContainsString('gallery-grid', $root['body'], 'its seeded gallery block');
        $this->assertOverviewListedOncePerLanguage($sitemap, self::$legacy);
        $this->assertSame(301, $old['status']);

        $this->assertCount(1, self::$legacy->rows("SELECT id FROM pages WHERE content_key = 'portfolio'"), 'still exactly one');
        $this->assertSame((int) $rows[0]['id'], (int) self::$legacy->rows("SELECT id FROM pages WHERE content_key = 'portfolio'")[0]['id'], 'under the same id');
    }

    /* ------------------------------------------------------------------ */

    /**
     * The overview's <loc>s: none twice, the default language's exactly once,
     * and no more than one per language — whichever collector lists it.
     */
    private function assertOverviewListedOncePerLanguage(string $sitemap, ScratchInstall $install): void
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $sitemap, $matches);
        $overview = array_values(array_filter(
            $matches[1],
            static fn (string $loc): bool => preg_match('#^https?://[^/]+(?:/[a-z]{2,3})?/portfolio$#', $loc) === 1
        ));
        $languages = count($install->rows('SELECT code FROM site_languages WHERE is_active = 1'));

        $this->assertSame($overview, array_values(array_unique($overview)), 'no overview address is listed twice');
        $this->assertCount(1, array_filter($overview, static fn (string $loc): bool => preg_match('#^https?://[^/]+/portfolio$#', $loc) === 1), 'the default language once, unprefixed');
        $this->assertLessThanOrEqual($languages, count($overview), 'at most one per active language (only the published ones are listed)');
    }

    private function server(ScratchInstall $install, bool $portfolio): BuiltInServer
    {
        $server = BuiltInServer::start([
            'DB_DATABASE' => $install->database,
            'MODULE_PORTFOLIO_ENABLED' => $portfolio ? 'true' : 'false',
        ], self::ROUTER);

        if ($server === null || !$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        return $server;
    }

    /** One visible item with its own project page, written as its editor writes it. */
    private function projectOnFreshInstall(): string
    {
        $pdo = self::$fresh->pdo();
        $pdo->exec("INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())");
        $galleryId = (int) $pdo->lastInsertId();

        $slug = 'zz-vers-project';
        $pdo->prepare(
            "INSERT INTO portfolio_gallery_items (portfolio_gallery_id, image_path, has_detail_page, slug, sort_order, is_active, created_at, updated_at)
             VALUES (?, 'assets/images/sections/zz-vers.jpg', 1, ?, 1, 1, NOW(), NOW())"
        )->execute([$galleryId, $slug]);
        $itemId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO portfolio_item_translations (portfolio_item_id, language_code, title, subtitle, created_at, updated_at)
             VALUES (?, 'nl', 'ZZ Vers project', 'ZZ korte tekst', NOW(), NOW())"
        )->execute([$itemId]);

        return $slug;
    }
}
