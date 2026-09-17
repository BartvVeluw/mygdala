<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\PortfolioGalleryContent;
use PHPUnit\Framework\TestCase;

/**
 * A portfolio item's optional link to an ordinary CMS page, as it is stored.
 *
 * No page is a complete item; a linked page comes back exactly as saved and
 * can be taken off again; linking touches no other column, the old project
 * page's included; the database refuses a page that does not exist; and
 * deleting the page through Pages' own route leaves the item standing without
 * a link — the ON DELETE SET NULL of
 * db/migrations/20260914200000_link_a_portfolio_item_to_a_page.php.
 *
 * Against the test database, through the repositories the site itself uses.
 * That a new and an upgraded installation get the same key is
 * Tests\Install\PortfolioPageLinkMigrationTest. Everything made here is its
 * own, marked zz-, and removed again in tearDown(); the image paths point
 * nowhere, because nothing here reads a file.
 */
final class PortfolioPageLinkTest extends TestCase
{
    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $pageIds = [];

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

        $this->itemIds = [];
        $this->pageIds = [];

        PortfolioGalleryContent::clearCache();
        PageContent::clearCache();
    }

    public function testANewItemHasNoPage(): void
    {
        $item = (array) (new PortfolioGalleryRepository())->findItemById($this->item());

        $this->assertArrayHasKey('page_id', $item);
        $this->assertNull($item['page_id'], 'an item without a page is a complete item');
    }

    public function testALinkedPageComesBackAsStoredAndCanBeTakenOffAgain(): void
    {
        $repository = new PortfolioGalleryRepository();
        $itemId = $this->item();
        $pageId = $this->page();

        $repository->setItemPage($itemId, $pageId);
        $this->assertSame($pageId, (int) $repository->findItemById($itemId)['page_id']);

        $repository->setItemPage($itemId, null);
        $this->assertNull($repository->findItemById($itemId)['page_id'], 'taking the link off is an ordinary save');
    }

    /**
     * Only the id travels. Every word of the item and every column of its old
     * project page keeps its value: the link replaces that page, it does not
     * overwrite what it held.
     */
    public function testLinkingAPageLeavesEveryOtherColumnAlone(): void
    {
        $repository = new PortfolioGalleryRepository();
        $itemId = $this->item();
        $this->giveItAnOldProjectPage($itemId);
        $before = (array) $repository->findItemById($itemId);

        $repository->setItemPage($itemId, $this->page());
        $after = (array) $repository->findItemById($itemId);

        $changing = ['page_id' => true, 'updated_at' => true];
        $this->assertSame(array_diff_key($before, $changing), array_diff_key($after, $changing));
        $this->assertSame('1', (string) $after['has_detail_page']);
        $this->assertSame('<p>ZZ oude intro</p>', $after['intro_nl']);
    }

    public function testTheDatabaseRefusesAPageThatDoesNotExist(): void
    {
        $repository = new PortfolioGalleryRepository();
        $itemId = $this->item();
        $missing = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM pages')->fetchColumn();

        try {
            $repository->setItemPage($itemId, $missing);
            $this->fail('a link to a page that does not exist must be refused');
        } catch (\PDOException $e) {
            $this->assertSame('23000', (string) $e->getCode(), 'refused by the foreign key, not by some other failure');
        }

        $this->assertNull($repository->findItemById($itemId)['page_id']);
    }

    /**
     * Pages deletes a page the way it always has — PageService::delete(), which
     * knows nothing about the Portfolio — and the item stays, without a link.
     */
    public function testDeletingThePageKeepsTheItemWithoutALink(): void
    {
        $repository = new PortfolioGalleryRepository();
        $itemId = $this->item();
        $pageId = $this->page();
        $repository->setItemPage($itemId, $pageId);

        PageService::delete((array) (new PageRepository())->findById($pageId));

        $this->assertNull((new PageRepository())->findById($pageId), 'the page is gone');

        $item = $repository->findItemById($itemId);
        $this->assertNotNull($item, 'the portfolio item is not');
        $this->assertNull($item['page_id']);
    }

    /* ------------------------------------------------------------------ */

    private function item(): int
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(4));

        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-portfolio-link-' . $marker . '.jpg',
            'thumbnail_path' => null,
            'alt_nl' => 'ZZ alt ' . $marker,
            'alt_en' => null,
            'title_nl' => 'ZZ Gekoppeld werk ' . $marker,
            'title_en' => null,
            'subtitle_nl' => 'ZZ onderschrift ' . $marker,
            'subtitle_en' => null,
        ]);
        $this->itemIds[] = $id;

        return $id;
    }

    /**
     * The old project page's columns, filled the way that editor filled them.
     * Nothing in the application writes them any more, so the fixture does it
     * directly, as Tests\Module\PortfolioModuleHttpTest sets a fixed route.
     */
    private function giveItAnOldProjectPage(int $itemId): void
    {
        Database::connection()
            ->prepare(
                "UPDATE portfolio_gallery_items
                    SET has_detail_page = 1, slug = :slug, intro_nl = '<p>ZZ oude intro</p>', description_nl = '<p>ZZ oude beschrijving</p>'
                  WHERE id = :id"
            )
            ->execute(['slug' => 'zz-oud-project-' . bin2hex(random_bytes(4)), 'id' => $itemId]);
    }

    private function page(string $status = PageContent::STATUS_PUBLISHED): int
    {
        $key = 'zz-projectpagina-' . bin2hex(random_bytes(4));

        $id = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => $status,
        ], 'ZZ Projectpagina');
        $this->pageIds[] = $id;

        return $id;
    }
}
