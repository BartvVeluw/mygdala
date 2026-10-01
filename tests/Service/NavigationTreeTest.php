<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\NavigationRepository;
use App\Service\NavigationPresentation;
use App\Service\NavigationTree;
use App\Service\PageOptions;
use App\Service\TreeOptions;
use PHPUnit\Framework\TestCase;

/**
 * The menu's tree as App\Service\NavigationTree reads it from flat rows, no
 * database (HEADER-FOOTER.md, "Verplaatsen"):
 *
 *   - levels, the overview's order, descendants and the height of a submenu;
 *   - every reason a place is refused: the item itself, a direct child, a
 *     deep descendant, an unknown parent, a header button as parent, a
 *     heading below the top level, a submenu pushed past MAX_DEPTH;
 *   - the Parent list offers exactly the places that are not refused;
 *   - a loop written into the table by hand ends every walk, leaves its rows
 *     without a level, and may only be repaired by moving to the top;
 *   - the option text is the one a page's Parent list uses (TreeOptions).
 *
 * The example menu:
 *
 *     1 Home
 *     2 Diensten
 *        3 Metaal
 *        4 Hout
 *           5 Snijplanken
 *     6 Portfolio
 *     7 Contact
 *     8 (button) Offerte
 */
final class NavigationTreeTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(int $id, ?int $parentId, int $sortOrder, string $linkType = 'external', string $presentation = NavigationPresentation::LINK): array
    {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'sort_order' => $sortOrder,
            'link_type' => $linkType,
            'presentation' => $presentation,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function menu(): array
    {
        return [
            self::row(7, null, 3),
            self::row(1, null, 0),
            self::row(2, null, 1),
            self::row(4, 2, 1),
            self::row(3, 2, 0),
            self::row(5, 4, 0),
            self::row(6, null, 2),
            self::row(8, null, 0, 'external', NavigationPresentation::BUTTON),
        ];
    }

    public function testLevelsAndOrderFollowTheTreeNotTheRows(): void
    {
        $tree = new NavigationTree(self::menu());

        $this->assertSame(
            [[1, 1], [2, 1], [3, 2], [4, 2], [5, 3], [6, 1], [7, 1]],
            array_map(static fn (array $e): array => [$e['id'], $e['level']], $tree->ordered())
        );
        $this->assertSame([1, 2, 6, 7], $tree->childIds(null), 'the button is not in the menu');
        $this->assertSame([3, 4], $tree->childIds(2));
        $this->assertNull($tree->levelOf(8), 'a header button has no level in the menu');
        $this->assertFalse($tree->has(8));
    }

    public function testDescendantsAndHeight(): void
    {
        $tree = new NavigationTree(self::menu());

        $this->assertEqualsCanonicalizing([3, 4, 5], $tree->descendantIds(2));
        $this->assertSame([], $tree->descendantIds(7));
        $this->assertSame(3, $tree->heightOf(2));
        $this->assertSame(2, $tree->heightOf(4));
        $this->assertSame(1, $tree->heightOf(5));
    }

    public function testEveryRefusalHasItsOwnReason(): void
    {
        $tree = new NavigationTree(self::menu());

        $this->assertSame('validation.nav_place_self', $tree->placementError(2, 2));
        $this->assertSame('validation.nav_place_descendant', $tree->placementError(2, 3), 'a direct child');
        $this->assertSame('validation.nav_place_descendant', $tree->placementError(2, 5), 'a deep descendant');
        $this->assertSame('validation.nav_place_parent_unknown', $tree->placementError(7, 999), 'an unknown parent');
        $this->assertSame('validation.nav_place_parent_unknown', $tree->placementError(7, 8), 'a header button is in another list');
        $this->assertSame('validation.nav_place_not_found', $tree->placementError(999, null));
        $this->assertSame('validation.nav_place_not_found', $tree->placementError(8, null), 'a button does not move in the menu');
        // Hout has a submenu of its own: under Metaal (level 2) it would
        // put Snijplanken on a fourth level.
        $this->assertSame('validation.nav_place_too_deep', $tree->placementError(4, 3));
        $this->assertSame('validation.nav_place_too_deep', $tree->placementError(7, 5), 'nothing below level 3');
    }

    public function testAllowedPlaces(): void
    {
        $tree = new NavigationTree(self::menu());

        $this->assertNull($tree->placementError(3, null), 'back to the top level');
        $this->assertNull($tree->placementError(3, 6), 'to another parent');
        $this->assertNull($tree->placementError(7, 4), 'from the top level to level 3');
        $this->assertNull($tree->placementError(4, 6), 'with its submenu, one level up stays within three');
        $this->assertNull($tree->placementError(3, 2), 'its own parent: a place in the same list');
    }

    public function testAHeadingWithoutDestinationStaysOnTop(): void
    {
        $rows = self::menu();
        $rows[] = self::row(9, null, 4, 'none');
        $tree = new NavigationTree($rows);

        $this->assertSame('validation.submenu_item_eigen_link_hebben', $tree->placementError(9, 6));
        $this->assertNull($tree->placementError(9, null));
        $this->assertSame([], $tree->parentCandidates(9));
    }

    public function testTheParentListOffersExactlyTheAllowedPlaces(): void
    {
        $tree = new NavigationTree(self::menu());

        $ids = static fn (array $entries): array => array_column($entries, 'id');

        // Diensten (height 3): only another top-level item would push its
        // submenu past level 3, so no parent at all is left.
        $this->assertSame([], $ids($tree->parentCandidates(2)));
        // Hout (height 2): every top-level item, its own parent included;
        // never Metaal (too deep), never itself or Snijplanken.
        $this->assertSame([1, 2, 6, 7], $ids($tree->parentCandidates(4)));
        // Contact (height 1): every item on level 1 or 2, not itself.
        $this->assertSame([1, 2, 3, 4, 6], $ids($tree->parentCandidates(7)));
        // A new item: everything above the deepest level.
        $this->assertSame([1, 2, 3, 4, 6, 7], $ids($tree->parentCandidates(null)));
    }

    public function testALoopInTheTableEndsEveryWalkAndCanOnlyGoToTheTop(): void
    {
        $rows = self::menu();
        $rows[] = self::row(10, 11, 0);
        $rows[] = self::row(11, 10, 0);
        $rows[] = self::row(12, 404, 0);
        $tree = new NavigationTree($rows);

        $this->assertEqualsCanonicalizing([10, 11, 12], $tree->unreachableIds());
        $this->assertNull($tree->levelOf(10));
        $this->assertSame([11], $tree->descendantIds(10), 'the loop is walked once');
        $this->assertSame(2, $tree->heightOf(10));
        $this->assertNotContains(10, array_column($tree->parentCandidates(null), 'id'), 'never offered as parent');
        $this->assertNull($tree->placementError(10, null), 'the top level repairs it');
        $this->assertSame('validation.nav_place_too_deep', $tree->placementError(7, 10), 'a parent without a level');
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7],
            array_column($tree->ordered(), 'id'),
            'the loop never reaches the overview tree'
        );
    }

    public function testADeeperLevelWrittenByHandIsListedButNeverAParent(): void
    {
        $rows = self::menu();
        $rows[] = self::row(13, 5, 0);
        $tree = new NavigationTree($rows);

        $this->assertSame(NavigationRepository::MAX_DEPTH + 1, $tree->levelOf(13));
        $this->assertNotContains(5, array_column($tree->parentCandidates(null), 'id'));
        $this->assertNull($tree->placementError(13, 6), 'it may be moved up into the menu');
    }

    public function testTheOptionTextIsThePagesOne(): void
    {
        $this->assertSame('Diensten', TreeOptions::label('Diensten', 0));
        $this->assertSame("\u{00A0}\u{00A0}\u{00A0}\u{2013}\u{00A0}Hout", TreeOptions::label('Hout', 1));
        $this->assertSame(TreeOptions::label('Snijplanken', 2), PageOptions::label('Snijplanken', 2));
    }
}
