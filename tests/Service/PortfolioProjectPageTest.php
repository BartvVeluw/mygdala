<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\PortfolioModule;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\SectionRegistry;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-item-gallery.php';

/**
 * What a portfolio item's link does in public, read through the model and the
 * block the site renders from (App\Service\PortfolioGalleryContent):
 *
 *   - the gallery card, in the contract's order: the published page the item
 *     links to, at its current address; otherwise, while the item still has
 *     its old project page, that page's /portfolio/<slug>; otherwise no link
 *     and no arrow. A draft or a deleted page is no link, so no unpublished
 *     address ever reaches the page;
 *   - the block's fallback link, which never makes a portfolio card clickable;
 *   - an old /portfolio/<slug> address: a redirect target only while a
 *     published page is linked, always that page's own canonical, following a
 *     rename and a relink, never an old address itself;
 *   - the sitemap: a linked project once, from Core's pages collector under the
 *     page's own address, and its old address not at all — while an old project
 *     page without a published link is still listed.
 *
 * Against the test database, through the repositories, the read model and the
 * block the site itself uses. The redirect's status code and the module
 * switched off and on, over real HTTP, are Tests\Module\PortfolioModuleHttpTest.
 * Everything made here is its own, marked zz-, and removed again in tearDown().
 */
final class PortfolioProjectPageTest extends TestCase
{
    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> page_sections ids */
    private array $sectionIds = [];

    protected function tearDown(): void
    {
        $sections = new PageSectionRepository();
        foreach ($this->sectionIds as $id) {
            $row = $sections->findById($id);
            if ($row !== null) {
                SectionRegistry::delete($row, $sections);
            }
        }

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
        $this->sectionIds = [];

        PortfolioGalleryContent::clearCache();
        ItemGalleryContent::clearCache();
        PageContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The gallery card                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Rule 3. No page and no old project page — which takes both the switch
     * and a slug to reach it by — gives no link and no arrow.
     */
    public function testACardWithNeitherAPageNorAnOldProjectPageIsNoLinkAndHasNoArrow(): void
    {
        $itemId = $this->item();

        $card = $this->card($itemId);
        $this->assertSame('', $card['url']);
        $this->assertFalse($card['is_detail_link']);

        $html = $this->render($card);
        $this->assertStringNotContainsString('<a class="gallery-item', $html);
        $this->assertStringNotContainsString('gallery-item__arrow', $html);

        $this->setOldProjectColumns($itemId, false, 'zz-alleen-een-slug-' . bin2hex(random_bytes(4)));
        $this->assertSame('', $this->card($itemId)['url'], 'a slug without the old page switched on is no old project page');

        $this->setOldProjectColumns($itemId, true, null);
        $this->assertSame('', $this->card($itemId)['url'], 'and neither is the switch without a slug to reach it by');
    }

    /**
     * Rule 2. An item that still has its old project page links its card to
     * that page's address, arrow and all, until a published page is linked —
     * so a site keeps working after the upgrade.
     */
    public function testAnOldProjectPageLinksTheCardToItsOldAddress(): void
    {
        $itemId = $this->item();
        $oldSlug = $this->giveItAnOldProjectPage($itemId);

        $card = $this->card($itemId);
        $this->assertSame('/portfolio/' . $oldSlug, $card['url']);
        $this->assertSame(PortfolioGalleryContent::publicPath($oldSlug), $card['url'], 'through the one place that knows the prefix');
        $this->assertTrue($card['is_detail_link']);

        $html = $this->render($card);
        $this->assertStringContainsString('<a class="gallery-item gallery-item--linked" href="/portfolio/' . $oldSlug . '"', $html);
        $this->assertStringContainsString('gallery-item__arrow', $html);
    }

    /**
     * The old address answers under every language's prefix, so a card read
     * in another language links there, as a card linked to a page already did
     * (docs/multilingual/ROUTING.md, §9). It used to send every language to
     * the default language's copy.
     */
    public function testAnOldProjectCardLinksInTheLanguageBeingRead(): void
    {
        if (!\App\Service\Language\SiteLanguages::isActive('en') || \App\Service\Language\SiteLanguages::defaultCode() !== 'nl') {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        $itemId = $this->item();
        $oldSlug = $this->giveItAnOldProjectPage($itemId);

        \App\Service\Routing\RequestLanguage::set('en', true);
        try {
            $this->assertSame('/en/portfolio/' . $oldSlug, $this->card($itemId)['url']);
        } finally {
            \App\Service\Routing\RequestLanguage::reset();
        }

        $this->assertSame('/portfolio/' . $oldSlug, $this->card($itemId)['url'], 'the default language keeps the unprefixed address');
    }

    /**
     * Rule 1 before rule 2, and only for a published page. Linked to a draft,
     * the card keeps the old address and names the draft nowhere; once the
     * page is published the card links to it; unlinked again, the old address
     * is back.
     */
    public function testAPublishedPageTakesOverFromTheOldProjectPageAndADraftDoesNot(): void
    {
        $itemId = $this->item();
        $oldSlug = $this->giveItAnOldProjectPage($itemId);
        $pageId = $this->page(PageContent::STATUS_DRAFT);
        $repository = new PortfolioGalleryRepository();
        $repository->setItemPage($itemId, $pageId);
        $pageSlug = (string) (new PageRepository())->findById($pageId)['slug'];

        $card = $this->card($itemId);
        $this->assertSame('/portfolio/' . $oldSlug, $card['url'], 'a draft does not take over');
        $this->assertStringNotContainsString($pageSlug, $this->render($card));

        $this->publish($pageId);
        $this->assertSame('/' . $pageSlug, $this->card($itemId)['url'], 'a published page does');

        $repository->setItemPage($itemId, null);
        $this->assertSame('/portfolio/' . $oldSlug, $this->card($itemId)['url'], 'unlinked, the old address is back');
    }

    /** Rule 1: the page's current address, never a stored one. */
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

    /** Rule 3 for an item without an old project page: a draft or a deleted page is no link. */
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
    /* The block's fallback link                                           */
    /* ------------------------------------------------------------------ */

    /**
     * A gallery block set to portfolio items, with a fallback link and the
     * lightbox on. The card without a page and without an old project page
     * stays plain — no link to the fallback, no arrow, and so enlargeable like
     * any plain card — while the other two link exactly where the contract
     * says, and the fallback address appears nowhere. Rendered through the
     * real block, from its stored settings. That the fallback link still
     * serves the cards of other sources is
     * Tests\Service\ReusableBlocksPhase4Test.
     */
    public function testTheBlocksFallbackLinkNeverMakesAPortfolioCardClickable(): void
    {
        $marker = bin2hex(random_bytes(4));
        $fallback = '/zz-fallback-' . $marker;

        $this->item('ZZ Kaal ' . $marker);

        $oldId = $this->item('ZZ Oud ' . $marker);
        $oldSlug = $this->giveItAnOldProjectPage($oldId);

        $linkedId = $this->item('ZZ Gekoppeld ' . $marker);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        (new PortfolioGalleryRepository())->setItemPage($linkedId, $pageId);
        $pageSlug = (string) (new PageRepository())->findById($pageId)['slug'];

        $html = $this->renderBlock($this->galleryBlock(['fallback_link_url' => $fallback, 'enable_lightbox' => true]));
        $xpath = $this->xpath($html);

        $plain = $this->cardElement($xpath, 'ZZ Kaal ' . $marker);
        $this->assertSame('div', $plain->nodeName, 'no page and no old project page: not a link');
        $this->assertFalse($plain->hasAttribute('href'));
        $this->assertTrue($plain->hasAttribute('data-lightbox-item'), 'a plain card, so the lightbox may enlarge it');
        $this->assertSame(0, $xpath->query('.//*[contains(@class, "gallery-item__arrow")]', $plain)->length);

        $this->assertSame('/portfolio/' . $oldSlug, $this->cardElement($xpath, 'ZZ Oud ' . $marker)->getAttribute('href'));
        $this->assertSame('/' . $pageSlug, $this->cardElement($xpath, 'ZZ Gekoppeld ' . $marker)->getAttribute('href'));

        $this->assertStringNotContainsString($fallback, $html, 'no portfolio card follows the fallback link');
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

        $otherPageId = $this->page(PageContent::STATUS_PUBLISHED);
        $repository->setItemPage($itemId, $otherPageId);
        $this->assertSame(
            PageContent::canonicalUrl((array) (new PageRepository())->findById($otherPageId)),
            PortfolioGalleryContent::legacyProjectRedirectUrl($oldSlug),
            'a relink to another page is followed at once'
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

    private function item(string $title = ''): int
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(4));

        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-portfolio-project-' . $marker . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;

        // Words per website language since Multilingual 2.0 phase 5 wave A.
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::ALT => 'ZZ alt ' . $marker,
            PortfolioLocalization::TITLE => $title !== '' ? $title : 'ZZ Project ' . $marker,
            PortfolioLocalization::SUBTITLE => 'ZZ onderschrift ' . $marker,
        ]);

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
            ->prepare('UPDATE portfolio_gallery_items SET has_detail_page = 1, slug = :slug WHERE id = :id')
            ->execute(['slug' => $slug, 'id' => $itemId]);

        // Its rich text goes through the words API like any other word since
        // Multilingual 2.0 phase 5 wave A.
        PortfolioLocalization::saveItem($itemId, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::INTRO => '<p>ZZ oude intro</p>',
            PortfolioLocalization::DESCRIPTION => '<p>ZZ oude beschrijving</p>',
        ]);

        return $slug;
    }

    /** Half of an old project page, to prove that half is not enough. */
    private function setOldProjectColumns(int $itemId, bool $hasDetailPage, ?string $slug): void
    {
        Database::connection()
            ->prepare('UPDATE portfolio_gallery_items SET has_detail_page = :switch, slug = :slug WHERE id = :id')
            ->execute(['switch' => $hasDetailPage ? 1 : 0, 'slug' => $slug, 'id' => $itemId]);
    }

    private function page(string $status): int
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
            'status' => (string) $page['status'],
        ]);
    }

    /**
     * A page of this test's own with one gallery block set to portfolio items,
     * stored the way the block editor stores its settings.
     *
     * @param array<string, mixed> $settings
     * @return int the page_sections id
     */
    private function galleryBlock(array $settings): int
    {
        $key = 'zz-galerij-' . bin2hex(random_bytes(4));
        $pageId = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => PageContent::STATUS_PUBLISHED,
        ], 'ZZ Galerij');
        $this->pageIds[] = $pageId;

        [$sectionId, $sectionKey] = SectionRegistry::create('item_gallery', $key);
        $pageSectionId = (new PageSectionRepository())->create($pageId, $key, 'item_gallery', $sectionKey, $sectionId);
        $this->sectionIds[] = $pageSectionId;

        (new ItemGalleryRepository())->upsertSection($key, (string) $sectionKey, $settings + [
            'source_type' => PortfolioModule::GALLERY_SOURCE,
            'portfolio_scope' => ItemGalleryContent::SCOPE_ALL,
            'background' => 'default',
            'is_active' => true,
        ]);

        return $pageSectionId;
    }

    private function renderBlock(int $pageSectionId): string
    {
        ItemGalleryContent::clearCache();
        PortfolioGalleryContent::clearCache();
        PageContent::clearCache();

        $row = (new PageSectionRepository())->findById($pageSectionId);
        $this->assertNotNull($row);

        ob_start();
        SectionRegistry::render($row);

        return (string) ob_get_clean();
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
            // The block's own words, all empty (per website language since
            // Multilingual 2.0 phase 3B).
            ...\App\Service\Blocks\BlockLocalization::words('item_galleries', 0),
            'button_url' => '',
            'background' => 'default',
            'tight_top' => false,
        ], 'zz-test');

        return (string) ob_get_clean();
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /** The one card whose overlay title is exactly $title. */
    private function cardElement(\DOMXPath $xpath, string $title): \DOMElement
    {
        $cards = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " gallery-item ")][.//p[normalize-space(.) = "' . $title . '"]]'
        );
        $this->assertNotFalse($cards);
        $this->assertSame(1, $cards->length, 'exactly one card titled ' . $title);

        $card = $cards->item(0);
        $this->assertInstanceOf(\DOMElement::class, $card);

        return $card;
    }
}
