<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use App\Repository\RedirectRepository;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogPostService;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogSlug;
use App\Service\Blog\BlogUrls;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * What a real request to a Blog URL does.
 *
 * Routing is the part of this module that cannot be asserted any other way:
 * which template answers /blog, /blog/<slug>, /blog/categorie/<slug>,
 * /blog/tag/<slug> and /blog/feed.xml is decided by Apache and .htaccess, not
 * by PHP. So is the fact that a two-segment post URL never matches the
 * three-segment archive rules.
 *
 * TWO SERVERS, ON PURPOSE. php_test runs with MODULE_BLOG_ENABLED=true and is
 * where the Blog is exercised; php_cms does not ask for the Blog at all and is
 * where "a disabled module's URLs are indistinguishable from URLs that never
 * existed" is proven over HTTP rather than in-process. Both skip themselves
 * when their container is not running.
 */
final class BlogRoutingTest extends TestCase
{
    private const PREFIX = 'zz-blogroute-';

    private BlogPostRepository $posts;
    private BlogCategoryRepository $categories;
    private BlogTagRepository $tags;
    private RedirectRepository $redirects;

    /** @var list<int> */
    private array $createdPosts = [];
    /** @var list<int> */
    private array $createdCategories = [];
    /** @var list<int> */
    private array $createdTags = [];
    /** @var list<string> */
    private array $createdRedirectPaths = [];

    protected function setUp(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $this->posts = new BlogPostRepository();
        $this->categories = new BlogCategoryRepository();
        $this->tags = new BlogTagRepository();
        $this->redirects = new RedirectRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPosts as $id) {
            $this->posts->delete($id);
        }
        foreach ($this->createdCategories as $id) {
            $this->categories->delete($id);
        }
        foreach ($this->createdTags as $id) {
            $this->tags->delete($id);
        }
        // Exact paths only, never a LIKE pattern.
        foreach ($this->createdRedirectPaths as $path) {
            $row = $this->redirects->findBySourcePath($path);
            if ($row !== null) {
                $this->redirects->delete((int) $row['id']);
            }
        }

        $this->createdPosts = $this->createdCategories = $this->createdTags = [];
        $this->createdRedirectPaths = [];
    }

    /* ------------------------------------------------------------------ */
    /* The five URLs                                                       */
    /* ------------------------------------------------------------------ */

    public function testTheListingAnswersAndCarriesOnlyItsOwnStylesheet(): void
    {
        $response = $this->get(BlogUrls::indexPath());

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('assets/css/blog/blog.css', $response['body']);
        $this->assertStringContainsString('<link rel="canonical"', $response['body']);
    }

    public function testAPublishedPostAnswersWithItsOwnContent(): void
    {
        $post = $this->publishedPost('Testbericht route detail', [
            'excerpt' => 'De samenvatting van dit testbericht.',
            'body' => '<p>De inhoud van dit testbericht.</p>',
            'author_name' => 'Testauteur',
        ]);

        $response = $this->get(BlogUrls::postPath((string) $post['slug']));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Testbericht route detail', $response['body']);
        $this->assertStringContainsString('De inhoud van dit testbericht.', $response['body']);
        $this->assertStringContainsString('"@type":"BlogPosting"', $response['body']);
        $this->assertStringContainsString(BlogUrls::post((string) $post['slug']), $response['body']);
    }

    public function testADraftAndAFuturePostAreBothJustANotFound(): void
    {
        $draft = $this->post('Testbericht route concept', BlogPostStatus::DRAFT, null);
        $future = $this->post('Testbericht route toekomst', BlogPostStatus::SCHEDULED, '+1 month');

        foreach ([$draft, $future] as $post) {
            $response = $this->get(BlogUrls::postPath((string) $post['slug']));

            $this->assertSame(404, $response['status']);
            $this->assertStringContainsString('Pagina niet gevonden', $response['body']);
            $this->assertStringNotContainsString((string) $post['title'], $response['body']);
        }
    }

    public function testAnUnknownPostSlugAnswersLikeAPageThatNeverExisted(): void
    {
        $response = $this->get('/' . BlogUrls::ROOT . '/' . self::PREFIX . 'bestaat-niet');

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('noindex', $response['body']);
    }

    public function testACategoryArchiveListsOnlyItsOwnPosts(): void
    {
        $categoryId = $this->category('Testcategorie route');
        $slug = (string) $this->categories->find($categoryId)['slug'];

        $inside = $this->publishedPost('Testbericht binnen categorie');
        $outside = $this->publishedPost('Testbericht buiten categorie');
        $this->posts->setCategories((int) $inside['id'], [$categoryId]);

        $response = $this->get(BlogUrls::categoryPath($slug));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Testbericht binnen categorie', $response['body']);
        $this->assertStringNotContainsString('Testbericht buiten categorie', $response['body']);
    }

    public function testATagArchiveListsItsPostsAndAsksNotToBeIndexed(): void
    {
        $post = $this->publishedPost('Testbericht met route-tag');
        $tagIds = BlogPostService::resolveTagIds(self::PREFIX . 'routetag');
        $this->rememberTags($tagIds);
        $this->posts->setTags((int) $post['id'], $tagIds);

        $slug = (string) $this->tags->find($tagIds[0])['slug'];
        $response = $this->get(BlogUrls::tagPath($slug));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Testbericht met route-tag', $response['body']);
        $this->assertStringContainsString('content="noindex,follow"', $response['body']);
    }

    public function testAnUnknownArchiveIs404RatherThanAnEmptyPage(): void
    {
        foreach ([
            BlogUrls::categoryPath(self::PREFIX . 'geen-categorie'),
            BlogUrls::tagPath(self::PREFIX . 'geen-tag'),
        ] as $path) {
            $this->assertSame(404, $this->get($path)['status'], $path);
        }
    }

    public function testTheFeedIsServedAsRss(): void
    {
        $post = $this->publishedPost('Testbericht in de route-feed');

        $response = $this->get(BlogUrls::feedPath());

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('application/rss+xml', $response['headers']);
        $this->assertStringContainsString('<rss version="2.0"', $response['body']);
        $this->assertStringContainsString('Testbericht in de route-feed', $response['body']);
        $this->assertNotFalse(simplexml_load_string($response['body']));
    }

    /**
     * The rewrite rules are narrow on purpose: /blog/categorie/x is three
     * segments and can never be read as a post called "categorie".
     */
    public function testTheArchiveNamespacesDoNotCollideWithPostUrls(): void
    {
        $this->assertSame(404, $this->get('/' . BlogUrls::ROOT . '/' . BlogUrls::CATEGORY_SEGMENT)['status']);
        $this->assertSame(404, $this->get('/' . BlogUrls::ROOT . '/' . BlogUrls::TAG_SEGMENT)['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Pagination                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Its own posts, one more than fits on a page, so this asserts real
     * paging rather than whatever happens to be in the database.
     */
    public function testTheListingPagesInsteadOfShowingEverything(): void
    {
        $perPage = BlogSettings::postsPerPage();

        for ($index = 1; $index <= $perPage + 1; $index++) {
            $this->post(
                'Testbericht paginering ' . $index,
                BlogPostStatus::PUBLISHED,
                '-' . $index . ' hours'
            );
        }

        $first = $this->get(BlogUrls::indexPath());
        $second = $this->get(BlogUrls::indexPath() . '?' . BlogUrls::PAGE_PARAM . '=2');

        $this->assertSame($perPage, substr_count($first['body'], 'class="blog-card"'), 'page one is full');
        $this->assertStringContainsString('rel="next"', $first['body'], 'and it offers the next page');
        $this->assertGreaterThan(0, substr_count($second['body'], 'class="blog-card"'), 'page two has the rest');
        $this->assertStringContainsString('rel="prev"', $second['body']);
    }

    public function testAPageNumberBeyondTheLastOneLandsOnTheLastPage(): void
    {
        $this->post('Testbericht ene pagina', BlogPostStatus::PUBLISHED, '-1 hour');

        $response = $this->get(BlogUrls::indexPath() . '?' . BlogUrls::PAGE_PARAM . '=9999');

        $this->assertSame(200, $response['status'], 'a stale page link is not a 404');
        // The canonical names the page actually rendered, so nothing is
        // indexed twice under a number that does not exist.
        $this->assertStringNotContainsString('pagina=9999', $response['body']);
        $this->assertStringContainsString('Testbericht ene pagina', $response['body']);
    }

    /* ------------------------------------------------------------------ */
    /* Renaming a published post                                           */
    /* ------------------------------------------------------------------ */

    public function testARenamedPostKeepsItsOldUrlWorking(): void
    {
        $post = $this->publishedPost('Testbericht dat hernoemd wordt');
        $oldSlug = (string) $post['slug'];
        $newSlug = $oldSlug . '-hernoemd';

        $this->createdRedirectPaths[] = '/' . BlogUrls::postRedirectPath($oldSlug);

        $this->posts->update((int) $post['id'], ['slug' => $newSlug]);
        $after = (array) $this->posts->find((int) $post['id']);

        $this->assertTrue(BlogPostService::recordSlugChange($post, $after));

        $response = $this->get(BlogUrls::postPath($oldSlug));

        $this->assertSame(301, $response['status']);
        $this->assertSame(BlogUrls::post($newSlug), $response['location']);
        $this->assertSame(200, $this->get(BlogUrls::postPath($newSlug))['status']);
    }

    /* ------------------------------------------------------------------ */
    /* No Blog anywhere it does not belong                                 */
    /* ------------------------------------------------------------------ */

    public function testNoPageOutsideTheBlogDownloadsBlogCss(): void
    {
        foreach (['/index.php', '/contact.php', '/portfolio.php'] as $path) {
            $response = $this->get($path);

            $this->assertSame(200, $response['status'], $path);
            $this->assertStringNotContainsString('assets/css/blog/', $response['body'], $path);
        }
    }

    /**
     * The CMS-only deployment, which asks for neither the Shop nor the Blog:
     * every Blog URL is the site's own "Pagina niet gevonden", and the
     * sitemap has nothing to say about it either.
     */
    public function testWithTheModuleOffEveryBlogUrlIsAnOrdinary404(): void
    {
        if (!TestEnvironment::cmsOnlySiteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::cmsOnlyUnreachableMessage());
        }

        $post = $this->publishedPost('Testbericht in de cms-only stand');

        foreach ([
            BlogUrls::indexPath(),
            BlogUrls::postPath((string) $post['slug']),
            BlogUrls::categoryPath('wat-dan-ook'),
            BlogUrls::tagPath('wat-dan-ook'),
            BlogUrls::feedPath(),
        ] as $path) {
            $response = $this->get($path, TestEnvironment::cmsOnlyBaseUrl());

            $this->assertSame(404, $response['status'], $path);
            $this->assertStringContainsString('Pagina niet gevonden', $response['body'], $path);
        }

        $sitemap = $this->get('/sitemap.xml', TestEnvironment::cmsOnlyBaseUrl());
        $this->assertStringNotContainsString('/' . BlogUrls::ROOT, $sitemap['body']);
    }

    public function testTheDataSurvivesTheModuleBeingOff(): void
    {
        if (!TestEnvironment::cmsOnlySiteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::cmsOnlyUnreachableMessage());
        }

        $post = $this->publishedPost('Testbericht dat blijft bestaan');

        $this->assertSame(404, $this->get(BlogUrls::postPath((string) $post['slug']), TestEnvironment::cmsOnlyBaseUrl())['status']);
        $this->assertSame(200, $this->get(BlogUrls::postPath((string) $post['slug']))['status']);
        $this->assertNotNull($this->posts->find((int) $post['id']));
    }

    /* ------------------------------------------------------------------ */

    /** @return array{status: int, location: ?string, body: string, headers: string} */
    private function get(string $path, ?string $baseUrl = null): array
    {
        $handle = curl_init(($baseUrl ?? TestEnvironment::baseUrl()) . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $headers = substr($response, 0, $headerSize);
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $matches) === 1) {
            $location = trim($matches[1]);
        }

        return [
            'status' => $status,
            'location' => $location,
            'body' => substr($response, $headerSize),
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function publishedPost(string $title, array $values = []): array
    {
        return $this->post($title, BlogPostStatus::PUBLISHED, '-1 hour', $values);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function post(string $title, string $status, ?string $when, array $values = []): array
    {
        $id = $this->posts->create($values + [
            'title' => $title,
            'slug' => BlogSlug::unique(
                self::PREFIX . BlogSlug::sanitize($title),
                $title,
                fn (string $candidate): bool => $this->posts->slugExists($candidate, null)
            ),
            'status' => $status,
            'published_at' => $when === null ? null : (new \DateTimeImmutable($when))->format(BlogClock::SQL_FORMAT),
        ]);

        $this->createdPosts[] = $id;

        return (array) $this->posts->find($id);
    }

    private function category(string $name): int
    {
        $id = $this->categories->create([
            'name' => $name,
            'slug' => BlogSlug::unique(
                self::PREFIX . BlogSlug::sanitize($name),
                $name,
                fn (string $candidate): bool => $this->categories->slugExists($candidate, null)
            ),
            'is_active' => true,
            'sort_order' => $this->categories->nextPosition(),
        ]);

        $this->createdCategories[] = $id;

        return $id;
    }

    /** @param list<int> $ids */
    private function rememberTags(array $ids): void
    {
        foreach ($ids as $id) {
            $this->createdTags[] = (int) $id;
        }
    }
}
