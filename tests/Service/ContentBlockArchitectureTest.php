<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\RouteRegistry;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * The phase 1 content-block architecture (docs/content-blocks/PHASE-1.md):
 * one ordered block list per page, one central registry with real
 * capabilities, repeatable blocks where allowed, and protection derived from
 * a page property instead of a hardcoded page list.
 *
 * Complements rather than repeats the existing coverage:
 * Tests\Repository\PageSectionRepositoryTest owns the SQL (one list, reorder
 * scoping), Tests\Install\LegacyUpgradeTest owns "the migrations lost no
 * block on an existing installation", Tests\Service\SectionRegistryTest owns create/delete against
 * the real content tables. This file owns the ARCHITECTURE rules those three
 * assume.
 *
 * Blocks are created on a throwaway page of this test's own, so nothing here
 * can touch real site content; tearDown removes the page (its page_sections
 * rows cascade) and any content rows the created blocks left behind.
 */
final class ContentBlockArchitectureTest extends TestCase
{
    private const TEST_KEY = '__test_blocks__';

    /**
     * A second page of this test's own, served from a fixed URL the way a
     * page with a root-level template is. Underscores, so it can never be a
     * real slug.
     */
    private const TEMPLATE_TEST_KEY = '__test_blocks_template__';

    /** The six pages served by their own root-level PHP template. */
    private const TEMPLATE_PAGES = ['index', 'shop', 'diensten', 'portfolio', 'over-mij', 'contact'];

    private PageRepository $pages;
    private PageSectionRepository $sections;
    private int $pageId;

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->sections = new PageSectionRepository();

        $this->cleanUp();

        $this->pageId = \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_KEY,
            'status' => 'draft',
        ], 'Blokkentestpagina');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $db = Database::connection();

        foreach ([self::TEST_KEY, self::TEMPLATE_TEST_KEY] as $key) {
            $stmt = $db->prepare('SELECT id FROM pages WHERE content_key = :key');
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch();

            if ($row === false) {
                continue;
            }

            // Only menu items pointing at this test's own page, by exact id.
            $del = $db->prepare('DELETE FROM nav_items WHERE target_page_id = :id');
            $del->execute(['id' => (int) $row['id']]);

            $del = $db->prepare('DELETE FROM page_sections WHERE page_id = :id');
            $del->execute(['id' => (int) $row['id']]);

            $del = $db->prepare('DELETE FROM pages WHERE id = :id');
            $del->execute(['id' => (int) $row['id']]);
        }

        foreach (['page_heroes', 'cta_bands', 'feature_grids', 'faq_sections', 'stat_strips', 'step_list_sections', 'text_image_splits', 'marquee_sections', 'rich_text_sections', 'contact_form_sections', 'contact_cards', 'detail_sections', 'card_carousels', 'item_galleries'] as $table) {
            // The block's words per language first, or they stay behind as orphans.
            \Tests\Support\BlockTextFixture::removeForPage($table, self::TEST_KEY);
            $del = $db->prepare("DELETE FROM {$table} WHERE page_slug = :key");
            $del->execute(['key' => self::TEST_KEY]);
        }

        PageContent::clearCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function testPage(): array
    {
        $page = $this->pages->findById($this->pageId);
        $this->assertNotNull($page);

        return $page;
    }

    /**
     * The route-bound test page, created on first use: what every page with
     * its own root-level template looks like in `pages`. The repository never
     * mints one — an administrator cannot — so the two structural flags are
     * set straight in the database, the same shortcut setStatus() takes.
     *
     * @return array<string, mixed>
     */
    private function routeBoundTestPage(): array
    {
        $page = $this->pages->findByContentKey(self::TEMPLATE_TEST_KEY);

        if ($page === null) {
            $id = \Tests\Support\PageFixture::create([
                'content_key' => self::TEMPLATE_TEST_KEY,
                'slug' => self::TEMPLATE_TEST_KEY,
                'status' => PageContent::STATUS_PUBLISHED,
            ], 'Sjabloontestpagina');

            $stmt = Database::connection()->prepare(
                "UPDATE pages SET is_system = 1, route_path = '/zz-sjabloontest.php' WHERE id = :id"
            );
            $stmt->execute(['id' => $id]);
            PageContent::clearCache();

            $page = $this->pages->findById($id);
        }

        $this->assertNotNull($page);

        return $page;
    }

    private function addBlock(string $type): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, self::TEST_KEY);

        return $this->sections->create($this->pageId, self::TEST_KEY, $type, $sectionKey, $sectionId);
    }

    /**
     * Flips one page's published status straight in the database — the
     * setup/teardown shortcut this suite uses throughout, rather than
     * driving an authenticated admin POST.
     */
    private function setStatus(int $pageId, string $status): void
    {
        $stmt = Database::connection()->prepare('UPDATE pages SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $pageId]);

        PageContent::clearCache();
    }

    private function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array{status: int, body: string}|null null when the request could not be made at all
     */
    private function request(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);
        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    // ---------------------------------------------------------------- pages

    public function testHomepageIsProtectedAndKeepsItsUndeletableHero(): void
    {
        $home = $this->pages->findByContentKey('index');
        $this->assertNotNull($home, 'expected the homepage row — run phinx migrate');

        $this->assertTrue(PageContent::isProtected($home));
        $this->assertFalse(SectionRegistry::isDeletable('homepage_hero'));

        $this->expectException(\RuntimeException::class);
        PageService::delete($home);
    }

    public function testAnOrdinaryContentPageHasNoPageSpecificProtection(): void
    {
        $page = $this->testPage();

        $this->assertFalse(PageContent::isProtected($page));
        $this->assertFalse(PageContent::hasOwnTemplate($page));
        $this->assertFalse(PageContent::isRouteBound($page));

        // ... and it may hold exactly the same block types as any other page.
        $available = SectionRegistry::availableForPage($page, $this->sections);
        foreach (['page_hero', 'rich_text', 'cta_band', 'feature_grid', 'faq', 'stat_strip', 'step_list', 'text_image_split', 'marquee'] as $type) {
            $this->assertArrayHasKey($type, $available, "\"{$type}\" must be addable to an ordinary content page");
        }
    }

    public function testOnlyTheHomepageIsProtected(): void
    {
        // The phase 1 protection rule in one assertion: having a dedicated
        // template/route is NOT a reason to protect a page. Only being the
        // site root, or carrying application-critical functionality, is —
        // and since the product overview became a page the owner chooses
        // (App\Service\ShopOverview), no block is application-critical: the
        // historical storefront page is an ordinary page with a product grid.
        $this->routeBoundTestPage();

        $expected = [
            'index' => true,                  // the site root
            'shop' => false,                  // its product grid is ordinary page content now
            self::TEMPLATE_TEST_KEY => false, // its own template and fixed URL, nothing more
        ];

        foreach ($expected as $contentKey => $isProtected) {
            $page = $this->pages->findByContentKey($contentKey);
            $this->assertNotNull($page);

            $this->assertTrue(
                PageContent::hasOwnTemplate($page),
                "\"{$contentKey}\" is served from its own template — the very thing that must no longer protect it"
            );
            $this->assertSame(
                $isProtected,
                PageContent::isProtected($page),
                "\"{$contentKey}\" protection is wrong"
            );
        }
    }

    /**
     * The storefront used to be protected because it carried the one
     * application-critical block. The product grid is an ordinary Shop block
     * now: the storefront keeps the grid it has, and nothing makes a page
     * undeletable any more except being the site root. The mechanism stays,
     * following the block and never a page name, for a block that ever needs
     * it again.
     */
    public function testNoBlockIsApplicationCriticalAnyMoreAndTheStorefrontKeepsItsGrid(): void
    {
        $this->assertSame([], SectionRegistry::applicationCriticalTypes());
        $this->assertFalse(SectionRegistry::isApplicationCritical('product_grid'));

        $shop = $this->pages->findByContentKey('shop');
        $attached = array_map(
            static fn (array $row): string => (string) $row['section_type'],
            $this->sections->findForPage((int) $shop['id'])
        );
        $this->assertSame(1, count(array_keys($attached, 'product_grid', true)), 'the storefront keeps exactly the one grid it had');
    }

    public function testAContentPageWithADedicatedTemplateBehavesLikeAnyOtherPage(): void
    {
        $page = $this->routeBoundTestPage();

        // Its URL is fixed (it is served from its own file) ...
        $this->assertTrue(PageContent::isRouteBound($page));
        // ... but that locks the slug and nothing else.
        $this->assertFalse(PageContent::isProtected($page));
    }

    public function testDeletingAnUnprotectedPageIsStillBlockedWhileTheMenuLinksToIt(): void
    {
        // Not protected is not the same as unguarded: the ordinary
        // "unlink it first" rule now covers these pages too, which it could
        // not while the menu linked to them as hardcoded routes.
        $page = $this->routeBoundTestPage();
        $this->assertSame(0, PageService::references((int) $page['id'])['total'], 'precondition: nothing links to the test page yet');

        (new NavigationRepository())->create([
            'link_type' => 'page',
            'target_page_id' => (int) $page['id'],
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => false,
        ]);

        $this->assertGreaterThan(
            0,
            PageService::references((int) $page['id'])['total'],
            'a page the menu links by pages.id must warn on delete instead of leaving a dead menu item'
        );
    }

    public function testContentPagesAreNotOfferedAsApplicationRoutes(): void
    {
        foreach (['diensten', 'portfolio', 'over-mij', 'contact'] as $contentKey) {
            $this->assertFalse(
                RouteRegistry::exists($contentKey),
                "\"{$contentKey}\" is a CMS page; linking to it as a hardcoded route would survive deleting the page"
            );
        }
    }

    public function testAnUnpublishedContentPageStopsResolvingAtItsOwnUrl(): void
    {
        if ($this->request('/') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        // Being allowed to unpublish these pages is only honest if their URL
        // really stops answering; otherwise /diensten.php would keep
        // returning 200 with an empty page.
        $page = $this->pages->findByContentKey('over-mij');
        $this->assertNotNull($page);

        $this->setStatus((int) $page['id'], PageContent::STATUS_DRAFT);

        try {
            $response = $this->request('/over-mij.php');
            $this->assertNotNull($response);
            $this->assertSame(404, $response['status'], 'a drafted content page must 404 at its own route');
            $this->assertStringContainsString('Pagina niet gevonden', $response['body']);
        } finally {
            $this->setStatus((int) $page['id'], PageContent::STATUS_PUBLISHED);
        }

        $restored = $this->request('/over-mij.php');
        $this->assertSame(200, $restored['status'], 'republishing must bring the page straight back');
    }

    // ------------------------------------------------------------- registry

    public function testUnknownTypeIsRejectedEverywhere(): void
    {
        $this->assertFalse(SectionRegistry::exists('__nope__'));
        $this->assertFalse(SectionRegistry::isAllowedOnPage('__nope__', $this->testPage()));
        $this->assertFalse(SectionRegistry::isManuallyAddable('__nope__'));
        $this->assertSame(0, SectionRegistry::maxInstances('__nope__'));
        $this->assertArrayNotHasKey('__nope__', SectionRegistry::availableForPage($this->testPage(), $this->sections));
    }

    public function testRegistryDeclaresEveryCapabilityTheArchitectureRequires(): void
    {
        foreach (SectionRegistry::types() as $type => $meta) {
            $this->assertIsString($meta['label'], "{$type} needs a CMS name");
            $this->assertNotSame('', $meta['label']);
            $this->assertArrayHasKey('manual_add', $meta, "{$type} must declare whether it can be added by hand");
            $this->assertArrayHasKey('allow_multiple', $meta, "{$type} must declare whether it repeats");
            $this->assertArrayHasKey('max_instances', $meta, "{$type} must declare its instance cap (null = unlimited)");
            $this->assertArrayHasKey('allowed_pages', $meta, "{$type} must declare where it is allowed");
            $this->assertArrayHasKey('deletable', $meta);
        }
    }

    public function testPageRestrictionsAreEnforced(): void
    {
        $home = $this->pages->findByContentKey('index');
        $contentPage = $this->testPage();

        $this->assertTrue(SectionRegistry::isAllowedOnPage('homepage_hero', $home));
        $this->assertFalse(
            SectionRegistry::isAllowedOnPage('homepage_hero', $contentPage),
            'the Homepage Hero belongs to the homepage alone'
        );

        $this->assertFalse(
            SectionRegistry::isAllowedOnPage('page_hero', $home),
            'the homepage has its own richer hero, so the ordinary Page Hero is denied there'
        );
        $this->assertTrue(SectionRegistry::isAllowedOnPage('page_hero', $contentPage));

        $this->assertFalse(
            SectionRegistry::isAllowedOnPage('shop_collections', $contentPage),
            'a fixed block belongs to the one page whose template owns it'
        );
        $this->assertTrue(
            SectionRegistry::isAllowedOnPage('product_grid', $contentPage),
            'the product grid is an ordinary Shop block, placed on any page'
        );
    }

    /**
     * The Shop's product grid on an ordinary page: added by hand, one per
     * page, removable again, and never a reason to protect the page. Its
     * page_sections row uses the page id as section_id (no content row of its
     * own), so two pages each holding one never collide on
     * UNIQUE(section_type, section_id), and the storefront's own row is left
     * as it was.
     */
    public function testTheProductGridIsAnOrdinaryBlockOncePerPage(): void
    {
        $page = $this->testPage();
        $this->assertArrayHasKey('product_grid', SectionRegistry::availableForPage($page, $this->sections));

        $id = $this->addBlock('product_grid');
        $row = $this->sections->findById($id);
        $this->assertSame($this->pageId, (int) $row['section_id']);
        $this->assertNull($row['section_key']);

        $this->assertArrayNotHasKey(
            'product_grid',
            SectionRegistry::availableForPage($this->testPage(), $this->sections),
            'at most one product grid per page'
        );
        $this->assertFalse(PageContent::isProtected($this->testPage()), 'a product grid never protects a page');

        $storefront = $this->pages->findByContentKey('shop');
        $storefrontGrids = array_values(array_filter(
            $this->sections->findForPage((int) $storefront['id']),
            static fn (array $r): bool => $r['section_type'] === 'product_grid'
        ));
        $this->assertCount(1, $storefrontGrids, 'the storefront keeps its own grid');

        SectionRegistry::delete($row, $this->sections);
        $this->assertNull($this->sections->findById($id));
        $this->assertArrayHasKey('product_grid', SectionRegistry::availableForPage($this->testPage(), $this->sections), 'and it can be placed again');
        $this->assertCount(1, array_filter(
            $this->sections->findForPage((int) $storefront['id']),
            static fn (array $r): bool => $r['section_type'] === 'product_grid'
        ), 'removing one page\'s grid never touches another page\'s');
    }

    public function testFixedBlocksCanBeNeitherAddedNorCreatedNorDeleted(): void
    {
        // `contact_form` used to be on this list; phase 2 turned it into an
        // ordinary, addable block (see ReusableBlocksPhase2Test), phase 3 did
        // the same for the two Diensten blocks (ReusableBlocksPhase3Test) and
        // phase 4 for the two Portfolio blocks, which became one `item_gallery`
        // (ReusableBlocksPhase4Test). The quicknav stayed fixed, but it is now
        // derived rather than hardcoded.
        // `product_grid` left this list when the product overview became a
        // page the owner chooses: it is placed by hand now (see
        // testTheProductGridIsAnOrdinaryBlockOncePerPage()).
        $fixed = ['shop_collections', 'quicknav'];

        foreach ($fixed as $type) {
            $this->assertTrue(SectionRegistry::isFixed($type), "{$type} must be a fixed block");
            $this->assertFalse(SectionRegistry::isManuallyAddable($type));
            $this->assertFalse(SectionRegistry::isDeletable($type));
            $this->assertSame(1, SectionRegistry::maxInstances($type));
        }

        $shop = $this->pages->findByContentKey('shop');
        $this->assertArrayNotHasKey(
            'shop_collections',
            SectionRegistry::availableForPage($shop, $this->sections),
            'a fixed block must never appear in "+ Sectie toevoegen", not even on its own page'
        );

        $this->expectException(\RuntimeException::class);
        SectionRegistry::create('shop_collections', 'shop');
    }

    public function testRepeatableBlocksStayAvailableAfterSeveralInstances(): void
    {
        $this->addBlock('rich_text');
        $this->addBlock('rich_text');
        $this->addBlock('feature_grid');

        $available = SectionRegistry::availableForPage($this->testPage(), $this->sections);

        $this->assertArrayHasKey('rich_text', $available);
        $this->assertArrayHasKey('feature_grid', $available);
        $this->assertCount(3, $this->sections->findForPage($this->pageId));
        $this->assertNull(SectionRegistry::maxInstances('rich_text'), 'a repeatable block has no cap');
    }

    public function testCappedBlockIsNotOfferedTwice(): void
    {
        $page = $this->testPage();

        $this->assertArrayHasKey('contact_form', SectionRegistry::availableForPage($page, $this->sections));
        $this->assertSame(1, SectionRegistry::maxInstances('contact_form'));

        $this->addBlock('contact_form');

        $available = SectionRegistry::availableForPage($page, $this->sections);
        $this->assertArrayNotHasKey('contact_form', $available, 'a block capped at one instance must not be offered again');
        $this->assertArrayHasKey('rich_text', $available, 'capping one type must not affect another');
    }

    public function testANewBlockIsAppendedToTheBottomOfTheOneList(): void
    {
        $first = $this->addBlock('page_hero');
        $second = $this->addBlock('rich_text');
        $third = $this->addBlock('cta_band');

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->sections->findForPage($this->pageId)
        );

        $this->assertSame([$first, $second, $third], $ids);
    }

    // ------------------------------------------------------- rendering side

    public function testEveryTemplatePageRendersItsBlocksThroughTheOneList(): void
    {
        foreach (self::TEMPLATE_PAGES as $contentKey) {
            $file = ($contentKey === 'index' ? 'index' : $contentKey) . '.php';
            $source = $this->sourceOf($file);

            $this->assertSame(
                1,
                substr_count($source, 'SectionRegistry::renderPage('),
                "{$file} must render its content with exactly one renderPage() call — one page, one list"
            );
            $this->assertStringNotContainsString(
                'renderZone',
                $source,
                "{$file} must not render content per zone any more"
            );
        }

        $this->assertStringContainsString('SectionRegistry::renderPage(', $this->sourceOf('pagina.php'));
    }

    public function testTemplateOwnedContentStillRendersOnItsPage(): void
    {
        if ($this->request('/') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        // One marker per fixed block: markup that only exists inside the
        // content each page template used to hardcode, and that must survive
        // its promotion to a block.
        $expectations = [
            '/' => ['orbit-carousel', 'gallery-item'],
            '/shop.php' => ['data-products-grid'],
            '/diensten.php' => ['quicknav', 'service-detail'],
            '/portfolio.php' => ['filter-bar', 'gallery-grid'],
            // Since Core Forms the quote form is rendered by the shared
            // form renderer, so the marker is `data-form-block` rather
            // than the old `data-quote-form`. The guarantee is unchanged:
            // the contact page still shows a working form beside its
            // details card.
            '/contact.php' => ['data-form-block', 'contact-grid'],
        ];

        foreach ($expectations as $path => $markers) {
            $response = $this->request($path);
            $this->assertNotNull($response);
            $this->assertSame(200, $response['status'], "{$path} must still render");

            foreach ($markers as $marker) {
                $this->assertStringContainsString(
                    $marker,
                    $response['body'],
                    "\"{$marker}\" disappeared from {$path} when its content became a block"
                );
            }
        }
    }

    // ----------------------------------------------------------- admin side

    public function testEveryAttachedBlockStillOpensItsOwnDedicatedEditor(): void
    {
        // The pages every installation has, plus this test's own: the blocks
        // on a particular site's other pages are that site's content.
        foreach (['index', 'shop', self::TEST_KEY] as $contentKey) {
            $page = $this->pages->findByContentKey($contentKey);
            $this->assertNotNull($page);

            foreach ($this->sections->findForPage((int) $page['id']) as $section) {
                $type = (string) $section['section_type'];
                if (!SectionRegistry::exists($type)) {
                    continue;
                }

                foreach (SectionRegistry::editLinks($section) as $link) {
                    $file = dirname(__DIR__, 2) . '/' . ltrim(parse_url($link['url'], PHP_URL_PATH) ?? '', '/');
                    $this->assertFileExists(
                        $file,
                        "\"{$type}\" on \"{$contentKey}\" links to an editor that does not exist"
                    );
                }
            }
        }
    }

    /**
     * One page, one ordered list, one way to add to it — and the control sits
     * under the list, because that is where the new block lands.
     *
     * Since the block picker replaced the type dropdown, the control on the
     * page is a button and the POST form lives in the picker's panel
     * (admin/_block_picker.php). What has to stay true is the same thing it
     * always was: exactly one route into api/admin/add-page-section.php, and
     * the editor's way in is below the blocks it appends to.
     */
    public function testTheAddBlockControlSitsBelowTheBlockListAndIsTheOnlyOne(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $listPosition = strpos($source, 'class="admin-page-sections"');
        $this->assertIsInt($listPosition);

        $listEnd = strpos($source, '<?php endforeach; ?>', $listPosition);
        $buttonPosition = strpos($source, 'block_picker_button()');

        $this->assertIsInt($listEnd);
        $this->assertIsInt($buttonPosition);
        $this->assertGreaterThan(
            $listEnd,
            $buttonPosition,
            'the "+ Contentblok toevoegen" control must come after the blocks it adds to'
        );

        $this->assertStringNotContainsString('zone_key', $source);
        $this->assertStringNotContainsString(
            'action="/api/admin/add-page-section.php"',
            $source,
            'adding happens through the picker panel, not through a second form on the page'
        );

        $picker = $this->sourceOf('admin/_block_picker.php');
        $this->assertSame(
            1,
            substr_count($picker, 'action="/api/admin/add-page-section.php"'),
            'one page, one list, one add control'
        );
    }

    public function testAdminNoLongerCarriesASecondPageRegistry(): void
    {
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2) . '/src/Service/AdminPageRegistry.php',
            'the fixed-content registry was absorbed into SectionRegistry — there must be exactly one block registry'
        );
    }
}
