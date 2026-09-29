<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentPages;
use App\Service\DetailSectionContent;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioProjectLayout;
use App\Service\ProductContentOwner;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;
use Tests\Support\TestEnvironment;

/**
 * Content blocks on a product, on a project and on a plain page, at the
 * addresses a visitor really uses, over the real Apache of php_test and
 * php_cms (TESTING.md, "De HTTP-tier").
 *
 * What ProductPortfolioContentPagesHttpTest and DetailSectionTwoHttpTest
 * prove through `php -S` on the template files themselves stays theirs; this
 * class proves the deployment around them:
 *
 *   - /product.php?id= (a real file) and /en/product.php?id= (the .htaccess
 *     catch-all and the dispatcher) show the product's block after its
 *     detail; with the Shop off (php_cms) both are 404 and show nothing of it;
 *   - /portfolio/<slug> (no file: the dispatcher) shows a free-layout
 *     project's block and its Projectinformatie where the editor put them;
 *     with Portfolio off (php_cms) a 404;
 *   - a Detailsectie with an anchor on a page at its pretty URL prints the
 *     anchor navigation and the anchor; a gallery item linked to a product
 *     shows that product's picture and link, and leaves it out, without a
 *     broken picture, once the product is inactive or the Shop is off.
 */
final class ContentOwnerPagesRoutingTest extends TestCase
{
    private ShopStockFixture $shop;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    private ?string $pageKey = null;

    protected function setUp(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        // In-process writes go through the block registry, which knows a
        // module's blocks only while that module is on.
        \App\Module\ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        $this->shop = new ShopStockFixture();
    }

    protected function tearDown(): void
    {
        if ($this->pageKey !== null) {
            $this->removePage($this->pageKey);
            $this->pageKey = null;
        }

        foreach ($this->productIds as $id) {
            ContentPages::deleteFor(ProductContentOwner::KIND, $id);
        }
        $this->productIds = [];
        $this->shop->cleanUp();

        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            ContentPages::deleteFor(PortfolioContentOwner::KIND, $id);
            $gallery->deleteItem($id);
        }
        $this->itemIds = [];
        PortfolioGalleryContent::clearCache();

        \App\Module\ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
    }

    // --------------------------------------------------------------- product

    public function testAProductsBlockFollowsItsDetailAtBothPublicAddresses(): void
    {
        $id = $this->product('ZZ Routeplank');
        $this->block(ContentPages::ensure(ProductContentOwner::KIND, $id), 'rich_text', ['body' => '<p>ZZ routeblok product</p>']);

        foreach (['/product.php?id=' . $id => 'nl', '/en/product.php?id=' . $id => 'en'] as $path => $language) {
            $response = $this->get(TestEnvironment::baseUrl(), $path);

            $this->assertSame(200, $response['status'], $path);
            $this->assertStringContainsString('<html lang="' . $language . '"', $response['body'], $path);
            $detail = strpos($response['body'], 'data-product-content');
            $block = strpos($response['body'], 'ZZ routeblok product');
            $this->assertIsInt($detail, $path);
            $this->assertIsInt($block, $path . ': the default language decides the block, in every language');
            $this->assertLessThan($block, $detail, $path . ': the block after the product detail');
            $this->assertLessThan(strpos($response['body'], '</main>'), $block, $path);
        }
    }

    public function testWithTheShopOffTheProductsAddressesAreNotFound(): void
    {
        if (!TestEnvironment::cmsOnlySiteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::cmsOnlyUnreachableMessage());
        }

        $id = $this->product('ZZ Routeplank uit');
        $this->block(ContentPages::ensure(ProductContentOwner::KIND, $id), 'rich_text', ['body' => '<p>ZZ routeblok verborgen</p>']);

        foreach (['/product.php?id=' . $id, '/en/product.php?id=' . $id] as $path) {
            $response = $this->get(TestEnvironment::cmsOnlyBaseUrl(), $path);

            $this->assertSame(404, $response['status'], $path);
            $this->assertStringNotContainsString('ZZ routeblok verborgen', $response['body'], $path);
            $this->assertStringNotContainsString('data-product-detail', $response['body'], $path);
        }

        // The same product on the server with the Shop on: there it is.
        $this->assertSame(200, $this->get(TestEnvironment::baseUrl(), '/product.php?id=' . $id)['status']);
    }

    // ------------------------------------------------------------- portfolio

    public function testAFreeLayoutProjectShowsItsBlocksWherePlacedAtItsPrettyUrl(): void
    {
        $id = $this->project('ZZ Vrij routeproject');
        (new PortfolioGalleryRepository())->setItemProjectLayout($id, PortfolioProjectLayout::FREE);
        $page = ContentPages::ensure(PortfolioContentOwner::KIND, $id);
        $this->block($page, 'rich_text', ['body' => '<p>ZZ routeblok vooraf</p>']);
        $info = $this->block($page, 'project_info', []);
        $this->block($page, 'rich_text', ['body' => '<p>ZZ routeblok achteraf</p>']);

        $response = $this->get(TestEnvironment::baseUrl(), '/portfolio/' . $this->projectSlug($id));

        $this->assertSame(200, $response['status']);
        $body = $response['body'];
        $before = strpos($body, 'ZZ routeblok vooraf');
        $head = strpos($body, 'project-hero__title');
        $after = strpos($body, 'ZZ routeblok achteraf');
        $this->assertIsInt($before);
        $this->assertIsInt($head, 'the Projectinformatie block renders the project');
        $this->assertIsInt($after);
        $this->assertLessThan($head, $before);
        $this->assertLessThan($after, $head);
        $this->assertSame(1, substr_count($body, 'project-hero__title'), 'one head: the block\'s, no automatic one');
        $this->assertStringContainsString('project-hero--in-flow', $body);
        $this->assertStringContainsString('data-reveal-group="project_info-' . $info . '"', $body);
        $this->assertStringContainsString('ZZ Vrij routeproject', $body);
    }

    public function testWithPortfolioOffTheProjectAddressIsNotFound(): void
    {
        if (!TestEnvironment::cmsOnlySiteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::cmsOnlyUnreachableMessage());
        }

        $id = $this->project('ZZ Routeproject uit');
        $this->block(ContentPages::ensure(PortfolioContentOwner::KIND, $id), 'rich_text', ['body' => '<p>ZZ projectblok verborgen</p>']);
        $path = '/portfolio/' . $this->projectSlug($id);

        $this->assertSame(200, $this->get(TestEnvironment::baseUrl(), $path)['status'], 'public with Portfolio on');

        $response = $this->get(TestEnvironment::cmsOnlyBaseUrl(), $path);
        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('ZZ projectblok verborgen', $response['body']);
        $this->assertStringNotContainsString('ZZ Routeproject uit', $response['body']);
    }

    // ------------------------------------------------------- Detailsectie 2.0

    public function testAnAnchoredDetailSectionPrintsTheAnchorNavigationAtThePagesPrettyUrl(): void
    {
        $key = $this->page();
        $this->detailSection($key, 'hout', ['title' => 'ZZ Hout bewerken', 'nav_label' => 'ZZ Hout']);
        $this->detailSection($key, 'metaal', ['title' => 'ZZ Metaal bewerken']);

        $response = $this->get(TestEnvironment::baseUrl(), '/' . $key);

        $this->assertSame(200, $response['status']);
        $body = $response['body'];
        $this->assertSame(1, preg_match('#<nav class="quicknav"[^>]*>(.*?)</nav>#s', $body, $nav), 'one anchor navigation');
        $this->assertStringContainsString('<a href="#hout">ZZ Hout</a>', $nav[1], 'the navigation label');
        $this->assertStringContainsString('<a href="#metaal">ZZ Metaal bewerken</a>', $nav[1], 'else the title');
        $this->assertLessThan(strpos($nav[1], '#metaal'), strpos($nav[1], '#hout'), 'in block order');
        $this->assertStringContainsString('<section class="service-detail" id="hout">', $body);
        $this->assertMatchesRegularExpression('#<section class="service-detail[^"]*" id="metaal">#', $body);
        $this->assertLessThan(strpos($body, 'id="hout"'), strpos($body, 'class="quicknav"'), 'the navigation above the sections');
    }

    public function testAGalleryItemLinkedToAProductShowsItsPictureAndLeavesItOutWhenItIsGone(): void
    {
        $key = $this->page();
        $product = $this->product('ZZ Gekoppeld routeproduct');
        $picture = 'assets/images/products/zz-linked-route-' . bin2hex(random_bytes(4)) . '.jpg';
        Database::connection()->prepare('UPDATE products SET image_path = :path WHERE id = :id')->execute(['path' => $picture, 'id' => $product]);
        $section = $this->detailSection($key, 'galerij', ['title' => 'ZZ Galerijsectie']);
        (new DetailSectionRepository())->createImage($section, ['source_type' => 'product', 'source_id' => $product]);

        $response = $this->get(TestEnvironment::baseUrl(), '/' . $key);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('class="service-detail__gallery-link" href="/product.php?id=' . $product . '"', $response['body']);
        $this->assertStringContainsString('/' . $picture, $response['body'], 'the product\'s own picture');
        $this->assertStringContainsString('<span class="service-detail__gallery-caption">ZZ Gekoppeld routeproduct</span>', $response['body']);

        // The Shop off: the page itself still answers, without the item.
        if (TestEnvironment::cmsOnlySiteIsReachable()) {
            $off = $this->get(TestEnvironment::cmsOnlyBaseUrl(), '/' . $key);
            $this->assertSame(200, $off['status']);
            $this->assertStringContainsString('ZZ Galerijsectie', $off['body']);
            $this->assertStringNotContainsString($picture, $off['body']);
            $this->assertStringNotContainsString('/product.php?id=' . $product, $off['body']);
        }

        // Inactive: the page answers, the item is left out — no picture that
        // leads nowhere, no empty <img>.
        Database::connection()->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute(['id' => $product]);
        $response = $this->get(TestEnvironment::baseUrl(), '/' . $key);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('ZZ Galerijsectie', $response['body']);
        $this->assertStringNotContainsString($picture, $response['body']);
        $this->assertStringNotContainsString('/product.php?id=' . $product, $response['body']);
        $this->assertStringNotContainsString('data-detail-gallery-item', $response['body'], 'no gallery left to draw');
        $this->assertDoesNotMatchRegularExpression('#<img[^>]*src=""#', $response['body']);
        $this->assertSame(404, $this->get(TestEnvironment::baseUrl(), '/product.php?id=' . $product)['status'], 'the product is really gone for a visitor');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{status: int, body: string} */
    private function get(string $base, string $path): array
    {
        $handle = curl_init($base . $path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $body = is_string($body) ? $body : '';
        $this->assertStringNotContainsString('Fatal error', $body, $path);

        return ['status' => $status, 'body' => $body];
    }

    private function product(string $name): int
    {
        $id = $this->shop->product($name);
        $this->productIds[] = $id;

        return $id;
    }

    private function project(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-owner-route-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => $title,
            PortfolioLocalization::ALT => $title,
        ]);
        $repository->setItemProjectPage($id, true, $this->projectSlug($id));
        PortfolioGalleryContent::clearCache();

        return $id;
    }

    private function projectSlug(int $id): string
    {
        return 'zz-owner-route-project-' . $id;
    }

    /** A published page of this test's own, its content key its slug. */
    private function page(): string
    {
        $key = 'zz-owner-route-' . bin2hex(random_bytes(4));
        PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Detailroute');
        $this->pageKey = $key;

        return $key;
    }

    /**
     * A Detailsectie at the end of the page, with its anchor and words.
     *
     * @param array<string, string> $words
     *
     * @return int the detail_sections id
     */
    private function detailSection(string $pageKey, string $anchor, array $words): int
    {
        $page = (new PageRepository())->findByContentKey($pageKey);
        [$sectionId, $sectionKey] = SectionRegistry::create('detail_section', $pageKey);
        (new PageSectionRepository())->create((int) $page['id'], $pageKey, 'detail_section', $sectionKey, $sectionId);
        (new DetailSectionRepository())->upsertSection($pageKey, $sectionKey, ['anchor' => $anchor, 'is_active' => true]);
        BlockLocalization::save('detail_sections', $sectionId, PageLocalization::defaultLanguage(), $words);
        DetailSectionContent::clearCache();

        return $sectionId;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, string> $words
     *
     * @return int the page_sections id
     */
    private function block(array $page, string $type, array $words): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, (string) $page['content_key']);
        $id = (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], $type, $sectionKey, $sectionId);

        $table = BlockDefinitions::get($type)?->contentTable();
        if ($words !== [] && $table !== null) {
            BlockLocalization::save($table, $sectionId, PageLocalization::defaultLanguage(), $words);
        }

        return $id;
    }

    private function removePage(string $key): void
    {
        $pages = new PageRepository();
        $page = $pages->findByContentKey($key);
        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            SectionRegistry::delete($row, $sections);
        }
        $pages->delete((int) $page['id']);
        PageContent::clearCache();
        BlockLocalization::clearCache();
    }
}
