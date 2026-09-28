<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Module\ModuleRegistry;
use App\Module\PortfolioModule;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\RandomOrder;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * PROJECTEN 2.0 on the test database (CONTENT-BLOCKS.md, "Projecten 2.0"):
 * which projects a Projecten block shows, placed on a page of its own the way
 * the page builder places one and read the way the site reads it
 * (App\Service\ItemGalleryContent::forSection()).
 *
 *   All       every visible project, the maximum, the orders, random;
 *   Category  only that category, another one never, the maximum, random,
 *             and a deleted category showing nothing while the rest stays;
 *   Manual    only the picked ones, in their order, a hidden one skipped, a
 *             deleted one gone, never one twice, random only when asked;
 *   several blocks on one page, each with its own choice;
 *   the Portfolio switched off: nothing shown, the choice kept, and back.
 *
 * Random draws come from a seeded engine (App\Service\RandomOrder), so what is
 * asserted is the pool, the count, no repeats and a draw per render — never
 * that the next draw differs. Everything made here is marked zz- and removed
 * again in tearDown(). The catalogue may hold other projects; a category of
 * this test's own keeps an order test to its own projects.
 */
final class ProjectCardsSelectionTest extends TestCase
{
    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> page_sections ids */
    private array $sectionIds = [];

    protected function setUp(): void
    {
        self::withPortfolio(true);
    }

    protected function tearDown(): void
    {
        self::withPortfolio(true);
        RandomOrder::useEngineForTests(null);

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

        $categories = new PortfolioCategoryRepository();
        foreach ($this->categoryIds as $id) {
            if ($categories->findById($id) !== null) {
                $categories->delete($id);
            }
        }

        $pages = new PageRepository();
        foreach ($this->pageIds as $id) {
            if ($pages->findById($id) !== null) {
                $pages->delete($id);
            }
        }

        $this->itemIds = $this->categoryIds = $this->pageIds = $this->sectionIds = [];
        ModuleRegistry::overrideForTests(null);
        self::clearCaches();
    }

    /* ------------------------------------------------------------------ */
    /* All                                                                 */
    /* ------------------------------------------------------------------ */

    public function testAllShowsEveryVisibleProjectAndNoHiddenOne(): void
    {
        $m = self::marker();
        $this->item('ZZ Zichtbaar A ' . $m);
        $this->item('ZZ Zichtbaar B ' . $m);
        $this->item('ZZ Verborgen ' . $m, [], false);

        $titles = $this->titles($this->block());

        self::assertContains('ZZ Zichtbaar A ' . $m, $titles);
        self::assertContains('ZZ Zichtbaar B ' . $m, $titles);
        self::assertNotContains('ZZ Verborgen ' . $m, $titles);
        self::assertSame(count(PortfolioGalleryContent::visibleRows()), count($titles), 'every visible project, nothing else');
    }

    public function testAllInTheDefaultPortfolioOrderAndNoMoreThanTheMaximum(): void
    {
        $m = self::marker();
        $this->item('ZZ Eerst ' . $m);
        $this->item('ZZ Daarna ' . $m);

        $titles = $this->titles($this->block());
        self::assertLessThan(
            array_search('ZZ Daarna ' . $m, $titles, true),
            array_search('ZZ Eerst ' . $m, $titles, true),
            'the Portfolio\'s own order: a later project comes later'
        );

        self::assertCount(2, $this->titles($this->block(['max_items' => 2])));
    }

    public function testEveryOrderForAllAndForACategory(): void
    {
        [$category, $titles] = $this->threeInACategory();
        $orders = [
            'newest' => [$titles[2], $titles[1], $titles[0]],
            'oldest' => [$titles[0], $titles[1], $titles[2]],
            'title_asc' => ['ZZ Beer ' . $titles['m'], 'ZZ Hert ' . $titles['m'], 'ZZ Wolf ' . $titles['m']],
            'title_desc' => ['ZZ Wolf ' . $titles['m'], 'ZZ Hert ' . $titles['m'], 'ZZ Beer ' . $titles['m']],
            'source' => [$titles[0], $titles[1], $titles[2]],
        ];

        foreach ($orders as $sort => $expected) {
            self::assertSame($expected, $this->titles($this->block([
                'portfolio_scope' => ItemGalleryContent::SCOPE_CATEGORY,
                'portfolio_category_id' => $category,
                'item_sort' => $sort,
            ])), 'category, ' . $sort);

            // "All" is the whole catalogue: this test's own projects keep the same order among the others.
            $mine = array_values(array_filter(
                $this->titles($this->block(['item_sort' => $sort])),
                static fn (string $title): bool => str_ends_with($title, $titles['m'])
            ));
            self::assertSame($expected, $mine, 'all, ' . $sort);
        }
    }

    public function testAllAtRandomDrawsFromTheVisibleProjectsOncePerRender(): void
    {
        $m = self::marker();
        $this->item('ZZ Willekeurig ' . $m);
        $this->item('ZZ Verborgen ' . $m, [], false);
        $visible = array_map(
            static fn (array $card): string => $card['title'],
            PortfolioGalleryContent::cards(array_values(PortfolioGalleryContent::visibleRows()))
        );

        [$pageKey, $sectionKey] = $this->placed(['item_sort' => 'random', 'max_items' => 2]);

        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(5));
        foreach ([1, 2, 3] as $render) {
            self::clearCaches();
            $titles = $this->titles(ItemGalleryContent::forSection($pageKey, $sectionKey));

            self::assertCount(min(2, count($visible)), $titles);
            self::assertSame([], array_diff($titles, $visible), 'only visible projects');
            self::assertNotContains('ZZ Verborgen ' . $m, $titles);
            self::assertSame($render, RandomOrder::calls(), 'a new draw for every render');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Category                                                            */
    /* ------------------------------------------------------------------ */

    public function testACategoryShowsOnlyItsOwnVisibleProjects(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        $dnd = $this->category('ZZ D&D ' . $m);
        $this->item('ZZ Wolf 1 ' . $m, [$wolves]);
        $this->item('ZZ Wolf 2 ' . $m, [$wolves, $dnd]);
        $this->item('ZZ Draak ' . $m, [$dnd]);
        $this->item('ZZ Wolf verborgen ' . $m, [$wolves], false);

        self::assertSame(
            ['ZZ Wolf 1 ' . $m, 'ZZ Wolf 2 ' . $m],
            $this->titles($this->block(['portfolio_scope' => ItemGalleryContent::SCOPE_CATEGORY, 'portfolio_category_id' => $wolves]))
        );
        self::assertCount(1, $this->titles($this->block([
            'portfolio_scope' => ItemGalleryContent::SCOPE_CATEGORY,
            'portfolio_category_id' => $wolves,
            'max_items' => 1,
        ])), 'the maximum');
    }

    public function testACategoryAtRandomDrawsOnlyFromThatCategory(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        $other = $this->category('ZZ Onderzetters ' . $m);
        $mine = [];
        foreach (range(1, 5) as $n) {
            $this->item($mine[] = 'ZZ Wolf ' . $n . ' ' . $m, [$wolves]);
        }
        $this->item('ZZ Onderzetter ' . $m, [$other]);

        [$pageKey, $sectionKey] = $this->placed([
            'portfolio_scope' => ItemGalleryContent::SCOPE_CATEGORY,
            'portfolio_category_id' => $wolves,
            'item_sort' => 'random',
            'max_items' => 3,
        ]);

        for ($seed = 1; $seed <= 12; $seed++) {
            RandomOrder::useEngineForTests(new \Random\Engine\Mt19937($seed));
            self::clearCaches();
            $titles = $this->titles(ItemGalleryContent::forSection($pageKey, $sectionKey));

            self::assertCount(3, $titles);
            self::assertSame([], array_diff($titles, $mine), 'only the Wolven');
            self::assertSame(array_values(array_unique($titles)), $titles);
        }
    }

    public function testADeletedCategoryShowsNothingAndTheBlockKeepsTheRest(): void
    {
        $m = self::marker();
        $gone = $this->category('ZZ Weg ' . $m);

        [$pageKey, $sectionKey, $blockId] = $this->placed([
            'portfolio_scope' => ItemGalleryContent::SCOPE_CATEGORY,
            'portfolio_category_id' => $gone,
            'item_sort' => 'newest',
            'max_items' => 4,
            'title' => 'ZZ Kop ' . $m,
        ]);

        (new PortfolioCategoryRepository())->delete($gone);
        self::clearCaches();

        $row = (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $sectionKey);
        self::assertNull($row['portfolio_category_id'], 'the foreign key lets go of it');
        self::assertSame(['category', 'newest', 4], [$row['portfolio_scope'], $row['item_sort'], (int) $row['max_items']], 'the rest of the block stays');

        self::assertSame([], ItemGalleryContent::forSection($pageKey, $sectionKey)['items']);
        self::assertSame('', $this->render($blockId), 'no items: no section, no heading');
    }

    /* ------------------------------------------------------------------ */
    /* Manual                                                              */
    /* ------------------------------------------------------------------ */

    public function testManualShowsOnlyThePickedInTheirOrder(): void
    {
        $m = self::marker();
        $a = $this->item('ZZ A ' . $m);
        $this->item('ZZ B ' . $m);
        $c = $this->item('ZZ C ' . $m);

        [$pageKey, $sectionKey] = $this->placed(['portfolio_scope' => ItemGalleryContent::SCOPE_MANUAL], [$c, $a]);

        self::assertSame(['ZZ C ' . $m, 'ZZ A ' . $m], $this->titles(ItemGalleryContent::forSection($pageKey, $sectionKey)));

        self::clearCaches();
        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(9));
        self::assertSame(['ZZ C ' . $m, 'ZZ A ' . $m], $this->titles(ItemGalleryContent::forSection($pageKey, $sectionKey)), 'a refresh: the same order');
        self::assertSame(0, RandomOrder::calls(), 'a fixed order draws nothing');
    }

    public function testManualSkipsAHiddenProjectAndForgetsADeletedOne(): void
    {
        $m = self::marker();
        $a = $this->item('ZZ A ' . $m);
        $hidden = $this->item('ZZ Verborgen ' . $m, [], false);
        $deleted = $this->item('ZZ Weg ' . $m);

        [$pageKey, $sectionKey, , $galleryId] = $this->placed(['portfolio_scope' => ItemGalleryContent::SCOPE_MANUAL], [$hidden, $deleted, $a]);

        (new PortfolioGalleryRepository())->deleteItem($deleted);
        self::clearCaches();

        self::assertSame(['ZZ A ' . $m], $this->titles(ItemGalleryContent::forSection($pageKey, $sectionKey)));
        self::assertSame([$hidden, $a], ItemGallerySources::selectedItems(PortfolioModule::GALLERY_SOURCE, $galleryId), 'the hidden one waits, the deleted one is gone');
    }

    public function testManualNeverPicksAProjectTwice(): void
    {
        $m = self::marker();
        $a = $this->item('ZZ A ' . $m);
        $b = $this->item('ZZ B ' . $m);

        [$pageKey, $sectionKey, , $galleryId] = $this->placed(['portfolio_scope' => ItemGalleryContent::SCOPE_MANUAL], [$a, $b, $a, $b]);

        self::assertSame([$a, $b], ItemGallerySources::selectedItems(PortfolioModule::GALLERY_SOURCE, $galleryId));
        self::assertSame(['ZZ A ' . $m, 'ZZ B ' . $m], $this->titles(ItemGalleryContent::forSection($pageKey, $sectionKey)));

        $this->expectException(\PDOException::class);
        Database::connection()->prepare('INSERT INTO item_gallery_portfolio_items (item_gallery_id, portfolio_item_id, sort_order) VALUES (?, ?, 9)')
            ->execute([$galleryId, $a]);
    }

    public function testManualAtRandomShufflesOnlyThePicked(): void
    {
        $m = self::marker();
        $picked = [$this->item('ZZ A ' . $m), $this->item('ZZ B ' . $m), $this->item('ZZ C ' . $m)];
        $this->item('ZZ Niet gekozen ' . $m);

        [$pageKey, $sectionKey] = $this->placed(['portfolio_scope' => ItemGalleryContent::SCOPE_MANUAL, 'item_sort' => 'random', 'max_items' => 2], $picked);

        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(21));
        $titles = $this->titles(ItemGalleryContent::forSection($pageKey, $sectionKey));

        self::assertCount(2, $titles);
        self::assertSame([], array_diff($titles, ['ZZ A ' . $m, 'ZZ B ' . $m, 'ZZ C ' . $m]));
        self::assertSame(1, RandomOrder::calls());
    }

    /* ------------------------------------------------------------------ */
    /* Several on one page, the module off                                 */
    /* ------------------------------------------------------------------ */

    public function testTwoBlocksOnOnePageEachKeepTheirOwnCategory(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        $dnd = $this->category('ZZ D&D ' . $m);
        $this->item('ZZ Wolf ' . $m, [$wolves]);
        $this->item('ZZ Draak ' . $m, [$dnd]);

        $pageKey = $this->page();
        [$first] = $this->placedOn($pageKey, ['portfolio_scope' => ItemGalleryContent::SCOPE_CATEGORY, 'portfolio_category_id' => $wolves]);
        [$second] = $this->placedOn($pageKey, ['portfolio_scope' => ItemGalleryContent::SCOPE_CATEGORY, 'portfolio_category_id' => $dnd, 'item_sort' => 'random']);

        self::assertSame(['ZZ Wolf ' . $m], $this->titles(ItemGalleryContent::forSection($pageKey, $first)));
        self::assertSame(['ZZ Draak ' . $m], $this->titles(ItemGalleryContent::forSection($pageKey, $second)));

        $rows = [(new ItemGalleryRepository())->findBySlugAndKey($pageKey, $first), (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $second)];
        self::assertSame([$wolves, $dnd], [(int) $rows[0]['portfolio_category_id'], (int) $rows[1]['portfolio_category_id']]);
        self::assertSame(['source', 'random'], [$rows[0]['item_sort'], $rows[1]['item_sort']], 'each its own order');
    }

    public function testTheModuleOffShowsNothingAndKeepsTheChoiceForWhenItIsBack(): void
    {
        $m = self::marker();
        $a = $this->item('ZZ A ' . $m);

        [$pageKey, $sectionKey, $blockId, $galleryId] = $this->placed(['portfolio_scope' => ItemGalleryContent::SCOPE_MANUAL, 'title' => 'ZZ Kop ' . $m], [$a]);

        self::withPortfolio(false);
        self::clearCaches();
        self::assertSame([], ItemGalleryContent::forSection($pageKey, $sectionKey)['items']);
        self::assertSame('', $this->render($blockId), 'no section, no heading, no category');

        self::withPortfolio(true);
        self::clearCaches();
        self::assertSame(['ZZ A ' . $m], $this->titles(ItemGalleryContent::forSection($pageKey, $sectionKey)));
        self::assertSame([$a], ItemGallerySources::selectedItems(PortfolioModule::GALLERY_SOURCE, $galleryId));
        self::assertStringContainsString('ZZ Kop ' . $m, $this->render($blockId));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Three projects in a category of their own, added one after the other:
     * titles [0..2] in the order they were added, and the marker.
     *
     * @return array{0: int, 1: array<int|string, string>}
     */
    private function threeInACategory(): array
    {
        $m = self::marker();
        $category = $this->category('ZZ Dieren ' . $m);
        $titles = ['ZZ Hert ' . $m, 'ZZ Wolf ' . $m, 'ZZ Beer ' . $m, 'm' => $m];
        foreach ([0, 1, 2] as $n) {
            $id = $this->item($titles[$n], [$category]);
            Database::connection()->prepare('UPDATE portfolio_gallery_items SET created_at = ? WHERE id = ?')
                ->execute([sprintf('2026-01-0%d 10:00:00', $n + 1), $id]);
        }

        return [$category, $titles];
    }

    /**
     * A Projecten block on a page of its own, read back.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed> ItemGalleryContent::forSection()
     */
    private function block(array $settings = []): array
    {
        [$pageKey, $sectionKey] = $this->placed($settings);

        return ItemGalleryContent::forSection($pageKey, $sectionKey);
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<int>|null       $picked
     *
     * @return array{0: string, 1: string, 2: int, 3: int} page key, section key, page_sections id, item_galleries id
     */
    private function placed(array $settings = [], ?array $picked = null): array
    {
        $pageKey = $this->page();
        [$sectionKey, $blockId, $galleryId] = $this->placedOn($pageKey, $settings, $picked);

        return [$pageKey, $sectionKey, $blockId, $galleryId];
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<int>|null       $picked
     *
     * @return array{0: string, 1: int, 2: int} section key, page_sections id, item_galleries id
     */
    private function placedOn(string $pageKey, array $settings, ?array $picked = null): array
    {
        $pageId = (int) (new PageRepository())->findByContentKey($pageKey)['id'];
        [$galleryId, $sectionKey] = SectionRegistry::create('project_cards', $pageKey);
        $this->sectionIds[] = $blockId = (new PageSectionRepository())->create($pageId, $pageKey, 'project_cards', $sectionKey, $galleryId);

        (new ItemGalleryRepository())->upsertSection($pageKey, (string) $sectionKey, ProjectCardsBlock::rowValues($settings));
        if (isset($settings['title'])) {
            BlockLocalization::save('item_galleries', (int) $galleryId, 'nl', ProjectCardsBlock::rowWords(['title' => $settings['title']]));
        }
        if ($picked !== null) {
            ItemGallerySources::saveSelection(PortfolioModule::GALLERY_SOURCE, (int) $galleryId, $picked);
        }

        self::clearCaches();

        return [(string) $sectionKey, (int) $blockId, (int) $galleryId];
    }

    private function page(): string
    {
        $key = 'zz-projecten-' . self::marker();
        $this->pageIds[] = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => PageContent::STATUS_PUBLISHED,
        ], 'ZZ Projecten');

        return $key;
    }

    /** @param list<int> $categories */
    private function item(string $title, array $categories = [], bool $visible = true): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-selection-' . self::marker() . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;

        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => $title]);
        $repository->setItemCategories($id, $categories);
        if (!$visible) {
            $repository->updateItem($id, ['media_id' => null, 'image_path' => 'assets/images/sections/zz-hidden.jpg', 'thumbnail_path' => null, 'is_active' => false]);
        }

        self::clearCaches();

        return $id;
    }

    private function category(string $name): int
    {
        $repository = new PortfolioCategoryRepository();
        $id = $repository->create('zz-' . self::marker());
        PortfolioLocalization::saveCategory($id, PortfolioLocalization::defaultLanguage(), $name);
        $this->categoryIds[] = $id;

        return $id;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<string>
     */
    private function titles(array $content): array
    {
        return array_map(static fn (array $card): string => (string) $card['title'], $content['items']);
    }

    private function render(int $pageSectionId): string
    {
        self::clearCaches();
        $row = (new PageSectionRepository())->findById($pageSectionId);
        self::assertNotNull($row);

        ob_start();
        SectionRegistry::render($row);

        return trim((string) ob_get_clean());
    }

    private static function marker(): string
    {
        return bin2hex(random_bytes(4));
    }

    private static function clearCaches(): void
    {
        PortfolioGalleryContent::clearCache();
        ItemGalleryContent::clearCache();
        PageContent::clearCache();
    }

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
}
