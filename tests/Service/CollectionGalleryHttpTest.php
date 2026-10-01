<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The Shop's Collectiegalerij and the Portfolio's Projecten over real HTTP
 * (api/admin/add-page-section.php, api/admin/update-item-gallery.php), against
 * PHP's built-in server with the Shop and the Portfolio on (v0.1.15):
 *
 *  - the gallery card adds an `item_gallery` on the Shop's collection source,
 *    the Projecten card a `project_cards` on portfolio items;
 *  - the old Portfoliogalerij preset (`section_preset`) is gone: posting it
 *    adds nothing, whatever it says;
 *  - the gallery's editor refuses portfolio items as its source, also on a
 *    hand-made request;
 *  - with the Shop off, the gallery's editor and endpoint answer no-access /
 *    not found before anything is read.
 *
 * A chosen block is a draft until its editor saves it, so "added" is read
 * from content_block_drafts. Every draft it makes is removed again in
 * tearDown(), by id.
 */
final class CollectionGalleryHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    private string $pageKey = '';

    /** content_block_drafts ids before the test, so exactly the added ones are removed. */
    private int $lastDraftId = 0;

    /** Only the drafts added since the last look count as "added" next time. */
    private ?int $watermark = null;

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
        $this->accounts = new AdminTestSession();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $db = Database::connection();
        $page = $db->query("SELECT id, content_key FROM pages WHERE content_key = 'over-ons' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if ($page === false) {
            $this->markTestSkipped('this database has no page to add a block to');
        }
        $this->pageId = (int) $page['id'];
        $this->pageKey = (string) $page['content_key'];
        $this->lastDraftId = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM content_block_drafts')->fetchColumn();
    }

    protected function tearDown(): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, p.content_key AS page_slug FROM content_block_drafts d JOIN pages p ON p.id = d.page_id WHERE d.id > :id'
        );
        $stmt->execute(['id' => $this->lastDraftId]);

        foreach ($stmt->fetchAll() as $draft) {
            ContentBlockDrafts::discard($draft);
        }

        $this->accounts->forget();
    }

    public function testTheGalleryCardAddsACollectionGalleryAndProjectenPortfolioItems(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        foreach (['item_gallery' => ['collection', '/admin/item-gallery.php?section='], 'project_cards' => ['portfolio', '/admin/project-cards.php?section=']] as $type => [$source, $editor]) {
            $response = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
                'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_type' => $type,
            ]);

            $this->assertSame(302, $response['status'], $type);
            $this->assertStringStartsWith($editor, (string) $response['location'], $type . ' goes to the new block\'s editor');
            $this->assertSame([['section_type' => $type, 'source_type' => $source]], $this->added(), $type);
            $this->watermarkNow();
        }
    }

    public function testTheOldPortfoliogalerijPresetAddsNothing(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        foreach (['item_gallery:portfolio', 'item_gallery:collection', 'project_cards:portfolio'] as $choice) {
            $response = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
                'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_preset' => $choice,
            ]);

            $this->assertSame(400, $response['status'], $choice);
            $this->assertSame([], $this->added(), $choice . ' added nothing');
        }

        // The guards still come first.
        $forged = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => 'wrong', 'page_id' => (string) $this->pageId, 'section_type' => 'item_gallery',
        ]);
        $this->assertSame(403, $forged['status']);
        $this->assertSame([], $this->added());
    }

    /**
     * A hand-made save of a Collectiegalerij that names portfolio items (or
     * any word that is not one of its sources) is refused: nothing is
     * written, and the new block stays a draft.
     */
    public function testTheGalleryEditorRefusesPortfolioItemsAsItsSource(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_type' => 'item_gallery',
        ]);
        $draft = $this->drafts()[0] ?? null;
        $this->assertNotNull($draft, 'a gallery draft');
        $section = $this->pageKey . ':' . $draft['section_key'];

        foreach (['portfolio', 'products', ''] as $source) {
            $response = self::$server->request('POST', '/api/admin/update-item-gallery.php', $session, [
                'csrf_token' => $csrf,
                'section' => $section,
                'language_code' => BlockLocalization::defaultLanguage(),
                'source_type' => $source,
                'title' => 'Geweigerd',
            ]);

            $this->assertSame(302, $response['status'], $source);
            $this->assertStringStartsWith('/admin/item-gallery.php?section=', (string) $response['location'], $source . ': back to the editor');
            $row = Database::connection()->query('SELECT source_type FROM item_galleries WHERE id = ' . (int) $draft['section_id'])->fetchColumn();
            $this->assertSame('collection', $row, $source . ': the source is unchanged');
            $this->assertSame('', BlockLocalization::raw('item_galleries', (int) $draft['section_id'], 'title', BlockLocalization::defaultLanguage()), $source . ': nothing written');
            $this->assertNotNull(ContentBlockDrafts::find('item_gallery', (int) $draft['section_id']), $source . ': still a draft');
        }

        // The editor offers no source to choose while there is only one.
        $editor = self::$server->request('GET', '/admin/item-gallery.php?section=' . urlencode($section), $session);
        $this->assertSame(200, $editor['status']);
        $this->assertStringNotContainsString('<select name="source_type"', $editor['body']);
        $this->assertStringContainsString('<input type="hidden" name="source_type" value="collection">', $editor['body']);
        $this->assertStringNotContainsString('Portfolio-items', $editor['body']);
    }

    public function testWithTheShopOffTheGalleryEditorAndEndpointAreClosed(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_type' => 'item_gallery',
        ]);
        $draft = $this->drafts()[0] ?? null;
        $this->assertNotNull($draft);
        $section = $this->pageKey . ':' . $draft['section_key'];

        $shopOff = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false', 'MODULE_PORTFOLIO_ENABLED' => 'true']);
        if ($shopOff === null || !$shopOff->answers()) {
            $this->markTestSkipped('could not start a second built-in server');
        }

        try {
            $this->assertSame(403, $shopOff->request('GET', '/admin/item-gallery.php?section=' . urlencode($section), $session)['status'], 'the no-access page');
            $this->assertSame(404, $shopOff->request('POST', '/api/admin/update-item-gallery.php', $session, [
                'csrf_token' => $csrf, 'section' => $section, 'language_code' => BlockLocalization::defaultLanguage(), 'source_type' => 'collection',
            ])['status']);
            $this->assertSame(400, $shopOff->request('POST', '/api/admin/add-page-section.php', $session, [
                'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_type' => 'item_gallery',
            ])['status'], 'not offered, so not accepted');
        } finally {
            $shopOff->stop();
        }

        $this->assertNotNull(ContentBlockDrafts::find('item_gallery', (int) $draft['section_id']), 'switching the Shop off removed nothing');
    }

    /** @return list<array{section_type: string, source_type: ?string}> */
    private function added(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.section_type, g.source_type
               FROM content_block_drafts d
               LEFT JOIN item_galleries g ON g.id = d.section_id
              WHERE d.id > :id ORDER BY d.id'
        );
        $stmt->execute(['id' => $this->watermark ?? $this->lastDraftId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> the drafts this test made */
    private function drafts(): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM content_block_drafts WHERE id > :id ORDER BY id');
        $stmt->execute(['id' => $this->lastDraftId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function watermarkNow(): void
    {
        $this->watermark = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) FROM content_block_drafts')->fetchColumn();
    }
}
