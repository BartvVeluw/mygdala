<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Module\ArticlesModule;
use App\Module\ModuleRegistry;
use App\Repository\ArticleRepository;
use App\Repository\ArticleTopicRepository;
use App\Repository\PageSectionRepository;
use App\Service\Articles\ArticleContentOwner;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticlePublishable;
use App\Service\Articles\ArticleSearchProvider;
use App\Service\Articles\ArticleService;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
use App\Service\Media\LinkedImages;
use App\Service\Media\MediaUsageRegistry;
use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\Publishables;
use App\Service\Publishing\PublishingClock;
use App\Service\RichTextContent;
use App\Service\Routing\LinkTargets;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchService;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Articles 1.0 over real HTTP and in-process (ARTICLES.md): the CMS from
 * creating to deleting, the Publishing Engine's four statuses on every
 * public surface, the blocks of an article as an owner of the ordinary block
 * engine, routes and redirects per language, topics, the integrations
 * (sitemap, search, link targets, linked images, media usage), the module
 * switched off and on, security, and a short Blog regression.
 *
 * Every article, topic, media row, block, content page, redirect and account
 * is this test's own and removed again by exact id.
 */
final class ArticlesTest extends TestCase
{
    private const PREFIX = 'zz-art-';

    private static ?BuiltInServer $server = null;

    private ArticleRepository $articles;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $created = [];

    /** @var list<int> */
    private array $topics = [];

    /** @var list<int> */
    private array $media = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_ARTICLES_ENABLED' => 'true', 'MODULE_BLOG_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
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
        if (!ModuleRegistry::isEnabled('articles')) {
            $this->markTestSkipped('Articles is off in this process (MODULE_ARTICLES_ENABLED)');
        }

        $this->articles = new ArticleRepository();
        $this->accounts = new AdminTestSession();
        self::assertSame('nl', ArticleLocalization::defaultLanguage());
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        PublishingClock::freezeForTests(null);
        $db = Database::connection();

        foreach ($this->created as $id) {
            ContentPages::deleteFor(ArticleContentOwner::KIND, $id);
            $this->articles->delete($id);
        }
        foreach ($this->topics as $id) {
            $db->prepare('DELETE FROM article_topics WHERE id = ?')->execute([$id]);
        }
        foreach ($this->media as $id) {
            $db->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
        }
        $db->exec("DELETE FROM redirects WHERE source_path LIKE '%/" . self::PREFIX . "%'");
        $this->created = $this->topics = $this->media = [];

        $this->accounts->forget();
        ArticleLocalization::clearCache();
        BlockLocalization::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The CMS                                                             */
    /* ------------------------------------------------------------------ */

    public function testAnArticleIsCreatedWrittenInBlocksPublishedTranslatedAndDeleted(): void
    {
        [$editor, $csrf] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);
        $topic = $this->topic('Onderhoud');
        $image = $this->mediaRow();

        // 1. Create: a draft with a title and an address in the default language.
        $created = self::$server->request('POST', '/api/admin/create-article.php', $editor, ['csrf_token' => $csrf, 'title' => 'ZZ Art Nieuw artikel']);
        parse_str((string) parse_url($created['location'], PHP_URL_QUERY), $query);
        $id = (int) ($query['id'] ?? 0);
        $this->assertGreaterThan(0, $id, $created['location']);
        $this->created[] = $id;
        $this->assertSame(PublicationStatus::DRAFT, $this->articles->find($id)['status']);
        $this->assertSame('zz-art-nieuw-artikel', ArticleLocalization::slug($id, 'nl'));

        $page = $this->get('/admin/article.php?id=' . $id, $editor);
        $this->assertSame(200, $page['status']);
        foreach (['name="status"', 'value="archived"', 'name="author_name"', 'name="topic_id"', 'name="featured_media_id"', 'name="meta_title"', 'name="noindex"'] as $field) {
            $this->assertStringContainsString($field, $page['body']);
        }
        $this->assertStringContainsString('name="content_owner" value="article"', $this->get('/admin/article.php?id=' . $id . '&tab=inhoud', $editor)['body'], 'the block picker of the article');

        // 2. An empty article cannot go out; nothing of the refused save stays.
        $refused = self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($id, $csrf, [
            'status' => 'published', 'excerpt' => 'Mag niet blijven', 'author_name' => 'Inge van Veluw', 'topic_id' => (string) $topic, 'featured_media_id' => (string) $image,
        ]));
        $this->assertSame('/admin/article.php?id=' . $id, $refused['location']);
        $this->assertSame(PublicationStatus::DRAFT, $this->articles->find($id)['status']);
        ArticleLocalization::clearCache();
        $this->assertSame('', ArticleLocalization::word($id, ArticleLocalization::EXCERPT, 'nl'), 'a refused publish rolls the words back too');
        $this->assertNull($this->articles->find($id)['topic_id']);

        // 3. First block: cancel leaves no content page; save places it.
        $key = $this->added(self::$server->request('POST', '/api/admin/add-page-section.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'article', 'content_owner_id' => (string) $id, 'section_type' => 'rich_text',
        ]));
        $cancel = self::$server->request('POST', '/api/admin/discard-block-draft.php', $editor, ['csrf_token' => $csrf, 'section_type' => 'rich_text', 'section' => $key]);
        $this->assertSame('/admin/article.php?id=' . $id . '&tab=inhoud', $cancel['location']);
        $this->assertNull(ContentPages::pageFor(ArticleContentOwner::KIND, $id), 'a cancelled first block leaves no orphan page');

        $key = $this->added(self::$server->request('POST', '/api/admin/add-page-section.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'article', 'content_owner_id' => (string) $id, 'section_type' => 'rich_text',
        ]));
        $this->saveText($editor, $csrf, $key, '<p>De inhoud van het artikel.</p>');
        $this->assertNotNull(ContentPages::pageFor(ArticleContentOwner::KIND, $id));

        // 4. Now it may go out, with its byline, topic and image.
        $saved = self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($id, $csrf, [
            'status' => 'published', 'excerpt' => 'Een korte intro.', 'author_name' => 'Inge van Veluw', 'topic_id' => (string) $topic, 'featured_media_id' => (string) $image,
        ]));
        $this->assertSame('/admin/article.php?id=' . $id . '&updated=1', $saved['location']);
        $row = $this->articles->find($id);
        $this->assertSame(PublicationStatus::PUBLISHED, $row['status']);
        $this->assertNotNull($row['published_at'], 'published without a date is now');
        $this->assertSame($topic, (int) $row['topic_id']);
        $this->assertSame($image, (int) $row['featured_media_id']);

        $public = $this->get('/artikelen/zz-art-nieuw-artikel');
        $this->assertSame(200, $public['status']);
        foreach (['ZZ Art Nieuw artikel', 'Een korte intro.', 'Door Inge van Veluw', 'De inhoud van het artikel.', 'ZZ Onderhoud', '/assets/media/zz-art-'] as $text) {
            $this->assertStringContainsString($text, $public['body']);
        }
        $this->assertStringContainsString('"@type":"Article"', $public['body']);

        // 5. A translation: its own words and address, the Dutch one untouched.
        $english = self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($id, $csrf, [
            'language_code' => 'en', 'title' => 'ZZ Art New article', 'slug' => '', 'excerpt' => 'A short intro.', 'status' => 'published',
            'published_at' => PublishingClock::forFormInput($row['published_at']), 'topic_id' => (string) $topic, 'featured_media_id' => (string) $image, 'author_name' => 'Inge van Veluw',
        ]));
        $this->assertSame('/admin/article.php?id=' . $id . '&updated=1', $english['location']);
        ArticleLocalization::clearCache();
        $this->assertSame('zz-art-new-article', ArticleLocalization::slug($id, 'en'), 'a first address made from its own title');
        $this->assertSame('ZZ Art Nieuw artikel', ArticleLocalization::word($id, ArticleLocalization::TITLE, 'nl'));
        $en = $this->get('/en/articles/zz-art-new-article');
        $this->assertSame(200, $en['status']);
        $this->assertStringContainsString('A short intro.', $en['body']);
        $this->assertStringContainsString('<html lang="en"', $en['body']);

        $overview = $this->get('/admin/articles.php?status=published', $editor)['body'];
        $this->assertStringContainsString('ZZ Art Nieuw artikel', $overview);
        $this->assertStringNotContainsString('ZZ Art Nieuw artikel', $this->get('/admin/articles.php?status=draft', $editor)['body']);

        // 6. Delete: blocks, content page and words go; the image and the topic stay.
        $page = ContentPages::pageFor(ArticleContentOwner::KIND, $id);
        $gone = self::$server->request('POST', '/api/admin/delete-article.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $id]);
        $this->assertSame('/admin/articles.php', $gone['location']);
        $this->assertNull($this->articles->find($id));
        $db = Database::connection();
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM page_sections WHERE page_id = ' . (int) $page['id'])->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM pages WHERE id = ' . (int) $page['id'])->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM article_content_pages WHERE article_id = ' . $id)->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM article_translations WHERE article_id = ' . $id)->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM media WHERE id = ' . $image)->fetchColumn(), 'the library keeps its file');
        $this->assertNotNull((new ArticleTopicRepository())->find($topic));
        $this->assertSame(404, $this->get('/artikelen/zz-art-nieuw-artikel')['status']);
        $this->created = [];
    }

    public function testBlocksKeepTheirOrderTheirLooksAndCanBeRemoved(): void
    {
        [$editor, $csrf] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);
        $id = $this->article('Blokken', 'published', '-1 day', body: '<p>Eerste blok</p>');
        $this->textBlock($id, '<p>Tweede blok</p>');
        $page = ContentPages::pageFor(ArticleContentOwner::KIND, $id);
        $rows = (new PageSectionRepository())->findForPage((int) $page['id']);
        $this->assertCount(2, $rows);

        $body = $this->get('/artikelen/' . $this->slug($id))['body'];
        $this->assertLessThan(strpos($body, 'Tweede blok'), strpos($body, 'Eerste blok'));

        $this->assertSame(200, self::$server->request('POST', '/api/admin/reorder-page-sections.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_ids' => $rows[1]['id'] . ',' . $rows[0]['id'],
        ])['status']);
        $body = $this->get('/artikelen/' . $this->slug($id))['body'];
        $this->assertLessThan(strpos($body, 'Eerste blok'), strpos($body, 'Tweede blok'));

        // Extra vormgeving on a block of an article: the ordinary appearance columns.
        (new PageSectionRepository())->updateAppearance((int) $rows[0]['id'], ['background' => 'page', 'border' => 'default', 'border_tone' => 'subtle', 'spacing' => 'default', 'decoration' => 'none']);
        $this->assertStringContainsString('block-appearance--bg-page', $this->get('/artikelen/' . $this->slug($id))['body']);

        $deleted = self::$server->request('POST', '/api/admin/delete-page-section.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $rows[1]['id']]);
        $this->assertSame('/admin/article.php?id=' . $id . '&tab=inhoud&deleted=1', $deleted['location']);
        $body = $this->get('/artikelen/' . $this->slug($id))['body'];
        $this->assertStringNotContainsString('Tweede blok', $body);
        $this->assertStringContainsString('Eerste blok', $body);
    }

    public function testThePublishRuleWantsATitleAnAddressAndABlockThatSaysSomething(): void
    {
        $id = $this->article('Regel', 'draft', null, body: null);
        $this->assertCount(1, ArticleService::publishErrors($id), 'only the blocks are missing');

        $this->spacer($id);
        $this->assertCount(1, ArticleService::publishErrors($id), 'a spacer says nothing');

        [, , $textId] = $this->textBlock($id, '');
        $this->assertCount(1, ArticleService::publishErrors($id), 'an empty text says nothing');

        BlockLocalization::save('rich_text_sections', $textId, 'nl', [RichTextContent::BODY => '<p>Nu wel</p>']);
        RichTextContent::clearCache();
        $this->assertSame([], ArticleService::publishErrors($id));

        ArticleLocalization::save($id, 'nl', [ArticleLocalization::TITLE => '']);
        $this->assertCount(1, ArticleService::publishErrors($id), 'no title in the default language');

        // The engine's own endpoint asks the same rule.
        [$editor, $csrf] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);
        self::$server->request('POST', '/api/admin/update-publication.php', $editor, ['csrf_token' => $csrf, 'type' => 'article', 'id' => (string) $id, 'status' => 'published', 'published_at' => '']);
        $this->assertSame(PublicationStatus::DRAFT, $this->articles->find($id)['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Publishing                                                          */
    /* ------------------------------------------------------------------ */

    public function testEveryStatusAnswersOnEveryPublicSurfaceAsTheEngineSays(): void
    {
        $topic = $this->topic('Status');
        $published = $this->article('Gepubliceerd', 'published', '-2 days', topic: $topic);
        $draft = $this->article('Concept', 'draft', '-1 day', topic: $topic);
        $future = $this->article('Toekomst', 'scheduled', '+1 day', topic: $topic);
        $elapsed = $this->article('Verstreken', 'scheduled', '-1 minute', topic: $topic);
        $archived = $this->article('Archief', 'archived', '-3 days', topic: $topic);
        $archivedEarly = $this->article('Archief later', 'archived', '+1 day', topic: $topic);

        $this->assertSame(200, $this->get('/artikelen/' . $this->slug($published))['status']);
        $this->assertSame(404, $this->get('/artikelen/' . $this->slug($draft))['status']);
        $this->assertSame(404, $this->get('/artikelen/' . $this->slug($future))['status']);
        $this->assertSame(200, $this->get('/artikelen/' . $this->slug($elapsed))['status']);
        $this->assertSame(404, $this->get('/artikelen/' . $this->slug($archivedEarly))['status'], 'archived before it ever went out');

        $archivedPage = $this->get('/artikelen/' . $this->slug($archived));
        $this->assertSame(200, $archivedPage['status'], 'an archived article keeps its address');
        $this->assertMatchesRegularExpression('#<meta name="robots" content="noindex#', $archivedPage['body']);
        $this->assertDoesNotMatchRegularExpression('#<meta name="robots" content="noindex#', $this->get('/artikelen/' . $this->slug($published))['body']);

        $listed = [$published, $elapsed];
        $hidden = [$draft, $future, $archived, $archivedEarly];
        $sitemap = json_encode((new ArticlesModule())->sitemapCollectors()['articles']());
        $topicSlug = (string) ArticleLocalization::topicSlug($topic, 'nl');

        foreach (['listing' => $this->get('/artikelen')['body'], 'topic' => $this->get('/artikelen/onderwerp/' . $topicSlug)['body'], 'sitemap' => $sitemap] as $surface => $body) {
            foreach ($listed as $id) {
                $this->assertStringContainsString($this->slug($id), $body, $surface . ' lists ' . $this->slug($id));
            }
            foreach ($hidden as $id) {
                $this->assertStringNotContainsString($this->slug($id), $body, $surface . ' leaves out ' . $this->slug($id));
            }
        }

        $search = json_encode((new ArticleSearchProvider())->documents(SearchQuery::fromInput('ZZ Art'), 'nl', 50));
        $this->assertStringContainsString($this->slug($published), $search);
        foreach ($hidden as $id) {
            $this->assertStringNotContainsString($this->slug($id), $search, 'search leaves out ' . $this->slug($id));
        }

        // Link targets follow REACHABLE: a button to an archived article keeps working.
        $this->assertSame('/artikelen/' . $this->slug($archived), LinkTargets::href('article', $archived));
        $this->assertNull(LinkTargets::href('article', $draft));
        $this->assertNull(LinkTargets::href('article', $future));
        $this->assertSame(['status' => 'archived', 'published_at' => $this->articles->find($archived)['published_at']], Publishables::get('article')->publication($archived));
    }

    public function testAScheduledArticleAppearsWhenItsMomentPassesWithoutACron(): void
    {
        $id = $this->article('Klok', 'scheduled', '+2 hours');
        $this->assertNull((new ArticleRepository())->findReachableById($id, PublishingClock::nowForSql()));

        PublishingClock::freezeForTests(new \DateTimeImmutable('+3 hours'));
        $this->assertNotNull((new ArticleRepository())->findReachableById($id, PublishingClock::nowForSql()));
    }

    /* ------------------------------------------------------------------ */
    /* Routing                                                             */
    /* ------------------------------------------------------------------ */

    public function testAddressesPerLanguageWithCanonicalHreflangAndRedirects(): void
    {
        $both = $this->article('Twee talen', 'published', '-1 day', english: 'Two languages');
        $one = $this->article('Een taal', 'published', '-1 day');

        $nl = $this->get('/artikelen/' . $this->slug($both))['body'];
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]*/artikelen/' . preg_quote($this->slug($both), '#') . '">#', $nl);
        $this->assertMatchesRegularExpression('#hreflang="en" href="[^"]*/en/articles/' . preg_quote($this->slug($both, 'en'), '#') . '"#', $nl);
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]*/en/articles/' . preg_quote($this->slug($both, 'en'), '#') . '">#', $this->get('/en/articles/' . $this->slug($both, 'en'))['body']);

        $this->assertStringNotContainsString('hreflang="en"', $this->get('/artikelen/' . $this->slug($one))['body'], 'no English version, no English alternate');
        $this->assertSame(404, $this->get('/en/articles/' . $this->slug($one))['status'], 'an address never falls back');
        $this->assertSame(404, $this->get('/artikelen/' . $this->slug($both, 'en'))['status'], 'the English address is not a Dutch one');
        $english = $this->get('/en/articles')['body'];
        $this->assertStringContainsString($this->slug($both, 'en'), $english);
        $this->assertStringNotContainsString($this->slug($one), $english, 'the English listing shows English versions only');
        $this->assertStringNotContainsString('ZZ Art Een taal', $english);

        // Renaming a public article's address redirects its old URL, per language.
        [$editor, $csrf] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);
        $old = $this->slug($both);
        $row = $this->articles->find($both);
        self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($both, $csrf, [
            'slug' => $old . '-nieuw', 'status' => 'published', 'published_at' => PublishingClock::forFormInput($row['published_at']),
        ]));
        $moved = $this->get('/artikelen/' . $old);
        $this->assertSame(301, $moved['status']);
        $this->assertStringEndsWith('/artikelen/' . $old . '-nieuw', $moved['location']);

        // A draft's address was never live: renaming it writes no redirect.
        $draft = $this->article('Concept hernoemd', 'draft', null);
        $draftSlug = $this->slug($draft);
        self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($draft, $csrf, ['slug' => $draftSlug . '-x', 'status' => 'draft']));
        $this->assertSame(404, $this->get('/artikelen/' . $draftSlug)['status']);

        // A status change alone writes none either.
        $this->assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM redirects WHERE source_path = '/artikelen/" . $draftSlug . "-x'")->fetchColumn());
    }

    public function testTopicsHaveTheirOwnListingAddressAndRedirect(): void
    {
        [$editor, $csrf] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);
        $saved = self::$server->request('POST', '/api/admin/save-article-topic.php', $editor, ['csrf_token' => $csrf, 'id' => '0', 'name' => 'ZZ Art Gereedschap', 'sort_order' => '5']);
        $this->assertSame('/admin/article-topics.php', $saved['location']);
        $topic = (int) Database::connection()->query("SELECT article_topic_id FROM article_topic_translations WHERE name = 'ZZ Art Gereedschap'")->fetchColumn();
        $this->topics[] = $topic;
        $this->assertSame('zz-art-gereedschap', ArticleLocalization::topicSlug($topic, 'nl'));

        $in = $this->article('Over gereedschap', 'published', '-1 day', topic: $topic);
        $out = $this->article('Ergens anders', 'published', '-1 day');

        $page = $this->get('/artikelen/onderwerp/zz-art-gereedschap');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString($this->slug($in), $page['body']);
        $this->assertStringNotContainsString($this->slug($out), $page['body']);
        $this->assertStringContainsString('/artikelen/onderwerp/zz-art-gereedschap', $this->get('/artikelen')['body'], 'the topic row links it');
        $this->assertSame(404, $this->get('/artikelen/onderwerp/zz-art-bestaat-niet')['status']);
        $this->assertStringContainsString('ZZ Art Gereedschap', $this->get('/admin/article-topics.php', $editor)['body']);

        // Renamed: the old topic address redirects.
        self::$server->request('POST', '/api/admin/save-article-topic.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $topic, 'language_code' => 'nl', 'name' => 'ZZ Art Gereedschap', 'slug' => 'zz-art-werktuigen', 'sort_order' => '5']);
        $moved = $this->get('/artikelen/onderwerp/zz-art-gereedschap');
        $this->assertSame(301, $moved['status']);
        $this->assertStringEndsWith('/artikelen/onderwerp/zz-art-werktuigen', $moved['location']);

        // A forged topic id is refused, never stored.
        $row = $this->articles->find($in);
        $refused = self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($in, $csrf, [
            'topic_id' => '999999999', 'status' => 'published', 'published_at' => PublishingClock::forFormInput($row['published_at']),
        ]));
        $this->assertSame('/admin/article.php?id=' . $in, $refused['location']);
        $this->assertSame($topic, (int) $this->articles->find($in)['topic_id']);

        // Deleting the topic keeps its article.
        self::$server->request('POST', '/api/admin/delete-article-topic.php', $editor, ['csrf_token' => $csrf, 'id' => (string) $topic]);
        $this->assertNull((new ArticleTopicRepository())->find($topic));
        $this->assertNull($this->articles->find($in)['topic_id']);
        $this->assertSame(200, $this->get('/artikelen/' . $this->slug($in))['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Security                                                            */
    /* ------------------------------------------------------------------ */

    public function testPermissionsTokensForgeriesAndEscaping(): void
    {
        $id = $this->article('Beveiliging', 'published', '-1 day', body: null);
        [$blockId, $key] = $this->textBlock($id, '<p>Van het artikel</p>');
        $page = ContentPages::pageFor(ArticleContentOwner::KIND, $id);
        [$editor, $csrf] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);
        [$pages, $pagesCsrf] = $this->accounts->signIn(['pages.manage', 'blog.manage', 'products.manage', 'portfolio.manage']);

        // Not an article manager: no screen, no endpoint, no block of an article.
        foreach (['/admin/articles.php', '/admin/article.php?id=' . $id, '/admin/article-topics.php'] as $screen) {
            $this->assertSame(403, $this->get($screen, $pages)['status'], $screen);
        }
        foreach (['create-article.php', 'update-article.php', 'delete-article.php', 'save-article-topic.php', 'delete-article-topic.php'] as $endpoint) {
            $this->assertSame(403, self::$server->request('POST', '/api/admin/' . $endpoint, $pages, ['csrf_token' => $pagesCsrf, 'id' => (string) $id, 'title' => 'x'])['status'], $endpoint);
        }
        $this->assertSame(403, $this->get('/admin/rich-text.php?section=' . urlencode($key), $pages)['status']);
        $this->assertSame(403, self::$server->request('POST', '/api/admin/delete-page-section.php', $pages, ['csrf_token' => $pagesCsrf, 'id' => (string) $blockId])['status'], 'a forged block id');
        $this->assertSame(403, self::$server->request('POST', '/api/admin/add-page-section.php', $pages, [
            'csrf_token' => $pagesCsrf, 'page_id' => '0', 'content_owner' => 'article', 'content_owner_id' => (string) $id, 'section_type' => 'spacer',
        ])['status'], 'the owner by its kind');
        $this->assertSame(403, self::$server->request('POST', '/api/admin/update-publication.php', $pages, ['csrf_token' => $pagesCsrf, 'type' => 'article', 'id' => (string) $id, 'status' => 'draft', 'published_at' => ''])['status']);

        // No token, wrong method.
        $this->assertSame(403, self::$server->request('POST', '/api/admin/update-article.php', $editor, ['id' => (string) $id] + $this->fields($id, ''))['status']);
        $this->assertSame(405, self::$server->request('GET', '/api/admin/delete-article.php', $editor)['status']);

        // Forged ids, kinds and types.
        $this->assertSame(404, self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields(999999999, $csrf))['status']);
        $this->assertSame(404, self::$server->request('POST', '/api/admin/add-page-section.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'article', 'content_owner_id' => '999999999', 'section_type' => 'spacer',
        ])['status'], 'a forged article id');
        $this->assertSame(404, self::$server->request('POST', '/api/admin/add-page-section.php', $editor, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'articles', 'content_owner_id' => (string) $id, 'section_type' => 'spacer',
        ])['status'], 'a forged owner kind');
        $this->assertSame(404, self::$server->request('POST', '/api/admin/update-publication.php', $editor, ['csrf_token' => $csrf, 'type' => 'article ', 'id' => (string) $id, 'status' => 'draft', 'published_at' => ''])['status'], 'a forged type');
        $this->assertSame(404, self::$server->request('POST', '/api/admin/update-publication.php', $editor, ['csrf_token' => $csrf, 'type' => 'article', 'id' => '999999999', 'status' => 'draft', 'published_at' => ''])['status']);
        $this->assertSame(404, self::$server->request('POST', '/api/admin/save-article-topic.php', $editor, ['csrf_token' => $csrf, 'id' => '999999999', 'name' => 'x'])['status']);
        $this->assertNull(Publishables::find('article', 999999999));
        $this->assertCount(1, (new PageSectionRepository())->findForPage((int) $page['id']));

        // A forged status and slug are refused.
        $row = $this->articles->find($id);
        $base = ['published_at' => PublishingClock::forFormInput($row['published_at'])];
        self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($id, $csrf, ['status' => 'deleted'] + $base));
        $this->assertSame('published', $this->articles->find($id)['status']);
        $other = $this->article('Ander adres', 'draft', null);
        foreach (['onderwerp', 'topic', $this->slug($other)] as $slug) {
            self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($id, $csrf, ['slug' => $slug, 'status' => 'published'] + $base));
            ArticleLocalization::clearCache();
            $this->assertNotSame($slug, ArticleLocalization::slug($id, 'nl'), $slug . ' is refused');
        }

        // Everything a visitor or an editor typed is printed as text.
        $xss = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
        self::$server->request('POST', '/api/admin/update-article.php', $editor, $this->fields($id, $csrf, [
            'title' => 'ZZ Art ' . $xss, 'excerpt' => $xss, 'author_name' => $xss, 'meta_title' => $xss, 'meta_description' => $xss, 'status' => 'published',
        ] + $base));
        foreach (['/artikelen/' . $this->slug($id), '/artikelen'] as $path) {
            $body = $this->get($path)['body'];
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body, $path);
            $this->assertStringNotContainsString('<script>alert(1)', $body, $path);
            $this->assertStringNotContainsString('<img src=x', $body, $path);
        }
        foreach (['/admin/articles.php', '/admin/article.php?id=' . $id] as $path) {
            $body = $this->get($path, $editor)['body'];
            $this->assertStringNotContainsString('<script>alert(1)', $body, $path);
            $this->assertStringNotContainsString('<img src=x', $body, $path);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Integrations                                                        */
    /* ------------------------------------------------------------------ */

    public function testSitemapSearchLinkTargetsLinkedImagesAndMediaUsage(): void
    {
        $image = $this->mediaRow();
        $id = $this->article('Integraties Zoekwoord', 'published', '-1 day', english: 'Integrations Searchword', image: $image);
        $hidden = $this->article('Niet indexeren', 'published', '-1 day');
        $this->articles->update($hidden, ['noindex' => 1]);

        $entries = (new ArticlesModule())->sitemapCollectors()['articles']();
        $mine = array_values(array_filter($entries, fn (array $entry): bool => str_ends_with((string) $entry['loc'], '/artikelen/' . $this->slug($id))));
        $this->assertCount(1, $mine, 'one entry per language version');
        $this->assertStringContainsString('/en/articles/' . $this->slug($id, 'en'), json_encode($mine[0], JSON_UNESCAPED_SLASHES), 'its English version as an alternate');
        $this->assertStringNotContainsString($this->slug($hidden), json_encode($entries), 'noindex stays out');
        $this->assertStringContainsString('/artikelen', json_encode($entries), 'the listing itself');
        $this->assertStringContainsString($this->slug($id), json_encode(Sitemap::entries()), 'in the site\'s sitemap');

        $nl = (new ArticleSearchProvider())->documents(SearchQuery::fromInput('Integraties Zoekwoord'), 'nl', 10);
        $this->assertCount(1, $nl);
        $this->assertSame('/artikelen/' . $this->slug($id), $nl[0]->url);
        $this->assertSame('ZZ Art Integraties Zoekwoord', $nl[0]->title);
        $en = (new ArticleSearchProvider())->documents(SearchQuery::fromInput('Searchword'), 'en', 10);
        $this->assertSame('/en/articles/' . $this->slug($id, 'en'), $en[0]->url ?? null);
        $this->assertSame([], (new ArticleSearchProvider())->documents(SearchQuery::fromInput('Niet indexeren'), 'nl', 10));
        $this->assertArrayHasKey('article', SearchService::providers());

        $this->assertContains('article', array_keys(LinkTargets::types()));
        $this->assertTrue(LinkTargets::exists('article', $id));
        $this->assertSame('/artikelen/' . $this->slug($id), LinkTargets::href('article', $id));
        $this->assertSame('ZZ Art Integrations Searchword', LinkTargets::title('article', $id, 'en'));

        $this->assertTrue(LinkedImages::isAvailable('article'));
        $picture = LinkedImages::resolve('article', $id);
        $this->assertStringContainsString('/assets/media/zz-art-', (string) ($picture['image_path'] ?? ''));

        $usages = MediaUsageRegistry::usagesFor([$image]);
        $this->assertStringContainsString('Artikel: ZZ Art Integraties Zoekwoord', json_encode($usages, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, MediaUsageRegistry::countsFor([$image])[$image] ?? 0, 'the library refuses to delete it');
    }

    /* ------------------------------------------------------------------ */
    /* Module off and on                                                   */
    /* ------------------------------------------------------------------ */

    public function testSwitchedOffArticlesContributeNothingAndKeepEveryRow(): void
    {
        $image = $this->mediaRow();
        $id = $this->article('Module uit', 'published', '-1 day', image: $image);
        $slug = $this->slug($id);
        [$editor] = $this->accounts->signIn([ArticlesModule::ARTICLES_MANAGE]);

        $off = BuiltInServer::start(['MODULE_ARTICLES_ENABLED' => 'false', 'MODULE_BLOG_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
        $this->assertNotNull($off);
        try {
            $this->assertSame(404, $off->request('GET', '/artikelen')['status']);
            $this->assertSame(404, $off->request('GET', '/artikelen/' . $slug)['status']);
            $this->assertSame(403, $off->request('GET', '/admin/articles.php', $editor)['status'], 'a permission of a module that is off is held by nobody');
            $this->assertSame(200, $off->request('GET', '/blog')['status'], 'the Blog does not depend on it');
        } finally {
            $off->stop();
        }

        ModuleRegistry::overrideForTests(['shop' => true, 'blog' => true, 'portfolio' => true, 'multilingual' => true, 'articles' => false]);
        $this->assertNull(Publishables::get('article'));
        $this->assertNotContains('article', array_keys(LinkTargets::types()));
        $this->assertNull(LinkTargets::href('article', $id));
        $this->assertFalse(LinkedImages::isAvailable('article'));
        $this->assertArrayNotHasKey('article', SearchService::providers());
        $this->assertNull(ContentOwners::getEnabled('article'));
        $this->assertStringNotContainsString($slug, json_encode(Sitemap::entries()));
        $this->assertStringNotContainsString('Artikel:', json_encode(MediaUsageRegistry::usagesFor([$image]), JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($this->articles->find($id), 'no row is touched');
        $this->assertNotNull(ContentPages::pageFor(ArticleContentOwner::KIND, $id), 'its blocks stay');

        ModuleRegistry::overrideForTests(['shop' => true, 'blog' => true, 'portfolio' => true, 'multilingual' => true, 'articles' => true]);
        $this->assertInstanceOf(ArticlePublishable::class, Publishables::get('article'));
        $this->assertSame('/artikelen/' . $slug, LinkTargets::href('article', $id));
        $this->assertSame(200, $this->get('/artikelen/' . $slug)['status'], 'switched on again, everything answers');
    }

    /* ------------------------------------------------------------------ */
    /* Blog regression                                                     */
    /* ------------------------------------------------------------------ */

    public function testTheBlogKeepsItsRoutesTypesAndProviders(): void
    {
        $this->assertSame(200, $this->get('/blog')['status']);
        $this->assertSame(200, $this->get('/artikelen')['status']);
        $this->assertSame(['blog_post', 'article'], array_keys(Publishables::all()));
        $this->assertSame('blog.manage', Publishables::get('blog_post')->permission());
        $this->assertSame('articles.manage', Publishables::get('article')->permission());
        $this->assertContains('blog_post', array_keys(LinkTargets::types()));
        $this->assertContains('article', array_keys(LinkTargets::types()));
        $this->assertArrayHasKey('post', SearchService::providers());
        $this->assertArrayHasKey('blog_post', LinkedImages::kinds());
        $this->assertArrayHasKey('article', LinkedImages::kinds());

        // One slug in both modules: two addresses, two kinds, no collision.
        $id = $this->article('Gedeeld adres', 'published', '-1 day');
        $this->assertSame(404, $this->get('/blog/' . $this->slug($id))['status'], 'an article is not a blog post');
        $this->assertSame(404, $this->get('/artikelen/blog')['status'], 'nor is the Blog an article');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function article(string $title, string $status, ?string $relative, ?string $english = null, ?int $topic = null, ?int $image = null, ?string $body = '<p>Tekst van het artikel</p>'): int
    {
        static $n = 0;
        $n++;
        $slug = self::PREFIX . $n . '-' . bin2hex(random_bytes(3));
        $at = $relative === null ? null : (new \DateTimeImmutable($relative))->format(PublishingClock::SQL_FORMAT);

        $id = $this->articles->create(['status' => $status, 'published_at' => $at, 'author_name' => 'Testauteur', 'topic_id' => $topic, 'featured_media_id' => $image]);
        $this->created[] = $id;
        ArticleLocalization::save($id, 'nl', [ArticleLocalization::SLUG => $slug, ArticleLocalization::TITLE => 'ZZ Art ' . $title, ArticleLocalization::EXCERPT => 'Intro']);
        if ($english !== null) {
            ArticleLocalization::save($id, 'en', [ArticleLocalization::SLUG => $slug . '-en', ArticleLocalization::TITLE => 'ZZ Art ' . $english, ArticleLocalization::EXCERPT => 'Intro EN']);
        }
        if ($body !== null) {
            $this->textBlock($id, $body);
        }
        ArticleLocalization::clearCache();

        return $id;
    }

    private function topic(string $name): int
    {
        $id = (new ArticleTopicRepository())->create(1);
        $this->topics[] = $id;
        ArticleLocalization::saveTopic($id, 'nl', [ArticleLocalization::SLUG => self::PREFIX . 'onderwerp-' . bin2hex(random_bytes(3)), ArticleLocalization::NAME => 'ZZ ' . $name]);

        return $id;
    }

    private function mediaRow(): int
    {
        $db = Database::connection();
        $path = 'assets/media/' . self::PREFIX . bin2hex(random_bytes(4)) . '.jpg';
        $db->prepare("INSERT INTO media (path, original_filename, display_name, mime_type, width, height, file_size, alt_text, created_at, updated_at)
                      VALUES (?, 'x.jpg', 'ZZ beeld', 'image/jpeg', 1600, 900, 1000, 'Een werkbank', NOW(), NOW())")->execute([$path]);
        $id = (int) $db->lastInsertId();
        $this->media[] = $id;

        return $id;
    }

    /** @return array{0: int, 1: string, 2: int} page_sections id, key and text block id */
    private function textBlock(int $articleId, string $body): array
    {
        $page = ContentPages::ensure(ArticleContentOwner::KIND, $articleId);
        [$sectionId, $key] = \App\Service\SectionRegistry::create('rich_text', (string) $page['content_key']);
        $pageSectionId = (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'rich_text', $key, $sectionId);
        if ($body !== '') {
            BlockLocalization::save('rich_text_sections', $sectionId, 'nl', [RichTextContent::BODY => $body]);
        }
        RichTextContent::clearCache();

        return [$pageSectionId, $page['content_key'] . ':' . $key, (int) $sectionId];
    }

    private function spacer(int $articleId): void
    {
        $page = ContentPages::ensure(ArticleContentOwner::KIND, $articleId);
        [$sectionId, $key] = \App\Service\SectionRegistry::create('spacer', (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'spacer', $key, $sectionId);
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
        $this->assertStringStartsWith('/admin/article.php?id=', $saved['location'], 'a saved block goes back to its article');
    }

    /**
     * Every field of the editor's one form, from what is stored.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function fields(int $id, string $csrf, array $overrides = []): array
    {
        $row = $this->articles->find($id) ?? [];
        $language = $overrides['language_code'] ?? 'nl';
        ArticleLocalization::clearCache();

        return $overrides + [
            'csrf_token' => $csrf,
            'id' => (string) $id,
            'language_code' => $language,
            'title' => ArticleLocalization::word($id, ArticleLocalization::TITLE, $language),
            'slug' => (string) ArticleLocalization::slug($id, $language),
            'excerpt' => ArticleLocalization::word($id, ArticleLocalization::EXCERPT, $language),
            'meta_title' => '',
            'meta_description' => '',
            'author_name' => (string) ($row['author_name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'draft'),
            'published_at' => PublishingClock::forFormInput($row['published_at'] ?? null),
            'topic_id' => (string) (int) ($row['topic_id'] ?? 0),
            'featured_media_id' => (string) (int) ($row['featured_media_id'] ?? 0),
            'noindex' => '0',
        ];
    }

    private function slug(int $id, string $language = 'nl'): string
    {
        ArticleLocalization::clearCache();

        return (string) ArticleLocalization::slug($id, $language);
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path, ?string $session = null): array
    {
        return self::$server->request('GET', $path, $session);
    }
}
