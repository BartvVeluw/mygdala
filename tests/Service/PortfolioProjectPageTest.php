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
 * What a portfolio item's card and project address do in public (Portfolio
 * 2.0), read through the model and the block the site renders from
 * (App\Service\PortfolioGalleryContent):
 *
 *   - the gallery card: never a link, its picture always a lightbox button,
 *     and a separate "Bekijk project" link, in the contract's order: the
 *     published LEGACY page the item links to, at its current address;
 *     otherwise the item's own project page /portfolio/<slug>; otherwise no
 *     button. A draft or a deleted page is no destination, so no unpublished
 *     address ever reaches the page;
 *   - the overlay: title, short text and button, top to bottom;
 *   - the block's fallback link and lightbox setting, which never make a
 *     portfolio card a link or stop it zooming;
 *   - a /portfolio/<slug> address: a redirect target only while a published
 *     legacy page is linked, always that page's own canonical, following a
 *     rename and a relink, never another project address itself;
 *   - the sitemap: a linked project once, from Core's pages collector under the
 *     page's own address, and its project address not at all — while a
 *     project page without a published link is listed in every language.
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
     * No project page — which takes both the switch and a slug to reach it
     * by — and no legacy page: no "Bekijk project", and the picture still
     * zooms. The card is never a link.
     */
    public function testACardWithoutAProjectPageHasNoButtonAndStillZooms(): void
    {
        $itemId = $this->item();

        $card = $this->card($itemId);
        $this->assertSame('', $card['url'], 'the card itself is never a link');
        $this->assertFalse($card['is_detail_link']);
        $this->assertTrue($card['opens_lightbox'], 'a portfolio picture always zooms');
        $this->assertNull($card['cta']);

        $html = $this->render($card);
        $this->assertStringNotContainsString('<a class="gallery-item', $html);
        $this->assertStringNotContainsString('gallery-item__cta', $html);
        $this->assertStringContainsString('data-lightbox-trigger', $html, 'even with the block lightbox setting off');

        $this->setOldProjectColumns($itemId, false, 'zz-alleen-een-slug-' . bin2hex(random_bytes(4)));
        $this->assertNull($this->card($itemId)['cta'], 'a slug without the page switched on is no project page');

        $this->setOldProjectColumns($itemId, true, null);
        $this->assertNull($this->card($itemId)['cta'], 'and neither is the switch without a slug to reach it by');
    }

    /**
     * The item's own project page: "Bekijk project" goes to /portfolio/<slug>,
     * a real link inside the overlay, while the picture is a button that opens
     * the lightbox — never both a link card and a zoom.
     */
    public function testAProjectPageGivesTheCardASeparateButtonToItsAddress(): void
    {
        $itemId = $this->item();
        $slug = $this->giveItAProjectPage($itemId);

        $card = $this->card($itemId);
        $this->assertSame('', $card['url']);
        $this->assertSame('/portfolio/' . $slug, $card['cta']['url']);
        $this->assertSame(PortfolioGalleryContent::publicPath($slug), $card['cta']['url'], 'through the one place that knows the prefix');
        $this->assertSame('Bekijk project', $card['cta']['label']);

        $xpath = $this->xpath($this->render($card));
        $this->assertSame(0, $xpath->query('//a[contains(@class, "gallery-item")][not(contains(@class, "gallery-item__cta"))]')->length, 'the card is no link');
        $zoom = $xpath->query('//button[@data-lightbox-trigger]');
        $this->assertSame(1, $zoom->length, 'the picture is one button');
        $this->assertSame('button', $zoom->item(0)->nodeName);
        $this->assertStringStartsWith('Vergroot afbeelding: ', (string) $zoom->item(0)->getAttribute('aria-label'), 'the button says what it does');

        $cta = $xpath->query('//a[contains(@class, "gallery-item__cta")]');
        $this->assertSame(1, $cta->length, 'the call to action is a real link');
        $this->assertSame('/portfolio/' . $slug, $cta->item(0)->getAttribute('href'));
        $this->assertSame(0, $xpath->query('//button//a | //a//button')->length, 'no link inside the button, and no button inside the link');
    }

    /**
     * The overlay reads top to bottom: the title, then the short text, then
     * the call to action — no title and text side by side any more.
     */
    public function testTheOverlayReadsTitleThenShortTextThenTheButton(): void
    {
        $itemId = $this->item('ZZ Een projecttitel die best lang is ' . bin2hex(random_bytes(4)));
        $this->giveItAProjectPage($itemId);

        $xpath = $this->xpath($this->render($this->card($itemId)));
        $children = $xpath->query('//*[contains(@class, "gallery-item__overlay")]/*');
        $this->assertSame(
            ['gallery-item__title', 'gallery-item__text', 'gallery-item__cta'],
            array_map(static fn (\DOMElement $node): string => (string) $node->getAttribute('class'), iterator_to_array($children))
        );

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/item-gallery.css');
        $this->assertMatchesRegularExpression('/\.gallery-item__overlay\{[^}]*flex-direction: column;/', $css, 'stacked, not in a row');
        $this->assertMatchesRegularExpression('/@media \(hover: none\)\{\s*\.gallery-item--has-cta \.gallery-item__overlay\{ opacity: 1; \}/', $css, 'on a touch screen the button is in view without hover');
    }

    /**
     * The address answers under every language's prefix, so a card read in
     * another language links there (docs/multilingual/ROUTING.md, §9).
     */
    public function testTheButtonLinksInTheLanguageBeingRead(): void
    {
        if (!\App\Service\Language\SiteLanguages::isActive('en') || \App\Service\Language\SiteLanguages::defaultCode() !== 'nl') {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        $itemId = $this->item();
        $slug = $this->giveItAProjectPage($itemId);

        \App\Service\Routing\RequestLanguage::set('en', true);
        try {
            $card = $this->card($itemId);
            $this->assertSame('/en/portfolio/' . $slug, $card['cta']['url']);
            $this->assertSame('View project', $card['cta']['label']);
        } finally {
            \App\Service\Routing\RequestLanguage::reset();
        }

        $this->assertSame('/portfolio/' . $slug, $this->card($itemId)['cta']['url'], 'the default language keeps the unprefixed address');
    }

    /**
     * A LEGACY linked page (phase 4B) before the item's own page, and only a
     * published one. Linked to a draft, the button keeps the item's own
     * address and names the draft nowhere; once the page is published the
     * button goes there; unlinked again, the item's own page is back.
     */
    public function testALegacyPublishedPageTakesOverTheButtonAndADraftDoesNot(): void
    {
        $itemId = $this->item();
        $slug = $this->giveItAProjectPage($itemId);
        $pageId = $this->page(PageContent::STATUS_DRAFT);
        $repository = new PortfolioGalleryRepository();
        $repository->setItemPage($itemId, $pageId);
        $pageSlug = (string) (new PageRepository())->findById($pageId)['slug'];

        $card = $this->card($itemId);
        $this->assertSame('/portfolio/' . $slug, $card['cta']['url'], 'a draft does not take over');
        $this->assertStringNotContainsString($pageSlug, $this->render($card));

        $this->publish($pageId);
        $this->assertSame('/' . $pageSlug, $this->card($itemId)['cta']['url'], 'a published page does');

        $repository->setItemPage($itemId, null);
        $this->assertSame('/portfolio/' . $slug, $this->card($itemId)['cta']['url'], 'unlinked, the own page is back');
    }

    /** A legacy link: the page's current address, never a stored one. */
    public function testALegacyButtonFollowsARenameOfItsPage(): void
    {
        $itemId = $this->item();
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        (new PortfolioGalleryRepository())->setItemPage($itemId, $pageId);
        $slug = (string) (new PageRepository())->findById($pageId)['slug'];

        $card = $this->card($itemId);
        $this->assertSame('', $card['url'], 'still no link card');
        $this->assertSame('/' . $slug, $card['cta']['url']);

        $renamed = $this->rename($pageId);
        $this->assertSame('/' . $renamed, $this->card($itemId)['cta']['url'], 'the button follows the page, never a stored address');
    }

    /** A legacy link to a draft or a deleted page is no button. */
    public function testALegacyButtonNeverGoesToADraftOrADeletedPage(): void
    {
        $itemId = $this->item();
        $pageId = $this->page(PageContent::STATUS_DRAFT);
        (new PortfolioGalleryRepository())->setItemPage($itemId, $pageId);
        $slug = (string) (new PageRepository())->findById($pageId)['slug'];

        $card = $this->card($itemId);
        $this->assertNull($card['cta'], 'a draft is not public, so neither is its address');
        $this->assertStringNotContainsString($slug, $this->render($card));

        $this->publish($pageId);
        $this->assertSame('/' . $slug, $this->card($itemId)['cta']['url'], 'published, the button is there');

        PageService::delete((array) (new PageRepository())->findById($pageId));
        $this->assertNull($this->card($itemId)['cta'], 'deleted, no button again');
    }

    /* ------------------------------------------------------------------ */
    /* The block's fallback link and its lightbox setting                  */
    /* ------------------------------------------------------------------ */

    /**
     * A gallery block set to portfolio items, with a fallback link and the
     * lightbox OFF. Every portfolio card still zooms, none is a link, the one
     * with a project page and the one with a legacy page carry their button,
     * and the fallback address appears nowhere. Rendered through the real
     * block, from its stored settings. That the fallback link still serves the
     * cards of other sources is Tests\Service\ReusableBlocksPhase4Test.
     */
    public function testEveryPortfolioCardZoomsAndNoneFollowsTheFallbackLink(): void
    {
        $marker = bin2hex(random_bytes(4));
        $fallback = '/zz-fallback-' . $marker;

        $this->item('ZZ Kaal ' . $marker);

        $ownId = $this->item('ZZ Eigen ' . $marker);
        $ownSlug = $this->giveItAProjectPage($ownId);

        $linkedId = $this->item('ZZ Gekoppeld ' . $marker);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        (new PortfolioGalleryRepository())->setItemPage($linkedId, $pageId);
        $pageSlug = (string) (new PageRepository())->findById($pageId)['slug'];

        $html = $this->renderBlock($this->galleryBlock(['fallback_link_url' => $fallback, 'enable_lightbox' => false]));
        $xpath = $this->xpath($html);

        foreach (['ZZ Kaal ', 'ZZ Eigen ', 'ZZ Gekoppeld '] as $name) {
            $card = $this->cardElement($xpath, $name . $marker);
            $this->assertSame('div', $card->nodeName, $name . 'is not a link');
            $this->assertSame(1, $xpath->query('.//button[@data-lightbox-trigger]', $card)->length, $name . 'zooms');
            $this->assertSame(0, $xpath->query('.//*[contains(@class, "gallery-item__arrow")]', $card)->length);
        }

        $cta = static fn (\DOMElement $card): array => array_map(
            static fn (\DOMElement $a): string => (string) $a->getAttribute('href'),
            iterator_to_array($xpath->query('.//a[contains(@class, "gallery-item__cta")]', $card))
        );
        $this->assertSame([], $cta($this->cardElement($xpath, 'ZZ Kaal ' . $marker)));
        $this->assertSame(['/portfolio/' . $ownSlug], $cta($this->cardElement($xpath, 'ZZ Eigen ' . $marker)));
        $this->assertSame(['/' . $pageSlug], $cta($this->cardElement($xpath, 'ZZ Gekoppeld ' . $marker)));

        $this->assertStringNotContainsString($fallback, $html, 'no portfolio card follows the fallback link');
        $this->assertSame(1, substr_count($html, 'data-lightbox '), 'the page gets the one lightbox overlay');
        $this->assertStringContainsString('data-lightbox-group', $html, 'the block is its own lightbox sequence');
    }

    /* ------------------------------------------------------------------ */
    /* The old address                                                     */
    /* ------------------------------------------------------------------ */

    public function testAnOldProjectAddressRedirectsOnlyToAPublishedLinkedPage(): void
    {
        $itemId = $this->item();
        $oldSlug = $this->giveItAProjectPage($itemId);
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
        $linkedSlug = $this->giveItAProjectPage($linkedId);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        $repository->setItemPage($linkedId, $pageId);
        $page = (array) (new PageRepository())->findById($pageId);

        $unlinkedSlug = $this->giveItAProjectPage($this->item());

        $oldAddresses = array_column(PortfolioGalleryContent::projectPagesForSitemap(), 'slug');
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

    /**
     * An old project page answers at its one slug in every published
     * language (portfolio-detail.php declares each prefixed address as a
     * version), so the sitemap lists every version, each naming all of them.
     * Before phase 7 wave E only the default-language address was listed.
     */
    public function testTheSitemapListsAnOldProjectPageInEveryPublishedLanguage(): void
    {
        $slug = $this->giveItAProjectPage($this->item());

        $expected = [];
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            $expected[$code] = PortfolioGalleryContent::canonicalUrlForSlug($slug, $code);
        }

        $entries = [];
        foreach (Sitemap::entries() as $entry) {
            if (in_array($entry['loc'], $expected, true)) {
                $entries[$entry['loc']] = $entry;
            }
        }

        $this->assertSame(array_values($expected), array_keys($entries), 'one entry per published language');
        foreach ($entries as $loc => $entry) {
            $this->assertSame(count($expected) > 1 ? $expected : [], $entry['alternates'], $loc . ' names every version, itself included');
        }
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
     * The item's own project page switched on, with a slug and its words —
     * what its editor saves (api/admin/update-portfolio-item.php), written
     * through the repository the endpoint uses.
     *
     * @return string the slug
     */
    private function giveItAProjectPage(int $itemId): string
    {
        $slug = 'zz-oud-project-' . bin2hex(random_bytes(4));

        (new PortfolioGalleryRepository())->setItemProjectPage($itemId, true, $slug);

        // Its rich text goes through the words API like any other word since
        // Multilingual 2.0 phase 5 wave A.
        PortfolioLocalization::saveItem($itemId, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::INTRO => '<p>ZZ oude intro</p>',
            PortfolioLocalization::DESCRIPTION => '<p>ZZ oude beschrijving</p>',
        ]);

        return $slug;
    }

    /** Half of a project page, to prove that half is not enough. */
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
