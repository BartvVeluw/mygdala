<?php

namespace Tests\Repository;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database (see MAIN.MD / the
 * project's Docker setup) — PageSectionRepository is a thin PDO wrapper
 * with no mocking seam, and this project has no existing repository-mock
 * test convention, so exercising it against the actual schema is the only
 * way to verify the SQL itself: the (section_type, section_id) unique
 * constraint, the ONE ordered list per page, and reorder()'s "ignore ids
 * that aren't on this page" safety net.
 *
 * Each test creates its own dedicated, obviously-fake draft page
 * ('__test_page__') to attach sections to — page_sections.page_id is a real
 * foreign key to pages.id since the unified page model landed, so a fake
 * page_slug alone is no longer enough. section_ids stay out-of-range
 * (900000+) and can never collide with real content — section_id is a
 * polymorphic pointer with no DB-level foreign key (see the page_sections
 * migration), so these tests never need to create a real feature_grids/
 * faq_sections/etc. row at all. tearDown() removes the test page, whose
 * cascade takes its page_sections rows with it, so a run never leaves stray
 * data behind.
 */
class PageSectionRepositoryTest extends TestCase
{
    private const TEST_PAGE_KEY = '__test_page__';
    private const OTHER_TEST_PAGE_KEY = '__test_page_other__';

    private PageSectionRepository $repository;
    private PageRepository $pageRepository;
    private int $pageId;

    protected function setUp(): void
    {
        $this->repository = new PageSectionRepository();
        $this->pageRepository = new PageRepository();

        $this->removeTestPage();

        $this->pageId = \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_PAGE_KEY,
            'slug' => self::TEST_PAGE_KEY,
            'status' => 'draft',
        ], 'Test page');
    }

    protected function tearDown(): void
    {
        $this->removeTestPage();
    }

    private function removeTestPage(): void
    {
        $db = Database::connection();

        foreach ([self::TEST_PAGE_KEY, self::OTHER_TEST_PAGE_KEY] as $key) {
            $stmt = $db->prepare('DELETE ps FROM page_sections ps JOIN pages p ON p.id = ps.page_id WHERE p.content_key = :key');
            $stmt->execute(['key' => $key]);

            $stmt = $db->prepare('DELETE FROM pages WHERE content_key = :key');
            $stmt->execute(['key' => $key]);
        }
    }

    private function attach(string $type, ?string $sectionKey, int $sectionId): int
    {
        return $this->repository->create($this->pageId, self::TEST_PAGE_KEY, $type, $sectionKey, $sectionId);
    }

    public function testSectionsAreRetrievedAsOneListInSortOrder(): void
    {
        $first = $this->attach('feature_grid', 'a', 900001);
        $second = $this->attach('faq', 'b', 900002);
        $third = $this->attach('marquee', 'c', 900016);

        $rows = $this->repository->findForPage($this->pageId);

        $this->assertSame(
            [$first, $second, $third],
            array_map(static fn (array $r): int => (int) $r['id'], $rows)
        );
        $this->assertSame(
            [0, 1, 2],
            array_map(static fn (array $r): int => (int) $r['sort_order'], $rows),
            'a page is one list: every new block appends to the bottom of that single sequence'
        );
    }

    /**
     * The invariant every page's block list relies on, asserted over every
     * page this installation has: one list numbered 0..n-1, with no gaps and
     * no duplicate positions. It holds for whatever an editor built, so it
     * asks nothing of what a particular site contains — this test's own page
     * guarantees there is at least one list to check.
     */
    public function testEveryPageIsOneContiguousOrderedList(): void
    {
        $this->attach('feature_grid', 'a', 900031);
        $this->attach('faq', 'b', 900032);

        foreach ($this->pageRepository->findAllForAdmin() as $page) {
            $sections = $this->repository->findForPage((int) $page['id']);
            if ($sections === []) {
                continue;
            }

            $this->assertSame(
                range(0, count($sections) - 1),
                array_map(static fn (array $s): int => (int) $s['sort_order'], $sections),
                "\"{$page['content_key']}\" must be one list numbered 0..n-1, with no gaps and no duplicate positions"
            );
        }
    }

    public function testAPageHasNoSecondOrderingDimension(): void
    {
        $this->attach('feature_grid', 'a', 900017);

        $row = $this->repository->findForPage($this->pageId)[0];

        $this->assertArrayNotHasKey(
            'zone_key',
            $row,
            'zone_key is gone: a page must never be splittable into several ordered sub-lists again'
        );
    }

    public function testHiddenSectionsExcludedWhenOnlyActiveRequested(): void
    {
        $visible = $this->attach('feature_grid', 'a', 900003);
        $hidden = $this->attach('faq', 'b', 900004);
        $this->repository->setActive($hidden, false);

        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->repository->findForPage($this->pageId, true)
        );

        $this->assertContains($visible, $ids);
        $this->assertNotContains($hidden, $ids);
    }

    public function testMultipleInstancesOfSameTypeCanCoexist(): void
    {
        $first = $this->attach('feature_grid', 'first', 900005);
        $second = $this->attach('feature_grid', 'second', 900006);

        $this->assertNotSame($first, $second);
        $this->assertCount(2, $this->repository->findForPage($this->pageId));
    }

    public function testReorderPersists(): void
    {
        $a = $this->attach('feature_grid', 'a', 900007);
        $b = $this->attach('faq', 'b', 900008);
        $c = $this->attach('marquee', 'c', 900009);

        $this->repository->reorder($this->pageId, [$c, $a, $b]);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repository->findForPage($this->pageId));
        $this->assertSame([$c, $a, $b], $ids);
    }

    public function testReorderIgnoresIdsFromAnotherPage(): void
    {
        $ourSection = $this->attach('feature_grid', 'a', 900010);

        $otherPageId = \Tests\Support\PageFixture::create([
            'content_key' => self::OTHER_TEST_PAGE_KEY,
            'slug' => self::OTHER_TEST_PAGE_KEY,
            'status' => 'draft',
        ], 'Other test page');
        $foreign = $this->repository->create($otherPageId, self::OTHER_TEST_PAGE_KEY, 'faq', 'b', 900011);

        // A forged/stale id from another page must never be able to join
        // this page's ordering.
        $this->repository->reorder($this->pageId, [$foreign, $ourSection]);

        $this->assertSame(0, (int) $this->repository->findById($ourSection)['sort_order']);
        $this->assertSame(
            $otherPageId,
            (int) $this->repository->findById($foreign)['page_id'],
            'the foreign section must stay on its own page'
        );
    }

    public function testDeletingOneSectionDoesNotAffectAnother(): void
    {
        $keep = $this->attach('feature_grid', 'keep', 900012);
        $remove = $this->attach('feature_grid', 'remove', 900013);

        $this->repository->delete($remove);

        $this->assertNotNull($this->repository->findById($keep));
        $this->assertNull($this->repository->findById($remove));
    }

    public function testFindBySectionTypeAndIdRoundTrips(): void
    {
        $id = $this->attach('marquee', 'x', 900014);

        $found = $this->repository->findBySectionTypeAndId('marquee', 900014);

        $this->assertNotNull($found);
        $this->assertSame($id, (int) $found['id']);
        $this->assertNull($this->repository->findBySectionTypeAndId('marquee', 999999));
    }

    public function testDeletingThePageCascadesItsSectionAttachments(): void
    {
        $id = $this->attach('feature_grid', 'cascade', 900015);

        $this->pageRepository->delete($this->pageId);

        $this->assertNull(
            $this->repository->findById($id),
            'page_sections.page_id is ON DELETE CASCADE — a removed page must never leave attachments behind'
        );
    }
}
