<?php

namespace Tests\Service;

use App\Database;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\RichTextRepository;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The write-side rules of the page model: slug normalisation/uniqueness/
 * reserved-route rejection, content-key generation, and the three things
 * deleting a page must get right (refuse a protected system page, refuse a
 * page still linked from navigation/footer, and otherwise take its sections
 * and their content with it).
 *
 * Integration tests against the real dev database — PageRepository is a thin
 * PDO wrapper with no mocking seam, same convention as
 * Tests\Repository\PageSectionRepositoryTest. Every page created here uses
 * an obviously-fake '__test_' prefix and is removed again in tearDown().
 */
class PageServiceTest extends TestCase
{
    private const PREFIX = '__test-page-service';

    private PageRepository $repository;

    /** @var list<int> */
    private array $createdNavItemIds = [];

    protected function setUp(): void
    {
        $this->repository = new PageRepository();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $db = Database::connection();

        foreach ($this->createdNavItemIds as $navId) {
            $stmt = $db->prepare('DELETE FROM nav_items WHERE id = :id');
            $stmt->execute(['id' => $navId]);
        }
        $this->createdNavItemIds = [];

        $stmt = $db->prepare('SELECT id, content_key FROM pages WHERE content_key LIKE :prefix');
        $stmt->execute(['prefix' => self::PREFIX . '%']);

        foreach ($stmt->fetchAll() as $row) {
            $delSections = $db->prepare('DELETE FROM page_sections WHERE page_id = :id');
            $delSections->execute(['id' => (int) $row['id']]);

            $delRichText = $db->prepare('DELETE FROM rich_text_sections WHERE page_slug = :key');
            $delRichText->execute(['key' => (string) $row['content_key']]);

            $delPage = $db->prepare('DELETE FROM pages WHERE id = :id');
            $delPage->execute(['id' => (int) $row['id']]);
        }

        PageContent::clearCache();
    }

    private function createPage(string $suffix, string $status = PageContent::STATUS_DRAFT): int
    {
        $key = self::PREFIX . $suffix;

        return \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => $status,
        ], 'Testpagina ' . $suffix);
    }

    // ---------------------------------------------------------------- slug

    public function testSlugIsNormalisedToLowercaseAsciiWithSingleHyphens(): void
    {
        $this->assertSame('veelgestelde-vragen', PageService::sanitizeSlug('Veelgestelde Vragen'));
        $this->assertSame('over-mij', PageService::sanitizeSlug('  Over   mij!  '));
        $this->assertSame('cafe-brulee', PageService::sanitizeSlug('Café Brûlée'));
        $this->assertSame('', PageService::sanitizeSlug('***'));
    }

    public function testSlugIsSuggestedFromTheTitle(): void
    {
        $this->assertSame(
            'frequently-asked-questions',
            PageService::generateSlug($this->repository, 'Frequently Asked Questions')
        );
    }

    public function testGeneratedSlugAvoidsAnExistingOne(): void
    {
        $this->createPage('-dup');

        $generated = PageService::generateSlug($this->repository, 'Testpagina dup');
        $this->assertNotSame(self::PREFIX . '-dup', $generated);
    }

    public function testDuplicateSlugIsRejected(): void
    {
        $existingId = $this->createPage('-taken');

        $this->assertNotNull(PageService::validateSlug($this->repository, self::PREFIX . '-taken', null));

        // ...but a page may of course keep its own slug when saving itself.
        $this->assertNull(PageService::validateSlug($this->repository, self::PREFIX . '-taken', $existingId));
    }

    public function testReservedSlugIsRejected(): void
    {
        foreach (['admin', 'api', 'shop', 'cart', 'checkout', 'product', 'pagina', 'storage'] as $reserved) {
            $this->assertNotNull(
                PageService::validateSlug($this->repository, $reserved, null),
                "\"{$reserved}\" is an application route and must never be usable as a page slug"
            );
        }
    }

    public function testGeneratedSlugSkipsReservedRoutes(): void
    {
        $this->assertNotSame('checkout', PageService::generateSlug($this->repository, 'Checkout'));
    }

    public function testContentKeyIsUniqueEvenWhenASlugIsReused(): void
    {
        $this->createPage('-key');

        $key = PageService::generateContentKey($this->repository, self::PREFIX . '-key');

        $this->assertNotSame(self::PREFIX . '-key', $key);
        $this->assertStringStartsWith(self::PREFIX . '-key-', $key);
    }

    // -------------------------------------------------------------- delete

    public function testSystemPageCannotBeDeleted(): void
    {
        $home = $this->repository->findByContentKey('index');
        $this->assertNotNull($home, 'expected the homepage row — run phinx migrate');

        $this->expectException(\RuntimeException::class);
        PageService::delete($home);
    }

    public function testPageStillLinkedFromNavigationCannotBeDeleted(): void
    {
        $pageId = $this->createPage('-linked', PageContent::STATUS_PUBLISHED);

        $navRepository = new NavigationRepository();
        $this->createdNavItemIds[] = $navRepository->create([
            'label_nl' => 'Testlink',
            'label_en' => 'Test link',
            'link_type' => 'page',
            'target_page_id' => $pageId,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => false,
        ]);

        $references = PageService::references($pageId);
        $this->assertSame(1, $references['nav']);
        $this->assertSame(1, $references['total']);

        try {
            PageService::delete($this->repository->findById($pageId));
            $this->fail('deleting a page that navigation still links to must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('navigatie', $e->getMessage());
        }

        $this->assertNotNull($this->repository->findById($pageId), 'the page must still exist after a refused delete');
    }

    public function testUnreferencedPageIsDeletedTogetherWithItsSections(): void
    {
        $pageId = $this->createPage('-deletable');
        $page = $this->repository->findById($pageId);
        $contentKey = (string) $page['content_key'];

        $sectionRepository = new PageSectionRepository();
        [$sectionId, $sectionKey] = SectionRegistry::create('rich_text', $contentKey);
        $sectionRepository->create($pageId, $contentKey, 'rich_text', $sectionKey, $sectionId);

        $this->assertCount(1, $sectionRepository->findForPage($pageId));

        PageService::delete($page);

        $this->assertNull($this->repository->findById($pageId));
        $this->assertSame([], $sectionRepository->findForPage($pageId));
        $this->assertNull(
            (new RichTextRepository())->findById($sectionId),
            "the section's own content row must be deleted too, not orphaned"
        );
    }
}
