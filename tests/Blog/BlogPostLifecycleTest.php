<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogContent;
use App\Service\Blog\BlogPostService;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogSlug;
use PHPUnit\Framework\TestCase;

/**
 * A post's whole life: created, written, scheduled, published, renamed,
 * deleted — and what is visible at each point.
 *
 * ITS OWN DATA, ALWAYS. Every row this test writes carries the prefix below
 * in its slug and is removed in tearDown() by EXACT id — never with a LIKE
 * pattern, in which `_` matches any character (TESTING.md). Nothing here
 * touches a post an editor wrote.
 *
 * THE CLOCK IS FROZEN where the answer depends on it. Scheduling is a
 * comparison against "now", and the interesting cases are one second either
 * side of the moment; App\Service\Blog\BlogClock exists so those can be
 * asserted instead of slept through.
 */
final class BlogPostLifecycleTest extends TestCase
{
    /** Every slug this test creates starts with it. */
    private const PREFIX = 'zz-blogtest-';

    private BlogPostRepository $posts;
    private BlogCategoryRepository $categories;
    private BlogTagRepository $tags;

    /** @var list<int> */
    private array $createdPosts = [];
    /** @var list<int> */
    private array $createdCategories = [];
    /** @var list<int> */
    private array $createdTags = [];

    protected function setUp(): void
    {
        $this->posts = new BlogPostRepository();
        $this->categories = new BlogCategoryRepository();
        $this->tags = new BlogTagRepository();
    }

    protected function tearDown(): void
    {
        BlogClock::freezeForTests(null);
        BlogSettings::overrideForTests(null);

        foreach ($this->createdPosts as $id) {
            $this->posts->delete($id);
        }
        foreach ($this->createdCategories as $id) {
            $this->categories->delete($id);
        }
        foreach ($this->createdTags as $id) {
            $this->tags->delete($id);
        }

        $this->createdPosts = $this->createdCategories = $this->createdTags = [];
    }

    /* ------------------------------------------------------------------ */
    /* Creating and editing                                                */
    /* ------------------------------------------------------------------ */

    public function testAPostIsStoredAndReadBackWithEveryFieldItWasGiven(): void
    {
        $id = $this->createPost([
            'title' => 'Testbericht over hout',
            'title_en' => 'Test post about wood',
            'excerpt' => 'Korte samenvatting.',
            'body' => '<p>De inhoud.</p>',
            'author_name' => 'Testauteur',
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => BlogClock::nowForSql(),
        ]);

        $post = $this->posts->find($id);

        $this->assertNotNull($post);
        $this->assertSame('Testbericht over hout', $post['title']);
        $this->assertSame('Test post about wood', $post['title_en']);
        $this->assertSame('Korte samenvatting.', $post['excerpt']);
        $this->assertSame('<p>De inhoud.</p>', $post['body']);
        $this->assertSame('Testauteur', $post['author_name']);
        $this->assertSame(BlogPostStatus::PUBLISHED, $post['status']);
    }

    /** An empty optional field is NULL, not an empty string — the fallbacks test for it. */
    public function testAnEmptyOptionalFieldIsStoredAsNull(): void
    {
        $id = $this->createPost(['title' => 'Testbericht zonder extras', 'title_en' => '', 'excerpt' => '']);

        $post = $this->posts->find($id);

        $this->assertNull($post['title_en']);
        $this->assertNull($post['excerpt']);
        $this->assertNull($post['published_at']);
    }

    public function testDeletingAPostRemovesItAndItsTaxonomyLinksAndNothingElse(): void
    {
        $categoryId = $this->createCategory('Testcategorie hout');
        $id = $this->createPost(['title' => 'Testbericht dat verdwijnt']);
        $this->posts->setCategories($id, [$categoryId]);
        $this->posts->setTags($id, BlogPostService::resolveTagIds('zz-blogtest-tag'));

        $tagIds = $this->posts->tagIdsFor($id);
        $this->rememberTags($tagIds);
        $this->assertNotSame([], $tagIds);

        $this->assertTrue($this->posts->delete($id));
        $this->createdPosts = array_values(array_diff($this->createdPosts, [$id]));

        $this->assertNull($this->posts->find($id));
        $this->assertSame([], $this->posts->categoryIdsFor($id));
        $this->assertSame([], $this->posts->tagIdsFor($id));

        // The taxonomy itself survives: it belongs to the blog, not the post.
        $this->assertNotNull($this->categories->find($categoryId));
        $this->assertNotNull($this->tags->find($tagIds[0]));
    }

    /* ------------------------------------------------------------------ */
    /* Slugs                                                               */
    /* ------------------------------------------------------------------ */

    public function testASlugIsMadeUniqueRatherThanRejected(): void
    {
        $first = $this->createPost(['title' => 'Testbericht dezelfde titel']);
        $second = $this->createPost(['title' => 'Testbericht dezelfde titel']);

        $slugs = [$this->posts->find($first)['slug'], $this->posts->find($second)['slug']];

        $this->assertNotSame($slugs[0], $slugs[1]);
        $this->assertStringStartsWith($slugs[0], $slugs[1]);
        $this->assertStringEndsWith('-2', $slugs[1]);
    }

    public function testAHandTypedSlugThatIsTakenIsRefusedWithAMessage(): void
    {
        $existing = $this->createPost(['title' => 'Testbericht bezette slug']);
        $taken = (string) $this->posts->find($existing)['slug'];

        $error = BlogSlug::validationError(
            $taken,
            fn (string $candidate): bool => $this->posts->slugExists($candidate, null)
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('al in gebruik', $error);
    }

    /**
     * /blog/categorie and /blog/tag are the module's own sub-paths, so a post
     * may not claim one: the URL would otherwise mean two things depending on
     * how many segments followed it.
     */
    public function testAPostCannotClaimTheBlogsOwnSubPaths(): void
    {
        foreach (BlogSlug::RESERVED_SEGMENTS as $reserved) {
            $this->assertTrue(BlogSlug::isReserved($reserved));
            $this->assertNotNull(BlogSlug::validationError($reserved, static fn (): bool => false));
        }
    }

    public function testASlugFollowsTheProjectsExistingSlugConvention(): void
    {
        $this->assertSame('een-titel-met-accenten', BlogSlug::sanitize('Één titel, mét accenten!'));
        $this->assertSame('', BlogSlug::sanitize('!!!'));
    }

    /* ------------------------------------------------------------------ */
    /* Publication, scheduling and the boundary                            */
    /* ------------------------------------------------------------------ */

    public function testADraftIsNeverPublicHoweverItsDateIsSet(): void
    {
        $id = $this->createPost([
            'title' => 'Testbericht concept',
            'status' => BlogPostStatus::DRAFT,
            'published_at' => (new \DateTimeImmutable('-1 year'))->format(BlogClock::SQL_FORMAT),
        ]);

        $post = $this->posts->find($id);

        $this->assertFalse(BlogPostStatus::isPublic($post));
        $this->assertFalse(BlogPostStatus::isPending($post));
        $this->assertNull($this->posts->findPublicBySlug((string) $post['slug'], BlogClock::nowForSql()));
    }

    public function testAPublishedPostWithAPastDateIsPublic(): void
    {
        $id = $this->createPost([
            'title' => 'Testbericht gepubliceerd',
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => (new \DateTimeImmutable('-1 hour'))->format(BlogClock::SQL_FORMAT),
        ]);

        $post = $this->posts->find($id);

        $this->assertTrue(BlogPostStatus::isPublic($post));
        $this->assertNotNull($this->posts->findPublicBySlug((string) $post['slug'], BlogClock::nowForSql()));
    }

    /**
     * THE SCHEDULING BOUNDARY, asserted on both sides of one second. Nothing
     * runs in between: the only thing that changed is the clock.
     */
    public function testAScheduledPostBecomesPublicExactlyAtItsMomentAndNotBefore(): void
    {
        $moment = new \DateTimeImmutable('2026-11-05 09:00:00');

        $id = $this->createPost([
            'title' => 'Testbericht ingepland',
            'status' => BlogPostStatus::SCHEDULED,
            'published_at' => $moment->format(BlogClock::SQL_FORMAT),
        ]);

        $post = $this->posts->find($id);
        $slug = (string) $post['slug'];

        BlogClock::freezeForTests($moment->modify('-1 second'));
        $this->assertFalse(BlogPostStatus::isPublic($post), 'not one second early');
        $this->assertTrue(BlogPostStatus::isPending($post));
        $this->assertNull($this->posts->findPublicBySlug($slug, BlogClock::nowForSql()));

        BlogClock::freezeForTests($moment);
        $this->assertTrue(BlogPostStatus::isPublic($post), 'public at its own moment');
        $this->assertFalse(BlogPostStatus::isPending($post));
        $this->assertNotNull($this->posts->findPublicBySlug($slug, BlogClock::nowForSql()));

        BlogClock::freezeForTests($moment->modify('+1 second'));
        $this->assertTrue(BlogPostStatus::isPublic($post));
    }

    /** A future post is in none of the public lists either, not just the route. */
    public function testAFuturePostIsInNoPublicListing(): void
    {
        $id = $this->createPost([
            'title' => 'Testbericht toekomst',
            'status' => BlogPostStatus::SCHEDULED,
            'published_at' => (new \DateTimeImmutable('+2 months'))->format(BlogClock::SQL_FORMAT),
        ]);
        $slug = (string) $this->posts->find($id)['slug'];

        $now = BlogClock::nowForSql();

        $listed = array_column($this->posts->findPublic($now, 100, 0), 'slug');
        $feed = array_column($this->posts->findPublicForFeed($now, 100), 'slug');
        $sitemap = array_column($this->posts->findPublicForSitemap($now), 'slug');

        $this->assertNotContains($slug, $listed);
        $this->assertNotContains($slug, $feed);
        $this->assertNotContains($slug, $sitemap);
    }

    /** Publishing without typing a date means "now", not "never". */
    public function testPublishingWithoutADateStampsThisMoment(): void
    {
        $resolved = BlogPostService::resolvePublishedAt(BlogPostStatus::PUBLISHED, '');

        $this->assertNotNull($resolved);
        $this->assertSame(BlogClock::nowForSql(), $resolved);

        $this->assertNull(BlogPostService::resolvePublishedAt(BlogPostStatus::DRAFT, ''));
        $this->assertSame(
            '2026-12-24 18:30:00',
            BlogPostService::resolvePublishedAt(BlogPostStatus::SCHEDULED, '2026-12-24T18:30')
        );
    }

    public function testAScheduledPostWithoutADateIsRefused(): void
    {
        $errors = BlogPostService::validate([
            'title' => 'Testbericht',
            'status' => BlogPostStatus::SCHEDULED,
            'published_at' => '',
        ]);

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('publicatiedatum', implode(' ', $errors));
    }

    public function testAPostWithoutATitleIsRefused(): void
    {
        $errors = BlogPostService::validate(['title' => '   ', 'status' => BlogPostStatus::DRAFT]);

        $this->assertContains('Titel is verplicht.', $errors);
    }

    /* ------------------------------------------------------------------ */
    /* Bilingual fallback and the excerpt                                  */
    /* ------------------------------------------------------------------ */

    public function testAnEmptyEnglishFieldFallsBackToTheDutchOne(): void
    {
        $id = $this->createPost([
            'title' => 'Testbericht tweetalig',
            'title_en' => '',
            'excerpt' => 'Nederlandse samenvatting.',
            'excerpt_en' => '',
            'body' => '<p>Nederlandse tekst.</p>',
            'body_en' => '',
        ]);
        $post = $this->posts->find($id);

        $this->assertSame('Testbericht tweetalig', BlogContent::title($post, 'en'));
        $this->assertSame('Nederlandse samenvatting.', BlogContent::excerpt($post, 'en'));
        $this->assertSame('<p>Nederlandse tekst.</p>', BlogContent::body($post, 'en'));
    }

    public function testAFilledEnglishFieldIsUsed(): void
    {
        $id = $this->createPost([
            'title' => 'Testbericht tweetalig twee',
            'title_en' => 'Bilingual test post two',
            'excerpt' => 'NL',
            'excerpt_en' => 'EN',
        ]);
        $post = $this->posts->find($id);

        $this->assertSame('Bilingual test post two', BlogContent::title($post, 'en'));
        $this->assertSame('EN', BlogContent::excerpt($post, 'en'));
        $this->assertSame('Testbericht tweetalig twee', BlogContent::title($post, 'nl'));
    }

    /** No excerpt: a short plain-text opening of the body, cut on a word. */
    public function testAMissingExcerptFallsBackToTheOpeningOfTheBody(): void
    {
        $id = $this->createPost([
            'title' => 'Testbericht zonder samenvatting',
            'excerpt' => '',
            'body' => '<h2>Kop</h2><p>' . str_repeat('woord ', 60) . '</p>',
        ]);

        $excerpt = BlogContent::excerpt($this->posts->find($id), 'nl');

        $this->assertStringStartsWith('Kop woord', $excerpt);
        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertLessThan(180, mb_strlen($excerpt));
    }

    /** The body is sanitised on the way out as well as on the way in. */
    public function testTheBodyIsSanitisedWhenItIsRead(): void
    {
        $id = $this->createPost([
            'title' => 'Testbericht met script',
            'body' => '<p>Veilig</p><script>alert(1)</script>',
        ]);

        $body = BlogContent::body($this->posts->find($id), 'nl');

        $this->assertStringContainsString('Veilig', $body);
        $this->assertStringNotContainsString('<script', $body);
    }

    /* ------------------------------------------------------------------ */
    /* Listing, neighbours and related posts                               */
    /* ------------------------------------------------------------------ */

    public function testTheListingIsNewestFirstAndPagesWithoutLoadingEverything(): void
    {
        BlogSettings::overrideForTests([BlogSettings::POSTS_PER_PAGE => '3']);

        $slugs = [];
        foreach ([1, 2, 3, 4, 5] as $index) {
            $id = $this->createPost([
                'title' => 'Testbericht paginering ' . $index,
                'status' => BlogPostStatus::PUBLISHED,
                'published_at' => (new \DateTimeImmutable('-' . $index . ' days'))->format(BlogClock::SQL_FORMAT),
            ]);
            $slugs[$index] = (string) $this->posts->find($id)['slug'];
        }

        $now = BlogClock::nowForSql();
        $page = $this->posts->findPublic($now, 3, 0);

        $this->assertCount(3, $page);

        $dates = array_column($page, 'published_at');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'the listing is newest first');

        // Page 2 continues where page 1 stopped rather than repeating it.
        $second = array_column($this->posts->findPublic($now, 3, 3), 'slug');
        $this->assertSame([], array_intersect(array_column($page, 'slug'), $second));
    }

    public function testPreviousAndNextWalkPublicationOrderInOppositeDirections(): void
    {
        $ids = [];
        foreach ([3, 2, 1] as $daysAgo) {
            $ids[$daysAgo] = $this->createPost([
                'title' => 'Testbericht buur ' . $daysAgo,
                'status' => BlogPostStatus::PUBLISHED,
                'published_at' => (new \DateTimeImmutable('-' . $daysAgo . ' days'))->format(BlogClock::SQL_FORMAT),
            ]);
        }

        $middle = $this->posts->find($ids[2]);
        $now = BlogClock::nowForSql();

        $previous = $this->posts->findNeighbour($middle, $now, 'previous');
        $next = $this->posts->findNeighbour($middle, $now, 'next');

        $this->assertSame($ids[3], (int) $previous['id'], 'previous is the older post');
        $this->assertSame($ids[1], (int) $next['id'], 'next is the newer post');
    }

    /**
     * Related posts are the ones that share the most categories and tags,
     * newest first — deterministic, and the post itself is never in its own
     * list.
     */
    public function testRelatedPostsAreTheOnesThatShareTheMostTaxonomy(): void
    {
        $categoryA = $this->createCategory('Testcategorie A');
        $categoryB = $this->createCategory('Testcategorie B');

        $subject = $this->publishedPost('Testbericht onderwerp', [$categoryA, $categoryB]);
        $strong = $this->publishedPost('Testbericht sterke overlap', [$categoryA, $categoryB]);
        $weak = $this->publishedPost('Testbericht zwakke overlap', [$categoryA]);
        $unrelated = $this->publishedPost('Testbericht geen overlap', []);

        $related = $this->posts->findRelated(
            $subject,
            [$categoryA, $categoryB],
            [],
            BlogClock::nowForSql(),
            5
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $related);

        $this->assertNotContains($subject, $ids, 'a post is never related to itself');
        $this->assertNotContains($unrelated, $ids);
        $this->assertSame([$strong, $weak], array_slice($ids, 0, 2), 'most overlap first');
    }

    public function testAPostWithNoTaxonomyGetsNoRelatedPostsRatherThanRandomOnes(): void
    {
        $id = $this->publishedPost('Testbericht alleenstaand', []);

        $this->assertSame([], $this->posts->findRelated($id, [], [], BlogClock::nowForSql(), 5));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $values */
    private function createPost(array $values): int
    {
        $title = (string) ($values['title'] ?? 'Testbericht');
        $slug = self::PREFIX . BlogSlug::sanitize($title);

        $values['slug'] = BlogSlug::unique(
            $slug,
            $slug,
            fn (string $candidate): bool => $this->posts->slugExists($candidate, null)
        );
        $values['status'] ??= BlogPostStatus::DRAFT;

        $id = $this->posts->create($values);
        $this->createdPosts[] = $id;

        return $id;
    }

    /** @param list<int> $categoryIds */
    private function publishedPost(string $title, array $categoryIds): int
    {
        static $offset = 0;
        $offset++;

        $id = $this->createPost([
            'title' => $title,
            'status' => BlogPostStatus::PUBLISHED,
            'published_at' => (new \DateTimeImmutable('-' . $offset . ' hours'))->format(BlogClock::SQL_FORMAT),
        ]);

        if ($categoryIds !== []) {
            $this->posts->setCategories($id, $categoryIds);
        }

        return $id;
    }

    private function createCategory(string $name): int
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
