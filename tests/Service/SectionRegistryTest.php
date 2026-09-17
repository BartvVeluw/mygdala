<?php

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\StatStripRepository;
use App\Repository\StepListRepository;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use App\Service\StatStripContent;
use App\Service\StepListContent;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the test database — see
 * Tests\Repository\PageSectionRepositoryTest's docblock for why this layer is
 * tested against a real database at all. Unlike that test,
 * SectionRegistry::create()/delete() DO write to content tables
 * (feature_grids, cta_bands, ...), and SectionRegistry::availableForPage()
 * takes a real `pages` row.
 *
 * That row used to be the site's own Contact page. It is now a throwaway page
 * this test creates and drops again, because nothing here is actually about
 * the Contact page: every rule under test (what may be added, what may repeat,
 * what a delete takes with it) applies to any ordinary CMS page, and reading
 * them off editor-managed content only made the test fail whenever somebody
 * edited the site. Where a test needs a page to already carry a block, it
 * attaches that block itself.
 *
 * tearDown() removes exactly the blocks a test attached — by id, via the very
 * SectionRegistry::delete() the CMS uses — and then the page itself.
 */
class SectionRegistryTest extends TestCase
{
    /**
     * Both the content_key and the slug of this test's own page. Underscores
     * keep it out of the `[a-z0-9-]` shape a real slug has, so it can never
     * collide with an editor's page or be reachable on the public site.
     */
    private const TEST_PAGE = '__test_sections__';

    /** @var list<int> page_sections ids created during the running test, deleted in tearDown */
    private array $createdPageSectionIds = [];

    /** @var array<string, mixed> this test's own `pages` row */
    private array $page;

    protected function setUp(): void
    {
        $this->removeTestPage();

        $pages = new PageRepository();
        \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'status' => 'draft',
        ], 'Sectieregister-testpagina');

        $page = $pages->findByContentKey(self::TEST_PAGE);
        $this->assertNotNull($page, 'the test page should have been created');
        $this->page = $page;
    }

    protected function tearDown(): void
    {
        $repository = new PageSectionRepository();

        foreach ($this->createdPageSectionIds as $id) {
            $row = $repository->findById($id);
            if ($row === null) {
                // A test deleted this one itself.
                continue;
            }

            if (SectionRegistry::isDeletable((string) $row['section_type'])) {
                SectionRegistry::delete($row, $repository);
                continue;
            }

            $repository->delete($id);
        }

        $this->createdPageSectionIds = [];

        $this->removeTestPage();
    }

    /**
     * Drops the page and anything still attached to it. Runs before the page
     * is created as well, so a run that died halfway cannot leave a row behind
     * that would break the next one.
     */
    private function removeTestPage(): void
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::TEST_PAGE]);
        $row = $stmt->fetch();

        if ($row === false) {
            return;
        }

        $pageId = (int) $row['id'];

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage($pageId) as $section) {
            if (SectionRegistry::isDeletable((string) $section['section_type'])) {
                SectionRegistry::delete($section, $sections);
                continue;
            }

            $sections->delete((int) $section['id']);
        }

        $delete = $db->prepare('DELETE FROM pages WHERE id = :id');
        $delete->execute(['id' => $pageId]);
    }

    /**
     * Attaches via PageSectionRepository::create() and remembers the new
     * page_sections id for tearDown() — a thin wrapper so every test only
     * ever deletes rows it created itself.
     */
    private function attach(PageSectionRepository $repository, string $type, ?string $sectionKey, int $sectionId): int
    {
        $id = $repository->create((int) $this->page['id'], self::TEST_PAGE, $type, $sectionKey, $sectionId);
        $this->createdPageSectionIds[] = $id;

        return $id;
    }

    public function testUnknownSectionTypeIsRejected(): void
    {
        $this->assertFalse(SectionRegistry::exists('not_a_real_type'));
        $this->assertArrayNotHasKey(
            'not_a_real_type',
            SectionRegistry::availableForPage($this->page, new PageSectionRepository())
        );
    }

    public function testHomepageHeroIsNotDeletableButOrdinaryTypesAre(): void
    {
        $this->assertFalse(SectionRegistry::isDeletable('homepage_hero'));
        $this->assertTrue(SectionRegistry::isDeletable('feature_grid'));
        $this->assertTrue(SectionRegistry::isDeletable('cta_band'));
    }

    public function testACappedTypeIsNotOfferedTwiceButARepeatableOneIs(): void
    {
        $repository = new PageSectionRepository();

        $available = SectionRegistry::availableForPage($this->page, $repository);
        $this->assertArrayHasKey('cta_band', $available);
        $this->assertArrayHasKey(
            'contact_form',
            $available,
            'a page without a quote form yet must be offered one'
        );

        // The quote form is capped at one per page: attaching it takes it off
        // the menu.
        [$formId, $formKey] = SectionRegistry::create('contact_form', self::TEST_PAGE);
        $this->attach($repository, 'contact_form', $formKey, $formId);

        $this->assertArrayNotHasKey(
            'contact_form',
            SectionRegistry::availableForPage($this->page, $repository),
            'a page may hold only one quote form'
        );

        // The CTA band became repeatable in phase 2: attaching one must not
        // take it off the menu the way a capped type does.
        [$sectionId, $sectionKey] = SectionRegistry::create('cta_band', self::TEST_PAGE);
        $this->attach($repository, 'cta_band', $sectionKey, $sectionId);

        $available = SectionRegistry::availableForPage($this->page, $repository);
        $this->assertArrayHasKey('cta_band', $available, 'a repeatable type must still be offered once attached');
        $this->assertArrayHasKey('feature_grid', $available);
    }

    public function testCreateAttachesNewInstanceToCorrectPage(): void
    {
        $repository = new PageSectionRepository();

        [$sectionId, $sectionKey] = SectionRegistry::create('feature_grid', self::TEST_PAGE);
        $id = $this->attach($repository, 'feature_grid', $sectionKey, $sectionId);

        $row = $repository->findById($id);
        $this->assertNotNull($row);
        $this->assertSame(self::TEST_PAGE, $row['page_slug']);
        $this->assertSame('feature_grid', $row['section_type']);
        $this->assertSame($sectionId, (int) $row['section_id']);
    }

    public function testEveryManuallyAddableTypeCanActuallyBeCreated(): void
    {
        $repository = new PageSectionRepository();
        $page = $this->page;

        $available = SectionRegistry::availableForPage($page, $repository);
        $this->assertNotSame([], $available, 'an ordinary page must be offered something to add');

        foreach ($available as $type => $label) {
            [$sectionId, $sectionKey] = SectionRegistry::create($type, self::TEST_PAGE);

            $this->assertGreaterThan(0, $sectionId, "\"{$type}\" is offered in \"+ Sectie toevoegen\" but created no content row");

            $this->attach($repository, $type, $sectionKey, $sectionId);
        }
    }

    public function testDeletingOneInstanceDoesNotDeleteAnothersContent(): void
    {
        $repository = new PageSectionRepository();

        [$idA, $keyA] = SectionRegistry::create('feature_grid', self::TEST_PAGE);
        $pageSectionA = $repository->findById($this->attach($repository, 'feature_grid', $keyA, $idA));

        [$idB, $keyB] = SectionRegistry::create('feature_grid', self::TEST_PAGE);
        $this->attach($repository, 'feature_grid', $keyB, $idB);

        SectionRegistry::delete($pageSectionA, $repository);

        $db = Database::connection();

        $stmtA = $db->prepare('SELECT id FROM feature_grids WHERE id = :id');
        $stmtA->execute(['id' => $idA]);
        $this->assertFalse($stmtA->fetch(), 'the deleted instance\'s own content row must be gone');

        $stmtB = $db->prepare('SELECT id FROM feature_grids WHERE id = :id');
        $stmtB->execute(['id' => $idB]);
        $this->assertNotFalse($stmtB->fetch(), 'the other instance\'s content row must be untouched');
    }

    /* ------------------------------------------------------------------ */
    /* The reveal group is part of the instance, not of the type           */
    /* ------------------------------------------------------------------ */

    /*
     * renderPage() hands every block a reveal group of its own —
     * "<section_type>-<page_sections.id>" — and assets/js/core.js staggers
     * the elements that share one by their document order. A block type that
     * prints a fixed string instead therefore merges its instances into a
     * single group across the whole page: the second strip's first tile
     * inherits the delay of the fourth tile of the first, and the ones after
     * it pile up against the 450ms ceiling. These three tests assert on the
     * markup a visitor actually receives, because that is the only place the
     * mistake is visible.
     */

    public function testTwoStatStripsOnOnePageEachGetTheirOwnRevealGroup(): void
    {
        $repository = new PageSectionRepository();
        $strips = new StatStripRepository();

        $pageSectionIds = [];
        foreach (['Eerste', 'Tweede'] as $label) {
            [$sectionId, $sectionKey] = SectionRegistry::create('stat_strip', self::TEST_PAGE);
            \App\Service\Blocks\BlockLocalization::save('stat_strip_items', $strips->createItem($sectionId), 'nl', [
                'primary_text' => $label,
                'secondary_text' => 'stat',
            ]);
            $pageSectionIds[] = $this->attach($repository, 'stat_strip', $sectionKey, $sectionId);
        }

        StatStripContent::clearCache();

        $this->assertSame(
            ['stat_strip-' . $pageSectionIds[0], 'stat_strip-' . $pageSectionIds[1]],
            $this->revealGroupsOnPage(),
            'each strip must carry the reveal group SectionRegistry gave its own instance'
        );
    }

    public function testTwoStepListsOnOnePageEachGetTheirOwnRevealGroup(): void
    {
        $repository = new PageSectionRepository();
        $lists = new StepListRepository();

        $pageSectionIds = [];
        foreach (['Eerste', 'Tweede'] as $label) {
            [$sectionId, $sectionKey] = SectionRegistry::create('step_list', self::TEST_PAGE);
            \App\Service\Blocks\BlockLocalization::save('step_list_items', $lists->createItem($sectionId), 'nl', [
                'title' => $label,
                'body' => 'stap',
            ]);
            $pageSectionIds[] = $this->attach($repository, 'step_list', $sectionKey, $sectionId);
        }

        StepListContent::clearCache();

        $this->assertSame(
            ['step_list-' . $pageSectionIds[0], 'step_list-' . $pageSectionIds[1]],
            $this->revealGroupsOnPage(),
            'each step list must carry the reveal group SectionRegistry gave its own instance'
        );
    }

    public function testNoTwoInstancesOfOneTypeShareARevealGroup(): void
    {
        $repository = new PageSectionRepository();
        $strips = new StatStripRepository();
        $lists = new StepListRepository();

        foreach (['Eerste', 'Tweede'] as $label) {
            [$stripId, $stripKey] = SectionRegistry::create('stat_strip', self::TEST_PAGE);
            \App\Service\Blocks\BlockLocalization::save('stat_strip_items', $strips->createItem($stripId), 'nl', [
                'primary_text' => $label,
                'secondary_text' => 'stat',
            ]);
            $this->attach($repository, 'stat_strip', $stripKey, $stripId);

            [$listId, $listKey] = SectionRegistry::create('step_list', self::TEST_PAGE);
            \App\Service\Blocks\BlockLocalization::save('step_list_items', $lists->createItem($listId), 'nl', [
                'title' => $label,
                'body' => 'stap',
            ]);
            $this->attach($repository, 'step_list', $listKey, $listId);
        }

        StatStripContent::clearCache();
        StepListContent::clearCache();

        $groups = $this->revealGroupsOnPage();

        $this->assertCount(4, $groups, 'four instances, four reveal-group attributes');
        $this->assertSame(
            $groups,
            array_values(array_unique($groups)),
            'two instances of one type must not be staggered as one group'
        );
    }

    /**
     * Renders this test's page the way a visitor receives it and returns every
     * data-reveal-group value in document order.
     *
     * @return list<string>
     */
    private function revealGroupsOnPage(): array
    {
        // renderPage() reads the page through PageContent, which caches by
        // content_key. setUp() drops and recreates the page for every test,
        // so a value cached by an earlier test would point at an id that no
        // longer exists.
        PageContent::clearCache();

        ob_start();

        try {
            SectionRegistry::renderPage(self::TEST_PAGE);
        } finally {
            $html = (string) ob_get_clean();
        }

        preg_match_all('/data-reveal-group="([^"]*)"/', $html, $matches);

        return $matches[1];
    }
}
