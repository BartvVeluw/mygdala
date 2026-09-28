<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\PortfolioModule;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Projecten 2.0 in its editors (admin/project-cards.php, admin/item-gallery.php)
 * and their saves (api/admin/update-project-cards.php,
 * api/admin/update-item-gallery.php), over PHP's built-in server:
 *
 *   - the choice on screen: Bron (all, one category, picked by hand), the
 *     categories by name with their number of visible projects, the orders
 *     with "Standaard Portfolio-volgorde", the shared picker with a hidden
 *     project marked, and the maximum as 3, 4, 6, 8, 12 or all — plus a number
 *     stored before there was a list;
 *   - one save stores the choice and the picked projects in their order, a
 *     repeated or a deleted one left out; "one category" without a category
 *     and an unknown order are refused and store nothing;
 *   - a deleted category is said in the editor;
 *   - the gallery block offers the same choice, and keeps a stored one while
 *     it cannot offer it.
 */
final class ProjectCardsEditorHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> */
    private array $sectionIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
        $this->accounts = new AdminTestSession();
        \App\Module\ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true, 'portfolio' => true, 'multilingual' => true]);
    }

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
        $this->accounts->forget();
        \App\Module\ModuleRegistry::overrideForTests(null);
        PortfolioGalleryContent::clearCache();
        ItemGalleryContent::clearCache();
    }

    public function testTheEditorOffersTheWholeChoice(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        $this->item('ZZ Wolf ' . $m, [$wolves]);
        $this->item('ZZ Wolf verborgen ' . $m, [$wolves], false);
        [$pageKey, $sectionKey] = $this->block('project_cards', ['max_items' => 9]);

        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $html = (string) self::$server->request('GET', '/admin/project-cards.php?section=' . urlencode($pageKey . ':' . $sectionKey), $session)['body'];

        foreach (['all' => 'Alle projecten', 'category' => 'Eén categorie', 'manual' => 'Handmatige selectie'] as $value => $label) {
            self::assertStringContainsString('<option value="' . $value . '"', $html);
            self::assertStringContainsString('>' . $label . '</option>', $html);
        }
        self::assertStringContainsString('>ZZ Wolven ' . $m . ' (1)</option>', $html, 'a category by name, with its visible projects');
        foreach (['Standaard Portfolio-volgorde', 'Nieuwste eerst', 'Oudste eerst', 'Alfabetisch A–Z', 'Alfabetisch Z–A', 'Willekeurig'] as $order) {
            self::assertStringContainsString('>' . $order . '</option>', $html);
        }
        self::assertStringContainsString('data-item-picker', $html);
        self::assertMatchesRegularExpression('#ZZ Wolf verborgen ' . $m . '</span>[\s\S]*?</label>\s*<span class="admin-badge admin-badge--canceled"#', $html, 'a hidden project is marked');
        self::assertStringContainsString('<input type="hidden" name="items_submitted" value="1">', $html);

        self::assertMatchesRegularExpression('#<select id="projects-max" name="max_items" data-gallery-max>\s*<option value="">Alles</option>\s*<option value="3">3</option>\s*<option value="4">4</option>\s*<option value="6">6</option>\s*<option value="8">8</option>\s*<option value="9" selected>9</option>\s*<option value="12">12</option>#', $html, 'the list, plus the number stored before it');
        self::assertStringNotContainsString('Toon op homepage', $html);
    }

    public function testOneSaveStoresTheChoiceAndThePickedProjectsInTheirOrder(): void
    {
        $m = self::marker();
        $wolves = $this->category('ZZ Wolven ' . $m);
        $a = $this->item('ZZ A ' . $m, [$wolves]);
        $b = $this->item('ZZ B ' . $m, [$wolves]);
        $gone = $this->item('ZZ Weg ' . $m, []);
        (new PortfolioGalleryRepository())->deleteItem($gone);
        [$pageKey, $sectionKey, $galleryId] = $this->block('project_cards');

        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $response = $this->saveProjects($session, $csrf, $pageKey, $sectionKey, [
            'portfolio_scope' => 'category',
            'category_id' => (string) $wolves,
            'item_sort' => 'newest',
            'max_items' => '6',
            'item_ids' => [(string) $b, (string) $gone, (string) $a, (string) $b],
        ]);

        self::assertStringContainsString('saved=1', $response['location']);
        $row = (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $sectionKey);
        self::assertSame(['category', $wolves, 'newest', 6], [$row['portfolio_scope'], (int) $row['portfolio_category_id'], $row['item_sort'], (int) $row['max_items']]);
        self::assertSame([$b, $a], ItemGallerySources::selectedItems(PortfolioModule::GALLERY_SOURCE, $galleryId), 'kept while "one category" is on, in order, once, the deleted one gone');

        $manual = $this->saveProjects($session, $csrf, $pageKey, $sectionKey, [
            'portfolio_scope' => 'manual',
            'item_sort' => 'title_asc',
            'manual_random' => '1',
            'item_ids' => [(string) $a],
        ]);
        self::assertStringContainsString('saved=1', $manual['location']);
        $row = (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $sectionKey);
        self::assertSame(['manual', 'random', $wolves], [$row['portfolio_scope'], $row['item_sort'], (int) $row['portfolio_category_id']], 'a picked list is its own order or random; the category is kept for later');
        self::assertSame([$a], ItemGallerySources::selectedItems(PortfolioModule::GALLERY_SOURCE, $galleryId));
    }

    public function testACategoryWithoutACategoryAndAnUnknownOrderAreRefused(): void
    {
        [$pageKey, $sectionKey] = $this->block('project_cards');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $response = $this->saveProjects($session, $csrf, $pageKey, $sectionKey, ['portfolio_scope' => 'category', 'category_id' => '']);
        self::assertStringNotContainsString('saved=1', $response['location']);
        self::assertSame(['Kies een categorie, of kies een andere bron.'], $this->accounts->read($session, 'admin_project_cards_errors'));

        $response = $this->saveProjects($session, $csrf, $pageKey, $sectionKey, ['item_sort' => 'popular']);
        self::assertStringNotContainsString('saved=1', $response['location']);
        self::assertSame(['Kies een volgorde uit de lijst.'], $this->accounts->read($session, 'admin_project_cards_errors'));

        $row = (new ItemGalleryRepository())->findBySlugAndKey($pageKey, $sectionKey);
        self::assertSame(['all', 'source'], [$row['portfolio_scope'], $row['item_sort']], 'nothing stored');
    }

    public function testADeletedCategoryIsSaidInTheEditor(): void
    {
        $m = self::marker();
        $gone = $this->category('ZZ Weg ' . $m);
        [$pageKey, $sectionKey] = $this->block('project_cards', ['portfolio_scope' => 'category', 'portfolio_category_id' => $gone]);
        (new PortfolioCategoryRepository())->delete($gone);

        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $html = (string) self::$server->request('GET', '/admin/project-cards.php?section=' . urlencode($pageKey . ':' . $sectionKey), $session)['body'];

        self::assertStringContainsString('De gekozen categorie bestaat niet meer.', $html);
        self::assertStringContainsString('<option value="category" selected>', $html, 'the block still says "one category"');
    }

    public function testTheGalleryOffersTheSameChoiceAndKeepsItsPickedProjects(): void
    {
        $m = self::marker();
        $a = $this->item('ZZ A ' . $m, []);
        $b = $this->item('ZZ B ' . $m, []);
        [$pageKey, $sectionKey, $galleryId] = $this->block('item_gallery', ['portfolio_scope' => 'manual'], [$b, $a]);

        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $html = (string) self::$server->request('GET', '/admin/item-gallery.php?section=' . urlencode($pageKey . ':' . $sectionKey), $session)['body'];
        self::assertStringContainsString('data-gallery-selection', $html);
        self::assertMatchesRegularExpression('#value="' . $b . '" checked[\s\S]*value="' . $a . '" checked#', $html, 'the picked ones first, in their order');

        $response = self::$server->request('POST', '/api/admin/update-item-gallery.php', $session, [
            'csrf_token' => $csrf,
            'section' => $pageKey . ':' . $sectionKey,
            'language_code' => 'nl',
            'source_type' => PortfolioModule::GALLERY_SOURCE,
            'portfolio_scope' => 'manual',
            'item_sort' => 'source',
            'items_submitted' => '1',
            'item_ids' => [(string) $a, (string) $b],
            'background' => 'default',
            'is_active' => '1',
        ]);
        self::assertStringContainsString('saved=1', $response['location']);
        self::assertSame([$a, $b], ItemGallerySources::selectedItems(PortfolioModule::GALLERY_SOURCE, $galleryId));

        // A form that could not show the choice (no items_submitted) keeps it.
        self::$server->request('POST', '/api/admin/update-item-gallery.php', $session, [
            'csrf_token' => $csrf,
            'section' => $pageKey . ':' . $sectionKey,
            'language_code' => 'nl',
            'source_type' => PortfolioModule::GALLERY_SOURCE,
            'portfolio_scope' => 'manual',
            'background' => 'default',
            'is_active' => '1',
        ]);
        self::assertSame([$a, $b], ItemGallerySources::selectedItems(PortfolioModule::GALLERY_SOURCE, $galleryId));
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function saveProjects(string $session, string $csrf, string $pageKey, string $sectionKey, array $fields): array
    {
        return self::$server->request('POST', '/api/admin/update-project-cards.php', $session, $fields + [
            'csrf_token' => $csrf,
            'section' => $pageKey . ':' . $sectionKey,
            'language_code' => 'nl',
            'portfolio_scope' => 'all',
            'item_sort' => 'source',
            'items_submitted' => '1',
            'background' => 'default',
            'is_active' => '1',
        ]);
    }

    /**
     * A block of $type on a page of its own.
     *
     * @param array<string, mixed> $settings
     * @param list<int>|null       $picked
     *
     * @return array{0: string, 1: string, 2: int} page key, section key, item_galleries id
     */
    private function block(string $type, array $settings = [], ?array $picked = null): array
    {
        $pageKey = 'zz-projecten-editor-' . self::marker();
        $pageId = \Tests\Support\PageFixture::create(['content_key' => $pageKey, 'slug' => $pageKey, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Editor');
        $this->pageIds[] = $pageId;

        [$galleryId, $sectionKey] = SectionRegistry::create($type, $pageKey);
        $this->sectionIds[] = (new PageSectionRepository())->create($pageId, $pageKey, $type, $sectionKey, $galleryId);

        $repository = new ItemGalleryRepository();
        $current = (array) $repository->findBySlugAndKey($pageKey, (string) $sectionKey);
        $repository->upsertSection($pageKey, (string) $sectionKey, $type === 'project_cards'
            ? ProjectCardsBlock::rowValues($settings)
            : $settings + [
                'source_type' => PortfolioModule::GALLERY_SOURCE,
                'portfolio_scope' => (string) $current['portfolio_scope'],
                'background' => 'default',
                'is_active' => true,
            ]);
        if ($picked !== null) {
            ItemGallerySources::saveSelection(PortfolioModule::GALLERY_SOURCE, (int) $galleryId, $picked);
        }

        return [$pageKey, (string) $sectionKey, (int) $galleryId];
    }

    /** @param list<int> $categories */
    private function item(string $title, array $categories, bool $visible = true): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-projecten-editor-' . self::marker() . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => $title]);
        $repository->setItemCategories($id, $categories);
        if (!$visible) {
            $repository->updateItem($id, ['media_id' => null, 'image_path' => 'assets/images/sections/zz-hidden.jpg', 'thumbnail_path' => null, 'is_active' => false]);
        }

        return $id;
    }

    private function category(string $name): int
    {
        $id = (new PortfolioCategoryRepository())->create('zz-' . self::marker());
        PortfolioLocalization::saveCategory($id, PortfolioLocalization::defaultLanguage(), $name);
        $this->categoryIds[] = $id;

        return $id;
    }

    private static function marker(): string
    {
        return bin2hex(random_bytes(4));
    }
}
