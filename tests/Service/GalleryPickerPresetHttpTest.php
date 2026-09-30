<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Service\AdminPermissions;
use App\Service\Blocks\ContentBlockDrafts;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The gallery's two picker cards over real HTTP (api/admin/add-page-section.php,
 * App\Service\Blocks\OffersPickerPresets), against PHP's built-in server with
 * the Shop and the Portfolio on:
 *
 *  - "Collectiegalerij" and "Portfoliogalerij" both create an ordinary
 *    `item_gallery` block, the same type an ordinary card creates, with the
 *    source already chosen;
 *  - a preset the block does not offer, a malformed choice, or a preset of
 *    a block without presets is refused and creates nothing.
 *
 * A chosen block is a draft until its editor saves it (Content Blocks
 * Lifecycle 1.0), so "created" is read from content_block_drafts. Every draft
 * it makes is removed again in tearDown(), by id.
 */
final class GalleryPickerPresetHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

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
        $this->pageId = (int) $db->query("SELECT id FROM pages WHERE content_key = 'over-ons' LIMIT 1")->fetchColumn();
        if ($this->pageId === 0) {
            $this->markTestSkipped('this database has no page to add a block to');
        }
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

    public function testEachGalleryCardAddsTheOneGalleryBlockWithItsSourceChosen(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        foreach (['item_gallery:collection' => 'collection', 'item_gallery:portfolio' => 'portfolio'] as $choice => $source) {
            $response = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
                'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_preset' => $choice,
            ]);

            $this->assertSame(302, $response['status'], $choice);
            $this->assertStringStartsWith('/admin/item-gallery.php?section=', (string) $response['location'], $choice . ' goes to the new block\'s editor');
            $this->assertSame([['section_type' => 'item_gallery', 'source_type' => $source]], $this->added(), $choice);
            $this->lastDraftIdAfter();
        }

        // The ordinary card of the same type still works, and starts on the first source.
        $plain = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_type' => 'item_gallery',
        ]);
        $this->assertSame(302, $plain['status']);
        $this->assertSame([['section_type' => 'item_gallery', 'source_type' => 'portfolio']], $this->added());
    }

    public function testAPresetTheBlockDoesNotOfferIsRefusedAndAddsNothing(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        foreach (['item_gallery:products', 'item_gallery:', 'item_gallery', 'rich_text:collection', 'ITEM_GALLERY:collection', 'item_gallery:collection:x', 'no_block:collection'] as $choice) {
            $response = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
                'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_preset' => $choice,
            ]);

            $this->assertSame(400, $response['status'], $choice);
            $this->assertSame([], $this->added(), $choice . ' added nothing');
        }

        // The guards still come first.
        $forged = self::$server->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => 'wrong', 'page_id' => (string) $this->pageId, 'section_preset' => 'item_gallery:collection',
        ]);
        $this->assertSame(403, $forged['status']);
        $this->assertSame([], $this->added());
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

    private function lastDraftIdAfter(): void
    {
        $this->watermark = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) FROM content_block_drafts')->fetchColumn();
    }
}
