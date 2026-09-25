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

            \Tests\Support\BlockTextFixture::removeForPage('rich_text_sections', (string) $row['content_key']);
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

    /**
     * A slug belongs to ONE language since Multilingual 2.0 phase 6
     * (docs/multilingual/ROUTING.md), so every call below says which. The
     * default language is what a new page is created in, and the language
     * whose address is kept in step with the neutral `pages.slug` column.
     */
    private function defaultLanguage(): string
    {
        return \App\Service\PageLocalization::defaultLanguage();
    }

    public function testSlugIsSuggestedFromTheTitle(): void
    {
        $this->assertSame(
            'frequently-asked-questions',
            PageService::generateSlug($this->repository, 'Frequently Asked Questions', $this->defaultLanguage())
        );
    }

    public function testGeneratedSlugAvoidsAnExistingOne(): void
    {
        $this->createPage('-dup');

        $generated = PageService::generateSlug($this->repository, 'Testpagina dup', $this->defaultLanguage());
        $this->assertNotSame(self::PREFIX . '-dup', $generated);
    }

    public function testDuplicateSlugIsRejected(): void
    {
        $existingId = $this->createPage('-taken');

        $this->assertNotNull(
            PageService::validateSlug($this->repository, self::PREFIX . '-taken', null, $this->defaultLanguage())
        );

        // ...but a page may of course keep its own slug when saving itself.
        $this->assertNull(
            PageService::validateSlug($this->repository, self::PREFIX . '-taken', $existingId, $this->defaultLanguage())
        );
    }

    public function testReservedSlugIsRejected(): void
    {
        foreach (['admin', 'api', 'shop', 'cart', 'checkout', 'product', 'pagina', 'storage'] as $reserved) {
            $this->assertNotNull(
                PageService::validateSlug($this->repository, $reserved, null, $this->defaultLanguage()),
                "\"{$reserved}\" is an application route and must never be usable as a page slug"
            );
        }
    }

    public function testGeneratedSlugSkipsReservedRoutes(): void
    {
        $this->assertNotSame(
            'checkout',
            PageService::generateSlug($this->repository, 'Checkout', $this->defaultLanguage())
        );
    }

    /**
     * A reserved word is refused with a reason an editor can act on: the
     * module that owns it, also while that module is off ("portfolio" is the
     * Portfolio's), else the website's own route. And a page titled
     * "Portfolio" whose address therefore became /portfolio-2 is told why,
     * instead of discovering a mysterious -2.
     */
    public function testAReservedWordIsExplainedByWhoOwnsIt(): void
    {
        $portfolio = (string) PageService::validateSlug($this->repository, 'portfolio', null, $this->defaultLanguage());
        $this->assertStringContainsString('Portfolio', $portfolio, 'the owning module is named');
        $this->assertStringContainsString('/portfolio', $portfolio);
        $this->assertSame(\App\Service\ReservedRoutes::moduleReserving('portfolio')?->key(), 'portfolio');

        $core = (string) PageService::validateSlug($this->repository, 'admin', null, $this->defaultLanguage());
        $this->assertNull(\App\Service\ReservedRoutes::moduleReserving('admin'));
        $this->assertStringContainsString('/admin', $core);

        $generated = PageService::generateSlug($this->repository, 'Portfolio', $this->defaultLanguage());
        $this->assertNotSame('portfolio', $generated);
        $notice = PageService::generatedSlugNotice('Portfolio', $generated);
        $this->assertNotNull($notice);
        $this->assertStringContainsString($portfolio, (string) $notice, 'the same reason');
        $this->assertStringContainsString('/' . $generated, (string) $notice, 'and the address it got instead');

        $this->assertNull(PageService::generatedSlugNotice('ZZ Gewone titel', 'zz-gewone-titel'));
        $this->assertNull(PageService::generatedSlugNotice('', 'pagina'));
    }

    /**
     * A LANGUAGE CODE can never be a page slug: /en would be
     * indistinguishable from the English prefix, and the dispatcher peels the
     * prefix first — so the page would simply be unreachable
     * (App\Service\Routing\ReservedPaths).
     */
    public function testALanguageCodeIsReservedAsASlug(): void
    {
        foreach (\App\Service\Language\SiteLanguages::all() as $language) {
            $this->assertNotNull(
                PageService::validateSlug($this->repository, $language->code, null, $this->defaultLanguage()),
                'a page must never be able to claim the language word "' . $language->code . '"'
            );
        }
    }

    /**
     * Two languages may spell the same address: /over-ons and /en/over-ons
     * are different URLs. A clash is only a clash inside ONE language.
     */
    public function testTheSameWordIsFreeInAnotherLanguage(): void
    {
        $other = null;
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            if ($code !== $this->defaultLanguage()) {
                $other = $code;
                break;
            }
        }

        if ($other === null) {
            $this->markTestSkipped('this installation publishes one language');
        }

        $this->createPage('-shared');

        $this->assertNotNull(
            PageService::validateSlug($this->repository, self::PREFIX . '-shared', null, $this->defaultLanguage())
        );
        $this->assertNull(
            PageService::validateSlug($this->repository, self::PREFIX . '-shared', null, $other)
        );
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
