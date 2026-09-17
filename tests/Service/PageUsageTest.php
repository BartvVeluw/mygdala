<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\FooterRepository;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\NavigationPresentation;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\PageUsage;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Where a page is linked from, as admin/page.php lists it before the page's
 * web address changes: menu items, footer links and header buttons that
 * point at the page itself — and nothing the CMS cannot tell points at it.
 *
 * Against the test database, with rows of its own that tearDown() removes by
 * id rather than by a LIKE pattern, in which "_" matches any character
 * (TESTING.md). A header button is a nav_items row presented as a button
 * since Navigation phase A (App\Service\NavigationPresentation), so it is
 * created and removed like a menu item.
 */
final class PageUsageTest extends TestCase
{
    private PageRepository $pages;
    private NavigationRepository $navigation;
    private FooterRepository $footer;

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> */
    private array $navIds = [];

    /** @var list<int> */
    private array $columnIds = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->navigation = new NavigationRepository();
        $this->footer = new FooterRepository();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->navIds as $id) {
            $db->prepare('DELETE FROM nav_items WHERE id = :id')->execute(['id' => $id]);
        }

        // A column takes its links with it (ON DELETE CASCADE), which has to
        // happen before the page they point at can go.
        foreach ($this->columnIds as $id) {
            $this->footer->deleteColumn($id);
        }

        foreach ($this->pageIds as $id) {
            $this->pages->delete($id);
        }

        $this->navIds = [];
        $this->columnIds = [];
        $this->pageIds = [];

        SiteSettings::overrideForTests(null);
        PageContent::clearCache();
    }

    private function page(): int
    {
        $key = '__test-page-usage-' . bin2hex(random_bytes(4));

        $id = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => PageContent::STATUS_PUBLISHED,
        ], 'Gebruikstest');
        $this->pageIds[] = $id;

        return $id;
    }

    private function menuItem(int $pageId, bool $visible = true, string $type = 'page', ?string $externalUrl = null): int
    {
        $id = $this->navigation->create([
            'label_nl' => 'Testmenu',
            'label_en' => 'Test menu',
            'link_type' => $type,
            'target_page_id' => $type === 'page' ? $pageId : null,
            'target_route' => null,
            'external_url' => $externalUrl,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => $visible,
        ]);
        $this->navIds[] = $id;

        return $id;
    }

    private function footerLink(int $pageId): int
    {
        $columnId = $this->footer->createColumn(['title_nl' => 'Testkolom', 'title_en' => 'Test column', 'is_visible' => true]);
        $this->columnIds[] = $columnId;

        return $this->footer->createLink([
            'column_id' => $columnId,
            'label_nl' => 'Testlink',
            'label_en' => 'Test link',
            'link_type' => 'page',
            'target_page_id' => $pageId,
            'target_route' => null,
            'external_url' => null,
            'action_key' => null,
            'open_in_new_tab' => false,
            'is_visible' => true,
        ]);
    }

    private function headerButtonPointingAt(int $pageId, bool $visible = true): int
    {
        $id = $this->navigation->create([
            'label_nl' => 'Vraag offerte aan',
            'label_en' => 'Request a quote',
            'link_type' => 'page',
            'target_page_id' => $pageId,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => $visible,
            'presentation' => NavigationPresentation::BUTTON,
            'button_variant' => NavigationPresentation::VARIANT_PRIMARY,
        ]);
        $this->navIds[] = $id;

        return $id;
    }

    public function testAPageNothingLinksToIsUsedNowhere(): void
    {
        $this->assertSame([], PageUsage::forPageId($this->page()));
    }

    public function testTheMenuTheFooterAndTheHeaderButtonAreEachOnePlace(): void
    {
        $pageId = $this->page();
        // Created first on purpose: the list still puts the menu first and
        // the header buttons last, whatever order the rows were made in.
        $buttonId = $this->headerButtonPointingAt($pageId);
        $menuId = $this->menuItem($pageId);
        $linkId = $this->footerLink($pageId);

        $places = PageUsage::forPageId($pageId);

        $this->assertSame(
            [PageUsage::KIND_MENU, PageUsage::KIND_FOOTER, PageUsage::KIND_HEADER_BUTTON],
            array_column($places, 'kind')
        );
        $this->assertSame(['Testmenu', 'Testlink', 'Vraag offerte aan'], array_column($places, 'label'));
        $this->assertSame('Testkolom', $places[1]['context'], 'a footer link says which column it is in');
        $this->assertSame('/admin/navigation-item.php?id=' . $menuId, $places[0]['edit_url']);
        $this->assertSame('/admin/footer-link.php?id=' . $linkId, $places[1]['edit_url']);
        $this->assertSame('/admin/navigation-item.php?id=' . $buttonId, $places[2]['edit_url']);
    }

    public function testAHiddenMenuItemStillCountsAndSaysItIsHidden(): void
    {
        $pageId = $this->page();
        $this->menuItem($pageId, visible: false);

        $places = PageUsage::forPageId($pageId);

        $this->assertCount(1, $places);
        $this->assertTrue($places[0]['hidden'], 'it still points at the page and would follow it');
    }

    /**
     * A hidden button is a place like a hidden menu item: not on the website
     * today, but still pointing at the page and following it. (The single
     * header CTA in site_settings was only listed while switched on; a
     * button row is content that stays, so it is listed and marked hidden.)
     */
    public function testAHiddenHeaderButtonStillCountsAndSaysItIsHidden(): void
    {
        $pageId = $this->page();
        $this->headerButtonPointingAt($pageId, visible: false);

        $places = PageUsage::forPageId($pageId);

        $this->assertSame([PageUsage::KIND_HEADER_BUTTON], array_column($places, 'kind'));
        $this->assertTrue($places[0]['hidden']);
    }

    /** A button pointing at a page blocks deleting it, like a menu item does. */
    public function testAHeaderButtonCountsAsAReferenceThatBlocksDeletingThePage(): void
    {
        $pageId = $this->page();
        $this->headerButtonPointingAt($pageId);

        $this->assertSame(1, PageService::references($pageId)['nav']);
    }

    public function testALinkToAnotherPageDoesNotCount(): void
    {
        $pageId = $this->page();
        $this->menuItem($this->page());

        $this->assertSame([], PageUsage::forPageId($pageId));
    }

    /**
     * The boundary this read model is honest about: a typed address is text,
     * so it is neither counted here nor ever rewritten. The redirect keeps it
     * working instead (REDIRECTS.md).
     */
    public function testATypedAddressIsNotCounted(): void
    {
        $pageId = $this->page();
        $slug = (string) $this->pages->findById($pageId)['slug'];
        $this->menuItem($pageId, type: 'external', externalUrl: '/' . $slug);

        $this->assertSame([], PageUsage::forPageId($pageId));
    }
}
