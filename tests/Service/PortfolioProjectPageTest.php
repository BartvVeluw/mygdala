<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\PortfolioGalleryContent;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-item-gallery.php';

/**
 * What a portfolio item's link to an ordinary page does in public, read through
 * the model the site renders from (App\Service\PortfolioGalleryContent):
 *
 *   - the gallery card: no page, no link and no arrow; a published page, its
 *     current address, also after a rename; a draft or a deleted page, a plain
 *     card again, so no draft's address ever reaches the page;
 *   - an old /portfolio/<slug> address: a redirect target only while a
 *     published page is linked, always that page's own canonical, following a
 *     rename, never an old address itself;
 *   - the sitemap: a linked project once, from Core's pages collector under the
 *     page's own address, and its old address not at all — while an old project
 *     page without a published link is still listed.
 *
 * Against the test database, through the repositories and read model the site
 * itself uses. The same over real HTTP, with the module switched off and on,
 * is Tests\Module\PortfolioModuleHttpTest. Everything made here is its own,
 * marked zz-, and removed again in tearDown().
 */
final class PortfolioProjectPageTest extends TestCase
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

    /* ------------------------------------------------------------------ */
    /* The gallery card                                                    */
    /* ------------------------------------------------------------------ */

    public function testACardWithoutAPageIsNoLinkAndHasNoArrow(): void
    {
        $card = $this->card($this->item());

        $this->assertSame('', $card['url']);
        $this->assertFalse($card['is_detail_link']);

        $html = $this->render($card);
        $this->assertStringNotContainsString('<a class="gallery-item', $html);
        $this->assertStringNotContainsString('gallery-item__arrow', $html);
    }

    /**
     * Even with an old project page switched on: that page only still answers
     * at its old address, it gives the card no link any more.
     */
    public function testAnOldProjectPageAloneMakesNoLink(): void
    {
        $itemId = $this->item();
        $this->giveItAnOldProjectPage($itemId);

        $this->assertSame('', $this->card($itemId)['url']);
        $this->assertFalse($this->card($itemId)['is_detail_link']);
    }

    public function testACardLinksToItsPublishedPageAndFollowsARename(): void
    {
        $itemId = $this->item();
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        (new PortfolioGalleryRepository())->setItemPage($itemId, $pageId);
        $slug = (string) (new PageRepository())->findById($pageId)['slug'];

        $card = $this->card($itemId);
        $this->assertSame('/' . $slug, $card['url']);
        $this->assertTrue($card['is_detail_link']);

        $html = $this->render($card);
        $this->assertStringContainsString('<a class="gallery-item gallery-item--linked" href="/' . $slug . '"', $html);
        $this->assertStringContainsString('gallery-item__arrow', $html);

        $renamed = $this->rename($pageId);
        $this->assertSame('/' . $renamed, $this->card($itemId)['url'], 'the card follows the page, never a stored address');
    }

    public function testACardNeverLinksToADraftOrADeletedPage(): void
    {
        $itemId = $this->item();
        $pageId = $this->page(PageContent::STATUS_DRAFT);
        (new PortfolioGalleryRepository())->setItemPage($itemId, $pageId);
        $slug = (string) (new PageRepository())->findById($pageId)['slug'];

        $card = $this->card($itemId);
        $this->assertSame('', $card['url'], 'a draft is not public, so neither is its address');
        $this->assertFalse($card['is_detail_link']);
        $this->assertStringNotContainsString($slug, $this->render($card));

        $this->publish($pageId);
        $this->assertSame('/' . $slug, $this->card($itemId)['url'], 'published, the card links');

        PageService::delete((array) (new PageRepository())->findById($pageId));
        $this->assertSame('', $this->card($itemId)['url'], 'deleted, it is a plain card again');
    }

    /* ------------------------------------------------------------------ */
    /* The old address                                                     */
    /* ------------------------------------------------------------------ */

    public function testAnOldProjectAddressRedirectsOnlyToAPublishedLinkedPage(): void
    {
        $itemId = $this->item();
        $oldSlug = $this->giveItAnOldProjectPage($itemId);
        $repository = new PortfolioGalleryRepository();

        $this->assertNull(PortfolioGalleryContent::legacyProjectRedirectUrl($oldSlug), 'no link: the old page answers itself');
        $this->assertNotNull(PortfolioGalleryContent::itemForDetailPage($oldSlug), 'and still shows what it showed');

        $pageId = $this->page(PageContent::STATUS_DRAFT);
        $repository->setItemPage($itemId, $pageId);
        $this->assertNull(PortfolioGalleryContent::legacyProjectRedirectUrl($oldSlug), 'never to a draft');

        $this->publish($pageId);
        $target = PortfolioGalleryContent::legacyProjectRedirectUrl($oldSlug);
        $this->assertSame(PageContent::canonicalUrl((array) (new PageRepository())->findById($pageId)), $target, "the page's own canonical");
        $this->assertStringNotContainsString('/portfolio/', (string) $target, 'never another old address, so no loop and no chain');

        $this->rename($pageId);
        $this->assertSame(
            PageContent::canonicalUrl((array) (new PageRepository())->findById($pageId)),
            PortfolioGalleryContent::legacyProjectRedirectUrl($oldSlug),
            'a rename is followed in one step'
        );

        $repository->setItemPage($itemId, null);
        $this->assertNull(PortfolioGalleryContent::legacyProjectRedirectUrl($oldSlug), 'unlinked: the old page answers again');

        $this->assertNull(PortfolioGalleryContent::legacyProjectRedirectUrl('zz-bestaat-niet-' . bin2hex(random_bytes(4))));
        $this->assertNull(PortfolioGalleryContent::legacyProjectRedirectUrl(''));
    }

    /* ------------------------------------------------------------------ */
    /* The sitemap                                                         */
    /* ------------------------------------------------------------------ */

    public function testTheSitemapListsALinkedProjectOnceUnderThePagesOwnAddress(): void
    {
        $repository = new PortfolioGalleryRepository();

        $linkedId = $this->item();
        $linkedSlug = $this->giveItAnOldProjectPage($linkedId);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        $repository->setItemPage($linkedId, $pageId);
        $page = (array) (new PageRepository())->findById($pageId);

        $unlinkedSlug = $this->giveItAnOldProjectPage($this->item());

        $oldAddresses = array_column(PortfolioGalleryContent::legacyProjectPagesForSitemap(), 'slug');
        $this->assertNotContains($linkedSlug, $oldAddresses, 'an address that redirects is no sitemap entry');
        $this->assertContains($unlinkedSlug, $oldAddresses, 'an old page that still answers stays listed');

        $locations = array_column(Sitemap::entries(), 'loc');
        $this->assertSame(
            1,
            count(array_keys($locations, PageContent::canonicalUrl($page), true)),
            "the page comes from Core's pages collector, once"
        );
        $this->assertNotContains(PortfolioGalleryContent::canonicalUrlForSlug($linkedSlug), $locations);
    }

    /* ------------------------------------------------------------------ */

    private function item(): int
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(4));

        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-portfolio-project-' . $marker . '.jpg',
            'thumbnail_path' => null,
            'alt_nl' => 'ZZ alt ' . $marker,
            'alt_en' => null,
            'title_nl' => 'ZZ Project ' . $marker,
            'title_en' => null,
            'subtitle_nl' => 'ZZ onderschrift ' . $marker,
            'subtitle_en' => null,
        ]);
        $this->itemIds[] = $id;

        return $id;
    }

    /**
     * The old project page's columns, as its editor left them. Nothing in the
     * application writes them any more, so the fixture does it directly.
     *
     * @return string the old slug
     */
    private function giveItAnOldProjectPage(int $itemId): string
    {
        $slug = 'zz-oud-project-' . bin2hex(random_bytes(4));

        Database::connection()
            ->prepare(
                "UPDATE portfolio_gallery_items
                    SET has_detail_page = 1, slug = :slug, intro_nl = '<p>ZZ oude intro</p>', description_nl = '<p>ZZ oude beschrijving</p>'
                  WHERE id = :id"
            )
            ->execute(['slug' => $slug, 'id' => $itemId]);

        return $slug;
    }

    private function page(string $status): int
    {
        $key = 'zz-projectpagina-' . bin2hex(random_bytes(4));

        $id = (new PageRepository())->create([
            'content_key' => $key,
            'slug' => $key,
            'title' => 'ZZ Projectpagina',
            'status' => $status,
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
        $this->pageIds[] = $id;

        return $id;
    }

    /** @return string the new slug */
    private function rename(int $pageId): string
    {
        $slug = 'zz-hernoemd-' . bin2hex(random_bytes(4));
        $this->savePage($pageId, ['slug' => $slug]);

        return $slug;
    }

    private function publish(int $pageId): void
    {
        $this->savePage($pageId, ['status' => PageContent::STATUS_PUBLISHED]);
    }

    /** @param array{slug?: string, status?: string} $changes */
    private function savePage(int $pageId, array $changes): void
    {
        $pages = new PageRepository();
        $page = (array) $pages->findById($pageId);

        $pages->update($pageId, $changes + [
            'slug' => (string) $page['slug'],
            'title' => (string) $page['title'],
            'status' => (string) $page['status'],
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
    }

    /**
     * The card the gallery would draw for one item, read fresh — every cache
     * in between cleared, as a new request would find it.
     *
     * @return array<string, mixed>
     */
    private function card(int $itemId): array
    {
        PortfolioGalleryContent::clearCache();
        PageContent::clearCache();

        $imagePath = (string) ((new PortfolioGalleryRepository())->findItemById($itemId)['image_path'] ?? '');

        foreach (PortfolioGalleryContent::catalogueItems(false) as $card) {
            if ($card['image_path'] === $imagePath) {
                return $card;
            }
        }

        $this->fail('item ' . $itemId . ' is not in the gallery');
    }

    /** @param array<string, mixed> $card */
    private function render(array $card): string
    {
        ItemGalleryContent::clearCache();

        ob_start();
        render_section_item_gallery([
            'items' => [$card],
            'enable_lightbox' => false,
            'filter_categories' => [],
            'fallback_link_url' => '',
            'eyebrow_nl' => '', 'eyebrow_en' => '',
            'title_nl' => '', 'title_en' => '',
            'lead_nl' => '', 'lead_en' => '',
            'footer_note_nl' => '', 'footer_note_en' => '',
            'button_label_nl' => '', 'button_label_en' => '',
            'button_url' => '',
            'background' => 'default',
            'tight_top' => false,
        ], 'zz-test');

        return (string) ob_get_clean();
    }
}
