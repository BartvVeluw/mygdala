<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Module\ModuleRegistry;
use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogFeed;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSeo;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogLocalization;
use App\Service\Blog\BlogSlug;
use App\Service\Blog\BlogUrls;
use App\Service\SeoDefaults;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;

/**
 * What the Blog says about itself to a search engine and to a feed reader:
 * the resolved metadata, the structured data, what is in the sitemap and what
 * is in the RSS document.
 *
 * The point of most of it is that there is no second SEO implementation — the
 * Blog produces an App\Service\SeoMetadata and everything else is Core's.
 */
final class BlogSeoTest extends TestCase
{
    private const PREFIX = 'zz-blogseo-';

    private BlogPostRepository $posts;
    private BlogCategoryRepository $categories;

    /** @var list<int> */
    private array $createdPosts = [];
    /** @var list<int> */
    private array $createdCategories = [];

    protected function setUp(): void
    {
        $this->posts = new BlogPostRepository();
        $this->categories = new BlogCategoryRepository();
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPosts as $id) {
            $this->posts->delete($id);
        }
        foreach ($this->createdCategories as $id) {
            $this->categories->delete($id);
        }

        $this->createdPosts = $this->createdCategories = [];

        BlogSettings::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
    }

    /* ------------------------------------------------------------------ */
    /* Metadata                                                            */
    /* ------------------------------------------------------------------ */

    public function testAPostsTitleNamesThePostTheBlogAndTheSite(): void
    {
        $post = $this->post(['title' => 'Testbericht SEO titel']);

        $seo = BlogSeo::forPost($post);

        $this->assertStringStartsWith('Testbericht SEO titel | ', $seo->titleNl);
        $this->assertStringContainsString(BlogSettings::title('nl'), $seo->titleNl);
        $this->assertStringContainsString(SeoDefaults::siteName(), $seo->titleNl);
    }

    public function testAnOwnSeoTitleIsUsedVerbatim(): void
    {
        $post = $this->post([
            'title' => 'Testbericht met eigen titel',
            'meta_title' => 'Precies deze tekst',
        ]);

        $this->assertSame('Precies deze tekst', BlogSeo::forPost($post)->titleNl);
    }

    /** meta_description, then the excerpt, then the site default, then nothing. */
    public function testTheDescriptionFallsBackToTheExcerpt(): void
    {
        $withMeta = $this->post([
            'title' => 'Testbericht met meta',
            'excerpt' => 'De samenvatting.',
            'meta_description' => 'De meta description.',
        ]);
        $withExcerpt = $this->post([
            'title' => 'Testbericht met samenvatting',
            'excerpt' => 'De samenvatting.',
        ]);

        $this->assertSame('De meta description.', BlogSeo::forPost($withMeta)->descriptionNl);
        $this->assertSame('De samenvatting.', BlogSeo::forPost($withExcerpt)->descriptionNl);
    }

    public function testAPostsCanonicalIsItsOwnUrlAndNothingElse(): void
    {
        $post = $this->post(['title' => 'Testbericht canonical']);

        $this->assertSame(BlogUrls::post((string) $post['slug']), BlogSeo::forPost($post)->canonical);
        $this->assertStringStartsWith('http', (string) BlogSeo::forPost($post)->canonical);
    }

    public function testAPostIsAnArticleWhileAListingIsAWebsite(): void
    {
        $this->assertSame('article', BlogSeo::forPost($this->post(['title' => 'Testbericht type']))->ogType);
        $this->assertSame('website', BlogSeo::forIndex()->ogType);
    }

    public function testANoindexPostSaysSoAndIsNotIndexable(): void
    {
        $post = $this->post(['title' => 'Testbericht niet indexeren', 'noindex' => 1]);

        $this->assertFalse(BlogSeo::forPost($post)->isIndexable());
        $this->assertFalse(BlogSeo::isIndexable($post));
        $this->assertSame(SeoDefaults::ROBOTS_NOINDEX, BlogSeo::forPost($post)->robots);
    }

    /**
     * A tag archive is linkable and crawlable but never invited into the
     * index: free-form tags multiply into thin, near-identical listings.
     */
    public function testATagArchiveIsNoindexWhileACategoryArchiveIsNot(): void
    {
        $tag = ['id' => 1, 'name' => 'Testtag', 'slug' => self::PREFIX . 'tag'];
        $categoryId = $this->category('Testcategorie SEO');
        $category = $this->categories->find($categoryId);

        $this->assertFalse(BlogSeo::forTag($tag)->isIndexable());
        $this->assertNotNull(BlogSeo::forTag($tag)->canonical, 'still a real, linkable URL');
        $this->assertTrue(BlogSeo::forCategory($category)->isIndexable());
    }

    public function testAPaginatedListingIsCanonicalToItself(): void
    {
        $this->assertSame(BlogUrls::index(2), BlogSeo::forIndex(2)->canonical);
        $this->assertStringContainsString('pagina 2', BlogSeo::forIndex(2)->titleNl);
        $this->assertSame(BlogUrls::index(), BlogSeo::forIndex()->canonical);
    }

    /* ------------------------------------------------------------------ */
    /* Structured data                                                     */
    /* ------------------------------------------------------------------ */

    public function testAPostEmitsBlogPostingBuiltOnlyFromWhatItKnows(): void
    {
        $post = $this->post([
            'title' => 'Testbericht structured data',
            'excerpt' => 'De samenvatting.',
            'author_name' => 'Testauteur',
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => '-1 hour',
        ]);

        $jsonLd = BlogSeo::forPost($post)->jsonLd;

        $this->assertIsArray($jsonLd);
        $this->assertSame('BlogPosting', $jsonLd['@type']);
        $this->assertSame('Testbericht structured data', $jsonLd['headline']);
        $this->assertSame(BlogUrls::post((string) $post['slug']), $jsonLd['url']);
        $this->assertSame(['@type' => 'Person', 'name' => 'Testauteur'], $jsonLd['author']);
        $this->assertArrayHasKey('datePublished', $jsonLd);
        $this->assertSame('De samenvatting.', $jsonLd['description']);

        // Nothing invented: no rating, no comment count, no breadcrumb.
        foreach (['aggregateRating', 'commentCount', 'breadcrumb', 'articleBody'] as $absent) {
            $this->assertArrayNotHasKey($absent, $jsonLd);
        }
    }

    public function testAPostWithoutAnAuthorEmitsNoAuthorRatherThanTheCompanyName(): void
    {
        $post = $this->post([
            'title' => 'Testbericht zonder auteur',
            'author_name' => '',
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => '-1 hour',
        ]);

        $this->assertArrayNotHasKey('author', (array) BlogSeo::forPost($post)->jsonLd);
    }

    public function testAPostThatMayNotBeIndexedEmitsNoStructuredData(): void
    {
        $noindex = $this->post(['title' => 'Testbericht geen jsonld', 'noindex' => 1]);
        $draft = $this->post(['title' => 'Testbericht concept jsonld', 'status' => BlogPostStatus::DRAFT]);

        $this->assertNull(BlogSeo::forPost($noindex)->jsonLd);
        $this->assertNull(BlogSeo::forPost($draft)->jsonLd);
    }

    /** A listing is a listing: the posts on it carry their own data. */
    public function testTheListingAndTheArchivesEmitNoStructuredData(): void
    {
        $categoryId = $this->category('Testcategorie geen jsonld');

        $this->assertNull(BlogSeo::forIndex()->jsonLd);
        $this->assertNull(BlogSeo::forCategory($this->categories->find($categoryId))->jsonLd);
        $this->assertNull(BlogSeo::forTag(['slug' => 'x', 'name' => 'x'])->jsonLd);
    }

    /* ------------------------------------------------------------------ */
    /* Sitemap                                                             */
    /* ------------------------------------------------------------------ */

    public function testTheSitemapCarriesThePublicPostsAndTheIndexButNoDraftOrFuturePost(): void
    {
        $public = $this->post(['title' => 'Testbericht in sitemap', 'status' => BlogPostStatus::PUBLISHED, 'published_at' => '-1 hour']);
        $draft = $this->post(['title' => 'Testbericht concept sitemap', 'status' => BlogPostStatus::DRAFT]);
        $future = $this->post(['title' => 'Testbericht toekomst sitemap', 'status' => BlogPostStatus::SCHEDULED, 'published_at' => '+1 month']);
        $noindex = $this->post(['title' => 'Testbericht noindex sitemap', 'status' => BlogPostStatus::PUBLISHED, 'published_at' => '-1 hour', 'noindex' => 1]);

        $locations = array_column(Sitemap::entries(), 'loc');

        $this->assertContains(BlogUrls::index(), $locations);
        $this->assertContains(BlogUrls::post((string) $public['slug']), $locations);

        foreach ([$draft, $future, $noindex] as $absent) {
            $this->assertNotContains(BlogUrls::post((string) $absent['slug']), $locations);
        }
    }

    public function testAnEmptyOrInactiveCategoryIsNotInTheSitemapButAFilledOneIs(): void
    {
        $filled = $this->category('Testcategorie gevuld');
        $empty = $this->category('Testcategorie leeg');
        $inactive = $this->category('Testcategorie uit', ['is_active' => false]);

        $post = $this->post(['title' => 'Testbericht in categorie', 'status' => BlogPostStatus::PUBLISHED, 'published_at' => '-1 hour']);
        $this->posts->setCategories((int) $post['id'], [$filled, $inactive]);

        $locations = array_column(Sitemap::entries(), 'loc');

        $this->assertContains(BlogUrls::category((string) $this->categories->find($filled)['slug']), $locations);
        $this->assertNotContains(BlogUrls::category((string) $this->categories->find($empty)['slug']), $locations);
        $this->assertNotContains(BlogUrls::category((string) $this->categories->find($inactive)['slug']), $locations);
    }

    /** Tag archives are noindex, so they are not offered to a crawler either. */
    public function testNoTagArchiveIsInTheSitemap(): void
    {
        foreach (array_column(Sitemap::entries(), 'loc') as $loc) {
            $this->assertStringNotContainsString('/' . BlogUrls::ROOT . '/' . BlogUrls::TAG_SEGMENT . '/', $loc);
        }
    }

    public function testWithTheBlogSwitchedOffNoBlogUrlCanReachTheSitemap(): void
    {
        $post = $this->post(['title' => 'Testbericht sitemap uit', 'status' => BlogPostStatus::PUBLISHED, 'published_at' => '-1 hour']);

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => false]);

        foreach (array_column(Sitemap::entries(), 'loc') as $loc) {
            $this->assertStringNotContainsString('/' . BlogUrls::ROOT, $loc);
        }

        $this->assertNotNull($this->posts->find((int) $post['id']), 'and the post itself is untouched');
    }

    /* ------------------------------------------------------------------ */
    /* RSS                                                                 */
    /* ------------------------------------------------------------------ */

    public function testTheFeedIsValidRssCarryingTitleUrlDateAndDescription(): void
    {
        $post = $this->post([
            'title' => 'Testbericht in de feed',
            'excerpt' => 'Wat er in de feed staat.',
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => '-1 hour',
        ]);

        $xml = BlogFeed::xml();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'the feed must parse');

        $this->assertStringContainsString('<title>Testbericht in de feed</title>', $xml);
        $this->assertStringContainsString('<link>' . BlogUrls::post((string) $post['slug']) . '</link>', $xml);
        $this->assertStringContainsString('<description>Wat er in de feed staat.</description>', $xml);
        $this->assertStringContainsString('<pubDate>', $xml);
        $this->assertStringContainsString('<atom:link href="' . BlogUrls::feed() . '"', $xml);
    }

    public function testTheFeedExposesNoDraftAndNoFuturePost(): void
    {
        $draft = $this->post(['title' => 'Testbericht concept feed', 'status' => BlogPostStatus::DRAFT]);
        $future = $this->post(['title' => 'Testbericht toekomst feed', 'status' => BlogPostStatus::SCHEDULED, 'published_at' => '+1 month']);

        $xml = BlogFeed::xml();

        $this->assertStringNotContainsString('Testbericht concept feed', $xml);
        $this->assertStringNotContainsString('Testbericht toekomst feed', $xml);
        $this->assertStringNotContainsString((string) $draft['slug'], $xml);
        $this->assertStringNotContainsString((string) $future['slug'], $xml);
    }

    /** Everything in the feed is built from the site's own identity settings. */
    public function testTheFeedNamesNoDomainOfItsOwn(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Blog/BlogFeed.php');

        $this->assertStringNotContainsString('vanveluw', strtolower($source));
        $this->assertStringNotContainsString('https://www.', $source);
    }

    public function testAnXmlUnsafeTitleCannotBreakTheFeed(): void
    {
        $this->post([
            'title' => 'Test & <bericht> met "tekens"',
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => '-1 hour',
        ]);

        $xml = BlogFeed::xml();

        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringNotContainsString('<bericht>', $xml);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function post(array $values): array
    {
        $title = (string) $values['title'];
        $values['slug'] = BlogSlug::unique(
            self::PREFIX . BlogSlug::sanitize($title),
            $title,
            fn (string $candidate): bool => $this->posts->slugExists($candidate, null)
        );
        $values['status'] ??= BlogPostStatus::PUBLISHED;
        $values['published_at'] = isset($values['published_at'])
            ? (new \DateTimeImmutable((string) $values['published_at']))->format(BlogClock::SQL_FORMAT)
            : (new \DateTimeImmutable('-1 hour'))->format(BlogClock::SQL_FORMAT);

        // The words are rows now, one per website language (Multilingual 2.0
        // phase 5 wave B), written in the default language by this fixture.
        $words = array_intersect_key($values, BlogLocalization::POST_FIELDS);
        $id = $this->posts->create(array_diff_key($values, BlogLocalization::POST_FIELDS));
        $this->createdPosts[] = $id;

        if ($words !== []) {
            BlogLocalization::savePost($id, BlogLocalization::defaultLanguage(), array_map('strval', $words));
        }
        BlogLocalization::clearCache();

        return (array) $this->posts->find($id);
    }

    /** @param array<string, mixed> $values */
    private function category(string $name, array $values = []): int
    {
        $id = $this->categories->create($values + [
            'slug' => BlogSlug::unique(
                self::PREFIX . BlogSlug::sanitize($name),
                $name,
                fn (string $candidate): bool => $this->categories->slugExists($candidate, null)
            ),
            'is_active' => true,
            'sort_order' => $this->categories->nextPosition(),
        ]);

        $this->createdCategories[] = $id;
        BlogLocalization::saveCategory($id, BlogLocalization::defaultLanguage(), [BlogLocalization::NAME => $name]);
        BlogLocalization::clearCache();

        return $id;
    }
}
