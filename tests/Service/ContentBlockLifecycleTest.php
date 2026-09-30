<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\ContentBlockDraftRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\RichTextRepository;
use App\Repository\SpacerRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\ProductContentOwner;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * The lifecycle of a new content block on the database (Content Blocks
 * Lifecycle 1.0, App\Service\Blocks\ContentBlockDrafts): a draft is on no
 * page until it is placed, placing it is exact and idempotent, cancelling
 * removes it and nothing else, and the "Leeg blok" warning judges what a
 * block shows. The same flows over real HTTP are
 * Tests\Service\ContentBlockLifecycleHttpTest.
 */
final class ContentBlockLifecycleTest extends TestCase
{
    /** @var list<int> */
    private array $pageIds = [];

    private ?ShopStockFixture $shop = null;

    /** @var list<int> */
    private array $productIds = [];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->pageIds) as $pageId) {
            $page = (new PageRepository())->findById($pageId);
            if ($page !== null) {
                ContentBlockDrafts::discardForPage($pageId);
                $sections = new PageSectionRepository();
                foreach ($sections->findForPage($pageId) as $row) {
                    SectionRegistry::delete($row, $sections);
                }
                (new PageRepository())->delete($pageId);
            }
        }

        foreach ($this->productIds as $id) {
            ContentPages::deleteFor(ProductContentOwner::KIND, $id);
        }
        $this->shop?->cleanUp();

        PageContent::clearCache();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
    }

    // ------------------------------------------------------------ create and cancel

    public function testANewBlockIsOnNoPageUntilItIsPlaced(): void
    {
        $page = $this->page();
        [$existingId] = $this->placed($page, 'spacer');

        $draft = ContentBlockDrafts::open($page, 'rich_text');

        $this->assertSame(0, $draft['id'], 'a draft has no page_sections row');
        $this->assertNotNull((new RichTextRepository())->findBySlugAndKey((string) $page['content_key'], (string) $draft['section_key']), 'its editor has a row to open');
        $this->assertSame([$existingId], $this->sectionIds($page), 'the page did not change');
        $this->assertNotNull(ContentBlockDrafts::find('rich_text', (int) $draft['section_id']));
        $this->assertNull((new PageSectionRepository())->findBySectionTypeAndId('rich_text', (int) $draft['section_id']));
    }

    public function testCancellingRemovesTheDraftAndLeavesThePageAsItWas(): void
    {
        $page = $this->page();
        [$first] = $this->placed($page, 'spacer');
        [$second] = $this->placed($page, 'spacer');
        $before = (new PageSectionRepository())->findForPage((int) $page['id']);

        $draft = ContentBlockDrafts::open($page, 'rich_text');
        $record = ContentBlockDrafts::find('rich_text', (int) $draft['section_id']);
        $this->assertNotNull($record);
        ContentBlockDrafts::discard($record);

        $this->assertNull((new RichTextRepository())->findBySlugAndKey((string) $page['content_key'], (string) $draft['section_key']), 'its content row is gone');
        $this->assertNull(ContentBlockDrafts::find('rich_text', (int) $draft['section_id']));
        $this->assertSame(
            array_map(static fn (array $r): array => [$r['id'], $r['sort_order'], $r['is_active']], $before),
            array_map(static fn (array $r): array => [$r['id'], $r['sort_order'], $r['is_active']], (new PageSectionRepository())->findForPage((int) $page['id'])),
            'the other blocks, their order and visibility are untouched'
        );
        $this->assertSame([$first, $second], $this->sectionIds($page));
    }

    public function testAPlacedBlockCanNeverBeRemovedAsADraft(): void
    {
        $page = $this->page();
        $draft = ContentBlockDrafts::open($page, 'spacer');
        $record = ContentBlockDrafts::find('spacer', (int) $draft['section_id']);
        $placed = ContentBlockDrafts::place('spacer', (int) $draft['section_id']);
        $this->assertNotNull($placed);

        // A cancel that arrives after the save (another tab, a double click).
        ContentBlockDrafts::discard((array) $record);

        $this->assertNotNull((new PageSectionRepository())->findById((int) $placed['id']));
        $this->assertNotNull((new SpacerRepository())->findBySlugAndKey((string) $page['content_key'], (string) $draft['section_key']));
    }

    // ------------------------------------------------------------ save and place

    public function testPlacingPutsTheBlockAtTheBottomExactlyOnce(): void
    {
        $page = $this->page();
        [$first] = $this->placed($page, 'spacer');

        $draft = ContentBlockDrafts::open($page, 'rich_text');
        [$second] = $this->placed($page, 'spacer');

        $placed = ContentBlockDrafts::place('rich_text', (int) $draft['section_id']);
        $this->assertNotNull($placed);
        $this->assertSame([$first, $second, (int) $placed['id']], $this->sectionIds($page), 'at the bottom at the moment of the save');
        $this->assertSame(2, (int) $placed['sort_order']);
        $this->assertNull(ContentBlockDrafts::find('rich_text', (int) $draft['section_id']), 'no longer a draft');

        // A second save of the same form (double submit, refresh) finds it.
        $again = ContentBlockDrafts::place('rich_text', (int) $draft['section_id']);
        $this->assertSame((int) $placed['id'], (int) $again['id']);
        $this->assertCount(3, $this->sectionIds($page), 'one block, not two');
    }

    public function testASaveThatFailsPlacesNothing(): void
    {
        $page = $this->page();
        $draft = ContentBlockDrafts::open($page, 'rich_text');
        $db = Database::connection();

        $db->beginTransaction();
        ContentBlockDrafts::place('rich_text', (int) $draft['section_id']);
        $db->rollBack();

        $this->assertSame([], $this->sectionIds($page), 'rolled back with the save');
        $this->assertNotNull(ContentBlockDrafts::find('rich_text', (int) $draft['section_id']), 'still a draft, to be saved again');
    }

    public function testADraftOfACappedTypeIsRefusedOnceThePageHasOne(): void
    {
        $page = $this->page();
        $one = ContentBlockDrafts::open($page, 'contact_form');
        $two = ContentBlockDrafts::open($page, 'contact_form');

        $this->assertNotNull(ContentBlockDrafts::place('contact_form', (int) $one['section_id']));

        try {
            ContentBlockDrafts::place('contact_form', (int) $two['section_id']);
            $this->fail('a second Offerte-/contactformulier must not be placed');
        } catch (\DomainException) {
        }

        $this->assertCount(1, $this->sectionIds($page));
    }

    public function testARowOfUnknownOriginIsNeitherPlacedNorDiscarded(): void
    {
        $page = $this->page();
        [$sectionId, $sectionKey] = SectionRegistry::create('spacer', (string) $page['content_key']);

        $this->assertNull(ContentBlockDrafts::place('spacer', $sectionId), 'not a draft: never put on a page');
        $this->assertSame([], $this->sectionIds($page));
        $this->assertNotNull((new SpacerRepository())->findBySlugAndKey((string) $page['content_key'], $sectionKey));

        (new SpacerRepository())->deleteSection($sectionId);
    }

    public function testAStaleDraftIsPurgedAndAFreshOneKept(): void
    {
        $page = $this->page();
        $stale = ContentBlockDrafts::open($page, 'spacer');
        $fresh = ContentBlockDrafts::open($page, 'spacer');
        Database::connection()->prepare('UPDATE content_block_drafts SET created_at = NOW() - INTERVAL 3 DAY WHERE section_type = ? AND section_id = ?')
            ->execute(['spacer', (int) $stale['section_id']]);

        ContentBlockDrafts::purgeStale();

        $this->assertNull(ContentBlockDrafts::find('spacer', (int) $stale['section_id']));
        $this->assertNull((new SpacerRepository())->findBySlugAndKey((string) $page['content_key'], (string) $stale['section_key']));
        $this->assertNotNull(ContentBlockDrafts::find('spacer', (int) $fresh['section_id']));
    }

    public function testDeletingAPageTakesItsDraftsContentAlong(): void
    {
        $page = $this->page();
        $draft = ContentBlockDrafts::open($page, 'rich_text');

        PageService::delete($page);
        $this->pageIds = array_values(array_diff($this->pageIds, [(int) $page['id']]));

        $this->assertNull((new RichTextRepository())->findBySlugAndKey((string) $page['content_key'], (string) $draft['section_key']));
        $this->assertSame([], (new ContentBlockDraftRepository())->findForPage((int) $page['id']));
    }

    public function testACancelledFirstBlockTakesTheProductsContentPageAway(): void
    {
        $productId = $this->product('ZZ Levensloop product');
        $page = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        $draft = ContentBlockDrafts::open($page, 'rich_text');

        ContentBlockDrafts::discard((array) ContentBlockDrafts::find('rich_text', (int) $draft['section_id']));

        $this->assertNull(ContentPages::pageFor(ProductContentOwner::KIND, $productId), 'no blocks, no content page — as before the choice');

        // With a placed block the content page stays.
        $page = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        $kept = ContentBlockDrafts::open($page, 'spacer');
        ContentBlockDrafts::place('spacer', (int) $kept['section_id']);
        $other = ContentBlockDrafts::open($page, 'rich_text');
        ContentBlockDrafts::discard((array) ContentBlockDrafts::find('rich_text', (int) $other['section_id']));
        $this->assertNotNull(ContentPages::pageFor(ProductContentOwner::KIND, $productId));
    }

    // ------------------------------------------------------------ empty blocks

    public function testATextBlockWithoutABodyIsEmptyAndOneWithABodyIsNot(): void
    {
        $page = $this->page();
        [$id, $row] = $this->placed($page, 'rich_text');
        $this->assertTrue(SectionRegistry::isEmpty($row), 'a legacy empty block from the old flow');

        BlockLocalization::save('rich_text_sections', (int) $row['section_id'], BlockLocalization::defaultLanguage(), [RichTextContent::BODY => '<p>Hallo</p>']);
        RichTextContent::clearCache();
        $this->assertFalse(SectionRegistry::isEmpty((new PageSectionRepository())->findById($id)));

        BlockLocalization::save('rich_text_sections', (int) $row['section_id'], BlockLocalization::defaultLanguage(), [RichTextContent::BODY => '<p><br></p>']);
        RichTextContent::clearCache();
        $this->assertTrue(SectionRegistry::isEmpty((new PageSectionRepository())->findById($id)), 'an empty paragraph is no content');
    }

    public function testAnEmptyImageBlockIsEmpty(): void
    {
        [, $banner] = $this->placed($this->page(), 'media_banner');
        $this->assertTrue(SectionRegistry::isEmpty($banner), 'a Mediabanner without a picture or video');

        [, $split] = $this->placed($this->page(), 'text_image_split');
        $this->assertTrue(SectionRegistry::isEmpty($split), 'a Tekst met afbeelding without an item');
    }

    public function testDecorativeAndDynamicBlocksAreNeverJudged(): void
    {
        $page = $this->page();

        foreach (['spacer', 'contact_form'] as $type) {
            [, $row] = $this->placed($page, $type);
            $this->assertFalse(SectionRegistry::isEmpty($row), $type);
        }
    }

    public function testAGalleryWithASourceIsNotEmptyAndOneWithoutIsEmpty(): void
    {
        $page = $this->page();

        // The portfolio source, all projects: a dynamic block with a valid
        // source is content, whatever the portfolio holds today.
        [, $portfolio] = $this->placed($page, 'item_gallery', 'portfolio');
        $this->assertFalse(SectionRegistry::isEmpty($portfolio));

        // A collection gallery without its collection has nothing to show.
        [, $collection] = $this->placed($page, 'item_gallery', 'collection');
        $this->assertTrue(SectionRegistry::isEmpty($collection));
    }

    public function testAHiddenBlockIsNotCalledEmpty(): void
    {
        $page = $this->page();
        [$id] = $this->placed($page, 'rich_text');
        (new PageSectionRepository())->setActive($id, false);

        $this->assertFalse(SectionRegistry::isEmpty((new PageSectionRepository())->findById($id)));
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string, mixed> a fresh published page */
    private function page(?int $parentId = null): array
    {
        $key = 'zz-lifecycle-' . bin2hex(random_bytes(4));
        $id = PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED, 'parent_id' => $parentId], 'ZZ Levensloop');
        $this->pageIds[] = $id;

        return (array) (new PageRepository())->findById($id);
    }

    /**
     * A block placed the way a save places it.
     *
     * @param array<string, mixed> $page
     *
     * @return array{0: int, 1: array<string, mixed>} the page_sections id and row
     */
    private function placed(array $page, string $type, ?string $preset = null): array
    {
        $draft = ContentBlockDrafts::open($page, $type, $preset);
        $row = ContentBlockDrafts::place($type, (int) $draft['section_id']);
        $this->assertNotNull($row);

        return [(int) $row['id'], $row];
    }

    /** @return list<int> */
    private function sectionIds(array $page): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], (new PageSectionRepository())->findForPage((int) $page['id']));
    }

    private function product(string $name): int
    {
        $this->shop ??= new ShopStockFixture();
        $id = $this->shop->product($name);
        $this->productIds[] = $id;

        return $id;
    }
}
