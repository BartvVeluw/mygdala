<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Module\ArticlesModule;
use App\Module\ModuleRegistry;
use App\Repository\ArticleRepository;
use App\Repository\PageSectionRepository;
use App\Repository\RichTextRepository;
use App\Service\Articles\ArticleContentOwner;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleService;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\ContentOwners\ContentPages;
use App\Service\ContentOwners\OwnerContentGuard;
use App\Service\ContentOwners\OwnerContentRequired;
use App\Service\Publishing\PublishingClock;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Articles Integrity Polish (v0.1.15 phase 6.1, ARTICLES.md "Inhoud houden"
 * and "Verwijderen"):
 *
 *   - an article that is not a draft keeps a block that says something: a
 *     block change that would take the last one away — delete, hide, empty
 *     it in its editor, switch it off in its editor — is refused whole, with
 *     the article's own message, through the central OwnerContentGuard;
 *   - deleting an article is ONE database change: a failure anywhere leaves
 *     the article, its words, its content page and its blocks exactly as
 *     they were, and a delete that succeeds leaves no orphan behind.
 *
 * Every article, block and account is this test's own and removed by exact id.
 */
final class ArticleIntegrityTest extends TestCase
{
    private const PREFIX = 'zz-artint-';

    private static ?BuiltInServer $server = null;

    private ArticleRepository $articles;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $created = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_ARTICLES_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (!ModuleRegistry::isEnabled('articles')) {
            $this->markTestSkipped('Articles is off in this process (MODULE_ARTICLES_ENABLED)');
        }

        $this->articles = new ArticleRepository();
        $this->accounts = new AdminTestSession();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        foreach ($this->created as $id) {
            if ($this->articles->find($id) !== null) {
                ArticleService::delete($id, $this->articles);
            }
        }
        $this->created = [];

        $this->accounts->forget();
        ArticleLocalization::clearCache();
        BlockLocalization::clearCache();
        RichTextContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The invariant                                                       */
    /* ------------------------------------------------------------------ */

    public function testAPublishedArticleMayLoseABlockAsLongAsAnotherSaysSomething(): void
    {
        $id = $this->article('published', '-1 day');
        $this->textBlock($id, '<p>Tweede</p>');
        $rows = $this->rows($id);
        $this->assertCount(2, $rows);

        SectionRegistry::delete($rows[1], new PageSectionRepository());

        $this->assertCount(1, $this->rows($id));
        $this->assertSame([], ArticleService::publishErrors($id));
    }

    public function testThePublishedArticlesLastMeaningfulBlockCannotBeDeletedOrHidden(): void
    {
        $id = $this->article('published', '-1 day');
        $this->spacer($id);
        [$text] = $this->rows($id);
        $before = $this->snapshot($id);

        $this->assertRefused(fn () => SectionRegistry::delete($text, new PageSectionRepository()), 'a spacer is no content');
        $this->assertSame($before, $this->snapshot($id), 'nothing of the refused delete stays');

        $this->assertRefused(fn () => SectionRegistry::setActive($text, false), 'hiding is losing it too');
        $this->assertSame($before, $this->snapshot($id), 'nothing of the refused hide stays');

        // Showing it again is never refused, nor is hiding the spacer.
        SectionRegistry::setActive($text, true);
        SectionRegistry::setActive($this->rows($id)[1], false);
        $this->assertSame([], ArticleService::publishErrors($id));
    }

    public function testEmptyingOrSwitchingOffTheLastBlockInItsEditorIsRolledBack(): void
    {
        $id = $this->article('published', '-1 day');
        [$text] = $this->rows($id);
        $sectionId = (int) $text['section_id'];
        $before = $this->snapshot($id);
        $db = Database::connection();

        // What every block editor's save does: write, then place() as its last write.
        $db->beginTransaction();
        BlockLocalization::save('rich_text_sections', $sectionId, 'nl', [RichTextContent::BODY => '']);
        $this->assertRefused(fn () => ContentBlockDrafts::place('rich_text', $sectionId), 'an emptied text');
        $db->rollBack();
        BlockLocalization::clearCache();
        $this->assertSame($before, $this->snapshot($id));

        $db->beginTransaction();
        (new RichTextRepository())->upsertSection((string) $text['page_slug'], (string) $text['section_key'], ['is_active' => false]);
        $this->assertRefused(fn () => ContentBlockDrafts::place('rich_text', $sectionId), 'switched off in its own editor');
        $db->rollBack();
        $this->assertSame($before, $this->snapshot($id));

        // A save that keeps something to read goes through.
        $db->beginTransaction();
        BlockLocalization::save('rich_text_sections', $sectionId, 'nl', [RichTextContent::BODY => '<p>Andere tekst</p>']);
        $this->assertNotNull(ContentBlockDrafts::place('rich_text', $sectionId));
        $db->commit();
    }

    public function testADraftMayBeEmptiedAndLoseEveryBlock(): void
    {
        $id = $this->article('draft', null);
        [$text] = $this->rows($id);

        SectionRegistry::setActive($text, false);
        SectionRegistry::setActive($text, true);
        SectionRegistry::delete($text, new PageSectionRepository());

        $this->assertSame([], $this->rows($id));
    }

    public function testScheduledAndArchivedArticlesFollowTheSameRule(): void
    {
        foreach ([['scheduled', '+3 days'], ['scheduled', '-1 hour'], ['archived', '-1 week']] as [$status, $moment]) {
            $id = $this->article($status, $moment);
            [$text] = $this->rows($id);

            $this->assertRefused(fn () => SectionRegistry::delete($text, new PageSectionRepository()), $status . ' ' . $moment);
            $this->assertCount(1, $this->rows($id));
        }
    }

    public function testTheOwnerSwitchedOffInItsEditorIsNoContentForThePublishRule(): void
    {
        $id = $this->article('draft', null);
        [$text] = $this->rows($id);
        $this->assertSame([], ArticleService::publishErrors($id));

        (new RichTextRepository())->upsertSection((string) $text['page_slug'], (string) $text['section_key'], ['is_active' => false]);
        RichTextContent::clearCache();
        $this->assertCount(1, ArticleService::publishErrors($id), 'a block switched off in its own editor is not shown');
    }

    public function testTheCmsSaysWhyAndKeepsWhatWasTyped(): void
    {
        [$editor, $csrf] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
        $id = $this->article('published', '-1 day');
        [$text] = $this->rows($id);
        $key = $text['page_slug'] . ':' . $text['section_key'];
        $message = 'Dit artikel is niet meer in concept';

        $delete = self::$server->request('POST', '/api/admin/delete-page-section.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $text['id']]);
        $this->assertSame('/admin/article.php?id=' . $id . '&tab=inhoud', $delete['location'], 'back to the blocks, not "deleted"');
        $this->assertStringContainsString($message, $this->page($delete['location'], $editor));

        $hide = self::$server->request('POST', '/api/admin/toggle-page-section.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $text['id'], 'is_active' => '0']);
        $this->assertStringContainsString($message, $this->page($hide['location'], $editor));

        $save = self::$server->request('POST', '/api/admin/update-rich-text-section.php', $editor, [
            'csrf_token' => $csrf, 'section' => $key, 'language_code' => 'nl', RichTextContent::BODY => '', 'is_active' => '1',
        ]);
        $this->assertStringStartsWith('/admin/rich-text.php?section=', $save['location'], 'back to the editor');
        $this->assertStringContainsString($message, $this->page($save['location'], $editor));

        BlockLocalization::clearCache();
        RichTextContent::clearCache();
        $this->assertCount(1, $this->rows($id));
        $this->assertSame([], ArticleService::publishErrors($id), 'the stored article is untouched');
    }

    /* ------------------------------------------------------------------ */
    /* Deleting an article                                                 */
    /* ------------------------------------------------------------------ */

    public function testDeletingAnArticleRemovesEverythingItOwnsAndNoMore(): void
    {
        $id = $this->article('published', '-1 day', english: true);
        $this->textBlock($id, '<p>Tweede</p>');
        $page = ContentPages::pageFor(ArticleContentOwner::KIND, $id);
        $sectionIds = array_map(static fn (array $row): int => (int) $row['section_id'], $this->rows($id));

        $this->assertTrue(ArticleService::delete($id, $this->articles));

        $this->assertNull($this->articles->find($id));
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM article_translations WHERE article_id = ?', [$id]));
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM article_content_pages WHERE article_id = ?', [$id]));
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM pages WHERE id = ?', [(int) $page['id']]));
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM page_sections WHERE page_id = ?', [(int) $page['id']]));
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM rich_text_sections WHERE id IN (' . implode(',', $sectionIds) . ')'));
        BlockLocalization::clearCache();
        foreach ($sectionIds as $sectionId) {
            $this->assertSame([], BlockLocalization::translations('rich_text_sections', $sectionId), 'no block words left behind');
        }
        $this->assertFalse(ArticleService::delete($id, $this->articles), 'a second delete finds nothing');
    }

    public function testAFailureHalfwayThroughTheDeleteRollsEverythingBack(): void
    {
        $id = $this->article('published', '-1 day', english: true);
        $this->textBlock($id, '<p>Tweede</p>');
        $before = $this->snapshot($id);

        // A real database error after the blocks and the content page went,
        // where the article row would go.
        try {
            ContentPages::deleteOwner(ArticleContentOwner::KIND, $id, static function (): void {
                Database::connection()->exec('DELETE FROM zz_no_such_table_artint');
            });
            $this->fail('the failing delete was not reported');
        } catch (\PDOException) {
        }
        $this->assertSame($before, $this->snapshot($id), 'blocks, words, link, page and article all still there');

        // The article row itself failing: the same.
        $failing = new class () {
            public function __invoke(): void
            {
                throw new \RuntimeException('the article row could not be deleted');
            }
        };
        try {
            ContentPages::deleteOwner(ArticleContentOwner::KIND, $id, \Closure::fromCallable($failing));
            $this->fail('the failing delete was not reported');
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, $this->snapshot($id));
        $this->assertFalse(Database::connection()->inTransaction());

        // And it can still be deleted for real.
        $this->assertTrue(ArticleService::delete($id, $this->articles));
        $this->assertNull($this->articles->find($id));
    }

    public function testTheEndpointDeletesInOneGoAndTheEditorOnlyLosesTheBlockList(): void
    {
        [$editor, $csrf] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
        $id = $this->article('published', '-1 day');
        $page = ContentPages::pageFor(ArticleContentOwner::KIND, $id);

        $response = self::$server->request('POST', '/api/admin/delete-article.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $id]);
        $this->assertSame('/admin/articles.php', $response['location']);
        $this->assertNull($this->articles->find($id));
        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM pages WHERE id = ?', [(int) $page['id']]));
    }

    /* ------------------------------------------------------------------ */

    private function assertRefused(\Closure $change, string $why): void
    {
        try {
            $change();
            $this->fail('not refused: ' . $why);
        } catch (OwnerContentRequired $e) {
            $this->assertSame($e->getMessage(), OwnerContentGuard::messageFor($e), $why);
            $this->assertStringContainsString('Concept', $e->getMessage());
        }
    }

    private function article(string $status, ?string $relative, bool $english = false): int
    {
        $slug = self::PREFIX . bin2hex(random_bytes(4));
        $at = $relative === null ? null : (new \DateTimeImmutable($relative))->format(PublishingClock::SQL_FORMAT);

        $id = $this->articles->create(['status' => $status, 'published_at' => $at, 'author_name' => '', 'topic_id' => null, 'featured_media_id' => null]);
        $this->created[] = $id;
        ArticleLocalization::save($id, 'nl', [ArticleLocalization::SLUG => $slug, ArticleLocalization::TITLE => 'ZZ Integriteit', ArticleLocalization::EXCERPT => 'Intro']);
        if ($english) {
            ArticleLocalization::save($id, 'en', [ArticleLocalization::SLUG => $slug . '-en', ArticleLocalization::TITLE => 'ZZ Integrity']);
        }
        $this->textBlock($id, '<p>Eerste</p>');
        ArticleLocalization::clearCache();

        return $id;
    }

    private function textBlock(int $articleId, string $body): void
    {
        $page = ContentPages::ensure(ArticleContentOwner::KIND, $articleId);
        [$sectionId, $key] = SectionRegistry::create('rich_text', (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'rich_text', $key, $sectionId);
        BlockLocalization::save('rich_text_sections', $sectionId, 'nl', [RichTextContent::BODY => $body]);
        BlockLocalization::save('rich_text_sections', $sectionId, 'en', [RichTextContent::BODY => $body . ' (en)']);
        RichTextContent::clearCache();
    }

    private function spacer(int $articleId): void
    {
        $page = ContentPages::ensure(ArticleContentOwner::KIND, $articleId);
        [$sectionId, $key] = SectionRegistry::create('spacer', (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'spacer', $key, $sectionId);
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $articleId): array
    {
        $page = ContentPages::pageFor(ArticleContentOwner::KIND, $articleId);

        return $page === null ? [] : (new PageSectionRepository())->findForPage((int) $page['id']);
    }

    /**
     * Everything an article owns, as stored.
     *
     * @return array<string, mixed>
     */
    private function snapshot(int $articleId): array
    {
        $db = Database::connection();
        $page = ContentPages::pageFor(ArticleContentOwner::KIND, $articleId);
        $pageId = (int) ($page['id'] ?? 0);
        $rows = $this->rows($articleId);
        $textIds = array_map(static fn (array $row): int => (int) $row['section_id'], array_filter($rows, static fn (array $row): bool => $row['section_type'] === 'rich_text'));
        $in = $textIds === [] ? '0' : implode(',', $textIds);
        $words = [];
        foreach ($textIds as $textId) {
            BlockLocalization::clearCache();
            $words[$textId] = BlockLocalization::translations('rich_text_sections', $textId);
        }

        return [
            'article' => $this->articles->find($articleId),
            'translations' => $this->rowCount('SELECT COUNT(*) FROM article_translations WHERE article_id = ?', [$articleId]),
            'link' => $this->rowCount('SELECT COUNT(*) FROM article_content_pages WHERE article_id = ? AND page_id = ?', [$articleId, $pageId]),
            'page' => $this->rowCount('SELECT COUNT(*) FROM pages WHERE id = ?', [$pageId]),
            'sections' => array_map(static fn (array $row): array => [$row['id'], $row['section_type'], $row['is_active']], $rows),
            'texts' => $db->query('SELECT id, is_active FROM rich_text_sections WHERE id IN (' . $in . ') ORDER BY id')->fetchAll(),
            'words' => $words,
        ];
    }

    /** @param list<int> $params */
    private function rowCount(string $sql, array $params = []): int
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function page(string $location, string $session): string
    {
        $response = self::$server->request('GET', $location, $session);
        $this->assertSame(200, $response['status'], $location);

        return $response['body'];
    }
}
