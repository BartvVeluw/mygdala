<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Database;
use App\Module\BlogModule;
use App\Repository\BlogPostRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogContentMode;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogPostContentOwner;
use App\Service\Blog\BlogSearchProvider;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
use App\Service\RichTextContent;
use App\Service\Search\SearchQuery;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Blog 2.0 over real HTTP (BLOG.md): Gearchiveerd from the Publishing Engine
 * on every public surface, the explicit content mode with the classic body
 * kept intact, a post as an owner of the ordinary block engine — adding,
 * cancelling, saving, ordering, deleting, permissions, deleting the post —
 * and the conversion both ways without losing a word.
 *
 * Posts, taxonomy, blocks, content pages and accounts are this test's own
 * and are removed again by exact id.
 */
final class BlogTwoTest extends TestCase
{
    private const PREFIX = 'zz-b2-';

    private static ?BuiltInServer $server = null;

    private BlogPostRepository $posts;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $created = [];

    /** @var list<int> */
    private array $categories = [];

    /** @var list<int> */
    private array $tags = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start([], 'tests/Support/dispatcher-router.php');
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

        $this->posts = new BlogPostRepository();
        $this->accounts = new AdminTestSession();
        self::assertSame('nl', BlogLocalization::defaultLanguage());
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            ContentPages::deleteFor(BlogPostContentOwner::KIND, $id);
            $this->posts->delete($id);
        }
        $db = Database::connection();
        foreach ($this->categories as $id) {
            $db->prepare('DELETE FROM blog_categories WHERE id = ?')->execute([$id]);
        }
        foreach ($this->tags as $id) {
            $db->prepare('DELETE FROM blog_tags WHERE id = ?')->execute([$id]);
        }
        $this->created = $this->categories = $this->tags = [];

        $this->accounts->forget();
        BlogLocalization::clearCache();
        BlockLocalization::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Gearchiveerd                                                        */
    /* ------------------------------------------------------------------ */

    public function testAnArchivedPostAnswersAtItsAddressAsNoindexAndIsListedNowhere(): void
    {
        [$category, $categorySlug] = $this->category();
        [$tag, $tagSlug] = $this->tag();
        $listed = $this->post('Blijft in de lijst', 'published', '-2 days', categories: [$category], tags: [$tag]);
        $archived = $this->post('Archief Zichtbaar Woord', 'archived', '-1 day', categories: [$category], tags: [$tag]);
        $slug = $this->slug($archived);

        $page = $this->get('/blog/' . $slug);
        $this->assertSame(200, $page['status'], 'its own address still answers');
        $this->assertMatchesRegularExpression('#<meta name="robots" content="noindex#', $page['body']);
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]*/blog/' . preg_quote($slug, '#') . '">#', $page['body'], 'canonical to its own address');

        foreach (['/blog', '/blog/categorie/' . $categorySlug, '/blog/tag/' . $tagSlug, '/blog/feed.xml'] as $listing) {
            $body = $this->get($listing)['body'];
            $this->assertStringContainsString($this->slug($listed), $body, $listing . ' lists the published one');
            $this->assertStringNotContainsString($slug, $body, $listing . ' leaves the archived one out');
        }

        $this->assertStringNotContainsString($slug, json_encode((new BlogModule())->sitemapCollectors()['blog']()), 'not in the sitemap');
        $this->assertStringNotContainsString('Archief Zichtbaar Woord', json_encode((new BlogSearchProvider())->documents(SearchQuery::fromInput('Archief Zichtbaar Woord'), 'nl', 10)), 'not in search');
        $this->assertStringNotContainsString($slug, $this->get('/blog/' . $this->slug($listed))['body'], 'not among related posts or neighbours');

        // Back to published: the same address takes part again, no redirect anywhere.
        $this->posts->update($archived, ['status' => 'published']);
        $this->assertStringContainsString($slug, $this->get('/blog')['body']);
        $again = $this->get('/blog/' . $slug);
        $this->assertSame(200, $again['status']);
        $this->assertDoesNotMatchRegularExpression('#<meta name="robots" content="noindex#', $again['body']);
    }

    public function testDraftsAndFuturePostsAreNotReachable(): void
    {
        $this->assertSame(404, $this->get('/blog/' . $this->slug($this->post('Concept', 'draft', '-1 day')))['status']);
        $this->assertSame(404, $this->get('/blog/' . $this->slug($this->post('Later', 'scheduled', '+1 day')))['status']);
        $this->assertSame(404, $this->get('/blog/' . $this->slug($this->post('Later archief', 'archived', '+1 day')))['status'], 'archived before it ever went out');
        $this->assertSame(200, $this->get('/blog/' . $this->slug($this->post('Voorbij', 'scheduled', '-1 minute')))['status']);
    }

    public function testTheEditorOffersGearchiveerdAndSavesItThroughTheSharedRules(): void
    {
        [$editor, $csrf] = $this->accounts->signIn([BlogModule::BLOG_MANAGE]);
        $id = $this->post('Opslaan als archief', 'published', '-3 days');
        $post = $this->posts->find($id);

        $saved = self::$server->request('POST', '/api/admin/update-blog-post.php', $editor, $this->editorFields($id, $csrf, [
            'status' => 'archived', 'published_at' => BlogClock::forFormInput($post['published_at']),
        ]));
        $this->assertSame('/admin/blog-post.php?id=' . $id . '&updated=1', $saved['location']);
        $this->assertSame('archived', $this->posts->find($id)['status']);

        $refused = self::$server->request('POST', '/api/admin/update-blog-post.php', $editor, $this->editorFields($id, $csrf, ['status' => 'deleted']));
        $this->assertSame('/admin/blog-post.php?id=' . $id, $refused['location'], 'a forged status is refused');
        $this->assertSame('archived', $this->posts->find($id)['status']);

        $overview = $this->get('/admin/blog.php?status=archived', $editor)['body'];
        $this->assertStringContainsString('Opslaan als archief', $overview, 'the overview filters on Gearchiveerd');
    }

    /* ------------------------------------------------------------------ */
    /* Classic body and content blocks                                     */
    /* ------------------------------------------------------------------ */

    public function testAClassicPostKeepsItsBodyAndABlockCannotHideIt(): void
    {
        $id = $this->post('Klassiek', 'published', '-1 day', body: '<p>De <strong>klassieke</strong> tekst.</p>');
        $this->assertSame(BlogContentMode::LEGACY, BlogContentMode::of($this->posts->find($id)), 'a post written without a mode is classic');

        $before = $this->get('/blog/' . $this->slug($id))['body'];
        $this->assertStringContainsString('<div class="rich-content blog-post__body" data-reveal><p>De <strong>klassieke</strong> tekst.</p></div>', $before);

        // A block on its content page, while the post is classic: nothing changes.
        $this->textBlock($id, '<p>Een blok.</p>');
        $after = $this->get('/blog/' . $this->slug($id))['body'];
        $this->assertSame($before, $after, 'the mode decides, not the existence of blocks');
    }

    public function testANewPostIsWrittenInBlocksAndTheirLifecycleIsTheOwnersOwn(): void
    {
        [$editor, $csrf] = $this->accounts->signIn([BlogModule::BLOG_MANAGE]);

        $created = self::$server->request('POST', '/api/admin/create-blog-post.php', $editor, ['csrf_token' => $csrf, 'title' => 'ZZ B2 Nieuw met blokken']);
        parse_str((string) parse_url($created['location'], PHP_URL_QUERY), $query);
        $id = (int) $query['id'];
        $this->created[] = $id;
        $this->assertSame(BlogContentMode::BLOCKS, $this->posts->find($id)['content_mode']);
        $this->assertInstanceOf(BlogPostContentOwner::class, ContentOwners::getEnabled('blog_post'));

        $tab = $this->get('/admin/blog-post.php?id=' . $id . '&tab=inhoud', $editor);
        $this->assertSame(200, $tab['status']);
        $this->assertStringContainsString('name="content_owner" value="blog_post"', $tab['body'], 'the block picker of the post');
        $this->assertStringNotContainsString('name="body"', $tab['body'], 'no classic body field in blocks mode');

        // 1. Add, then cancel: no block and no orphan content page.
        $key = $this->added(self::$server->request('POST', '/api/admin/add-page-section.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'blog_post', 'content_owner_id' => (string) $id, 'section_type' => 'rich_text',
        ]));
        $cancel = self::$server->request('POST', '/api/admin/discard-block-draft.php', $editor, ['csrf_token' => $csrf, 'section_type' => 'rich_text', 'section' => $key]);
        $this->assertSame('/admin/blog-post.php?id=' . $id . '&tab=inhoud', $cancel['location']);
        $this->assertNull(ContentPages::pageFor(BlogPostContentOwner::KIND, $id), 'a cancelled first block leaves no content page');

        // 2. Add two and save them: the first save places each.
        $first = $this->added(self::$server->request('POST', '/api/admin/add-page-section.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'blog_post', 'content_owner_id' => (string) $id, 'section_type' => 'rich_text',
        ]));
        $this->saveText($editor, $csrf, $first, '<p>Eerste <em>blok</em> &lt;script&gt;</p>');
        $page = ContentPages::pageFor(BlogPostContentOwner::KIND, $id);
        $second = $this->added(self::$server->request('POST', '/api/admin/add-page-section.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_type' => 'rich_text',
        ]));
        $this->saveText($editor, $csrf, $second, '<p>Tweede blok</p>');
        $rows = (new PageSectionRepository())->findForPage((int) $page['id']);
        $this->assertCount(2, $rows);

        // 3. Publish and read: blocks in order, escaped as the block does it.
        $this->posts->update($id, ['status' => 'published', 'published_at' => (new \DateTimeImmutable('-1 minute'))->format(BlogClock::SQL_FORMAT)]);
        $public = $this->get('/blog/' . $this->slug($id))['body'];
        $this->assertLessThan(strpos($public, 'Tweede blok'), strpos($public, 'Eerste <em>blok</em>'));
        $this->assertStringContainsString('&lt;script&gt;', $public, 'typed markup stays text');
        $this->assertStringNotContainsString('</em> <script>', $public);
        $this->assertStringNotContainsString('blog-post__body', $public, 'no classic body section in blocks mode');

        // 4. Reorder and delete one.
        $this->assertSame(200, self::$server->request('POST', '/api/admin/reorder-page-sections.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_ids' => $rows[1]['id'] . ',' . $rows[0]['id'],
        ])['status']);
        $public = $this->get('/blog/' . $this->slug($id))['body'];
        $this->assertLessThan(strpos($public, 'Eerste <em>blok</em>'), strpos($public, 'Tweede blok'));
        $deleted = self::$server->request('POST', '/api/admin/delete-page-section.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $rows[1]['id']]);
        $this->assertSame('/admin/blog-post.php?id=' . $id . '&tab=inhoud&deleted=1', $deleted['location']);
        $this->assertCount(1, (new PageSectionRepository())->findForPage((int) $page['id']));

        // 5. Deleting the post takes its blocks, drafts and content page along.
        $sectionId = (int) $rows[0]['section_id'];
        $gone = self::$server->request('POST', '/api/admin/delete-blog-post.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $id]);
        $this->assertSame('/admin/blog.php', $gone['location']);
        $this->assertNull($this->posts->find($id));
        $this->assertNull((new \App\Repository\PageRepository())->findById((int) $page['id']), 'the content page is gone');
        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM page_sections WHERE page_id = ' . (int) $page['id'])->fetchColumn());
        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM rich_text_sections WHERE id = ' . $sectionId)->fetchColumn());
        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM blog_post_content_pages WHERE blog_post_id = ' . $id)->fetchColumn());
        $this->created = array_values(array_diff($this->created, [$id]));
    }

    public function testOnlyBlogManagersReachAPostsBlocks(): void
    {
        $id = $this->post('Rechten', 'draft', null);
        [$sectionId, $key, $textId] = $this->textBlock($id, '<p>Van de blog</p>');
        $page = ContentPages::pageFor(BlogPostContentOwner::KIND, $id);
        [$pages, $csrf] = $this->accounts->signIn(['pages.manage', 'products.manage', 'portfolio.manage']);

        $this->assertSame(403, $this->get('/admin/rich-text.php?section=' . urlencode($key), $pages)['status']);
        $this->assertSame(403, self::$server->request('POST', '/api/admin/update-rich-text-section.php', $pages, [
            'csrf_token' => $csrf, 'section' => $key, 'language_code' => 'nl', RichTextContent::BODY => '<p>Overschreven</p>',
        ])['status'], 'a forged content key');
        $this->assertSame(403, self::$server->request('POST', '/api/admin/delete-page-section.php', $pages, ['csrf_token' => $csrf, 'id' => (string) $sectionId])['status'], 'a forged block id');
        $this->assertSame(403, self::$server->request('POST', '/api/admin/add-page-section.php', $pages, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'blog_post', 'content_owner_id' => (string) $id, 'section_type' => 'spacer',
        ])['status'], 'the owner by its kind');
        $this->assertSame(403, self::$server->request('POST', '/api/admin/add-page-section.php', $pages, [
            'csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_type' => 'spacer',
        ])['status'], 'the holder page by its id');

        [$blog, $blogCsrf] = $this->accounts->signIn([BlogModule::BLOG_MANAGE]);
        $this->assertSame(404, self::$server->request('POST', '/api/admin/add-page-section.php', $blog, [
            'csrf_token' => $blogCsrf, 'page_id' => '0', 'content_owner' => 'blog_post', 'content_owner_id' => '999999999', 'section_type' => 'spacer',
        ])['status'], 'a forged post id');
        $this->assertSame(404, self::$server->request('POST', '/api/admin/add-page-section.php', $blog, [
            'csrf_token' => $blogCsrf, 'page_id' => '0', 'content_owner' => 'blog_posts', 'content_owner_id' => (string) $id, 'section_type' => 'spacer',
        ])['status'], 'a forged owner kind');
        $this->assertCount(1, (new PageSectionRepository())->findForPage((int) $page['id']));
        BlockLocalization::clearCache();
        $this->assertSame('<p>Van de blog</p>', BlockLocalization::raw('rich_text_sections', $textId, RichTextContent::BODY, 'nl'));
    }

    public function testConvertingKeepsEveryWordAndGoingBackIsExact(): void
    {
        [$editor, $csrf] = $this->accounts->signIn([BlogModule::BLOG_MANAGE]);
        $nl = '<p>Klassieke <strong>tekst</strong> met <a href="/contact">een link</a>.</p><h2>Kop</h2><ul><li>een</li></ul>';
        $en = '<p>Classic <em>text</em>.</p>';
        $id = $this->post('Omzetten', 'published', '-1 day', body: $nl, english: $en);
        $endpoint = '/api/admin/update-blog-post-content-mode.php';

        $this->assertSame(403, self::$server->request('POST', $endpoint, $editor, ['id' => (string) $id, 'content_mode' => 'blocks'])['status'], 'no token');
        $this->assertSame(400, self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $csrf, 'id' => (string) $id, 'content_mode' => 'magic'])['status'], 'a forged mode');
        $this->assertSame(404, self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $csrf, 'id' => '999999999', 'content_mode' => 'blocks'])['status']);
        [$pagesOnly, $pagesCsrf] = $this->accounts->signIn(['pages.manage']);
        $this->assertSame(403, self::$server->request('POST', $endpoint, $pagesOnly, ['csrf_token' => $pagesCsrf, 'id' => (string) $id, 'content_mode' => 'blocks'])['status']);
        $this->assertSame(BlogContentMode::LEGACY, $this->posts->find($id)['content_mode']);

        $editorPage = $this->get('/admin/blog-post.php?id=' . $id . '&tab=inhoud', $editor)['body'];
        $this->assertStringContainsString('data-admin-confirm-title="Bericht omzetten naar contentblokken?"', $editorPage, 'converting asks first');

        $converted = self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $csrf, 'id' => (string) $id, 'content_mode' => 'blocks']);
        $this->assertSame('/admin/blog-post.php?id=' . $id . '&tab=inhoud&updated=1', $converted['location']);
        $this->assertSame(BlogContentMode::BLOCKS, $this->posts->find($id)['content_mode']);

        $page = ContentPages::pageFor(BlogPostContentOwner::KIND, $id);
        $rows = (new PageSectionRepository())->findForPage((int) $page['id']);
        $this->assertCount(1, $rows);
        $this->assertSame('rich_text', $rows[0]['section_type']);
        BlockLocalization::clearCache();
        $this->assertSame($nl, BlockLocalization::raw('rich_text_sections', (int) $rows[0]['section_id'], RichTextContent::BODY, 'nl'), 'the Dutch HTML, exactly');
        $this->assertSame($en, BlockLocalization::raw('rich_text_sections', (int) $rows[0]['section_id'], RichTextContent::BODY, 'en'), 'the English HTML, exactly');
        BlogLocalization::clearCache();
        $this->assertSame($nl, BlogLocalization::rawPost($id, BlogLocalization::BODY, 'nl'), 'the classic body is kept');

        $public = $this->get('/blog/' . $this->slug($id))['body'];
        $this->assertStringContainsString($nl, $public, 'the same text, now in its block');
        $this->assertStringContainsString($en, $this->get('/en/blog/' . $this->slug($id) . '-en')['body']);

        // A save in blocks mode leaves the classic body alone.
        self::$server->request('POST', '/api/admin/update-blog-post.php', $editor, $this->editorFields($id, $csrf, ['title' => 'Omzetten bewaard']));
        BlogLocalization::clearCache();
        $this->assertSame($nl, BlogLocalization::rawPost($id, BlogLocalization::BODY, 'nl'));

        // Back, and forth again: no second copy of the text.
        self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $csrf, 'id' => (string) $id, 'content_mode' => 'legacy']);
        $this->assertSame(BlogContentMode::LEGACY, $this->posts->find($id)['content_mode']);
        $this->assertStringContainsString('<div class="rich-content blog-post__body" data-reveal>' . $nl . '</div>', $this->get('/blog/' . $this->slug($id))['body']);
        self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $csrf, 'id' => (string) $id, 'content_mode' => 'blocks']);
        $this->assertCount(1, (new PageSectionRepository())->findForPage((int) $page['id']), 'its blocks came back; nothing was duplicated');
    }

    public function testHreflangNamesOnlyRealTranslations(): void
    {
        $both = $this->post('Twee talen', 'published', '-1 day', english: '<p>EN</p>');
        $one = $this->post('Een taal', 'published', '-1 day');

        $this->assertMatchesRegularExpression('#hreflang="en" href="[^"]*/en/blog/' . preg_quote($this->slug($both), '#') . '-en"#', $this->get('/blog/' . $this->slug($both))['body']);
        $this->assertStringNotContainsString('hreflang="en"', $this->get('/blog/' . $this->slug($one))['body'], 'no English URL for a post without one');
        $this->assertSame(404, $this->get('/en/blog/' . $this->slug($one))['status'], 'an address never falls back to another language');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param list<int> $categories
     * @param list<int> $tags
     */
    private function post(string $title, string $status, ?string $relative, string $body = '<p>Tekst</p>', ?string $english = null, array $categories = [], array $tags = []): int
    {
        static $n = 0;
        $n++;
        $slug = self::PREFIX . $n . '-' . bin2hex(random_bytes(3));
        $at = $relative === null ? null : (new \DateTimeImmutable($relative))->format(BlogClock::SQL_FORMAT);

        $id = $this->posts->create(['slug' => $slug, 'status' => $status, 'published_at' => $at, 'author_name' => 'Testauteur', 'noindex' => 0]);
        $this->created[] = $id;

        BlogLocalization::savePost($id, 'nl', [BlogLocalization::SLUG => $slug, BlogLocalization::TITLE => $title, BlogLocalization::EXCERPT => 'Samenvatting', BlogLocalization::BODY => $body]);
        if ($english !== null) {
            BlogLocalization::savePost($id, 'en', [BlogLocalization::SLUG => $slug . '-en', BlogLocalization::TITLE => 'EN ' . $title, BlogLocalization::BODY => $english]);
        }
        if ($categories !== []) {
            $this->posts->setCategories($id, $categories);
        }
        if ($tags !== []) {
            $this->posts->setTags($id, $tags);
        }
        BlogLocalization::clearCache();

        return $id;
    }

    /** @return array{0: int, 1: string} */
    private function category(): array
    {
        $slug = self::PREFIX . 'cat-' . bin2hex(random_bytes(3));
        $db = Database::connection();
        $db->prepare('INSERT INTO blog_categories (slug, is_active, sort_order, created_at, updated_at) VALUES (?, 1, 1, NOW(), NOW())')->execute([$slug]);
        $id = (int) $db->lastInsertId();
        $this->categories[] = $id;
        $db->prepare("INSERT INTO blog_category_translations (blog_category_id, language_code, slug, name, created_at, updated_at) VALUES (?, 'nl', ?, 'ZZ Categorie', NOW(), NOW())")->execute([$id, $slug]);

        return [$id, $slug];
    }

    /** @return array{0: int, 1: string} */
    private function tag(): array
    {
        $slug = self::PREFIX . 'tag-' . bin2hex(random_bytes(3));
        $db = Database::connection();
        $db->prepare('INSERT INTO blog_tags (slug, created_at, updated_at) VALUES (?, NOW(), NOW())')->execute([$slug]);
        $id = (int) $db->lastInsertId();
        $this->tags[] = $id;
        $db->prepare("INSERT INTO blog_tag_translations (blog_tag_id, language_code, slug, name, created_at, updated_at) VALUES (?, 'nl', ?, 'zztag', NOW(), NOW())")->execute([$id, $slug]);

        return [$id, $slug];
    }

    /** A saved text block on the post's content page, made the way the picker and a first save do. @return array{0: int, 1: string, 2: int} page_sections id, key and text block id */
    private function textBlock(int $postId, string $body): array
    {
        $page = ContentPages::ensure(BlogPostContentOwner::KIND, $postId);
        [$sectionId, $key] = \App\Service\SectionRegistry::create('rich_text', (string) $page['content_key']);
        $pageSectionId = (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'rich_text', $key, $sectionId);
        BlockLocalization::save('rich_text_sections', $sectionId, 'nl', [RichTextContent::BODY => $body]);
        RichTextContent::clearCache();

        return [$pageSectionId, $page['content_key'] . ':' . $key, (int) $sectionId];
    }

    /** @param array{status: int, location: string, body: string, headers: string} $response */
    private function added(array $response): string
    {
        $this->assertSame(302, $response['status'], $response['body']);
        $this->assertStringStartsWith('/admin/rich-text.php?section=', $response['location']);
        parse_str((string) parse_url($response['location'], PHP_URL_QUERY), $query);

        return (string) $query['section'];
    }

    private function saveText(string $session, string $csrf, string $key, string $body): void
    {
        $saved = self::$server->request('POST', '/api/admin/update-rich-text-section.php', $session, [
            'csrf_token' => $csrf, 'section' => $key, 'language_code' => 'nl', RichTextContent::BODY => $body, 'is_active' => '1',
        ]);
        $this->assertSame(302, $saved['status']);
        $this->assertStringStartsWith('/admin/blog-post.php?id=', $saved['location'], 'a saved block goes back to its post');
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function editorFields(int $id, string $csrf, array $overrides): array
    {
        $post = $this->posts->find($id);

        return $overrides + [
            'csrf_token' => $csrf,
            'id' => (string) $id,
            'language_code' => 'nl',
            'title' => BlogLocalization::rawPost($id, BlogLocalization::TITLE, 'nl'),
            'excerpt' => '',
            'slug' => (string) $post['slug'],
            'status' => (string) $post['status'],
            'published_at' => BlogClock::forFormInput($post['published_at']),
            'author_name' => '',
            'meta_title' => '',
            'meta_description' => '',
            'tags' => '',
            'noindex' => '0',
        ];
    }

    private function slug(int $id): string
    {
        return (string) $this->posts->find($id)['slug'];
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path, ?string $session = null): array
    {
        return self::$server->request('GET', $path, $session);
    }
}
