<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\RedirectRepository;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioSlug;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * The address of a Portfolio project page, /portfolio/<slug>, answered the
 * way a visitor reaches it: through dispatcher.php (Portfolio 2.0,
 * docs/multilingual/ROUTING.md), with PHP's built-in server standing in for
 * Apache via tests/Support/dispatcher-router.php.
 *
 *   - a visible item with its project page switched on: 200, its own words,
 *     its canonical, its breadcrumb and one lightbox group of its pictures;
 *   - an unknown slug, a hidden item, a page switched off: 404;
 *   - a renamed project: its old address 301s to the new one;
 *   - a legacy linked page: a 302 to that page, as before;
 *   - an ordinary CMS page next to it still answers, and the project never
 *     became a `pages` row;
 *   - `portfolio` is refused as a page's address with a message that names
 *     the module that owns it.
 *
 * Everything made here is its own, marked zz-, and removed again in
 * tearDown(). When the server cannot be started the test skips itself, like
 * the HTTP tier does (TESTING.md).
 */
final class PortfolioProjectRoutingHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<string> */
    private array $redirectSources = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    protected function tearDown(): void
    {
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }

        $pages = new PageRepository();
        foreach ($this->pageIds as $id) {
            if ($pages->findById($id) !== null) {
                $pages->delete($id);
            }
        }

        $redirects = new RedirectRepository();
        foreach ($this->redirectSources as $source) {
            $row = $redirects->findBySourcePath($source);
            if ($row !== null) {
                $redirects->delete((int) $row['id']);
            }
        }

        $this->itemIds = [];
        $this->pageIds = [];
        $this->redirectSources = [];
        PortfolioGalleryContent::clearCache();
        PageContent::clearCache();
    }

    public function testAProjectPageAnswersAtItsOwnAddress(): void
    {
        $pagesBefore = $this->pageCount();
        [$itemId, $slug] = $this->project('ZZ Gegraveerde snijplank');

        $response = self::$server->request('GET', '/portfolio/' . $slug);

        $this->assertSame(200, $response['status']);
        $body = $response['body'];
        $this->assertStringContainsString('<h1 class="project-hero__title">ZZ Gegraveerde snijplank</h1>', $body);
        $this->assertStringContainsString('ZZ Het verhaal', $body, 'the description');
        $this->assertStringContainsString('<link rel="canonical" href="' . PortfolioGalleryContent::canonicalUrlForSlug($slug) . '">', $body);
        $this->assertStringContainsString('<title>ZZ Gegraveerde snijplank | Portfolio', $body);
        $this->assertStringContainsString('<meta name="description" content="ZZ korte tekst">', $body, 'the short text is the description');
        $this->assertStringContainsString('property="og:image"', $body, 'the main picture is the share image');
        $this->assertStringContainsString('class="breadcrumb', $body, 'a breadcrumb');
        $this->assertStringContainsString('data-lightbox-group', $body);
        $this->assertSame(1, substr_count($body, 'data-lightbox '), 'one lightbox overlay');
        $this->assertStringContainsString('/assets/js/lightbox.js', $body, 'the one lightbox script');
        $this->assertStringNotContainsString('portfolio-detail.js', $body);
        $this->assertSame($pagesBefore, $this->pageCount(), 'a project is never a pages row');
    }

    public function testAnUnknownHiddenOrSwitchedOffProjectIsA404(): void
    {
        $this->assertSame(404, self::$server->request('GET', '/portfolio/zz-bestaat-niet-' . bin2hex(random_bytes(4)))['status']);

        [$hiddenId, $hiddenSlug] = $this->project('ZZ Verborgen');
        Database::connection()->prepare('UPDATE portfolio_gallery_items SET is_active = 0 WHERE id = ?')->execute([$hiddenId]);
        $this->assertSame(404, self::$server->request('GET', '/portfolio/' . $hiddenSlug)['status'], 'a hidden item');

        [$offId, $offSlug] = $this->project('ZZ Uit');
        (new PortfolioGalleryRepository())->setItemProjectPage($offId, false, $offSlug);
        $this->assertSame(404, self::$server->request('GET', '/portfolio/' . $offSlug)['status'], 'a page switched off');
    }

    public function testARenamedProjectsOldAddressRedirectsPermanently(): void
    {
        [$itemId, $oldSlug] = $this->project('ZZ Hernoemd');
        $newSlug = 'zz-hernoemd-nieuw-' . bin2hex(random_bytes(3));
        (new PortfolioGalleryRepository())->setItemProjectPage($itemId, true, $newSlug);

        $this->assertGreaterThan(0, PortfolioSlug::recordRename(true, $oldSlug, true, $newSlug));
        foreach (SiteLanguages::activeCodes() as $language) {
            $this->redirectSources[] = \App\Service\Routing\LocalizedUrl::path('/portfolio/' . $oldSlug, $language);
        }

        $old = self::$server->request('GET', '/portfolio/' . $oldSlug);
        $this->assertSame(301, $old['status']);
        $this->assertStringEndsWith('/portfolio/' . $newSlug, $old['location']);

        $this->assertSame(200, self::$server->request('GET', '/portfolio/' . $newSlug)['status']);
    }

    public function testALegacyLinkedPageStillTakesTheAddress(): void
    {
        [$itemId, $slug] = $this->project('ZZ Gekoppeld');
        $pageId = $this->page();
        (new PortfolioGalleryRepository())->setItemPage($itemId, $pageId);
        $page = (array) (new PageRepository())->findById($pageId);

        $response = self::$server->request('GET', '/portfolio/' . $slug);
        $this->assertSame(302, $response['status'], 'temporary, as before Portfolio 2.0');
        $this->assertSame(PageContent::canonicalUrl($page), $response['location']);

        $this->assertSame(200, self::$server->request('GET', '/' . $page['slug'])['status'], 'the ordinary page itself answers, Pages 2.0 untouched');
    }

    public function testPortfolioIsRefusedAsAPageAddressWithTheOwningModuleNamed(): void
    {
        $message = \App\Service\PageService::validateSlug(new PageRepository(), 'portfolio', null, PortfolioLocalization::defaultLanguage());
        $this->assertNotNull($message);
        $this->assertStringContainsString('Portfolio', (string) $message, 'the editor is told which module owns the word');

        $notice = \App\Service\PageService::generatedSlugNotice('Portfolio', 'portfolio-2');
        $this->assertNotNull($notice, 'an address made from the title "Portfolio" explains its -2');
        $this->assertStringContainsString('portfolio-2', (string) $notice);
        $this->assertNull(\App\Service\PageService::generatedSlugNotice('Over ons', 'over-ons'), 'an address that is simply the title needs no word');
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0: int, 1: string} [item id, slug] */
    private function project(string $title): array
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(4));

        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-routing-' . $marker . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;

        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => $title,
            PortfolioLocalization::SUBTITLE => 'ZZ korte tekst',
            PortfolioLocalization::DESCRIPTION => '<h2>ZZ Het verhaal</h2><p>Lang verhaal.</p>',
        ]);

        $slug = 'zz-project-' . $marker;
        $repository->setItemProjectPage($id, true, $slug);

        return [$id, $slug];
    }

    private function page(): int
    {
        $key = 'zz-gekoppelde-pagina-' . bin2hex(random_bytes(4));
        $id = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => PageContent::STATUS_PUBLISHED,
        ], 'ZZ Gekoppelde pagina');
        $this->pageIds[] = $id;

        return $id;
    }

    private function pageCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM pages')->fetchColumn();
    }
}
