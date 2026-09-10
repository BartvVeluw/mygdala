<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use App\Repository\RedirectRepository;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogContent;
use App\Service\Blog\BlogPostService;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSlug;
use App\Service\Blog\BlogTaxonomy;
use App\Service\Blog\BlogUrls;
use PHPUnit\Framework\TestCase;

/**
 * Categories and tags: what they are, how a tag stops being typed twice, and
 * what deleting one does and does not do.
 *
 * Own data, removed in tearDown() by exact id — never a LIKE pattern. The
 * redirects this test creates are cleaned up the same way.
 */
final class BlogTaxonomyTest extends TestCase
{
    private const PREFIX = 'zz-blogtax-';

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
    /* Categories                                                          */
    /* ------------------------------------------------------------------ */

    public function testACategoryCarriesANameASlugAndAnOptionalDescriptionInBothLanguages(): void
    {
        $id = $this->createCategory('Testcategorie materialen', [
            'name_en' => 'Test category materials',
            'description' => 'Waar we mee werken.',
            'description_en' => 'What we work with.',
        ]);

        $category = $this->categories->find($id);

        $this->assertSame('Testcategorie materialen', BlogContent::categoryName($category, 'nl'));
        $this->assertSame('Test category materials', BlogContent::categoryName($category, 'en'));
        $this->assertSame('Waar we mee werken.', $category['description']);
    }

    public function testAnEmptyEnglishCategoryNameFallsBackToTheDutchOne(): void
    {
        $id = $this->createCategory('Testcategorie zonder EN', ['name_en' => '']);

        $this->assertSame('Testcategorie zonder EN', BlogContent::categoryName($this->categories->find($id), 'en'));
    }

    public function testAnInactiveCategoryIsNotFoundByThePublicLookup(): void
    {
        $id = $this->createCategory('Testcategorie inactief', ['is_active' => false]);
        $slug = (string) $this->categories->find($id)['slug'];

        $this->assertNotNull($this->categories->findBySlug($slug), 'still there for the admin');
        $this->assertNull($this->categories->findActiveBySlug($slug), 'but not for a visitor');
        $this->assertNull(BlogContent::listing(['category' => $slug]));
    }

    public function testAPostCanBelongToSeveralCategoriesAndTheFirstOneLeads(): void
    {
        $first = $this->createCategory('Testcategorie alfa', ['sort_order' => 10]);
        $second = $this->createCategory('Testcategorie beta', ['sort_order' => 20]);

        $postId = $this->createPost('Testbericht met twee categorieën');
        // Deliberately in the "wrong" order: the primary category comes from
        // the categories' own ordering, not from how they were submitted.
        $this->posts->setCategories($postId, [$second, $first]);

        $this->assertSame([$first, $second], $this->posts->categoryIdsFor($postId));

        $decorated = BlogContent::decorateForAdmin($this->posts->find($postId));
        $this->assertCount(2, $decorated['categories']);
        $this->assertSame($first, (int) $decorated['primary_category']['id']);
    }

    /**
     * Deleting a category is safe by construction: the link rows cascade, the
     * posts stay exactly as they were, and a post that loses its only
     * category simply becomes uncategorised.
     */
    public function testDeletingACategoryLeavesEveryPostIntact(): void
    {
        $doomed = $this->createCategory('Testcategorie die weggaat');
        $kept = $this->createCategory('Testcategorie die blijft');

        $both = $this->createPost('Testbericht in twee categorieën');
        $onlyDoomed = $this->createPost('Testbericht in één categorie');

        $this->posts->setCategories($both, [$doomed, $kept]);
        $this->posts->setCategories($onlyDoomed, [$doomed]);

        $this->assertTrue($this->categories->delete($doomed));
        $this->createdCategories = array_values(array_diff($this->createdCategories, [$doomed]));

        $this->assertNotNull($this->posts->find($both), 'the post is still there');
        $this->assertNotNull($this->posts->find($onlyDoomed));
        $this->assertSame([$kept], $this->posts->categoryIdsFor($both), 'its other category is untouched');
        $this->assertSame([], $this->posts->categoryIdsFor($onlyDoomed), 'and this one is simply uncategorised');
    }

    public function testACategoryCountsOnlyItsPublicPosts(): void
    {
        $categoryId = $this->createCategory('Testcategorie telling');

        $public = $this->createPost('Testbericht publiek', BlogPostStatus::PUBLISHED, '-1 hour');
        $draft = $this->createPost('Testbericht concept telling');
        $future = $this->createPost('Testbericht toekomst telling', BlogPostStatus::SCHEDULED, '+1 month');

        foreach ([$public, $draft, $future] as $postId) {
            $this->posts->setCategories($postId, [$categoryId]);
        }

        $counts = $this->posts->publicCountsByCategory(BlogClock::nowForSql());

        $this->assertSame(1, $counts[$categoryId] ?? 0);
        $this->assertSame(3, $this->posts->countByCategory($categoryId), 'the admin sees all three');
    }

    /* ------------------------------------------------------------------ */
    /* Tags                                                                */
    /* ------------------------------------------------------------------ */

    /** The de-duplication rule: the normalised slug is the identity. */
    public function testATagTypedDifferentlyIsTheSameTag(): void
    {
        $ids = BlogPostService::resolveTagIds('Laser Graveren, laser-graveren, LASER GRAVEREN');
        $this->rememberTags($ids);

        $this->assertCount(1, $ids, 'three spellings, one tag');
        $this->assertSame('laser-graveren', $this->tags->find($ids[0])['slug']);
    }

    public function testATagIsReusedAcrossPostsRatherThanRecreated(): void
    {
        $first = BlogPostService::resolveTagIds('zz-blogtax-hergebruik');
        $second = BlogPostService::resolveTagIds('ZZ Blogtax Hergebruik');
        $this->rememberTags(array_merge($first, $second));

        $this->assertSame($first, $second);
    }

    public function testATagLineIsSplitTrimmedAndCapped(): void
    {
        $names = [];
        for ($i = 1; $i <= BlogPostService::MAX_TAGS_PER_POST + 4; $i++) {
            $names[] = 'zz-blogtax-veel-' . $i;
        }

        $ids = BlogPostService::resolveTagIds(implode(' ,  ', $names));
        $this->rememberTags($ids);

        $this->assertCount(BlogPostService::MAX_TAGS_PER_POST, $ids);
    }

    public function testANameThatNormalisesToNothingCreatesNoTag(): void
    {
        $before = count($this->tags->all());

        $this->assertSame([], BlogPostService::resolveTagIds('  ,  !!! , ,  '));
        $this->assertCount($before, $this->tags->all());
    }

    public function testDeletingATagLeavesItsPostsIntact(): void
    {
        $postId = $this->createPost('Testbericht met tags');
        $ids = BlogPostService::resolveTagIds('zz-blogtax-blijft, zz-blogtax-verdwijnt');
        $this->rememberTags($ids);
        $this->posts->setTags($postId, $ids);

        $doomed = $ids[1];
        $this->assertTrue($this->tags->delete($doomed));
        $this->createdTags = array_values(array_diff($this->createdTags, [$doomed]));

        $this->assertNotNull($this->posts->find($postId));
        $this->assertSame([$ids[0]], $this->posts->tagIdsFor($postId));
    }

    public function testAPostsTagsAreReplacedNotAppended(): void
    {
        $postId = $this->createPost('Testbericht tags vervangen');

        $first = BlogPostService::resolveTagIds('zz-blogtax-een, zz-blogtax-twee');
        $second = BlogPostService::resolveTagIds('zz-blogtax-twee, zz-blogtax-drie');
        $this->rememberTags(array_merge($first, $second));

        $this->posts->setTags($postId, $first);
        $this->posts->setTags($postId, $second);

        // Sorted on both sides: the repository returns a post's tags in name
        // order, which is what the editor sees, not the order they arrived in.
        $stored = $this->posts->tagIdsFor($postId);
        sort($stored);
        sort($second);

        $this->assertSame($second, $stored);
    }

    /* ------------------------------------------------------------------ */
    /* Archive renames                                                     */
    /* ------------------------------------------------------------------ */

    public function testRenamingALiveCategoryArchiveKeepsItsOldUrlWorking(): void
    {
        $from = self::PREFIX . 'oude-categorie';
        $to = self::PREFIX . 'nieuwe-categorie';
        $this->createdRedirectPaths[] = '/' . BlogUrls::categoryRedirectPath($from);

        $this->assertTrue(BlogTaxonomy::recordCategorySlugChange($from, $to, true, true));

        $row = $this->redirects->findBySourcePath('/' . BlogUrls::categoryRedirectPath($from));

        $this->assertNotNull($row);
        $this->assertSame('/' . BlogUrls::categoryRedirectPath($to), $row['target_value']);
        $this->assertSame(301, (int) $row['status_code']);
        $this->assertSame('slug_change', $row['origin']);
    }

    /** An archive that was not reachable has no URL worth preserving. */
    public function testRenamingAnInactiveCategoryWritesNoRedirect(): void
    {
        $from = self::PREFIX . 'inactieve-categorie';
        $to = self::PREFIX . 'hernoemde-inactieve';
        $this->createdRedirectPaths[] = '/' . BlogUrls::categoryRedirectPath($from);

        $this->assertFalse(BlogTaxonomy::recordCategorySlugChange($from, $to, false, true));
        $this->assertNull($this->redirects->findBySourcePath('/' . BlogUrls::categoryRedirectPath($from)));
    }

    public function testRenamingATagArchiveKeepsItsOldUrlWorkingToo(): void
    {
        $from = self::PREFIX . 'oude-tag';
        $to = self::PREFIX . 'nieuwe-tag';
        $this->createdRedirectPaths[] = '/' . BlogUrls::tagRedirectPath($from);

        $this->assertTrue(BlogTaxonomy::recordTagSlugChange($from, $to));

        $row = $this->redirects->findBySourcePath('/' . BlogUrls::tagRedirectPath($from));

        $this->assertNotNull($row);
        $this->assertSame('/' . BlogUrls::tagRedirectPath($to), $row['target_value']);
    }

    public function testARenameThatChangesNothingWritesNothing(): void
    {
        $slug = self::PREFIX . 'zelfde-slug';
        $this->createdRedirectPaths[] = '/' . BlogUrls::categoryRedirectPath($slug);

        $this->assertFalse(BlogTaxonomy::recordCategorySlugChange($slug, $slug, true, true));
        $this->assertNull($this->redirects->findBySourcePath('/' . BlogUrls::categoryRedirectPath($slug)));
    }

    /* ------------------------------------------------------------------ */

    private function createPost(
        string $title,
        string $status = BlogPostStatus::DRAFT,
        ?string $when = null
    ): int {
        $slug = BlogSlug::unique(
            self::PREFIX . BlogSlug::sanitize($title),
            $title,
            fn (string $candidate): bool => $this->posts->slugExists($candidate, null)
        );

        $id = $this->posts->create([
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'published_at' => $when === null ? null : (new \DateTimeImmutable($when))->format(BlogClock::SQL_FORMAT),
        ]);

        $this->createdPosts[] = $id;

        return $id;
    }

    /** @param array<string, mixed> $values */
    private function createCategory(string $name, array $values = []): int
    {
        $id = $this->categories->create($values + [
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
            if (!in_array((int) $id, $this->createdTags, true)) {
                $this->createdTags[] = (int) $id;
            }
        }
    }
}
