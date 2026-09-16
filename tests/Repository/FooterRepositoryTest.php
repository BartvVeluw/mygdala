<?php

namespace Tests\Repository;

use App\Repository\FooterRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database — same rationale as
 * PageSectionRepositoryTest/NavigationRepositoryTest. Every link created
 * here uses link_type='external' (no dependency on information_pages/route
 * seed data). tearDown() deletes only the columns this test created —
 * footer_links.column_id CASCADEs, so deleting the column is enough.
 */
class FooterRepositoryTest extends TestCase
{
    private FooterRepository $repository;

    /** @var list<int> */
    private array $createdColumnIds = [];

    protected function setUp(): void
    {
        $this->repository = new FooterRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdColumnIds as $id) {
            $this->repository->deleteColumn($id);
        }
    }

    private function makeColumn(string $title = 'Test column'): int
    {
        $id = $this->repository->createColumn(['title_nl' => $title, 'title_en' => $title, 'is_visible' => true]);
        $this->createdColumnIds[] = $id;

        return $id;
    }

    private function makeLink(int $columnId, string $label = 'Test link'): int
    {
        return $this->repository->createLink([
            'column_id' => $columnId,
            'label_nl' => $label,
            'label_en' => $label,
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => '/test',
            'action_key' => null,
            'open_in_new_tab' => false,
            'is_visible' => true,
        ]);
    }

    public function testColumnsLoadInSortOrder(): void
    {
        $second = $this->makeColumn('B');
        $first = $this->makeColumn('A');
        $this->repository->reorderColumns([$first, $second]);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repository->findAllColumnsForAdmin());
        $firstPos = array_search($first, $ids, true);
        $secondPos = array_search($second, $ids, true);

        $this->assertLessThan($secondPos, $firstPos);
    }

    public function testHiddenColumnsExcludedFromPublicList(): void
    {
        $visible = $this->makeColumn();
        $hidden = $this->makeColumn();
        $this->repository->setColumnVisible($hidden, false);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repository->findVisibleColumnsForPublic());

        $this->assertContains($visible, $ids);
        $this->assertNotContains($hidden, $ids);
    }

    public function testLinksLoadInColumnAndSortOrder(): void
    {
        $column = $this->makeColumn();
        $second = $this->makeLink($column, 'B');
        $first = $this->makeLink($column, 'A');
        $this->repository->reorderLinks($column, [$first, $second]);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repository->findLinksForColumn($column));

        $this->assertSame([$first, $second], $ids);
    }

    public function testHiddenLinksExcludedFromVisibleList(): void
    {
        $column = $this->makeColumn();
        $visible = $this->makeLink($column, 'Visible');
        $hidden = $this->makeLink($column, 'Hidden');
        $this->repository->setLinkVisible($hidden, false);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repository->findAllVisibleLinks());

        $this->assertContains($visible, $ids);
        $this->assertNotContains($hidden, $ids);
    }

    public function testReorderLinksIgnoresIdsFromAnotherColumn(): void
    {
        $columnA = $this->makeColumn('A');
        $columnB = $this->makeColumn('B');
        $linkA = $this->makeLink($columnA, 'Link A');
        $linkB = $this->makeLink($columnB, 'Link B');

        $this->repository->reorderLinks($columnA, [$linkB, $linkA]);

        $rowA = $this->repository->findLinkById($linkA);
        $rowB = $this->repository->findLinkById($linkB);

        $this->assertSame($columnA, (int) $rowA['column_id']);
        $this->assertSame(0, (int) $rowA['sort_order']);
        $this->assertSame($columnB, (int) $rowB['column_id']);
    }

    /** ↑/↓ on the Footer screen: a swap with the neighbour, nothing else. */
    public function testMoveColumnSwapsWithItsNeighbourOnly(): void
    {
        $a = $this->makeColumn('A');
        $b = $this->makeColumn('B');
        $c = $this->makeColumn('C');

        $this->repository->moveColumn($c, 'up');
        $this->assertSame([$a, $c, $b], $this->ownColumnOrder());

        $this->repository->moveColumn($b, 'down');
        $this->repository->moveColumn($b, 'sideways');
        $this->assertSame([$a, $c, $b], $this->ownColumnOrder(), 'the last column does not move down');

        $all = $this->repository->findAllColumnsForAdmin();
        $this->assertSame(range(0, count($all) - 1), array_map(static fn (array $r): int => (int) $r['sort_order'], $all));
    }

    /** A link moves only inside its own column; the column comes from the row. */
    public function testMoveLinkStaysInsideItsColumn(): void
    {
        $columnA = $this->makeColumn('A');
        $columnB = $this->makeColumn('B');
        $first = $this->makeLink($columnA, 'Eerste');
        $second = $this->makeLink($columnA, 'Tweede');
        $other = $this->makeLink($columnB, 'Ander');

        $this->repository->moveLink($first, 'up');
        $this->assertSame([$first, $second], $this->linkOrder($columnA), 'the first link does not move up');

        $this->repository->moveLink($second, 'up');
        $this->assertSame([$second, $first], $this->linkOrder($columnA));
        $this->assertSame([$other], $this->linkOrder($columnB));

        $this->repository->moveLink(999999999, 'up');
        $this->assertSame([$second, $first], $this->linkOrder($columnA), 'an unknown link changes nothing');
    }

    /** @return list<int> */
    private function ownColumnOrder(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $r): int => (int) $r['id'], $this->repository->findAllColumnsForAdmin()),
            fn (int $id): bool => in_array($id, $this->createdColumnIds, true)
        ));
    }

    /** @return list<int> */
    private function linkOrder(int $columnId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->repository->findLinksForColumn($columnId));
    }

    public function testDeletingColumnCascadesToItsLinks(): void
    {
        $column = $this->makeColumn();
        $link = $this->makeLink($column);

        $this->repository->deleteColumn($column);
        $this->createdColumnIds = array_values(array_diff($this->createdColumnIds, [$column]));

        $this->assertNull($this->repository->findLinkById($link));
        $this->assertNull($this->repository->findColumnById($column));
    }
}
