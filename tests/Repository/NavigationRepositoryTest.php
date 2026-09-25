<?php

namespace Tests\Repository;

use App\Database;
use App\Repository\NavigationRepository;
use App\Service\NavigationLocalization;
use App\Service\NavigationPresentation;
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
        NavigationLocalization::clearCache();
        if ($this->createdIds === []) {
            return;
        }
        // Newest first: every submenu item was made after its parent, so each
        // level goes before the one above it (parent_id is ON DELETE RESTRICT).
        $db = Database::connection();
        foreach (array_reverse($this->createdIds) as $id) {
            $db->prepare('DELETE FROM nav_items WHERE id = ?')->execute([$id]);
        }
    }

    private function makeItem(?int $parentId = null, string $label = 'Test item'): int
    {
        $id = $this->repository->create([
            'link_type' => 'none',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => $parentId,
            'is_visible' => true,
        ]);
        $this->createdIds[] = $id;
        NavigationLocalization::save($id, 'nl', $label);
        NavigationLocalization::save($id, 'en', $label);

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

    public function testCanBeParentAllowsThreeLevelsAndNoFourth(): void
    {
        $topLevel = $this->makeItem();
        $child = $this->makeItem($topLevel);
        $grandchild = $this->makeItem($child);

        $this->assertTrue($this->repository->canBeParent($topLevel), 'level 1 may have a submenu');
        $this->assertTrue($this->repository->canBeParent($child), 'level 2 may have a submenu');
        $this->assertFalse($this->repository->canBeParent($grandchild), 'level 3 may not: there is no level 4');
        $this->assertSame([1, 2, 3], [
            $this->repository->depthOf($topLevel),
            $this->repository->depthOf($child),
            $this->repository->depthOf($grandchild),
        ]);
    }

    /**
     * No endpoint can make a loop (parent_id is set once, at creation), but a
     * chain written into the database by hand must not make depthOf() walk
     * forever or treat an over-deep chain as a valid parent.
     */
    public function testAChainThatNeverReachesTheTopIsNoParent(): void
    {
        $a = $this->makeItem();
        $b = $this->makeItem($a);
        $db = Database::connection();
        $db->prepare('UPDATE nav_items SET parent_id = ? WHERE id = ?')->execute([$b, $a]);

        try {
            $this->assertNull($this->repository->depthOf($a));
            $this->assertNull($this->repository->depthOf($b));
            $this->assertFalse($this->repository->canBeParent($a));
            $this->assertFalse($this->repository->canBeParent($b));
        } finally {
            $db->prepare('UPDATE nav_items SET parent_id = NULL WHERE id = ?')->execute([$a]);
        }
    }

    public function testAHandWrittenFourthLevelIsNoParentEither(): void
    {
        $one = $this->makeItem();
        $two = $this->makeItem($one);
        $three = $this->makeItem($two);
        // Bypasses canBeParent(), the way only a hand-written row could.
        $four = $this->makeItem($three);

        $this->assertNull($this->repository->depthOf($four));
        $this->assertFalse($this->repository->canBeParent($four));
    }

    public function testReorderAndMoveWorkInsideAThirdLevelSubmenu(): void
    {
        $top = $this->makeItem(null, 'Top');
        $parent = $this->makeItem($top, 'Parent');
        $a = $this->makeItem($parent, 'A');
        $b = $this->makeItem($parent, 'B');
        $c = $this->makeItem($parent, 'C');
        $other = $this->makeItem($top, 'Sibling of the parent');

        $this->repository->reorder($parent, [$c, $other, $a, $b]);
        $this->repository->move($b, 'up');

        $order = array_map(
            static fn (array $r): int => (int) $r['id'],
            array_values(array_filter(
                $this->repository->findAllForAdmin(),
                static fn (array $r): bool => $r['parent_id'] !== null && (int) $r['parent_id'] === $parent
            ))
        );
        $this->assertSame([$c, $b, $a], $order, 'the level-2 sibling never joins the level-3 group');
        $this->assertSame($top, (int) $this->repository->findById($other)['parent_id']);
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

    // ------------------------------------------ header buttons and ordering

    private function makeButton(string $label = 'Test button', string $variant = NavigationPresentation::VARIANT_PRIMARY): int
    {
        $id = $this->repository->create([
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => 'https://example.com/' . rawurlencode($label),
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => true,
            'presentation' => NavigationPresentation::BUTTON,
            'button_variant' => $variant,
        ]);
        $this->createdIds[] = $id;
        NavigationLocalization::save($id, 'nl', $label);
        NavigationLocalization::save($id, 'en', $label . ' EN');

        return $id;
    }

    /** @return list<int> ids of one group, in stored order, limited to $ids */
    private function orderOf(array $ids): array
    {
        $rows = array_values(array_filter(
            $this->repository->findAllForAdmin(),
            static fn (array $r): bool => in_array((int) $r['id'], $ids, true)
        ));
        usort($rows, static fn (array $x, array $y): int => [(int) $x['sort_order'], (int) $x['id']] <=> [(int) $y['sort_order'], (int) $y['id']]);

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    public function testANewItemIsAMenuLinkUnlessItSaysOtherwise(): void
    {
        $row = $this->repository->findById($this->makeItem());

        $this->assertSame(NavigationPresentation::LINK, $row['presentation']);
        $this->assertSame(NavigationPresentation::VARIANT_PRIMARY, $row['button_variant']);
    }

    /**
     * What an editor saves is what comes back, for a link and for a button.
     * The label is not a column of nav_items any more; it round-trips through
     * App\Service\NavigationLocalization, one language at a time.
     */
    public function testEveryFieldRoundTrips(): void
    {
        $id = $this->makeItem(null, 'Rondje');

        NavigationLocalization::save($id, 'nl', 'Over ons');
        NavigationLocalization::save($id, 'en', 'About us');
        $this->repository->update($id, [
            'link_type' => 'route',
            'target_page_id' => null,
            'target_route' => 'home',
            'external_url' => null,
            'open_in_new_tab' => true,
            'is_visible' => false,
        ]);

        $row = $this->repository->findById($id);
        $this->assertSame(
            ['Over ons', 'About us', 'route', 'home', 1, 0, NavigationPresentation::LINK],
            [NavigationLocalization::raw($id, 'nl'), NavigationLocalization::raw($id, 'en'), $row['link_type'], $row['target_route'], (int) $row['open_in_new_tab'], (int) $row['is_visible'], $row['presentation']],
            'an update without a presentation keeps the stored one'
        );

        $button = $this->repository->findById($this->makeButton('Rustig', NavigationPresentation::VARIANT_GHOST));
        $this->assertSame(
            [NavigationPresentation::BUTTON, NavigationPresentation::VARIANT_GHOST, 'external', 'Rustig EN'],
            [$button['presentation'], $button['button_variant'], $button['link_type'], NavigationLocalization::raw((int) $button['id'], 'en')]
        );
        $this->assertArrayNotHasKey('label_nl', $button, 'nav_items holds no words of its own');
    }

    public function testAButtonIsNeverAParent(): void
    {
        $this->assertFalse($this->repository->canBeParent($this->makeButton()));
    }

    /**
     * The menu and the buttons are ordered independently: a new button goes
     * to the end of the BUTTONS, whatever the menu holds, and reordering the
     * buttons leaves every menu link where it was.
     */
    public function testButtonsAndMenuLinksAreOrderedSeparately(): void
    {
        $link = $this->makeItem(null, 'Link');
        $first = $this->makeButton('Eerste');
        $second = $this->makeButton('Tweede');
        $linkOrder = (int) $this->repository->findById($link)['sort_order'];

        $this->assertSame(
            (int) $this->repository->findById($first)['sort_order'] + 1,
            (int) $this->repository->findById($second)['sort_order'],
            'the second button follows the first within its own group'
        );

        $this->repository->reorder(null, [$second, $first, $link], NavigationPresentation::BUTTON);

        $this->assertSame([$second, $first], $this->orderOf([$first, $second]));
        $this->assertSame($linkOrder, (int) $this->repository->findById($link)['sort_order'], 'a menu link id in a button reorder is ignored');
    }

    public function testMoveGoesOnePlaceWithinTheGroup(): void
    {
        $parent = $this->makeItem(null, 'Parent');
        $a = $this->makeItem($parent, 'A');
        $b = $this->makeItem($parent, 'B');
        $c = $this->makeItem($parent, 'C');

        $this->repository->move($c, 'up');
        $this->assertSame([$a, $c, $b], $this->orderOf([$a, $b, $c]));

        $this->repository->move($a, 'up');
        $this->assertSame([$a, $c, $b], $this->orderOf([$a, $b, $c]), 'the first item up changes nothing');

        $this->repository->move($b, 'down');
        $this->assertSame([$a, $c, $b], $this->orderOf([$a, $b, $c]), 'the last item down changes nothing');

        $this->repository->move($a, 'down');
        $this->assertSame([$c, $a, $b], $this->orderOf([$a, $b, $c]));
    }

    /** Gaps and ties from older data still move exactly one place. */
    public function testMoveRenumbersAGroupWithGapsAndTiesFirst(): void
    {
        $parent = $this->makeItem(null, 'Parent');
        $a = $this->makeItem($parent, 'A');
        $b = $this->makeItem($parent, 'B');
        $c = $this->makeItem($parent, 'C');

        $set = Database::connection()->prepare('UPDATE nav_items SET sort_order = :sort_order WHERE id = :id');
        $set->execute(['sort_order' => 4, 'id' => $a]);
        $set->execute(['sort_order' => 4, 'id' => $b]);
        $set->execute(['sort_order' => 9, 'id' => $c]);

        $this->repository->move($c, 'up');

        $this->assertSame([$a, $c, $b], $this->orderOf([$a, $b, $c]));
        $this->assertSame(
            [0, 1, 2],
            array_map(fn (int $id): int => (int) $this->repository->findById($id)['sort_order'], [$a, $c, $b])
        );
    }

    public function testMovingAButtonNeverPassesAMenuLink(): void
    {
        $link = $this->makeItem(null, 'Link');
        $linkOrder = (int) $this->repository->findById($link)['sort_order'];
        $first = $this->makeButton('Eerste');
        $second = $this->makeButton('Tweede');

        $this->repository->move($second, 'up');

        $this->assertSame([$second, $first], $this->orderOf([$first, $second]));
        $this->assertSame($linkOrder, (int) $this->repository->findById($link)['sort_order']);
    }

    /**
     * A link that becomes a button joins the END of the buttons instead of
     * landing somewhere in the middle of an order an editor arranged.
     */
    public function testChangingThePresentationMovesTheItemToTheEndOfItsNewGroup(): void
    {
        $button = $this->makeButton('Bestaand');
        $link = $this->makeItem(null, 'Wordt knop');
        $this->repository->update($link, [
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => 'https://example.com/',
            'open_in_new_tab' => false,
            'is_visible' => true,
            'presentation' => NavigationPresentation::BUTTON,
            'button_variant' => NavigationPresentation::VARIANT_GHOST,
        ]);

        $updated = $this->repository->findById($link);
        $this->assertSame(NavigationPresentation::BUTTON, $updated['presentation']);
        $this->assertSame(NavigationPresentation::VARIANT_GHOST, $updated['button_variant']);
        $this->assertSame([$button, $link], $this->orderOf([$button, $link]));
    }
}
