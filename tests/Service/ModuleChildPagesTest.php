<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductRepository;
use App\Service\AdminPermissions;
use App\Service\Breadcrumbs\PageBreadcrumb;
use App\Service\LinkResolver;
use App\Service\ModuleSystemPages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioSlug;
use App\Service\ProductSeo;
use App\Service\Redirects\RedirectTarget;
use App\Service\Routing\LinkTargets;
use App\Service\Routing\RequestLanguage;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * Pages under the Shop's and the Portfolio's system pages (Pages &
 * Destinations 3.0, docs/pages/NESTING.md "Pagina's onder Shop en
 * Portfolio"), against the real database:
 *
 *   - a system page that names a child prefix may be a parent; every other
 *     page with a fixed URL still may not;
 *   - a page under the Shop lives at /shop/<slug> (the Shop's own word, never
 *     its /shop.php), under the Portfolio at /portfolio/<slug>, nested and in
 *     every language, and is found by that whole path only;
 *   - the breadcrumb names the system page the way the module's own trails
 *     do, never by the prefix;
 *   - with the module off the whole subtree answers 404, drops out of the
 *     sitemap and every link, and comes back unchanged when it is on;
 *   - /portfolio/<slug> is ONE namespace: a page directly under the
 *     Portfolio and a project never share a slug, checked both ways and in
 *     every language, while nothing the Shop serves lives under /shop/ (a
 *     product is /product.php?id=), so a Shop page and a product may share a
 *     word.
 *
 * Every page, project and product here is the test's own (zz-modkind) and is
 * removed in tearDown(), children before their parents.
 */
final class ModuleChildPagesTest extends TestCase
{
    private const P = 'zz-modkind';

    private static ?BuiltInServer $on = null;

    private static ?BuiltInServer $off = null;

    private PageRepository $pages;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $productIds = [];

    private ?AdminTestSession $accounts = null;

    public static function setUpBeforeClass(): void
    {
        self::$on = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
        self::$off = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false', 'MODULE_PORTFOLIO_ENABLED' => 'false', 'MODULE_PERSONALIZATION_ENABLED' => 'false'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$on?->stop();
        self::$off?->stop();
        self::$on = null;
        self::$off = null;
    }

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->cleanUp();
        self::modules(true, true);
        PageContent::clearCache();
    }

    protected function tearDown(): void
    {
        $this->accounts?->forget();
        $this->cleanUp();
        ModuleRegistry::overrideForTests(null);
        RequestLanguage::reset();
        PageContent::clearCache();
        PortfolioGalleryContent::clearCache();
    }

    // ------------------------------------------------------------ parents

    public function testOnlyASystemPageWithAPrefixIsAParentAmongFixedUrlPages(): void
    {
        $shop = $this->systemPage('shop');
        $portfolio = $this->systemPage('portfolio');

        self::assertSame('shop', ModuleSystemPages::childPrefix($shop));
        self::assertSame('portfolio', ModuleSystemPages::childPrefix($portfolio));
        self::assertNull(PageService::validateParent(null, (int) $shop['id']), 'a new page under the Shop');
        self::assertNull(PageService::validateParent(null, (int) $portfolio['id']), 'a new page under the Portfolio');
        self::assertContains((int) $shop['id'], PageService::parentCandidates(null));
        self::assertContains((int) $portfolio['id'], PageService::parentCandidates(null));

        $home = (new PageRepository())->findByContentKey('index');
        if ($home !== null) {
            self::assertNotNull(PageService::validateParent(null, (int) $home['id']), 'the homepage is still no parent');
            self::assertNotContains((int) $home['id'], PageService::parentCandidates(null));
        }

        // The system page itself never gets a parent.
        $root = $this->page('root');
        self::assertNotNull(PageService::validateParent($shop, $root));
        self::assertSame([], PageService::parentCandidates($shop));
    }

    // -------------------------------------------------------------- paths

    public function testAPageUnderTheShopLivesUnderTheShopsWordInEveryLanguage(): void
    {
        $shop = $this->systemPage('shop');
        $child = $this->page('zakelijk', (int) $shop['id'], 'business');
        $grandchild = $this->page('offerte', $child, 'quote');

        self::assertSame('/shop/' . self::P . '-zakelijk', PageContent::localizedPath($this->row($child), 'nl'));
        self::assertSame('/en/shop/' . self::P . '-business', PageContent::localizedPath($this->row($child), 'en'));
        self::assertSame('/shop/' . self::P . '-zakelijk/' . self::P . '-offerte', PageContent::localizedPath($this->row($grandchild), 'nl'));
        self::assertSame('/en/shop/' . self::P . '-business/' . self::P . '-quote', PageContent::localizedPath($this->row($grandchild), 'en'));

        // Found by the whole path only.
        self::assertSame($child, (int) (PageContent::forPath(['shop', self::P . '-zakelijk'], 'nl')['id'] ?? 0));
        self::assertSame($grandchild, (int) (PageContent::forPath(['shop', self::P . '-zakelijk', self::P . '-offerte'], 'nl')['id'] ?? 0));
        self::assertNull(PageContent::forPath([self::P . '-zakelijk'], 'nl'), 'not a root page');
        self::assertNull(PageContent::forPath(['shop.php', self::P . '-zakelijk'], 'nl'), 'never under the route');

        // The prefix is what an editor's preview is built on, not /shop.php.
        self::assertSame('/shop', PagePath::childBase((int) $shop['id'], 'nl'));
        self::assertSame('/en/shop', PagePath::childBase((int) $shop['id'], 'en'));
        self::assertSame('/shop/' . self::P . '-nieuw', PagePath::prospective((int) $shop['id'], self::P . '-nieuw', 'nl'));
    }

    public function testAPageUnderThePortfolioLivesUnderItsWord(): void
    {
        $portfolio = $this->systemPage('portfolio');
        $child = $this->page('wolven', (int) $portfolio['id'], 'wolves');
        $grandchild = $this->page('detail', $child);

        self::assertSame('/portfolio/' . self::P . '-wolven', PageContent::localizedPath($this->row($child), 'nl'));
        self::assertSame('/en/portfolio/' . self::P . '-wolves', PageContent::localizedPath($this->row($child), 'en'));
        self::assertSame('/portfolio/' . self::P . '-wolven/' . self::P . '-detail', PageContent::localizedPath($this->row($grandchild), 'nl'));
    }

    public function testTheBreadcrumbNamesTheSystemPageByItsRouteNeverItsWord(): void
    {
        $shop = $this->systemPage('shop');
        $child = $this->page('zakelijk', (int) $shop['id']);
        $grandchild = $this->page('offerte', $child);
        RequestLanguage::set('nl', false);

        $items = PageBreadcrumb::forPage($this->row($grandchild))?->items() ?? [];
        $hrefs = array_map(static fn ($item): ?string => $item->href, $items);

        self::assertSame('/', $hrefs[0]);
        self::assertNotContains('/shop', $hrefs, 'the Shop is never linked by its prefix');
        self::assertContains('/shop/' . self::P . '-zakelijk', $hrefs, 'the parent page, by its own path');
        self::assertSame('Modkind offerte', $items[array_key_last($items)]->label);
        self::assertNull($items[array_key_last($items)]->href, 'the page itself is never a link');

        // The Shop level, when there is one, points at the Shop's own address.
        foreach ($hrefs as $href) {
            if ($href !== null && str_starts_with($href, '/shop') && !str_starts_with($href, '/shop/')) {
                self::assertSame('/shop.php', $href);
            }
        }
    }

    // --------------------------------------------------- the module off/on

    public function testASubtreeIsOnTheWebsiteOnlyWhileItsModuleIsOn(): void
    {
        $shop = $this->systemPage('shop');
        $portfolio = $this->systemPage('portfolio');
        $shopChild = $this->page('zakelijk', (int) $shop['id']);
        $shopGrandchild = $this->page('offerte', $shopChild);
        $portfolioChild = $this->page('wolven', (int) $portfolio['id']);

        self::assertTrue(PageContent::isServedByAnEnabledModule($this->row($shopGrandchild)));
        $xml = Sitemap::xml();
        self::assertStringContainsString('/shop/' . self::P . '-zakelijk/' . self::P . '-offerte</loc>', $xml);
        self::assertStringContainsString('/portfolio/' . self::P . '-wolven</loc>', $xml);

        self::modules(false, false);
        PageContent::clearCache();

        foreach ([$shopChild, $shopGrandchild, $portfolioChild] as $id) {
            self::assertTrue(ModuleSystemPages::inDisabledModuleSubtree($id));
            self::assertFalse(PageContent::isServedByAnEnabledModule($this->row($id)), 'page ' . $id);
            self::assertNull(LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $id]), 'no menu or footer link to it');
            self::assertNotContains($id, array_column(LinkTargets::choices(LinkTargets::PAGE), 'id'), 'not offered to a block button');
        }
        $xml = Sitemap::xml();
        self::assertStringNotContainsString(self::P, $xml, 'the whole subtree is out of the sitemap');

        // The rows are untouched, and back when the module is.
        self::assertSame(PageContent::STATUS_PUBLISHED, (string) $this->row($shopChild)['status']);
        self::modules(true, true);
        PageContent::clearCache();
        self::assertTrue(PageContent::isServedByAnEnabledModule($this->row($shopGrandchild)));
        self::assertStringContainsString('/shop/' . self::P . '-zakelijk</loc>', Sitemap::xml());
    }

    public function testTheSubtreeAnswersOverHttpOnlyWithItsModuleOn(): void
    {
        $this->requireServers();
        $shop = $this->systemPage('shop');
        $portfolio = $this->systemPage('portfolio');
        $shopChild = $this->page('zakelijk', (int) $shop['id'], 'business');
        $this->page('offerte', $shopChild);
        $this->page('wolven', (int) $portfolio['id']);
        [, $projectSlug] = $this->project('ZZ Modkind project');

        $paths = [
            '/shop/' . self::P . '-zakelijk',
            '/shop/' . self::P . '-zakelijk/' . self::P . '-offerte',
            '/en/shop/' . self::P . '-business',
            '/portfolio/' . self::P . '-wolven',
        ];

        foreach ($paths as $path) {
            $response = self::$on->request('GET', $path);
            self::assertSame(200, $response['status'], $path . ' with the module on');
            self::assertStringContainsString('<link rel="canonical" href="' . \App\Service\AppUrl::canonical($path) . '">', $response['body'], $path);
            self::assertSame(404, self::$off->request('GET', $path)['status'], $path . ' with the module off');
        }

        $project = self::$on->request('GET', '/portfolio/' . $projectSlug);
        self::assertSame(200, $project['status'], 'a project page still answers in the same namespace');
        self::assertStringContainsString('project-hero__title', $project['body']);

        self::assertSame(404, self::$on->request('GET', '/shop/' . self::P . '-bestaat-niet')['status']);
        self::assertSame(404, self::$on->request('GET', '/portfolio/' . self::P . '-bestaat-niet')['status']);

        $body = self::$on->request('GET', '/portfolio/' . self::P . '-wolven')['body'];
        self::assertMatchesRegularExpression('#breadcrumb[\s\S]*?href="/portfolio"#', $body, 'Home / Portfolio / the page');
    }

    public function testARedirectIntoASwitchedOffSubtreeWaitsForTheModule(): void
    {
        self::modules(false, true);
        self::assertSame('shop', RedirectTarget::disabledModuleFor(RedirectTarget::TYPE_INTERNAL, '/shop/iets'));
        self::assertNull(RedirectTarget::disabledModuleFor(RedirectTarget::TYPE_INTERNAL, '/portfolio/iets'));

        self::modules(true, false);
        self::assertNull(RedirectTarget::disabledModuleFor(RedirectTarget::TYPE_INTERNAL, '/shop/iets'));
        self::assertSame('portfolio', RedirectTarget::disabledModuleFor(RedirectTarget::TYPE_INTERNAL, '/portfolio/iets'));
    }

    // ------------------------------------------------ one namespace per module

    public function testAPortfolioPageCannotTakeAProjectsAddressInAnyLanguage(): void
    {
        $portfolio = $this->systemPage('portfolio');
        [, $projectSlug] = $this->project('ZZ Modkind wolf', self::P . '-wolf');

        $message = PageService::moduleNamespaceProblem((int) $portfolio['id'], ['nl' => $projectSlug]);
        self::assertNotNull($message);
        self::assertStringContainsString('/portfolio/' . $projectSlug, (string) $message);
        self::assertStringContainsString('ZZ Modkind wolf', (string) $message, 'the project is named');

        self::assertNotNull(
            PageService::moduleNamespaceProblem((int) $portfolio['id'], ['nl' => self::P . '-anders', 'en' => $projectSlug]),
            'the English address counts too: a project answers under every prefix'
        );
        self::assertNull(PageService::moduleNamespaceProblem((int) $portfolio['id'], ['nl' => self::P . '-vrij']));

        // Only a direct child shares the namespace; deeper is free.
        $child = $this->page('tussen', (int) $portfolio['id']);
        self::assertNull(PageService::moduleNamespaceProblem($child, ['nl' => $projectSlug]));

        // A root page moved under the Portfolio brings its addresses along.
        $loose = $this->page('los', null, 'wolf');
        self::assertNotNull(PageService::moduleNamespaceProblem((int) $portfolio['id'], ModuleSystemPages::slugsOf($this->row($loose))));

        // An address made from a title steps around the project's.
        self::assertSame(
            $projectSlug . '-2',
            PageService::generateSlug($this->pages, 'ZZ Modkind wolf', 'nl', null, (int) $portfolio['id'])
        );
    }

    public function testAProjectCannotTakeTheAddressOfAPageUnderThePortfolio(): void
    {
        $portfolio = $this->systemPage('portfolio');
        $child = $this->page('vos', (int) $portfolio['id'], 'fox');
        $this->page('diep', $child);
        $repository = new PortfolioGalleryRepository();

        $message = PortfolioSlug::problem($repository, self::P . '-vos');
        self::assertNotNull($message);
        self::assertStringContainsString('Modkind vos', (string) $message, 'the page is named');
        self::assertNotNull(PortfolioSlug::problem($repository, self::P . '-fox'), 'its English address as well');
        self::assertNull(PortfolioSlug::problem($repository, self::P . '-diep'), 'a page two levels down is no neighbour');
        self::assertSame(self::P . '-vos-2', PortfolioSlug::suggest($repository, 'ZZ modkind vos'));
    }

    public function testAShopPageAndAProductMayShareAWord(): void
    {
        $shop = $this->systemPage('shop');
        $productId = (new ProductRepository())->create([
            'slug' => self::P . '-zakelijk',
            'price' => 1.00,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $productId;

        // A product is /product.php?id=, never /shop/<slug>: nothing to clash with.
        self::assertStringStartsWith('/product.php?id=', ProductSeo::publicPath($productId, 'nl'));
        self::assertNull(ModuleSystemPages::all()['shop']['child_conflicts']);
        self::assertNull(PageService::moduleNamespaceProblem((int) $shop['id'], ['nl' => self::P . '-zakelijk']));

        $child = $this->page('zakelijk', (int) $shop['id']);
        self::assertSame('/shop/' . self::P . '-zakelijk', PageContent::localizedPath($this->row($child), 'nl'));
    }

    public function testTheEditorRefusesAPageOnAProjectsAddressAndKeepsWhatWasTyped(): void
    {
        $this->requireServers();
        $portfolio = $this->systemPage('portfolio');
        [, $projectSlug] = $this->project('ZZ Modkind botsing', self::P . '-botsing');
        $this->accounts = new AdminTestSession();
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $response = self::$on->request('POST', '/api/admin/create-page.php', $session, [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'title' => 'ZZ Modkind botsing',
            'slug' => $projectSlug,
            'slug_auto' => '0',
            'status' => PageContent::STATUS_PUBLISHED,
            'template' => 'blank',
            'parent_id' => (string) $portfolio['id'],
        ]);

        self::assertStringContainsString('/admin/page-new.php', $response['location']);
        $errors = (array) $this->accounts->read($session, 'admin_page_errors');
        self::assertStringContainsString('/portfolio/' . $projectSlug, implode(' ', $errors));
        self::assertNull($this->pages->findByContentKey($projectSlug), 'nothing was saved');

        $form = self::$on->request('GET', '/admin/page-new.php', $session)['body'];
        self::assertStringContainsString('value="' . $projectSlug . '"', $form, 'the typed address is kept');
    }

    // ------------------------------------------------------------ helpers

    /**
     * The modules as this test needs them: the Shop and the Portfolio as
     * asked, the rest as the test environment has them — an unlisted module
     * would be off, and without Meertaligheid there is no /en/ at all.
     */
    private static function modules(bool $shop, bool $portfolio): void
    {
        ModuleRegistry::overrideForTests([
            'shop' => $shop,
            'personalization' => $shop,
            'portfolio' => $portfolio,
            'blog' => true,
            'multilingual' => true,
        ]);
    }

    private function requireServers(): void
    {
        if (self::$on === null || !self::$on->answers() || self::$off === null || !self::$off->answers()) {
            self::markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    /** @return array<string, mixed> */
    private function systemPage(string $contentKey): array
    {
        $page = $this->pages->findByContentKey($contentKey);
        if ($page === null) {
            self::markTestSkipped('this database has no ' . $contentKey . ' page (it runs after 20260928150000)');
        }

        return $page;
    }

    private function page(string $name, ?int $parentId = null, ?string $english = null): int
    {
        $slug = self::P . '-' . $name;
        $title = 'Modkind ' . $name;
        $id = PageFixture::create(['content_key' => $slug, 'slug' => $slug, 'status' => PageContent::STATUS_PUBLISHED, 'parent_id' => $parentId], $title);
        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => $title], $slug);
        if ($english !== null) {
            PageLocalization::save($id, 'en', [PageTranslation::TITLE => 'Modkind EN ' . $english], self::P . '-' . $english);
        }
        PageContent::clearCache();

        return $id;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->pages->findById($id);
    }

    /** @return array{0: int, 1: string} [item id, slug] */
    private function project(string $title, ?string $slug = null): array
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/' . self::P . '-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => $title]);

        $slug ??= self::P . '-project-' . bin2hex(random_bytes(3));
        $repository->setItemProjectPage($id, true, $slug);
        PortfolioGalleryContent::clearCache();

        return [$id, $slug];
    }

    private function cleanUp(): void
    {
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }
        $this->itemIds = [];

        $db = Database::connection();
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }
        $this->productIds = [];

        $db->prepare('DELETE FROM redirects WHERE source_path LIKE ?')->execute(['%' . self::P . '%']);

        // Children before their parents: the key refuses the other order.
        for ($round = 0; $round < 10; $round++) {
            $db->prepare('DELETE FROM pages WHERE content_key LIKE ? AND id NOT IN (SELECT parent_id FROM (SELECT parent_id FROM pages WHERE parent_id IS NOT NULL) AS parents)')
                ->execute([self::P . '%']);
        }
    }
}
