<?php

namespace Tests\Repository;

use App\Database;
use App\Repository\NavigationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database — same rationale as
 * PageSectionRepositoryTest (no mocking seam, this project's convention for
 * thin PDO repositories). Every row created here uses link_type='none' (no
 * FK dependency on information_pages/routes) so these tests never depend on
 * seeded content existing. tearDown() removes every row this test created.
 */
class NavigationRepositoryTest extends TestCase
{
    private NavigationRepository $repository;

    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->repository = new NavigationRepository();
    }

    protected function tearDown(): void
    {
        if ($this->createdIds === []) {
            return;
        }
        // Children first (parent_id is ON DELETE RESTRICT).
        $placeholders = implode(',', array_fill(0, count($this->createdIds), '?'));
        $db = Database::connection();
        $db->prepare("DELETE FROM nav_items WHERE id IN ($placeholders) AND parent_id IS NOT NULL")->execute($this->createdIds);
        $db->prepare("DELETE FROM nav_items WHERE id IN ($placeholders)")->execute($this->createdIds);
    }

    private function makeItem(?int $parentId = null, string $label = 'Test item'): int
    {
        $id = $this->repository->create([
            'label_nl' => $label,
            'label_en' => $label,
            'link_type' => 'none',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => $parentId,
            'is_visible' => true,
        ]);
        $this->createdIds[] = $id;

        return $id;
    }

    public function testItemsLoadInSortOrder(): void
    {
        $second = $this->makeItem(null, 'B');
        $first = $this->makeItem(null, 'A');
        $this->repository->reorder(null, [$first, $second]);

        $all = $this->repository->findAllForAdmin();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $all);
        $firstPos = array_search($first, $ids, true);
        $secondPos = array_search($second, $ids, true);

        $this->assertLessThan($secondPos, $firstPos);
    }

    public function testHiddenItemsExcludedFromPublicList(): void
    {
        $visible = $this->makeItem();
        $hidden = $this->makeItem();
        $this->repository->setVisible($hidden, false);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repository->findVisibleForPublic());

        $this->assertContains($visible, $ids);
        $this->assertNotContains($hidden, $ids);
    }

    public function testChildIsReturnedUnderItsParent(): void
    {
        $parent = $this->makeItem(null, 'Parent');
        $child = $this->makeItem($parent, 'Child');

        $all = $this->repository->findAllForAdmin();
        $childRow = null;
        foreach ($all as $row) {
            if ((int) $row['id'] === $child) {
                $childRow = $row;
            }
        }

        $this->assertNotNull($childRow);
        $this->assertSame($parent, (int) $childRow['parent_id']);
    }

    public function testReorderPersistsWithinParentScope(): void
    {
        $parent = $this->makeItem(null, 'Parent');
        $a = $this->makeItem($parent, 'A');
        $b = $this->makeItem($parent, 'B');
        $c = $this->makeItem($parent, 'C');

        $this->repository->reorder($parent, [$c, $a, $b]);

        $rows = array_filter(
            $this->repository->findAllForAdmin(),
            static fn (array $r): bool => $r['parent_id'] !== null && (int) $r['parent_id'] === $parent
        );
        usort($rows, static fn (array $x, array $y): int => ((int) $x['sort_order']) <=> ((int) $y['sort_order']));
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        $this->assertSame([$c, $a, $b], $ids);
    }

    public function testReorderIgnoresIdsFromAnotherParentScope(): void
    {
        $parentA = $this->makeItem(null, 'Parent A');
        $parentB = $this->makeItem(null, 'Parent B');
        $childA = $this->makeItem($parentA, 'Child A');
        $childB = $this->makeItem($parentB, 'Child B');

        // A forged/stale id from a different parent must never move into
        // parentA's own ordering.
        $this->repository->reorder($parentA, [$childB, $childA]);

        $rowA = $this->repository->findById($childA);
        $rowB = $this->repository->findById($childB);

        $this->assertSame($parentA, (int) $rowA['parent_id']);
        $this->assertSame(0, (int) $rowA['sort_order']);
        $this->assertSame($parentB, (int) $rowB['parent_id']);
    }

    public function testDeletingOneItemDoesNotAffectAnother(): void
    {
        $keep = $this->makeItem();
        $remove = $this->makeItem();

        $this->repository->delete($remove);

        $this->assertNotNull($this->repository->findById($keep));
        $this->assertNull($this->repository->findById($remove));
    }

    public function testCanBeParentRejectsItemThatAlreadyHasAParent(): void
    {
        $topLevel = $this->makeItem();
        $child = $this->makeItem($topLevel);

        $this->assertTrue($this->repository->canBeParent($topLevel));
        $this->assertFalse($this->repository->canBeParent($child));
    }

    public function testCanBeParentRejectsUnknownId(): void
    {
        $this->assertFalse($this->repository->canBeParent(999999));
    }

    public function testCountChildrenReflectsCurrentChildren(): void
    {
        $parent = $this->makeItem();
        $this->assertSame(0, $this->repository->countChildren($parent));

        $this->makeItem($parent);
        $this->makeItem($parent);

        $this->assertSame(2, $this->repository->countChildren($parent));
    }
}
