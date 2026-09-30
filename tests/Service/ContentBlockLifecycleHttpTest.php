<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Repository\ContentBlockDraftRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\RichTextRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
use App\Service\PageContent;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\ProductContentOwner;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Content Blocks Lifecycle 1.0 over real HTTP, against PHP's built-in server
 * with the Shop and the Portfolio on (CONTENT-BLOCKS.md, "De levensloop van
 * een nieuw blok"):
 *
 *   - choosing a block opens its editor and changes nothing on the page;
 *     Annuleren and going back leave the page as it was;
 *   - Opslaan places exactly one block at the bottom and lands on the page's
 *     list naming it (?saved=<id>#blok-<id>), for a page, a nested page, a
 *     product and a project; a refused save stays in the editor;
 *   - an existing block is saved in place and never removed by Annuleren;
 *   - CSRF, a forged owner, a forged type, a missing permission and a
 *     request-supplied return address change nothing;
 *   - an empty block is marked in the page builder and nowhere on the site.
 */
final class ContentBlockLifecycleHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $pageIds = [];

    private ?ShopStockFixture $shop = null;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true']);
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

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
        $this->accounts = new AdminTestSession();
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

        $this->accounts->forget();
        PageContent::clearCache();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
    }

    // ------------------------------------------------------------ create and cancel

    public function testChoosingABlockOpensItsEditorAndChangesNothingOnThePage(): void
    {
        $page = $this->page();
        $existing = $this->legacyBlock($page, 'spacer');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $added = $this->add($session, $csrf, ['page_id' => (string) $page['id'], 'section_type' => 'rich_text']);
        $key = $this->sectionFrom($added, '/admin/rich-text.php');

        $this->assertSame([$existing], $this->sectionIds($page), '1. opening a new block adds nothing to the page');

        $editor = self::$server->request('GET', '/admin/rich-text.php?section=' . urlencode($key), $session);
        $this->assertSame(200, $editor['status']);
        $this->assertStringContainsString('data-block-draft', $editor['body'], 'the editor says the block is new');
        $this->assertStringContainsString('action="/api/admin/discard-block-draft.php"', $editor['body']);

        // 2. Annuleren
        $cancelled = self::$server->request('POST', '/api/admin/discard-block-draft.php', $session, [
            'csrf_token' => $csrf, 'section_type' => 'rich_text', 'section' => $key,
        ]);
        $this->assertSame(302, $cancelled['status']);
        $this->assertSame('/admin/page.php?id=' . $page['id'], $cancelled['location']);
        $this->assertSame([$existing], $this->sectionIds($page));
        $this->assertNull($this->richText($key), 'nothing of the new block is left');

        // 3. Back without saving: the draft stays off the page.
        $this->add($session, $csrf, ['page_id' => (string) $page['id'], 'section_type' => 'rich_text']);
        $builder = self::$server->request('GET', '/admin/page.php?id=' . $page['id'], $session);
        $this->assertSame(200, $builder['status']);
        $this->assertSame([$existing], $this->sectionIds($page));
        $this->assertSame(1, substr_count($builder['body'], 'data-page-section-id='), 'the list shows the one saved block');
    }

    public function testSavingPlacesExactlyOneBlockAtTheBottomAndReturnsToThePage(): void
    {
        $page = $this->page();
        $first = $this->legacyBlock($page, 'spacer');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $key = $this->sectionFrom($this->add($session, $csrf, ['page_id' => (string) $page['id'], 'section_type' => 'rich_text']), '/admin/rich-text.php');

        // 6. A refused save makes no half block and stays in the editor.
        $refused = $this->saveText($session, $csrf, $key, '<p>Welkom</p>', ['language_code' => 'xx']);
        $this->assertStringStartsWith('/admin/rich-text.php?section=', $refused['location'], '17. a validation error stays in the editor');
        $this->assertStringNotContainsString('saved=', $refused['location']);
        $this->assertSame([$first], $this->sectionIds($page));

        // 4. Opslaan
        $saved = $this->saveText($session, $csrf, $key, '<p>Welkom</p>');
        $ids = $this->sectionIds($page);
        $this->assertCount(2, $ids, 'exactly one block');
        $this->assertSame($first, $ids[0], '7. the order is kept, the new block at the bottom');
        $this->assertSame('/admin/page.php?id=' . $page['id'] . '&saved=' . $ids[1] . '#blok-' . $ids[1], $saved['location'], '18. back to the page, at the block');

        // 5. The same form again (double click, refresh of the POST): one block.
        $again = $this->saveText($session, $csrf, $key, '<p>Welkom</p>');
        $this->assertSame($saved['location'], $again['location']);
        $this->assertCount(2, $this->sectionIds($page));

        $builder = self::$server->request('GET', '/admin/page.php?id=' . $page['id'] . '&saved=' . $ids[1], $session);
        $this->assertStringContainsString('Het contentblok is opgeslagen.', $builder['body']);
        $this->assertMatchesRegularExpression('~id="blok-' . $ids[1] . '"[^>]*~', $builder['body']);
        $this->assertStringContainsString('is-just-saved', $builder['body']);
        $this->assertStringContainsString('data-admin-collapse-focus', $builder['body']);
    }

    // ------------------------------------------------------------ existing blocks

    public function testAnExistingBlockIsSavedInPlaceAndNeverRemovedByAnnuleren(): void
    {
        $page = $this->page();
        $text = $this->legacyBlock($page, 'rich_text');
        $after = $this->legacyBlock($page, 'spacer');
        $key = $page['content_key'] . ':' . (new PageSectionRepository())->findById($text)['section_key'];
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        // 8. Opening it: no draft line, the ordinary way back.
        $editor = self::$server->request('GET', '/admin/rich-text.php?section=' . urlencode($key), $session);
        $this->assertSame(200, $editor['status']);
        $this->assertStringNotContainsString('data-block-draft', $editor['body']);
        $this->assertStringContainsString('href="/admin/page.php?id=' . $page['id'] . '"', $editor['body']);

        // 10./11. Saving keeps it where it was, on its own page.
        $saved = $this->saveText($session, $csrf, $key, '<p>Eerst</p>');
        $this->assertSame('/admin/page.php?id=' . $page['id'] . '&saved=' . $text . '#blok-' . $text, $saved['location']);
        $this->assertSame([$text, $after], $this->sectionIds($page));

        // 9. A forged Annuleren for it removes nothing; the old content stays.
        $cancel = self::$server->request('POST', '/api/admin/discard-block-draft.php', $session, [
            'csrf_token' => $csrf, 'section_type' => 'rich_text', 'section' => $key,
        ]);
        $this->assertSame(302, $cancel['status']);
        $this->assertSame([$text, $after], $this->sectionIds($page));
        $this->assertStringContainsString('Eerst', BlockLocalization::raw('rich_text_sections', (int) $this->richText($key)['id'], RichTextContent::BODY, BlockLocalization::defaultLanguage()));
    }

    // ------------------------------------------------------------ where a save lands

    public function testANestedPageAProductAndAProjectAreReturnedTo(): void
    {
        $parent = $this->page();
        $child = $this->page((int) $parent['id']);
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE, 'products.manage', 'portfolio.manage']);

        // 13. A nested page.
        $key = $this->sectionFrom($this->add($session, $csrf, ['page_id' => (string) $child['id'], 'section_type' => 'rich_text']), '/admin/rich-text.php');
        $saved = $this->saveText($session, $csrf, $key, '<p>Kind</p>');
        [$id] = $this->sectionIds($child);
        $this->assertSame('/admin/page.php?id=' . $child['id'] . '&saved=' . $id . '#blok-' . $id, $saved['location']);
        $this->assertSame([], $this->sectionIds($parent));

        // 14. A Product Content Page.
        $productId = $this->product('ZZ Levensloop HTTP');
        $key = $this->sectionFrom($this->add($session, $csrf, ['page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => (string) $productId, 'section_type' => 'rich_text']), '/admin/rich-text.php');
        $saved = $this->saveText($session, $csrf, $key, '<p>Product</p>');
        $page = ContentPages::pageFor(ProductContentOwner::KIND, $productId);
        [$id] = $this->sectionIds((array) $page);
        $this->assertSame('/admin/product-form.php?id=' . $productId . '&tab=inhoud&saved=' . $id . '#blok-' . $id, $saved['location']);
        $tab = self::$server->request('GET', '/admin/product-form.php?id=' . $productId . '&tab=inhoud&saved=' . $id, $session);
        $this->assertStringContainsString('Het contentblok is opgeslagen.', $tab['body']);
        $this->assertStringContainsString('is-just-saved', $tab['body']);

        // A cancelled first block of a product leaves it without a content page.
        $other = $this->product('ZZ Levensloop zonder blok');
        $key = $this->sectionFrom($this->add($session, $csrf, ['page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => (string) $other, 'section_type' => 'rich_text']), '/admin/rich-text.php');
        $cancelled = self::$server->request('POST', '/api/admin/discard-block-draft.php', $session, ['csrf_token' => $csrf, 'section_type' => 'rich_text', 'section' => $key]);
        $this->assertSame('/admin/product-form.php?id=' . $other . '&tab=inhoud', $cancelled['location']);
        $this->assertNull(ContentPages::pageFor(ProductContentOwner::KIND, $other));

        // 15. A Portfolio Content Page.
        $itemId = $this->project('ZZ Levensloop project');
        $key = $this->sectionFrom($this->add($session, $csrf, ['page_id' => '0', 'content_owner' => 'portfolio_project', 'content_owner_id' => (string) $itemId, 'section_type' => 'rich_text']), '/admin/rich-text.php');
        $saved = $this->saveText($session, $csrf, $key, '<p>Project</p>');
        [$id] = $this->sectionIds((array) ContentPages::pageFor(PortfolioContentOwner::KIND, $itemId));
        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId . '&tab=inhoud&saved=' . $id . '#blok-' . $id, $saved['location']);
    }

    public function testNoRequestSuppliedAddressDecidesWhereASaveLands(): void
    {
        $page = $this->page();
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $key = $this->sectionFrom($this->add($session, $csrf, ['page_id' => (string) $page['id'], 'section_type' => 'rich_text']), '/admin/rich-text.php');

        // 16./30. Whatever a request calls its return address.
        $saved = $this->saveText($session, $csrf, $key, '<p>Hallo</p>', [
            'return' => 'https://evil.example/', 'return_to' => '//evil.example', 'redirect' => '/admin/users.php', 'saved' => '999',
        ]);
        [$id] = $this->sectionIds($page);
        $this->assertSame('/admin/page.php?id=' . $page['id'] . '&saved=' . $id . '#blok-' . $id, $saved['location']);

        // A saved= naming a block of another page is not claimed by this list.
        $elsewhere = self::$server->request('GET', '/admin/page.php?id=' . $page['id'] . '&saved=' . ($id + 100000), $session);
        $this->assertStringNotContainsString('Het contentblok is opgeslagen.', $elsewhere['body']);
    }

    // ------------------------------------------------------------ security

    public function testForgedRequestsChangeNothing(): void
    {
        $page = $this->page();
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $key = $this->sectionFrom($this->add($session, $csrf, ['page_id' => (string) $page['id'], 'section_type' => 'rich_text']), '/admin/rich-text.php');

        // 26. CSRF, on add, on Annuleren and on the save that would place it.
        $this->assertSame(403, $this->addRaw($session, 'wrong', ['page_id' => (string) $page['id'], 'section_type' => 'rich_text'])['status']);
        $this->assertSame(403, self::$server->request('POST', '/api/admin/discard-block-draft.php', $session, ['csrf_token' => 'wrong', 'section_type' => 'rich_text', 'section' => $key])['status']);
        $this->assertSame(403, $this->saveText($session, 'wrong', $key, '<p>x</p>')['status']);
        $this->assertSame(405, self::$server->request('GET', '/api/admin/discard-block-draft.php', $session)['status']);

        // 27. A forged owner.
        $this->assertSame(404, $this->addRaw($session, $csrf, ['page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => '999999999', 'section_type' => 'rich_text'])['status']);
        $this->assertSame(404, $this->addRaw($session, $csrf, ['page_id' => '0', 'content_owner' => 'App\\Service\\ProductContentOwner', 'content_owner_id' => '1', 'section_type' => 'rich_text'])['status']);

        // 29. A forged block type.
        foreach (['no_such_block', 'quicknav', '../rich_text', 'RICH_TEXT'] as $type) {
            $this->assertSame(400, $this->addRaw($session, $csrf, ['page_id' => (string) $page['id'], 'section_type' => $type])['status'], $type);
        }

        // 28. Not enough permission: a Shop manager cannot cancel, save or add on a page.
        [$shop, $shopCsrf] = $this->accounts->signIn(['products.manage']);
        $this->assertSame(403, self::$server->request('POST', '/api/admin/discard-block-draft.php', $shop, ['csrf_token' => $shopCsrf, 'section_type' => 'rich_text', 'section' => $key])['status']);
        $this->assertSame(403, $this->saveText($shop, $shopCsrf, $key, '<p>x</p>')['status']);
        $this->assertSame(403, $this->addRaw($shop, $shopCsrf, ['page_id' => (string) $page['id'], 'section_type' => 'rich_text'])['status']);

        $this->assertSame([], $this->sectionIds($page), 'nothing was placed');
        $this->assertCount(1, (new ContentBlockDraftRepository())->findForPage((int) $page['id']), 'the one draft is untouched');
        $this->assertNotNull($this->richText($key));
    }

    // ------------------------------------------------------------ empty blocks

    public function testAnEmptyLegacyBlockIsMarkedInTheBuilderAndNowhereOnTheSite(): void
    {
        $page = $this->page();
        $empty = $this->legacyBlock($page, 'rich_text');
        $filled = $this->legacyBlock($page, 'rich_text');
        $filledRow = (new PageSectionRepository())->findById($filled);
        BlockLocalization::save('rich_text_sections', (int) $filledRow['section_id'], BlockLocalization::defaultLanguage(), [RichTextContent::BODY => '<p>Zichtbaar</p>']);
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $builder = self::$server->request('GET', '/admin/page.php?id=' . $page['id'], $session)['body'];
        $this->assertSame(1, substr_count($builder, 'admin-badge--empty'), '24. only the empty one');
        $this->assertMatchesRegularExpression('~id="blok-' . $empty . '"[^>]*is-empty-block|is-empty-block[^>]*id="blok-' . $empty . '"~', $builder);
        $this->assertStringContainsString('Dit contentblok bevat nog geen inhoud', $builder);
        $this->assertStringContainsString('/admin/rich-text.php?section=', $builder, 'the note leads to the editor');

        // 25. The public page.
        $public = self::$server->request('GET', '/pagina.php?slug=' . urlencode((string) $page['content_key']));
        $this->assertSame(200, $public['status']);
        $this->assertStringContainsString('Zichtbaar', $public['body']);
        $this->assertStringNotContainsString('Leeg blok', $public['body']);
        $this->assertStringNotContainsString('admin-badge', $public['body']);
        $this->assertStringNotContainsString('bevat nog geen inhoud', $public['body']);
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string, mixed> a fresh published page */
    private function page(?int $parentId = null): array
    {
        $key = 'zz-lifecycle-http-' . bin2hex(random_bytes(4));
        $id = PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED, 'parent_id' => $parentId], 'ZZ Levensloop HTTP');
        $this->pageIds[] = $id;

        return (array) (new PageRepository())->findById($id);
    }

    /** A block on the page the way the old flow left one: attached at once, never saved. */
    private function legacyBlock(array $page, string $type): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, (string) $page['content_key']);

        return (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], $type, $sectionKey, $sectionId);
    }

    /** @param array<string, string> $fields */
    private function add(string $session, string $csrf, array $fields): array
    {
        $response = $this->addRaw($session, $csrf, $fields);
        $this->assertSame(302, $response['status'], $response['body']);

        return $response;
    }

    /** @param array<string, string> $fields */
    private function addRaw(string $session, string $csrf, array $fields): array
    {
        return self::$server->request('POST', '/api/admin/add-page-section.php', $session, ['csrf_token' => $csrf] + $fields);
    }

    /** @param array<string, string> $extra */
    private function saveText(string $session, string $csrf, string $key, string $body, array $extra = []): array
    {
        return self::$server->request('POST', '/api/admin/update-rich-text-section.php', $session, $extra + [
            'csrf_token' => $csrf,
            'section' => $key,
            'language_code' => BlockLocalization::defaultLanguage(),
            RichTextContent::BODY => $body,
            RichTextContent::BUTTON_LABEL => '',
            'is_active' => '1',
        ]);
    }

    /** The `<page>:<key>` a redirect into a block editor names. */
    private function sectionFrom(array $response, string $editor): string
    {
        $this->assertStringStartsWith($editor . '?section=', $response['location']);
        parse_str((string) parse_url($response['location'], PHP_URL_QUERY), $query);

        return (string) ($query['section'] ?? '');
    }

    /** @return array<string, mixed>|null */
    private function richText(string $key): ?array
    {
        [$pageSlug, $sectionKey] = explode(':', $key, 2);

        return (new RichTextRepository())->findBySlugAndKey($pageSlug, $sectionKey);
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

    private function project(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-lifecycle-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => $title,
            PortfolioLocalization::ALT => $title,
        ]);
        PortfolioGalleryContent::clearCache();

        return $id;
    }
}
