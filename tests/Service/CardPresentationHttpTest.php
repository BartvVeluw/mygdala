<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Module\PortfolioModule;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\CardPresentation;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\Blocks\ProjectCardsBlock;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ItemGalleryContent;
use App\Service\PageAssets;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\SavedRedirect;

/**
 * Card Presentation 2.0 over real HTTP, against PHP's built-in server with
 * the Portfolio on (CONTENT-BLOCKS.md, "Kaartweergave"):
 *
 *   - both editors show "Kaartweergave" with the stored choice;
 *   - saving Breed changes the cards on the public page and loads the
 *     shared stylesheet; back to Standaard is the public page as it was;
 *   - Breed and Extra vormgeving together: the look on the block's root, the
 *     presentation on its grid, both stylesheets;
 *   - the gallery stores its presentation the same way;
 *   - a new block takes its presentation from its first save, and a
 *     cancelled draft leaves no row behind;
 *   - CSRF, an unknown word, a forged or another block's section and a user
 *     without the list's permission write nothing;
 *   - the stylesheet is asked for only where a block shows a presentation
 *     other than its default.
 *
 * The contract itself without a server: Tests\Service\CardPresentationContractTest.
 */
final class CardPresentationHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'true']);
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
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }

        $this->pageIds = $this->itemIds = [];
        $this->accounts->forget();
        PageContent::clearCache();
        PortfolioGalleryContent::clearCache();
        ItemGalleryContent::clearCache();
        PageAssets::reset();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        ContentOwners::reset();
    }

    public function testBothEditorsShowKaartweergaveWithTheStoredChoice(): void
    {
        $page = $this->page();
        [$projectsId, $projectsKey] = $this->block($page, 'project_cards');
        [$galleryId, $galleryKey] = $this->block($page, 'item_gallery');
        $repository = new ItemGalleryRepository();
        $repository->saveCardPresentation($projectsId, 'compact');
        $repository->saveCardPresentation($galleryId, 'wide');

        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        foreach ([
            '/admin/project-cards.php?section=' => [$projectsKey, 'compact'],
            '/admin/item-gallery.php?section=' => [$galleryKey, 'wide'],
        ] as $screen => [$key, $stored]) {
            $html = (string) self::$server->request('GET', $screen . urlencode($page['content_key'] . ':' . $key), $session)['body'];
            self::assertStringContainsString('<fieldset class="admin-cp" data-card-presentation-field>', $html, $screen);
            self::assertStringContainsString('Kaartweergave', $html);
            self::assertSame(3, substr_count($html, 'name="card_presentation"'), $screen);
            self::assertMatchesRegularExpression('#name="card_presentation" value="' . $stored . '" checked#', $html, $screen);
            foreach (['Standaard', 'Compact', 'Breed'] as $label) {
                self::assertStringContainsString($label, $html);
            }
        }
    }

    public function testBreedChangesTheCardsAndBackToStandaardRestoresThePage(): void
    {
        $page = $this->page();
        $this->item('ZZ Project breed');
        [$galleryId, $sectionKey] = $this->block($page, 'project_cards');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $url = '/pagina.php?slug=' . urlencode((string) $page['content_key']);

        $before = (string) self::$server->request('GET', $url)['body'];
        self::assertStringContainsString('ZZ Project breed', $before, 'the block shows the project');
        self::assertStringNotContainsString('card-presentation', $before, 'no class and no stylesheet before');

        $saved = $this->saveProjects($session, $csrf, $page, $sectionKey, ['card_presentation' => 'wide', 'title' => 'ZZ Werk']);
        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, (string) $saved['location']);
        self::assertSame('wide', $this->stored($page, $sectionKey));

        $public = (string) self::$server->request('GET', $url)['body'];
        self::assertStringContainsString('<div class="gallery-grid card-presentation card-presentation--wide">', $public);
        self::assertStringContainsString('/assets/css/card-presentation.css', $public);
        self::assertSame(1, substr_count($public, 'card-presentation.css'), 'one stylesheet');
        self::assertStringContainsString('<h3 class="gallery-item__title card-presentation__title">ZZ Project breed</h3>', $public);
        self::assertStringContainsString('<button type="button" class="gallery-item__zoom card-presentation__media" data-lightbox-trigger', $public, 'the picture still zooms');

        $compact = $this->saveProjects($session, $csrf, $page, $sectionKey, ['card_presentation' => 'compact']);
        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, (string) $compact['location']);
        self::assertStringContainsString('card-presentation--compact', (string) self::$server->request('GET', $url)['body']);

        $this->saveProjects($session, $csrf, $page, $sectionKey, ['card_presentation' => 'default']);
        self::assertSame('default', $this->stored($page, $sectionKey));
        self::assertSame($this->withoutTokens($before), $this->withoutTokens((string) self::$server->request('GET', $url)['body']), 'the public page as it was');
    }

    public function testBreedAndExtraVormgevingWorkTogether(): void
    {
        $page = $this->page();
        $this->item('ZZ Project vormgeving');
        [, $sectionKey] = $this->block($page, 'project_cards');
        $pageSectionId = (int) (new PageSectionRepository())->findBySectionTypeAndId('project_cards', $this->galleryId($page, $sectionKey))['id'];
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $this->saveProjects($session, $csrf, $page, $sectionKey, ['card_presentation' => 'wide']);
        $look = self::$server->request('POST', '/api/admin/update-block-appearance.php', $session, [
            'csrf_token' => $csrf,
            'id' => (string) $pageSectionId,
            'background' => 'subtle',
            'border' => 'both',
            'border_tone' => 'accent',
            'spacing' => 'spacious',
            'decoration' => 'none',
        ]);
        self::assertSame(302, $look['status']);

        $public = (string) self::$server->request('GET', '/pagina.php?slug=' . urlencode((string) $page['content_key']))['body'];
        self::assertMatchesRegularExpression('~<section class="block-appearance block-appearance--bg-subtle block-appearance--border-both block-appearance--line-accent block-appearance--space-spacious" data-gallery-block data-lightbox-group>~', $public, 'the look on the block');
        self::assertStringContainsString('<div class="gallery-grid card-presentation card-presentation--wide">', $public, 'the presentation on its cards');
        self::assertStringContainsString('/assets/css/block-appearance.css', $public);
        self::assertStringContainsString('/assets/css/card-presentation.css', $public);
        self::assertSame('wide', $this->stored($page, $sectionKey), 'saving the look leaves the presentation alone');
    }

    public function testTheGalleryStoresItsPresentationTheSameWay(): void
    {
        $page = $this->page();
        [, $sectionKey] = $this->block($page, 'item_gallery');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-item-gallery.php', $session, [
            'csrf_token' => $csrf,
            'section' => $page['content_key'] . ':' . $sectionKey,
            'language_code' => 'nl',
            'source_type' => PortfolioModule::GALLERY_SOURCE,
            'portfolio_scope' => 'all',
            'item_sort' => 'source',
            'card_presentation' => 'compact',
            'is_active' => '1',
        ]);

        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, (string) $response['location']);
        self::assertSame('compact', $this->stored($page, $sectionKey));
    }

    public function testANewBlockTakesItsPresentationFromItsFirstSaveAndACancelLeavesNothing(): void
    {
        $page = $this->page();
        $draft = ContentBlockDrafts::open($page, 'project_cards');
        $sectionKey = (string) $draft['section_key'];
        self::assertNull((new PageSectionRepository())->findBySectionTypeAndId('project_cards', (int) $draft['section_id']), 'a draft is not on the page');

        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $response = $this->saveProjects($session, $csrf, $page, $sectionKey, ['card_presentation' => 'wide']);
        self::assertSame(302, $response['status']);
        self::assertNotNull((new PageSectionRepository())->findBySectionTypeAndId('project_cards', (int) $draft['section_id']), 'placed by its first save');
        self::assertSame('wide', $this->stored($page, $sectionKey), 'with the presentation of that save');

        $cancelled = ContentBlockDrafts::open($page, 'project_cards');
        ContentBlockDrafts::discard((array) ContentBlockDrafts::find('project_cards', (int) $cancelled['section_id']), false);
        self::assertNull((new ItemGalleryRepository())->findById((int) $cancelled['section_id']), 'a cancelled draft leaves no row, presentation or otherwise');
    }

    public function testForgedAndUnauthorisedRequestsWriteNothing(): void
    {
        $page = $this->page();
        [, $sectionKey] = $this->block($page, 'project_cards');
        [, $galleryKey] = $this->block($page, 'item_gallery');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        // An unknown word, an array, CSS: refused with the editor's message.
        foreach (['visual', 'wide;display:none', '"><script>alert(1)</script>'] as $forged) {
            $response = $this->saveProjects($session, $csrf, $page, $sectionKey, ['card_presentation' => $forged, 'max_items' => '3']);
            self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, (string) $response['location'], $forged);
            self::assertSame(['Kies een kaartweergave uit de lijst.'], $this->accounts->read($session, 'admin_project_cards_errors'), $forged);
        }
        $response = $this->saveProjects($session, $csrf, $page, $sectionKey, ['card_presentation' => ['wide']]);
        self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, (string) $response['location']);

        // CSRF.
        self::assertSame(403, $this->saveProjects($session, 'wrong', $page, $sectionKey, ['card_presentation' => 'wide'])['status']);

        // A forged section, and another block's row through this endpoint.
        self::assertSame(404, $this->saveProjects($session, $csrf, $page, 'zz-no-such-key', ['card_presentation' => 'wide'])['status']);
        self::assertSame(404, $this->saveProjects($session, $csrf, $page, $galleryKey, ['card_presentation' => 'wide'])['status']);

        // Not this list's permission.
        [$shop, $shopCsrf] = $this->accounts->signIn(['products.manage']);
        self::assertSame(403, $this->saveProjects($shop, $shopCsrf, $page, $sectionKey, ['card_presentation' => 'wide'])['status']);

        self::assertSame('default', $this->stored($page, $sectionKey));
        self::assertSame('default', $this->stored($page, $galleryKey));
        self::assertNull((new ItemGalleryRepository())->findBySlugAndKey((string) $page['content_key'], $sectionKey)['max_items'], 'a refused save stores nothing else either');
    }

    public function testTheStylesheetIsAskedForOnlyWhereABlockUsesAPresentation(): void
    {
        $page = $this->page();
        [$galleryId] = $this->block($page, 'project_cards');
        $sections = (new PageSectionRepository())->findForPage((int) $page['id'], true);

        PageAssets::reset();
        CardPresentation::collectAssets($sections);
        self::assertNotContains(CardPresentation::STYLESHEET, PageAssets::collected()['styles'] ?? [], 'the default asks for nothing');

        (new ItemGalleryRepository())->saveCardPresentation($galleryId, 'wide');
        ItemGalleryContent::clearCache();
        PageAssets::reset();
        CardPresentation::collectAssets($sections);
        self::assertContains(CardPresentation::STYLESHEET, PageAssets::collected()['styles'] ?? []);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $fields
     *
     * @return array{status: int, location: ?string, body: string}
     */
    private function saveProjects(string $session, string $csrf, array $page, string $sectionKey, array $fields): array
    {
        return self::$server->request('POST', '/api/admin/update-project-cards.php', $session, $fields + [
            'csrf_token' => $csrf,
            'section' => $page['content_key'] . ':' . $sectionKey,
            'language_code' => 'nl',
            'portfolio_scope' => 'all',
            'item_sort' => 'source',
            'is_active' => '1',
        ]);
    }

    /** @param array<string, mixed> $page */
    private function stored(array $page, string $sectionKey): string
    {
        return (string) (new ItemGalleryRepository())->findBySlugAndKey((string) $page['content_key'], $sectionKey)['card_presentation'];
    }

    /** @param array<string, mixed> $page */
    private function galleryId(array $page, string $sectionKey): int
    {
        return (int) (new ItemGalleryRepository())->findBySlugAndKey((string) $page['content_key'], $sectionKey)['id'];
    }

    /** @return array<string, mixed> a fresh published page */
    private function page(): array
    {
        $key = 'zz-kaartweergave-' . bin2hex(random_bytes(4));
        $id = PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Kaartweergave');
        $this->pageIds[] = $id;

        return (array) (new PageRepository())->findById($id);
    }

    /**
     * A placed block of $type on $page, showing the Portfolio's projects.
     *
     * @param array<string, mixed> $page
     *
     * @return array{0: int, 1: string} item_galleries id, section key
     */
    private function block(array $page, string $type): array
    {
        [$galleryId, $sectionKey] = SectionRegistry::create($type, (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], $type, $sectionKey, $galleryId);

        $repository = new ItemGalleryRepository();
        $repository->upsertSection((string) $page['content_key'], (string) $sectionKey, $type === 'project_cards'
            ? ProjectCardsBlock::rowValues([])
            : ['source_type' => PortfolioModule::GALLERY_SOURCE, 'portfolio_scope' => 'all', 'background' => 'default', 'is_active' => true]);

        return [(int) $galleryId, (string) $sectionKey];
    }

    private function item(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-kaartweergave.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => $title]);

        return $id;
    }

    /** A public page without what differs per request (CSRF tokens, nonces). */
    private function withoutTokens(string $html): string
    {
        return (string) preg_replace(['/name="csrf_token" value="[^"]*"/', '/nonce="[^"]*"/'], '', $html);
    }
}
