<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\RichTextRepository;
use App\Service\Blocks\BlockAppearance;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
use App\Service\PageAssets;
use App\Service\PageContent;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\ProductContentOwner;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Extra vormgeving on the database (Contentblock Styling 1.0,
 * CONTENT-BLOCKS.md "Extra vormgeving"): the look is stored on the block
 * instance's own page_sections row, so
 *
 *   - a new row starts at the defaults, and a page of unstyled blocks renders
 *     exactly as before, loading no new stylesheet;
 *   - two blocks of one type on one page each keep their own look, and only
 *     the styled block changes on the page;
 *   - back to Standaard is the old page, byte for byte;
 *   - a draft has no row and so no look; cancelling it leaves nothing behind;
 *     reordering, hiding and deleting take the look along with the row;
 *   - several effects on one page share one stylesheet and no script;
 *   - the same holds on the content page of a product and of a project.
 */
final class BlockAppearanceTest extends TestCase
{
    /** @var list<int> */
    private array $pageIds = [];

    private ?ShopStockFixture $shop = null;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
        PageAssets::reset();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->pageIds) as $pageId) {
            if ((new PageRepository())->findById($pageId) !== null) {
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

        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            ContentPages::deleteFor(PortfolioContentOwner::KIND, $id);
            $gallery->deleteItem($id);
        }

        PageAssets::reset();
        PageContent::clearCache();
        RichTextContent::clearCache();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
    }

    public function testANewBlockStartsAtTheDefaultsAndRendersAsBefore(): void
    {
        $page = $this->page();
        $first = $this->text($page, 'Eerste tekst');
        $this->text($page, 'Tweede tekst');

        $row = (new PageSectionRepository())->findById($first);
        $this->assertSame(BlockAppearance::defaults(), BlockAppearance::fromRow((array) $row), '1. the columns start at the defaults');

        $html = $this->renderPage($page);
        $this->assertStringContainsString('Eerste tekst', $html);
        $this->assertStringNotContainsString('block-appearance', $html);
        $this->assertStringNotContainsString('block-decor', $html);

        SectionRegistry::collectPageAssets((string) $page['content_key']);
        $styles = PageAssets::collected()['styles'];
        $this->assertNotContains(BlockAppearance::STYLESHEET, $styles, '24. nothing styled, nothing loaded');
        $this->assertNotContains(BlockAppearance::DECORATION_STYLESHEET, $styles);
    }

    public function testEachInstanceKeepsItsOwnLookAndOnlyThatBlockChanges(): void
    {
        $page = $this->page();
        $a = $this->text($page, 'Blok A');
        $b = $this->text($page, 'Blok B');
        $c = $this->text($page, 'Blok C');
        $before = $this->renderPage($page);

        $repository = new PageSectionRepository();
        $repository->updateAppearance($b, ['background' => 'subtle'] + BlockAppearance::defaults());
        $repository->updateAppearance($c, ['decoration' => 'sparks', 'border' => 'top', 'border_tone' => 'accent', 'spacing' => 'compact'] + BlockAppearance::defaults());

        // 2.–5. and 8.: stored per row.
        $this->assertSame(BlockAppearance::defaults(), BlockAppearance::fromRow((array) $repository->findById($a)));
        $this->assertSame('subtle', BlockAppearance::fromRow((array) $repository->findById($b))['background']);
        $this->assertSame(
            ['background' => 'default', 'border' => 'top', 'border_tone' => 'accent', 'spacing' => 'compact', 'decoration' => 'sparks'],
            BlockAppearance::fromRow((array) $repository->findById($c))
        );

        $after = $this->renderPage($page);
        $blocks = $this->blocks($after);
        $this->assertCount(3, $blocks);
        $this->assertSame($this->blocks($before)[0], $blocks[0], 'block A is untouched');
        $this->assertStringContainsString('class="rich-text-section block-appearance block-appearance--bg-subtle"', $blocks[1]);
        $this->assertStringNotContainsString('block-decor', $blocks[1]);
        $this->assertStringContainsString('class="rich-text-section block-appearance block-appearance--border-top block-appearance--line-accent block-appearance--space-compact block-appearance--decor-sparks"', $blocks[2]);
        $this->assertStringContainsString('<div class="block-decor block-decor--sparks" aria-hidden="true">', $blocks[2]);

        // Back to Standaard: the page as it was.
        $repository->updateAppearance($b, BlockAppearance::defaults());
        $repository->updateAppearance($c, BlockAppearance::defaults());
        $this->assertSame($before, $this->renderPage($page), '9. Standaard is the legacy output, byte for byte');
    }

    public function testSeveralEffectsOnOnePageShareOneStylesheetAndNoScript(): void
    {
        $page = $this->page();
        $repository = new PageSectionRepository();
        $repository->updateAppearance($this->text($page, 'Een'), ['decoration' => 'sparks'] + BlockAppearance::defaults());
        $repository->updateAppearance($this->text($page, 'Twee'), ['decoration' => 'sparks'] + BlockAppearance::defaults());
        $repository->updateAppearance($this->text($page, 'Drie'), ['decoration' => 'glow'] + BlockAppearance::defaults());

        $scriptsBefore = PageAssets::collected()['scripts'];
        SectionRegistry::collectPageAssets((string) $page['content_key']);
        $assets = PageAssets::collected();

        $this->assertSame(1, count(array_keys($assets['styles'], BlockAppearance::STYLESHEET, true)));
        $this->assertSame(1, count(array_keys($assets['styles'], BlockAppearance::DECORATION_STYLESHEET, true)), '25. one stylesheet for three effects');
        $this->assertSame($scriptsBefore, $assets['scripts'], 'no animation script at all');
        $this->assertSame([], $assets['vendor']);

        $html = $this->renderPage($page);
        $this->assertSame(2, substr_count($html, 'block-decor--sparks'));
        $this->assertSame(1, substr_count($html, 'block-decor--glow'));
        $this->assertSame(3, substr_count($html, 'aria-hidden="true"><'), 'every layer hidden from assistive technology');
    }

    public function testAStoredLookTheBlockCannotCarryIsNotDrawn(): void
    {
        $page = $this->page();
        [$sectionId, $sectionKey] = SectionRegistry::create('spacer', (string) $page['content_key']);
        $id = (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'spacer', $sectionKey, $sectionId);
        $before = $this->renderPage($page);

        // Written behind the CMS's back: a spacer supports nothing.
        (new PageSectionRepository())->updateAppearance($id, ['background' => 'primary', 'decoration' => 'sparks'] + BlockAppearance::defaults());

        $this->assertSame($before, $this->renderPage($page));
    }

    public function testAHiddenBlockWithALookStaysInvisible(): void
    {
        $page = $this->page();
        $id = $this->text($page, 'Verborgen tekst');
        $row = (new PageSectionRepository())->findById($id);
        (new PageSectionRepository())->updateAppearance($id, ['background' => 'primary', 'border' => 'both', 'decoration' => 'sparks'] + BlockAppearance::defaults());

        // Hidden in its own editor: the partial renders nothing, and so does the look.
        $content = (new RichTextRepository())->findById((int) $row['section_id']);
        $this->assertNotNull($content);
        \App\Database::connection()->prepare('UPDATE rich_text_sections SET is_active = 0 WHERE id = :id')->execute(['id' => (int) $row['section_id']]);
        RichTextContent::clearCache();

        $html = $this->renderPage($page);
        $this->assertStringNotContainsString('block-appearance', $html);
        $this->assertStringNotContainsString('Verborgen tekst', $html);
    }

    public function testADraftHasNoLookAndCancellingLeavesNothingBehind(): void
    {
        $page = $this->page();
        $existing = $this->text($page, 'Bestaand');
        (new PageSectionRepository())->updateAppearance($existing, ['background' => 'secondary'] + BlockAppearance::defaults());

        $draft = ContentBlockDrafts::open($page, 'rich_text');
        $this->assertSame(0, $draft['id'], '31. a draft has no page_sections row, so nowhere to store a look');
        $this->assertSame(BlockAppearance::defaults(), BlockAppearance::fromRow($draft));

        $record = ContentBlockDrafts::find('rich_text', (int) $draft['section_id']);
        $this->assertNotNull($record);
        ContentBlockDrafts::discard($record);

        $rows = (new PageSectionRepository())->findForPage((int) $page['id']);
        $this->assertCount(1, $rows);
        $this->assertSame('secondary', $rows[0]['appearance_background'], 'the existing block keeps its look');

        // Placed by its first save: it starts at the defaults.
        $next = ContentBlockDrafts::open($page, 'rich_text');
        $placed = ContentBlockDrafts::place('rich_text', (int) $next['section_id']);
        $this->assertNotNull($placed);
        $this->assertSame(BlockAppearance::defaults(), BlockAppearance::fromRow((array) (new PageSectionRepository())->findById((int) $placed['id'])));
    }

    public function testReorderingHidingAndDeletingTakeTheLookAlong(): void
    {
        $page = $this->page();
        $a = $this->text($page, 'A');
        $b = $this->text($page, 'B');
        $repository = new PageSectionRepository();
        $repository->updateAppearance($b, ['background' => 'page'] + BlockAppearance::defaults());

        $repository->reorder((int) $page['id'], [$b, $a]);
        $this->assertSame([$b, $a], array_map(static fn (array $r): int => (int) $r['id'], $repository->findForPage((int) $page['id'])));
        $this->assertSame('page', $repository->findById($b)['appearance_background'], 'the look moves with its block');

        $repository->setActive($b, false);
        $repository->setActive($b, true);
        $this->assertSame('page', $repository->findById($b)['appearance_background'], 'hiding keeps it');

        SectionRegistry::delete((array) $repository->findById($b), $repository);
        $this->assertNull($repository->findById($b), 'deleting the block deletes its look with its row');
        $this->assertSame('default', $repository->findById($a)['appearance_background']);
    }

    public function testTheContentPagesOfAProductAndAProjectCarryALook(): void
    {
        // 32. A Product Content Page.
        $this->shop ??= new ShopStockFixture();
        $productId = $this->shop->product('ZZ Vormgeving product');
        $this->productIds[] = $productId;
        $productPage = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        $productBlock = $this->text($productPage, 'Productverhaal', false);
        (new PageSectionRepository())->updateAppearance($productBlock, ['background' => 'subtle', 'decoration' => 'glow'] + BlockAppearance::defaults());

        $html = $this->renderPage($productPage);
        $this->assertStringContainsString('class="rich-text-section block-appearance block-appearance--bg-subtle block-appearance--decor-glow"', $html);
        $this->assertStringContainsString('Productverhaal', $html);

        // 33. A Portfolio Content Page.
        $repository = new PortfolioGalleryRepository();
        $itemId = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-appearance-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $itemId;
        PortfolioLocalization::saveItem($itemId, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => 'ZZ Vormgeving project', PortfolioLocalization::ALT => 'ZZ']);
        PortfolioGalleryContent::clearCache();
        $projectPage = ContentPages::ensure(PortfolioContentOwner::KIND, $itemId);
        $projectBlock = $this->text($projectPage, 'Projectverhaal', false);
        (new PageSectionRepository())->updateAppearance($projectBlock, ['border' => 'both', 'border_tone' => 'normal'] + BlockAppearance::defaults());

        $html = $this->renderPage($projectPage);
        $this->assertStringContainsString('class="rich-text-section block-appearance block-appearance--border-both block-appearance--line-normal"', $html);
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string, mixed> a fresh published page */
    private function page(): array
    {
        $key = 'zz-appearance-' . bin2hex(random_bytes(4));
        $id = PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Vormgeving');
        $this->pageIds[] = $id;

        return (array) (new PageRepository())->findById($id);
    }

    /**
     * A Tekstblok with words, placed at the bottom of $page.
     *
     * @param array<string, mixed> $page
     */
    private function text(array $page, string $words, bool $track = true): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('rich_text', (string) $page['content_key']);
        BlockLocalization::save('rich_text_sections', $sectionId, BlockLocalization::defaultLanguage(), [RichTextContent::BODY => '<p>' . $words . '</p>']);
        RichTextContent::clearCache();

        return (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'rich_text', $sectionKey, $sectionId);
    }

    /** @param array<string, mixed> $page */
    private function renderPage(array $page): string
    {
        PageContent::clearCache();
        RichTextContent::clearCache();
        ob_start();
        try {
            SectionRegistry::renderPage((string) $page['content_key']);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * The page's blocks, one string each (every Tekstblok is one <section>).
     *
     * @return list<string>
     */
    private function blocks(string $html): array
    {
        preg_match_all('~<section\b.*?</section>~s', $html, $matches);

        return $matches[0];
    }
}
