<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Module\PortfolioModule;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\ItemGalleryContent;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The Portfolio's "Projecten" block (App\Service\Blocks\ProjectCardsBlock) on
 * an ordinary page of this test's own, placed the way the page builder places
 * a block and rendered the way the site renders one
 * (App\Service\SectionRegistry::render()):
 *
 *   - a new block is every visible project as plain cards, stored as the
 *     Portfolio's source plus what its editor offers;
 *   - a card follows the phase 4B contract without a line of its own: the
 *     published page it links to, at that page's current address; otherwise
 *     its old project address; otherwise no link, no arrow and no zoom. A
 *     draft never reaches the page;
 *   - which projects and how many; title and introduction optional; no
 *     projects, no section;
 *   - the Portfolio switched off and on again: nothing public, nothing
 *     changed, and the same block back.
 *
 * The card-link rules themselves are Tests\Service\PortfolioProjectPageTest's;
 * this file proves the block reaches them. That the block has no query, card
 * or link of its own, and that its editor guards like every block editor, is
 * Tests\Module\PortfolioModuleTest. Everything made here is marked zz- and
 * removed again in tearDown().
 */
final class ProjectCardsBlockTest extends TestCase
{
    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> page_sections ids */
    private array $sectionIds = [];

    private bool $inTransaction = false;

    /**
     * The Portfolio on, whatever the container says: this file is about the
     * block, and switches the module off itself where that is the question.
     */
    protected function setUp(): void
    {
        self::withPortfolio(true);
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            Database::connection()->rollBack();
            $this->inTransaction = false;
        }

        // A switched-off module's block cannot be deleted, so on first.
        self::withPortfolio(true);

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

        ModuleRegistry::overrideForTests(null);

        PortfolioGalleryContent::clearCache();
        ItemGalleryContent::clearCache();
        PageContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* A new block                                                         */
    /* ------------------------------------------------------------------ */

    public function testANewBlockShowsEveryVisibleProjectAsPlainCards(): void
    {
        $marker = bin2hex(random_bytes(4));
        $this->item('ZZ Nieuw ' . $marker);

        [$blockId, $sectionKey, $pageKey, $pageId] = $this->projectsBlock();

        $row = (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $sectionKey);
        $this->assertNotNull($row);
        $this->assertSame(PortfolioModule::GALLERY_SOURCE, $row['source_type']);
        $this->assertSame(ItemGalleryContent::SCOPE_ALL, $row['portfolio_scope']);
        $this->assertNull($row['max_items']);
        $this->assertNull($row['collection_id']);
        $this->assertSame(0, (int) $row['show_filter_bar']);
        $this->assertSame(0, (int) $row['enable_lightbox']);
        $this->assertSame([], BlockLocalization::translations('item_galleries', (int) $row['id']), 'no heading until the editor writes one');
        $this->assertSame(1, (int) $row['is_active']);

        $html = $this->renderBlock($blockId);
        $this->assertStringContainsString('data-gallery-block', $html);
        $this->assertStringNotContainsString('section-head', $html);
        $this->assertStringNotContainsString('filter-bar', $html);
        $this->assertStringNotContainsString('data-gallery-lightbox', $html);
        $this->cardElement($this->xpath($html), 'ZZ Nieuw ' . $marker);

        $page = (array) (new PageRepository())->findById($pageId);
        $this->assertArrayHasKey(
            'project_cards',
            SectionRegistry::availableForPage($page, new PageSectionRepository()),
            'a second one can be added to the same page'
        );

        $section = (array) (new PageSectionRepository())->findById($blockId);
        $this->assertStringStartsWith('/admin/project-cards.php?section=', (string) SectionRegistry::editUrl($section));
    }

    /* ------------------------------------------------------------------ */
    /* The cards                                                           */
    /* ------------------------------------------------------------------ */

    /** Rule 1, through the block: the page's current address, and a rename is followed. */
    public function testACardOpensItsPublishedPageAndFollowsARename(): void
    {
        $marker = bin2hex(random_bytes(4));
        $itemId = $this->item('ZZ Gekoppeld ' . $marker);
        $projectPageId = $this->page(PageContent::STATUS_PUBLISHED);
        (new PortfolioGalleryRepository())->setItemPage($itemId, $projectPageId);

        [$blockId] = $this->projectsBlock();

        $card = $this->cardElement($this->xpath($this->renderBlock($blockId)), 'ZZ Gekoppeld ' . $marker);
        $this->assertSame('a', $card->nodeName);
        $this->assertSame('/' . $this->slugOf($projectPageId), $card->getAttribute('href'));
        $this->assertTrue($this->hasArrow($card), 'a card that opens its own page carries the arrow');

        $renamed = $this->rename($projectPageId);

        $card = $this->cardElement($this->xpath($this->renderBlock($blockId)), 'ZZ Gekoppeld ' . $marker);
        $this->assertSame('/' . $renamed, $card->getAttribute('href'), 'the block follows the page, never a stored address');
    }

    /** A draft is not public, and neither is its address: not as a link, and not behind an old one. */
    public function testADraftPageNeverReachesThePublicPage(): void
    {
        $marker = bin2hex(random_bytes(4));
        $itemId = $this->item('ZZ Concept ' . $marker);
        $draftId = $this->page(PageContent::STATUS_DRAFT);
        (new PortfolioGalleryRepository())->setItemPage($itemId, $draftId);
        $draftSlug = $this->slugOf($draftId);

        [$blockId] = $this->projectsBlock();

        $html = $this->renderBlock($blockId);
        $this->assertSame('div', $this->cardElement($this->xpath($html), 'ZZ Concept ' . $marker)->nodeName, 'linked to a draft only: no link');
        $this->assertStringNotContainsString($draftSlug, $html);

        $oldSlug = $this->giveItAnOldProjectPage($itemId);

        $html = $this->renderBlock($blockId);
        $this->assertSame(
            '/portfolio/' . $oldSlug,
            $this->cardElement($this->xpath($html), 'ZZ Concept ' . $marker)->getAttribute('href'),
            'the old address, and still not the draft'
        );
        $this->assertStringNotContainsString($draftSlug, $html);
    }

    /** Rule 2, through the block: an item that still has its old project page links there. */
    public function testAnOldProjectKeepsItsOldAddress(): void
    {
        $marker = bin2hex(random_bytes(4));
        $oldSlug = $this->giveItAnOldProjectPage($this->item('ZZ Oud ' . $marker));

        [$blockId] = $this->projectsBlock();

        $card = $this->cardElement($this->xpath($this->renderBlock($blockId)), 'ZZ Oud ' . $marker);
        $this->assertSame('a', $card->nodeName);
        $this->assertSame(PortfolioGalleryContent::publicPath($oldSlug), $card->getAttribute('href'));
        $this->assertTrue($this->hasArrow($card));
    }

    /** Rule 3, through the block: no page and no old project page is a card that does nothing. */
    public function testAProjectWithoutADestinationIsNotClickable(): void
    {
        $marker = bin2hex(random_bytes(4));
        $this->item('ZZ Kaal ' . $marker);

        [$blockId] = $this->projectsBlock();

        $html = $this->renderBlock($blockId);
        $card = $this->cardElement($this->xpath($html), 'ZZ Kaal ' . $marker);

        $this->assertSame('div', $card->nodeName);
        $this->assertFalse($card->hasAttribute('href'));
        $this->assertFalse($card->hasAttribute('data-lightbox-item'), 'and no zoom either: this block has none');
        $this->assertFalse($this->hasArrow($card));
        $this->assertStringNotContainsString('data-item-lightbox', $html);
    }

    /* ------------------------------------------------------------------ */
    /* What the editor sets                                                */
    /* ------------------------------------------------------------------ */

    public function testWhichProjectsAndHowMany(): void
    {
        $marker = bin2hex(random_bytes(4));
        $featuredId = $this->item('ZZ Uitgelicht ' . $marker);
        $this->item('ZZ Niet uitgelicht ' . $marker);

        Database::connection()
            ->prepare('UPDATE portfolio_gallery_items SET is_featured = 1 WHERE id = :id')
            ->execute(['id' => $featuredId]);

        [$blockId, $sectionKey, $pageKey] = $this->projectsBlock(['portfolio_scope' => ItemGalleryContent::SCOPE_FEATURED]);

        $xpath = $this->xpath($this->renderBlock($blockId));
        $this->cardElement($xpath, 'ZZ Uitgelicht ' . $marker);
        $this->assertSame(0, $this->cardsTitled($xpath, 'ZZ Niet uitgelicht ' . $marker), 'only the homepage selection');

        $this->configure($pageKey, $sectionKey, ['portfolio_scope' => ItemGalleryContent::SCOPE_ALL, 'max_items' => 2]);

        $this->assertSame(2, $this->cardCount($this->xpath($this->renderBlock($blockId))), 'no more than the maximum');
    }

    public function testTitleAndIntroductionAreOptional(): void
    {
        $marker = bin2hex(random_bytes(4));
        $this->item('ZZ Project ' . $marker);

        [$blockId, $sectionKey, $pageKey] = $this->projectsBlock();

        $bare = $this->renderBlock($blockId);
        $this->assertStringContainsString('gallery-grid', $bare);
        $this->assertStringNotContainsString('section-head', $bare, 'no title and no introduction: no heading markup at all');

        $this->configure($pageKey, $sectionKey, ['title' => 'ZZ Onze projecten ' . $marker]);

        $titled = $this->renderBlock($blockId);
        $this->assertStringContainsString('ZZ Onze projecten ' . $marker . '</h2>', $titled);
        $this->assertStringNotContainsString('class="lead"', $titled);
        $this->assertStringNotContainsString('class="eyebrow"', $titled);

        $this->configure($pageKey, $sectionKey, ['title' => '', 'lead' => 'ZZ Een greep uit ons werk ' . $marker]);

        $introduced = $this->renderBlock($blockId);
        $this->assertStringContainsString('ZZ Een greep uit ons werk ' . $marker . '</p>', $introduced);
        $this->assertStringNotContainsString('<h2', $introduced);
    }

    public function testWithoutProjectsTheBlockLeavesNoSection(): void
    {
        [$blockId] = $this->projectsBlock(['title' => 'ZZ Kop zonder projecten']);

        // Every project hidden, inside a transaction tearDown() rolls back: the
        // one way to ask a database that holds projects for "none at all".
        Database::connection()->beginTransaction();
        $this->inTransaction = true;
        Database::connection()->exec('UPDATE portfolio_gallery_items SET is_active = 0');

        $this->assertSame('', $this->renderBlock($blockId), 'no projects: no section, no heading, no gap');
    }

    /* ------------------------------------------------------------------ */
    /* The module switched off and on                                      */
    /* ------------------------------------------------------------------ */

    public function testTheBlockSurvivesThePortfolioBeingSwitchedOffAndOn(): void
    {
        $marker = bin2hex(random_bytes(4));
        $this->item('ZZ Blijft ' . $marker);

        [$blockId, $sectionKey, $pageKey, $pageId] = $this->projectsBlock([
            'title' => 'ZZ Projecten ' . $marker,
            'max_items' => 200,
            'show_filter_bar' => true,
            'background' => 'soft',
        ]);
        $stored = (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $sectionKey);
        $this->assertStringContainsString('ZZ Blijft ' . $marker, $this->renderBlock($blockId));

        self::withPortfolio(false);
        $page = (array) (new PageRepository())->findById($pageId);

        $this->assertFalse(SectionRegistry::exists('project_cards'));
        $this->assertSame('portfolio', SectionRegistry::disabledModuleFor('project_cards'), 'a switched-off part, not broken data');
        $this->assertArrayNotHasKey('project_cards', SectionRegistry::availableForPage($page, new PageSectionRepository()));
        $this->assertSame('', $this->renderBlock($blockId), 'nothing public while the Portfolio is off');
        $this->assertNotNull((new PageSectionRepository())->findById($blockId), 'the block stays on its page');
        $this->assertSame($stored, (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $sectionKey), 'with every setting it had');

        self::withPortfolio(true);

        $html = $this->renderBlock($blockId);
        $this->assertStringContainsString('ZZ Projecten ' . $marker, $html, 'on again: the same block');
        $this->assertStringContainsString('ZZ Blijft ' . $marker, $html);
        $this->assertStringContainsString('bg-soft', $html);
        $this->assertSame($stored, (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $sectionKey));
    }

    /* ------------------------------------------------------------------ */

    private static function withPortfolio(bool $enabled): void
    {
        ModuleRegistry::overrideForTests([
            'shop' => true,
            'personalization' => true,
            'blog' => true,
            'portfolio' => $enabled,
            'multilingual' => true,
        ]);
    }

    /**
     * A published page of this test's own with one Projecten block on it:
     * created through the registry, attached the way the page builder attaches
     * a block and, when $settings are given, saved the way its editor saves.
     *
     * @param array<string, mixed> $settings
     * @return array{0: int, 1: string, 2: string, 3: int} page_sections id, section_key, page content_key, page id
     */
    private function projectsBlock(array $settings = []): array
    {
        $key = 'zz-projecten-' . bin2hex(random_bytes(4));
        $pageId = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => PageContent::STATUS_PUBLISHED,
        ], 'ZZ Projecten');
        $this->pageIds[] = $pageId;

        [$sectionId, $sectionKey] = SectionRegistry::create('project_cards', $key);
        $pageSectionId = (new PageSectionRepository())->create($pageId, $key, 'project_cards', $sectionKey, $sectionId);
        $this->sectionIds[] = $pageSectionId;

        if ($settings !== []) {
            $this->configure($key, (string) $sectionKey, $settings);
        }

        return [$pageSectionId, (string) $sectionKey, $key, $pageId];
    }

    /**
     * $settings over what the block holds, stored through
     * ProjectCardsBlock::rowValues() and, for its Dutch title and lead,
     * rowWords(): exactly what its endpoint stores.
     *
     * @param array<string, mixed> $settings
     */
    private function configure(string $pageKey, string $sectionKey, array $settings): void
    {
        $repository = new ItemGalleryRepository();
        $current = (array) $repository->findBySlugAndKey($pageKey, $sectionKey);
        $id = (int) $current['id'];

        $repository->upsertSection($pageKey, $sectionKey, ProjectCardsBlock::rowValues($settings + [
            'portfolio_scope' => (string) $current['portfolio_scope'],
            'max_items' => $current['max_items'] === null ? null : (int) $current['max_items'],
            'show_filter_bar' => (bool) $current['show_filter_bar'],
            'background' => (string) $current['background'],
            'is_active' => (bool) $current['is_active'],
        ]));

        BlockLocalization::save('item_galleries', $id, 'nl', ProjectCardsBlock::rowWords([
            'title' => (string) ($settings['title'] ?? BlockLocalization::raw('item_galleries', $id, 'title', 'nl')),
            'lead' => (string) ($settings['lead'] ?? BlockLocalization::raw('item_galleries', $id, 'lead', 'nl')),
        ]));

        ItemGalleryContent::clearCache();
    }

    private function item(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(4));

        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-projecten-' . $marker . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;

        // Its words live per website language since Multilingual 2.0 phase 5
        // wave A; the default language is what a card shows first.
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::ALT => 'ZZ alt ' . $marker,
            PortfolioLocalization::TITLE => $title,
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

        return $slug;
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

    private function slugOf(int $pageId): string
    {
        return (string) ((new PageRepository())->findById($pageId)['slug'] ?? '');
    }

    /** @return string the new slug */
    private function rename(int $pageId): string
    {
        $slug = 'zz-hernoemd-' . bin2hex(random_bytes(4));
        $pages = new PageRepository();
        $page = (array) $pages->findById($pageId);

        $pages->update($pageId, [
            'slug' => $slug,
            'status' => (string) $page['status'],
        ]);

        return $slug;
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

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private const CARD = '//*[contains(concat(" ", normalize-space(@class), " "), " gallery-item ")]';

    private function cardCount(\DOMXPath $xpath): int
    {
        return (int) $xpath->query(self::CARD)->length;
    }

    private function cardsTitled(\DOMXPath $xpath, string $title): int
    {
        return (int) $xpath->query(self::CARD . '[.//p[normalize-space(.) = "' . $title . '"]]')->length;
    }

    /** The one card whose overlay title is exactly $title. */
    private function cardElement(\DOMXPath $xpath, string $title): \DOMElement
    {
        $this->assertSame(1, $this->cardsTitled($xpath, $title), 'exactly one card titled ' . $title);

        $card = $xpath->query(self::CARD . '[.//p[normalize-space(.) = "' . $title . '"]]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $card);

        return $card;
    }

    private function hasArrow(\DOMElement $card): bool
    {
        foreach ($card->getElementsByTagName('span') as $span) {
            if (str_contains(' ' . $span->getAttribute('class') . ' ', ' gallery-item__arrow ')) {
                return true;
            }
        }

        return false;
    }
}
