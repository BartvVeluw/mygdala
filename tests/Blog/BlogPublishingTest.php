<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Module\BlogModule;
use App\Repository\BlogPostRepository;
use App\Service\AdminPermissions;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogPostPublishable;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSeo;
use App\Service\Publishing\Publishables;
use App\Service\Publishing\PublishingClock;
use App\Service\Publishing\PublishingService;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The Blog as the first kind on the Publishing Engine (docs/publishing/ARCHITECTURE.md, BLOG.md
 * "De publicatiecyclus"): the adapter answers from the Blog's own tables,
 * PublishingService refuses every forged or premature change without
 * touching the row, and api/admin/update-publication.php puts the guards in
 * front of it over real HTTP — next to the editor, the public route and the
 * sitemap, which must behave exactly as before.
 *
 * Posts, translations and accounts are this test's own and removed again.
 */
final class BlogPublishingTest extends TestCase
{
    private const PREFIX = 'zz-pub-';

    private static ?BuiltInServer $server = null;

    private BlogPostRepository $posts;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $created = [];

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
        $this->posts = new BlogPostRepository();
        $this->accounts = new AdminTestSession();
        self::assertSame('nl', BlogLocalization::defaultLanguage(), 'this test expects the Dutch-default test database');
        self::assertNotNull(Publishables::get('blog_post'), 'the test runner has the Blog switched on');
    }

    protected function tearDown(): void
    {
        PublishingClock::freezeForTests(null);

        foreach ($this->created as $id) {
            $this->posts->delete($id);
        }
        $this->created = [];

        $this->accounts->forget();
        BlogLocalization::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The adapter                                                         */
    /* ------------------------------------------------------------------ */

    public function testTheAdapterAnswersFromTheBlogsOwnRows(): void
    {
        $id = $this->post('Gepubliceerd', 'published', '-1 day', english: true);
        $provider = Publishables::get('blog_post');

        $this->assertSame(['draft', 'published', 'scheduled', 'archived'], $provider->statuses());
        $this->assertSame('published', $provider->publication($id)['status']);
        $this->assertSame((string) $this->posts->find($id)['published_at'], $provider->publication($id)['published_at']);

        $slug = (string) $this->posts->find($id)['slug'];
        $this->assertSame(['nl' => '/blog/' . $slug, 'en' => '/en/blog/' . $slug . '-en'], $provider->alternates($id), 'the address per language, from the Blog\'s own slugs');
        $this->assertSame('/en/blog/' . $slug . '-en', $provider->publicPath($id, 'en'));
        $this->assertNull($provider->publicPath($id, 'de'));

        $draft = $this->post('Concept', 'draft', null);
        $this->assertSame([], $provider->alternates($draft), 'a draft has no public address');
        $this->assertNull($provider->publication(999999999));
        $this->assertNull(Publishables::find('blog_post', 999999999));
        $this->assertNull(Publishables::find('news_item', $id), 'a blog post\'s id under an unknown type finds nothing');
    }

    public function testCanPublishIsTheBlogsOwnRule(): void
    {
        $provider = Publishables::get('blog_post');
        $fine = $this->post('Met titel', 'draft', null);
        $untitled = $this->post('', 'draft', null);

        $this->assertSame([], $provider->publishErrors($fine, 'published'));
        $this->assertCount(1, $provider->publishErrors($untitled, 'published'), 'no title in the default language');
    }

    /* ------------------------------------------------------------------ */
    /* PublishingService                                                   */
    /* ------------------------------------------------------------------ */

    public function testAChangeIsRefusedWholeOrSavedWhole(): void
    {
        PublishingClock::freezeForTests(new \DateTimeImmutable('2026-10-01 12:00:00'));
        $may = static fn (string $permission): bool => $permission === 'blog.manage';
        $mayNot = static fn (string $permission): bool => false;
        $id = $this->post('Wissel', 'draft', null);
        $before = $this->posts->find($id);

        $this->assertSame('not_found', PublishingService::change('news_item', $id, 'published', '', $may)['outcome'], 'forged type');
        $this->assertSame('not_found', PublishingService::change('blog_post', 999999999, 'published', '', $may)['outcome'], 'forged id');
        $this->assertSame('not_found', PublishingService::change('blog_post', $id . 'x', 'published', '', $may)['outcome'], 'malformed id');
        $this->assertSame('forbidden', PublishingService::change('blog_post', $id, 'published', '', $mayNot)['outcome'], 'no permission for this kind');
        $this->assertSame('invalid', PublishingService::change('blog_post', $id, 'deleted', '', $may)['outcome'], 'not a status at all');
        $this->assertSame('invalid', PublishingService::change('blog_post', $id, 'live', '', $may)['outcome']);
        $this->assertSame('invalid', PublishingService::change('blog_post', $id, 'scheduled', '', $may)['outcome']);
        $this->assertSame('invalid', PublishingService::change('blog_post', $id, 'published', '2026-02-30T10:00', $may)['outcome']);
        $this->assertSame($before, $this->posts->find($id), 'every refusal left the row exactly as it was');

        $untitled = $this->post('', 'draft', null);
        $this->assertSame('invalid', PublishingService::change('blog_post', $untitled, 'published', '', $may)['outcome'], 'the owner\'s can-publish said no');
        $this->assertSame('draft', $this->posts->find($untitled)['status']);

        $this->assertSame('saved', PublishingService::change('blog_post', $id, 'scheduled', '2026-10-01T12:01', $may)['outcome']);
        $this->assertFalse(BlogPostStatus::isPublic($this->posts->find($id)), 'scheduled in one minute');
        PublishingClock::freezeForTests(new \DateTimeImmutable('2026-10-01 12:01:00'));
        $this->assertTrue(BlogPostStatus::isPublic($this->posts->find($id)), 'its moment came; nothing ran');

        $this->assertSame('saved', PublishingService::change('blog_post', $id, 'draft', '2026-10-01T12:01', $may)['outcome']);
        $this->assertFalse(BlogPostStatus::isPublic($this->posts->find($id)), 'scheduled -> draft hides it, date kept');
        $this->assertSame('2026-10-01 12:01:00', $this->posts->find($id)['published_at']);

        $this->assertSame('saved', PublishingService::change('blog_post', $id, 'published', '', $may)['outcome']);
        $this->assertSame('2026-10-01 12:01:00', $this->posts->find($id)['published_at'], 'published without a date is now');
        $this->assertTrue(BlogPostStatus::isPublic($this->posts->find($id)));
    }

    public function testTheSitemapListsOnlyWhatIsOutAndCarriesItsLanguages(): void
    {
        $published = $this->post('In de sitemap', 'published', '-1 day', english: true);
        $future = $this->post('Nog niet', 'scheduled', '+1 day');
        $draft = $this->post('Concept niet', 'draft', '-1 day');

        $entries = (new BlogModule())->sitemapCollectors()['blog']();
        $locs = array_column($entries, 'loc');
        $slug = static fn (int $id): string => (string) (new BlogPostRepository())->find($id)['slug'];

        $mine = array_values(array_filter($entries, static fn (array $e): bool => str_ends_with($e['loc'], '/blog/' . $slug($published))));
        $this->assertCount(1, $mine);
        $this->assertSame(['nl', 'en'], array_keys($mine[0]['alternates']), 'hreflang for the two languages it really has');
        $this->assertNotEmpty(array_filter($locs, static fn (string $l): bool => str_ends_with($l, '/en/blog/' . $slug($published) . '-en')));

        foreach ([$future, $draft] as $hidden) {
            $this->assertEmpty(array_filter($locs, static fn (string $l): bool => str_contains($l, $slug($hidden))));
        }

        $seo = BlogSeo::forPost($this->posts->find($published), 'nl');
        $this->assertStringEndsWith('/blog/' . $slug($published), (string) $seo->canonical);
        $this->assertTrue($seo->isIndexable());
    }

    /* ------------------------------------------------------------------ */
    /* Over HTTP                                                           */
    /* ------------------------------------------------------------------ */

    public function testTheEndpointGuardsAndAnswers(): void
    {
        $this->needServer();
        [$editor, $token] = $this->accounts->signIn([BlogModule::BLOG_MANAGE]);
        [$viewer, $viewerToken] = $this->accounts->signIn([BlogModule::BLOG_VIEW]);
        [$other, $otherToken] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $id = $this->post('Via het endpoint', 'draft', null);
        $request = ['type' => 'blog_post', 'id' => (string) $id, 'status' => 'published', 'published_at' => ''];
        $endpoint = '/api/admin/update-publication.php';

        $this->assertSame(401, self::$server->request('POST', $endpoint, null, ['csrf_token' => $token] + $request)['status'], 'signed out');
        $this->assertSame(403, self::$server->request('POST', $endpoint, $other, ['csrf_token' => $otherToken] + $request)['status'], 'no publishing permission at all');
        $this->assertSame(403, self::$server->request('POST', $endpoint, $viewer, ['csrf_token' => $viewerToken] + $request)['status'], 'reading the blog is not publishing it');
        $this->assertSame(405, self::$server->request('GET', $endpoint . '?' . http_build_query($request), $editor)['status']);
        $this->assertSame(403, self::$server->request('POST', $endpoint, $editor, ['csrf_token' => str_repeat('0', 64)] + $request)['status'], 'wrong token');
        $this->assertSame(403, self::$server->request('POST', $endpoint, $editor, $request)['status'], 'no token');
        $this->assertSame(404, self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $token, 'type' => 'page'] + $request)['status'], 'forged type');
        $this->assertSame(404, self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $token, 'id' => '999999999'] + $request)['status'], 'forged id');
        $this->assertSame('draft', $this->posts->find($id)['status'], 'nothing changed');

        $refused = self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $token, 'status' => 'scheduled'] + $request);
        $this->assertSame(302, $refused['status']);
        $this->assertSame('/admin/blog-post.php?id=' . $id, $refused['location']);
        $editorPage = self::$server->request('GET', $refused['location'], $editor)['body'];
        $this->assertStringContainsString('Wat ingepland is, heeft een publicatiedatum nodig.', $editorPage, 'the reason, once, on the editor');
        $this->assertSame('draft', $this->posts->find($id)['status']);

        $saved = self::$server->request('POST', $endpoint, $editor, ['csrf_token' => $token] + $request);
        $this->assertSame('/admin/blog-post.php?id=' . $id . '&updated=1', $saved['location']);
        $this->assertSame('published', $this->posts->find($id)['status']);
        $this->assertSame(200, self::$server->request('GET', '/blog/' . $this->posts->find($id)['slug'])['status'], 'and it is out');
    }

    public function testTheEditorUsesTheSharedFieldsAndEscapesTheByline(): void
    {
        $this->needServer();
        [$editor] = $this->accounts->signIn([BlogModule::BLOG_MANAGE]);
        $id = $this->post('Velden', 'scheduled', '+2 days', author: '<script>alert(1)</script> & Co');

        $html = self::$server->request('GET', '/admin/blog-post.php?id=' . $id, $editor)['body'];
        $xpath = $this->xpath($html);

        $options = [];
        foreach ($xpath->query('//select[@name="status"]/option') as $option) {
            $options[$option->getAttribute('value')] = trim($option->textContent);
        }
        $this->assertSame(['draft' => 'Concept', 'published' => 'Gepubliceerd', 'scheduled' => 'Ingepland', 'archived' => 'Gearchiveerd'], $options, 'the Blog\'s four states in its own words');
        $this->assertSame('scheduled', $xpath->query('//select[@name="status"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame(BlogClock::forFormInput($this->posts->find($id)['published_at']), $xpath->query('//input[@name="published_at"]')->item(0)->getAttribute('value'));
        $this->assertSame('<script>alert(1)</script> & Co', $xpath->query('//input[@name="author_name"]')->item(0)->getAttribute('value'));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'escaped, not markup');

        $slug = (string) $this->posts->find($id)['slug'];
        $this->assertSame(404, self::$server->request('GET', '/blog/' . $slug)['status'], 'scheduled for later: not reachable yet');
    }

    public function testTheCardIsTheSameFieldsBehindItsOwnForm(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/_publication_fields.php';
        $id = $this->post('Kaart', 'draft', null);
        $provider = Publishables::get('blog_post');

        $html = admin_publication_card($provider, $id, $provider->publication($id), 'tok"en');
        $xpath = $this->xpath('<!doctype html><html><body>' . $html . '</body></html>');

        $form = $xpath->query('//form')->item(0);
        $this->assertSame('/api/admin/update-publication.php', $form->getAttribute('action'));
        $hidden = [];
        foreach ($xpath->query('//input[@type="hidden"]') as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertSame(['csrf_token' => 'tok"en', 'type' => 'blog_post', 'id' => (string) $id], $hidden);
        $this->assertSame(4, $xpath->query('//select[@name="status"]/option')->length, 'the statuses this kind offers');
        $this->assertSame(1, $xpath->query('//input[@name="published_at"][@type="datetime-local"]')->length);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function post(string $title, string $status, ?string $relative, bool $english = false, string $author = ''): int
    {
        static $n = 0;
        $n++;
        $slug = self::PREFIX . $n . '-' . bin2hex(random_bytes(3));
        $at = $relative === null ? null : (new \DateTimeImmutable($relative))->format(BlogClock::SQL_FORMAT);

        $id = $this->posts->create(['slug' => $slug, 'status' => $status, 'published_at' => $at, 'author_name' => $author, 'noindex' => 0]);
        $this->created[] = $id;

        BlogLocalization::savePost($id, 'nl', [BlogLocalization::SLUG => $slug, BlogLocalization::TITLE => $title, BlogLocalization::BODY => '<p>Tekst</p>']);
        if ($english) {
            BlogLocalization::savePost($id, 'en', [BlogLocalization::SLUG => $slug . '-en', BlogLocalization::TITLE => 'EN ' . $title]);
        }
        BlogLocalization::clearCache();

        return $id;
    }

    private function needServer(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
