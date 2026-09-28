<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\CollectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\CtaBandContent;
use App\Service\Language\SiteLanguages;
use App\Service\LinkResolver;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\LinkTargets;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * The Destination Picker (2.0, Pages & Destinations 3.0; CONTENT-BLOCKS.md
 * "Waar een knop heen gaat"), against the real database: the kinds of
 * destination the enabled modules offer (App\Service\Routing\LinkTargets),
 * what a posted choice is stored as (App\Service\Routing\LinkChoice), what a
 * stored one renders, and the editor that offers them, over HTTP with the
 * modules on and off.
 *
 * What it proves: none, a page, an own address, a product, a collection and a
 * Portfolio project are destinations, a Portfolio category is not; the Shop
 * and the Portfolio take their kinds with them when they are off, and a
 * stored destination of theirs is kept and named; a destination that is gone
 * or not public is kept when it is sent back unchanged and renders no link;
 * a forged id or type is refused; and a stored id follows its page's,
 * collection's and project's address, in the language being read.
 *
 * Every page, product, collection, project and block here is the test's own
 * (zz-dest) and is removed in tearDown().
 */
final class DestinationPickerTest extends TestCase
{
    private const P = 'zz-dest';

    private static ?BuiltInServer $on = null;

    private static ?BuiltInServer $off = null;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $collectionIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    private ?AdminTestSession $accounts = null;

    public static function setUpBeforeClass(): void
    {
        self::$on = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true', 'MODULE_BLOG_ENABLED' => 'true']);
        self::$off = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false', 'MODULE_PERSONALIZATION_ENABLED' => 'false', 'MODULE_PORTFOLIO_ENABLED' => 'false', 'MODULE_BLOG_ENABLED' => 'true']);
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
        $this->cleanUp();
        self::modules(true, true);
    }

    protected function tearDown(): void
    {
        $this->accounts?->forget();
        $this->cleanUp();
        ModuleRegistry::overrideForTests(null);
        RequestLanguage::reset();
        self::clearCaches();
    }

    // ------------------------------------------------------------ the kinds

    public function testTheKindsAreThePageAndWhatTheEnabledModulesOffer(): void
    {
        $kinds = array_keys(LinkTargets::types());

        foreach (['page', 'blog_post', 'product', 'collection', 'portfolio_project'] as $kind) {
            self::assertContains($kind, $kinds);
        }
        self::assertSame('page', $kinds[0], 'a page first');
        self::assertSame('Pagina', LinkTargets::label('page'));
        self::assertSame(LinkTargets::PICKER_TREE, LinkTargets::picker('page'));
        foreach (['product', 'collection', 'portfolio_project', 'blog_post'] as $kind) {
            self::assertSame(LinkTargets::PICKER_SEARCH, LinkTargets::picker($kind), $kind . ' is searched, not scrolled');
        }
    }

    /** A category is only a filter on a gallery: no address, so no destination. */
    public function testAPortfolioCategoryIsNoDestination(): void
    {
        foreach (array_keys(LinkTargets::types()) as $kind) {
            self::assertStringNotContainsString('categor', $kind);
        }
    }

    public function testAModuleThatIsOffTakesItsKindsWithIt(): void
    {
        self::modules(false, true);
        self::assertFalse(LinkTargets::isAvailable('product'));
        self::assertFalse(LinkTargets::isAvailable('collection'));
        self::assertTrue(LinkTargets::isAvailable('portfolio_project'));
        self::assertSame('Shop', LinkTargets::disabledModuleOf('product'));
        self::assertSame('Shop', LinkTargets::disabledModuleOf('collection'));

        self::modules(true, false);
        self::assertTrue(LinkTargets::isAvailable('product'));
        self::assertFalse(LinkTargets::isAvailable('portfolio_project'));
        self::assertSame('Portfolio', LinkTargets::disabledModuleOf('portfolio_project'));
        self::assertNull(LinkTargets::disabledModuleOf('page'), 'Core\'s page is never off');
    }

    // ------------------------------------------------------ choose and store

    public function testNoneAPageAndAnOwnAddress(): void
    {
        $page = $this->page('pagina');

        self::assertSame(['link_type' => null, 'link_target_id' => null, 'error' => null], LinkChoice::fromRequest('none', null, ''));
        self::assertSame(['link_type' => 'page', 'link_target_id' => $page, 'error' => null], LinkChoice::fromRequest('page', (string) $page, ''));
        self::assertSame(['link_type' => 'url', 'link_target_id' => null, 'error' => null], LinkChoice::fromRequest('url', null, 'https://example.com'));
        self::assertNotNull(LinkChoice::fromRequest('url', null, 'javascript:alert(1)')['error'], 'an unsafe scheme is refused');
    }

    public function testAProductACollectionAndAProjectAreDestinationsByTheirId(): void
    {
        $product = $this->product('Harness plank', true);
        $collection = $this->collection('Harness cadeaus', true);
        [$project, $slug] = $this->project('Harness wolf', true);

        foreach (['product' => $product, 'collection' => $collection, 'portfolio_project' => $project] as $kind => $id) {
            self::assertSame(['link_type' => $kind, 'link_target_id' => $id, 'error' => null], LinkChoice::fromRequest($kind, (string) $id, ''), $kind);
            self::assertNotNull(LinkTargets::find($kind, $id), $kind . ' is a choice');
        }

        RequestLanguage::set('nl', false);
        self::assertSame('/product.php?id=' . $product, LinkChoice::href('product', $product, ''));
        self::assertSame('/collecties/' . self::P . '-harness-cadeaus', LinkChoice::href('collection', $collection, ''));
        self::assertSame('/portfolio/' . $slug, LinkChoice::href('portfolio_project', $project, ''));

        $choice = LinkTargets::find('product', $product);
        self::assertSame('/assets/zz-dest.svg', $choice['thumbnail'] ?? null, 'the searchable list shows its picture');
        self::assertSame('Harness plank', LinkTargets::title('product', $product, 'nl'));
        self::assertSame('Harness wolf', LinkTargets::title('portfolio_project', $project, 'nl'));
    }

    public function testAStoredIdFollowsItsAddressAndTheLanguageBeingRead(): void
    {
        $page = $this->page('eerst', 'first');
        $collection = $this->collection('Harness hernoemd', true);
        [$project] = $this->project('Harness verhuisd', true);

        RequestLanguage::set('nl', false);
        self::assertSame('/' . self::P . '-eerst', LinkChoice::href('page', $page, ''));

        // A new page address, a new collection address, a new project address.
        PageLocalization::save($page, 'nl', [PageTranslation::TITLE => 'Dest eerst'], self::P . '-daarna');
        Database::connection()->prepare('UPDATE pages SET slug = ? WHERE id = ?')->execute([self::P . '-daarna', $page]);
        (new CollectionRepository())->update($collection, ['slug' => self::P . '-nieuwe-plek', 'is_active' => true]);
        (new PortfolioGalleryRepository())->setItemProjectPage($project, true, self::P . '-nieuw-project');
        self::clearCaches();

        self::assertSame('/' . self::P . '-daarna', LinkChoice::href('page', $page, ''));
        self::assertSame('/collecties/' . self::P . '-nieuwe-plek', LinkChoice::href('collection', $collection, ''));
        self::assertSame('/portfolio/' . self::P . '-nieuw-project', LinkChoice::href('portfolio_project', $project, ''));

        if (SiteLanguages::isActive('en')) {
            ShopLocalization::saveCollection($collection, 'en', [ShopLocalization::SLUG => self::P . '-english', ShopLocalization::NAME => 'Harness renamed']);
            self::clearCaches();
            RequestLanguage::set('en', true);
            self::assertSame('/en/' . self::P . '-first', LinkChoice::href('page', $page, ''), 'the page in the language being read');
            self::assertSame('/en/collections/' . self::P . '-english', LinkChoice::href('collection', $collection, ''));
            self::assertSame('/en/portfolio/' . self::P . '-nieuw-project', LinkChoice::href('portfolio_project', $project, ''));
            self::assertSame('/en/product.php?id=' . $this->product('Harness taal', true), LinkChoice::href('product', end($this->productIds), ''));
        }
    }

    /** Gone, not public, or of a module that is off: kept when sent back unchanged, no link on the website. */
    public function testAnUnreachableDestinationIsKeptAndRendersNoLink(): void
    {
        $product = $this->product('Harness weg', true);
        $inactive = $this->product('Harness slapend', false);
        [$hidden] = $this->project('Harness verborgen', false);

        self::assertSame('', LinkChoice::href('product', $inactive, ''), 'an inactive product');
        self::assertSame('', LinkChoice::href('portfolio_project', $hidden, ''), 'a hidden project');
        self::assertSame('inactive', LinkTargets::find('product', $inactive)['note'] ?? null, 'offered, marked');

        Database::connection()->prepare('DELETE FROM products WHERE id = ?')->execute([$product]);
        self::clearCaches();

        self::assertSame(
            ['link_type' => 'product', 'link_target_id' => $product, 'error' => null],
            LinkChoice::fromRequest('product', (string) $product, '', 'product', $product),
            'the stored destination, sent back unchanged, is kept'
        );
        self::assertNotNull(LinkChoice::fromRequest('product', (string) $product, '', 'product', 0)['error'], 'but never chosen anew');
        self::assertSame('', LinkChoice::href('product', $product, ''), 'and no link on the website');

        self::modules(false, true);
        self::assertSame(
            ['link_type' => 'product', 'link_target_id' => $inactive, 'error' => null],
            LinkChoice::fromRequest('product', (string) $inactive, '', 'product', $inactive),
            'a kind whose module is off, kept as stored'
        );
        self::assertSame('', LinkChoice::href('product', $inactive, ''));
    }

    public function testAForgedDestinationIsRefused(): void
    {
        self::assertNotNull(LinkChoice::fromRequest('product', '999999999', '')['error'], 'an id that is nothing');
        self::assertNotNull(LinkChoice::fromRequest('collection', '0', '')['error']);
        self::assertNotNull(LinkChoice::fromRequest('App\\Evil', '1', '')['error'], 'a type is never taken from the request');
        self::assertNotNull(LinkChoice::fromRequest('portfolio_category', '1', '')['error'], 'no such kind');

        // A kind of a module that is off cannot be chosen anew either.
        $product = $this->product('Harness vervalst', true);
        self::modules(false, true);
        self::assertNotNull(LinkChoice::fromRequest('product', (string) $product, '', 'page', 3)['error']);
    }

    // ---------------------------------------------------------- the editor

    public function testTheEditorOffersTheEnabledKindsAndKeepsAStoredOneOfAModuleThatIsOff(): void
    {
        if (self::$on === null || !self::$on->answers() || self::$off === null || !self::$off->answers()) {
            self::markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $pageId = $this->page('cta');
        [$bandId, $key] = SectionRegistry::create('cta_band', self::P . '-cta');
        (new PageSectionRepository())->create($pageId, self::P . '-cta', 'cta_band', $key, $bandId);
        $product = $this->product('Harness knop', true);
        Database::connection()->prepare("UPDATE cta_bands SET primary_link_type = 'product', primary_link_target_id = ? WHERE id = ?")->execute([$product, $bandId]);
        self::clearCaches();

        $this->accounts = new AdminTestSession();
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $path = '/admin/cta-band.php?section=' . urlencode(self::P . '-cta:' . $key);

        $on = $this->xpath(self::$on->request('GET', $path, $session)['body']);
        $kinds = array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'), iterator_to_array($on->query('//select[@name="primary_link_type"]/option')));
        self::assertSame(['none', 'page', 'blog_post', 'product', 'collection', 'portfolio_project', 'url'], $kinds);
        self::assertSame('product', $on->query('//select[@name="primary_link_type"]/option[@selected]')->item(0)?->getAttribute('value'));
        self::assertSame((string) $product, $on->query('//select[@name="primary_link_target[product]"]/option[@selected]')->item(0)?->getAttribute('value'));
        self::assertSame(1, $on->query('//*[@data-nav-link-field="product" and @data-destination-search]//select[@name="primary_link_target[product]"]')->length, 'a product is searched');
        self::assertSame(0, $on->query('//*[@data-nav-link-field="page" and @data-destination-search]')->length, 'a page is picked from the tree');
        self::assertGreaterThan(0, $on->query('//script[contains(@src, "destination-picker.js")]')->length);

        $off = $this->xpath(self::$off->request('GET', $path, $session)['body']);
        $kinds = array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'), iterator_to_array($off->query('//select[@name="primary_link_type"]/option')));
        self::assertSame(['none', 'page', 'blog_post', 'url', 'product'], $kinds, 'no Shop or Portfolio kinds, the stored one kept last');
        $kept = $off->query('//select[@name="primary_link_type"]/option[@selected]')->item(0);
        self::assertSame('product', $kept?->getAttribute('value'));
        self::assertStringContainsString('Shop', (string) $kept?->textContent, 'named by its module');

        // Saving the unchanged form with the Shop off keeps the product.
        $response = self::$off->request('POST', '/api/admin/update-cta-band.php', $session, [
            'csrf_token' => (string) $this->accounts->read($session, 'csrf_token'),
            'language_code' => BlockLocalization::defaultLanguage(),
            'section' => self::P . '-cta:' . $key,
            'is_active' => '1',
            'title' => 'Harness',
            'primary_label' => 'Knop',
            'primary_link_type' => 'product',
            'primary_link_target' => ['product' => (string) $product],
            'primary_url' => '',
            'secondary_link_type' => 'none',
            'content_align' => 'center',
            'lead_width' => 'narrow',
            'background_overlay' => 'medium',
            'text_panel_opacity' => 'strong',
            'background_focus' => 'center',
        ]);
        self::assertStringContainsString('saved=1', $response['location'], $response['body']);
        $row = Database::connection()->query('SELECT primary_link_type, primary_link_target_id FROM cta_bands WHERE id = ' . (int) $bandId)->fetch();
        self::assertSame(['primary_link_type' => 'product', 'primary_link_target_id' => $product], ['primary_link_type' => $row['primary_link_type'], 'primary_link_target_id' => (int) $row['primary_link_target_id']]);
    }

    public function testTheEditorWarnsAboutAStoredDestinationThatIsGone(): void
    {
        if (self::$on === null || !self::$on->answers()) {
            self::markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $pageId = $this->page('weg');
        [$bandId, $key] = SectionRegistry::create('cta_band', self::P . '-weg');
        (new PageSectionRepository())->create($pageId, self::P . '-weg', 'cta_band', $key, $bandId);
        Database::connection()->prepare("UPDATE cta_bands SET primary_link_type = 'collection', primary_link_target_id = 987654321 WHERE id = ?")->execute([$bandId]);
        self::clearCaches();

        $this->accounts = new AdminTestSession();
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $body = self::$on->request('GET', '/admin/cta-band.php?section=' . urlencode(self::P . '-weg:' . $key), $session)['body'];
        $xpath = $this->xpath($body);

        self::assertSame('987654321', $xpath->query('//select[@name="primary_link_target[collection]"]/option[@selected]')->item(0)?->getAttribute('value'), 'kept selected');
        self::assertSame(1, $xpath->query('//*[@data-destination-warning="987654321"]')->length, 'and warned about');

        CtaBandContent::clearCache();
        self::assertSame('', CtaBandContent::forSection(self::P . '-weg', $key)['primary_url'], 'no link on the website');
    }

    // ------------------------------------------------------------ helpers

    /** The Shop and the Portfolio as asked; the rest as the test environment has them. */
    private static function modules(bool $shop, bool $portfolio): void
    {
        ModuleRegistry::overrideForTests([
            'shop' => $shop,
            'personalization' => $shop,
            'portfolio' => $portfolio,
            'blog' => true,
            'multilingual' => true,
        ]);
        self::clearCaches();
    }

    private static function clearCaches(): void
    {
        BlockLocalization::clearCache();
        CtaBandContent::clearCache();
        PageContent::clearCache();
        PageLocalization::clearCache();
        PagePath::clearCache();
        LinkResolver::clearCache();
        LinkTargets::reset();
        ShopLocalization::clearCache();
        PortfolioGalleryContent::clearCache();
    }

    private function page(string $name, ?string $english = null): int
    {
        $slug = self::P . '-' . $name;
        $id = PageFixture::create(['content_key' => $slug, 'slug' => $slug, 'status' => PageContent::STATUS_PUBLISHED], 'Dest ' . $name);
        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => 'Dest ' . $name], $slug);
        if ($english !== null) {
            PageLocalization::save($id, 'en', [PageTranslation::TITLE => 'Dest EN ' . $english], self::P . '-' . $english);
        }
        self::clearCaches();

        return $id;
    }

    private function product(string $name, bool $active): int
    {
        $id = (new ProductRepository())->create([
            'slug' => self::P . '-' . bin2hex(random_bytes(4)),
            'price' => 5.00,
            'image_path' => 'assets/zz-dest.svg',
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($id, ShopLocalization::defaultLanguage(), [
            ShopLocalization::NAME => $name,
            ShopLocalization::DESCRIPTION => '',
            ShopLocalization::META_TITLE => '',
            ShopLocalization::META_DESCRIPTION => '',
        ]);
        $this->productIds[] = $id;
        self::clearCaches();

        return $id;
    }

    private function collection(string $name, bool $active): int
    {
        $slug = self::P . '-' . strtolower(str_replace(' ', '-', $name));
        $id = (new CollectionRepository())->create(['slug' => $slug, 'image_path' => null, 'is_active' => $active]);
        ShopLocalization::saveCollection($id, ShopLocalization::defaultLanguage(), [ShopLocalization::NAME => $name]);
        $this->collectionIds[] = $id;
        self::clearCaches();

        return $id;
    }

    /** @return array{0: int, 1: string} */
    private function project(string $title, bool $visible): array
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/' . self::P . '-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => $title]);
        $slug = self::P . '-' . bin2hex(random_bytes(3));
        $repository->setItemProjectPage($id, true, $slug);
        if (!$visible) {
            Database::connection()->prepare('UPDATE portfolio_gallery_items SET is_active = 0 WHERE id = ?')->execute([$id]);
        }
        $this->itemIds[] = $id;
        self::clearCaches();

        return [$id, $slug];
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function cleanUp(): void
    {
        $db = Database::connection();
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
        }
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }
        $this->productIds = [];
        $this->collectionIds = [];
        $this->itemIds = [];

        foreach ((new PageRepository())->findAllForAdmin() as $page) {
            if (str_starts_with((string) $page['content_key'], self::P)) {
                PageService::delete($page);
            }
        }
        self::clearCaches();
    }
}
