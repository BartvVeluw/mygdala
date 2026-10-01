<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\NavigationRepository;
use App\Service\LinkResolver;
use App\Service\NavigationLocalization;
use App\Service\NavigationPresentation;
use App\Service\NavigationService;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;

/**
 * Moving and deleting in the menu tree against the real test database
 * (NavigationRepository::place() and ::delete(), HEADER-FOOTER.md
 * "Verplaatsen" and "Verwijderen"):
 *
 *   - a move to another parent, back to the top level and within one list,
 *     at an exact position or last;
 *   - the old list closes its gap and the new one is numbered 0..n-1, with
 *     every other item keeping its relative order and no position twice;
 *   - a refused move writes nothing, and a caller's transaction that rolls
 *     back takes the move with it;
 *   - a deleted parent hands its submenu items to its own list, in its
 *     place, in their order — on the top level and below it;
 *   - a loop written by hand is repaired by moving to the top;
 *   - the public tree follows every move, in every language, from the same
 *     rows: the structure has no language, the labels do.
 *
 * Every row is this test's own (external links on /zz-nav-place-…), removed
 * in tearDown() by exact id after their parents are cleared, so the order
 * of creation never matters. Positions are asserted on this test's own rows
 * only, so whatever menu the test database holds does not matter — the top
 * level is only ever compared as a relative order.
 */
final class NavigationPlacementTest extends TestCase
{
    private NavigationRepository $repository;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->repository = new NavigationRepository();
    }

    protected function tearDown(): void
    {
        RequestLanguage::reset();
        NavigationLocalization::clearCache();
        LinkResolver::clearCache();
        if ($this->ids === []) {
            return;
        }
        $db = Database::connection();
        $in = implode(',', array_map('intval', $this->ids));
        $db->exec("UPDATE nav_items SET parent_id = NULL WHERE id IN ({$in})");
        $db->exec("DELETE FROM nav_items WHERE id IN ({$in})");
        $this->ids = [];
    }

    private function link(string $label, ?int $parentId = null, string $linkType = 'external', string $presentation = NavigationPresentation::LINK): int
    {
        $id = $this->repository->create([
            'link_type' => $linkType,
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => $linkType === 'external' ? '/zz-nav-place-' . strtolower($label) : null,
            'open_in_new_tab' => false,
            'parent_id' => $parentId,
            'is_visible' => true,
            'presentation' => $presentation,
        ]);
        $this->ids[] = $id;
        NavigationLocalization::save($id, 'nl', $label);
        NavigationLocalization::save($id, 'en', $label . ' EN');

        return $id;
    }

    /** @return array{0:?int,1:int} parent id and sort_order as stored */
    private function place(int $id): array
    {
        $row = $this->repository->findById($id);

        return [$row['parent_id'] === null ? null : (int) $row['parent_id'], (int) $row['sort_order']];
    }

    /** @return list<int> one list's ids in order, restricted to this test's rows */
    private function list(?int $parentId): array
    {
        $own = array_flip($this->ids);

        return array_values(array_filter(
            $this->repository->tree()->childIds($parentId),
            static fn (int $id): bool => isset($own[$id])
        ));
    }

    /** @return list<int> the sort_orders of one list, in order */
    private function sortOrders(int $parentId): array
    {
        $stmt = Database::connection()->prepare('SELECT sort_order FROM nav_items WHERE parent_id = ? ORDER BY sort_order, id');
        $stmt->execute([$parentId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Diensten [Metaal, Hout [Snijplanken]], Portfolio, Contact.
     *
     * @return array<string, int>
     */
    private function menu(): array
    {
        $m = [];
        $m['diensten'] = $this->link('Diensten');
        $m['metaal'] = $this->link('Metaal', $m['diensten']);
        $m['hout'] = $this->link('Hout', $m['diensten']);
        $m['snijplanken'] = $this->link('Snijplanken', $m['hout']);
        $m['portfolio'] = $this->link('Portfolio');
        $m['contact'] = $this->link('Contact');

        return $m;
    }

    public function testAChildGoesToTheTopLevelAndBack(): void
    {
        $m = $this->menu();

        $this->assertNull($this->repository->place($m['metaal'], null));
        $this->assertNull($this->place($m['metaal'])[0]);
        $this->assertSame([$m['diensten'], $m['portfolio'], $m['contact'], $m['metaal']], $this->list(null), 'last on the top level');
        $this->assertSame([$m['hout']], $this->list($m['diensten']));
        $this->assertSame([0], $this->sortOrders($m['diensten']), 'the old list closed its gap');

        $this->assertNull($this->repository->place($m['metaal'], $m['portfolio'], 0));
        $this->assertSame([$m['portfolio'], 0], $this->place($m['metaal']));
        $this->assertSame([$m['diensten'], $m['portfolio'], $m['contact']], $this->list(null));
    }

    public function testAMoveLandsOnTheExactPositionAndKeepsTheOthersInOrder(): void
    {
        $m = $this->menu();
        $extra = $this->link('Extra', $m['diensten']);

        // Contact into Diensten, between Metaal and Hout.
        $this->assertNull($this->repository->place($m['contact'], $m['diensten'], 1));
        $this->assertSame([$m['metaal'], $m['contact'], $m['hout'], $extra], $this->list($m['diensten']));
        $this->assertSame([0, 1, 2, 3], $this->sortOrders($m['diensten']), 'no position twice, no gap');

        // Within one list: Extra to the front.
        $this->assertNull($this->repository->place($extra, $m['diensten'], 0));
        $this->assertSame([$extra, $m['metaal'], $m['contact'], $m['hout']], $this->list($m['diensten']));

        // A position past the end, or none, means last.
        $this->assertNull($this->repository->place($extra, $m['diensten'], 99));
        $this->assertSame([$m['metaal'], $m['contact'], $m['hout'], $extra], $this->list($m['diensten']));
        $this->assertSame([0, 1, 2, 3], $this->sortOrders($m['diensten']));
    }

    public function testASubmenuMovesAlongToThirdLevel(): void
    {
        $m = $this->menu();
        $leaf = $this->link('Leaf');

        $this->assertNull($this->repository->place($m['hout'], $m['portfolio']));
        $this->assertSame([$m['hout']], $this->list($m['portfolio']));
        $this->assertSame([$m['snijplanken']], $this->list($m['hout']), 'its own submenu went along');
        $this->assertSame(3, $this->repository->depthOf($m['snijplanken']));

        $this->assertNull($this->repository->place($leaf, $m['hout']), 'a third level');
        $this->assertSame(3, $this->repository->depthOf($leaf));
    }

    public function testARefusedMoveWritesNothing(): void
    {
        $m = $this->menu();
        $button = $this->link('Knop', null, 'external', NavigationPresentation::BUTTON);
        $before = array_map(fn (int $id): array => $this->place($id), $m);

        $refusals = [
            'self' => [$m['diensten'], $m['diensten'], 'validation.nav_place_self'],
            'direct child' => [$m['diensten'], $m['metaal'], 'validation.nav_place_descendant'],
            'deep descendant' => [$m['diensten'], $m['snijplanken'], 'validation.nav_place_descendant'],
            'unknown parent' => [$m['contact'], 999999999, 'validation.nav_place_parent_unknown'],
            'a button as parent' => [$m['contact'], $button, 'validation.nav_place_parent_unknown'],
            'a fourth level' => [$m['contact'], $m['snijplanken'], 'validation.nav_place_too_deep'],
            'a submenu past three levels' => [$m['hout'], $m['metaal'], 'validation.nav_place_too_deep'],
            'an unknown item' => [999999999, null, 'validation.nav_place_not_found'],
            'a button' => [$button, $m['portfolio'], 'validation.nav_place_button'],
        ];
        foreach ($refusals as $what => [$id, $parentId, $key]) {
            $this->assertSame($key, $this->repository->place($id, $parentId), $what);
        }

        $this->assertSame($before, array_map(fn (int $id): array => $this->place($id), $m));
        $this->assertSame([null, 0], [$this->place($button)[0], 0], 'the button stays on top');
    }

    public function testAHeadingWithoutDestinationIsNeverPlacedInASubmenu(): void
    {
        $m = $this->menu();
        $heading = $this->link('Kop', null, 'none');

        $this->assertSame('validation.submenu_item_eigen_link_hebben', $this->repository->place($heading, $m['portfolio']));
        $this->assertNull($this->place($heading)[0]);
    }

    public function testAMoveInsideACallersTransactionRollsBackWithIt(): void
    {
        $m = $this->menu();
        $db = Database::connection();

        $db->beginTransaction();
        $this->assertNull($this->repository->place($m['metaal'], $m['portfolio']));
        $this->assertTrue($db->inTransaction(), 'the caller still owns its transaction');
        $db->rollBack();

        $this->assertSame([$m['diensten'], 0], $this->place($m['metaal']));
        $this->assertSame([$m['metaal'], $m['hout']], $this->list($m['diensten']));
    }

    public function testDeletingAParentHandsItsSubmenuToItsOwnListInItsPlace(): void
    {
        $m = $this->menu();
        $tail = $this->link('Na', $m['diensten']);

        // Hout (level 2) goes: Snijplanken takes its place between Metaal and Na.
        $this->assertTrue($this->repository->delete($m['hout']));
        $this->assertSame([$m['metaal'], $m['snijplanken'], $tail], $this->list($m['diensten']));
        $this->assertSame([0, 1, 2], $this->sortOrders($m['diensten']));

        // Diensten (top level) goes: its submenu takes its place on top.
        $this->assertTrue($this->repository->delete($m['diensten']));
        $this->assertSame([$m['metaal'], $m['snijplanken'], $tail, $m['portfolio'], $m['contact']], $this->list(null));
        NavigationLocalization::clearCache();
        $this->assertSame('', NavigationLocalization::raw($m['diensten'], 'nl'), 'its labels went with it');
        $this->assertFalse($this->repository->delete($m['diensten']), 'gone is gone');
    }

    public function testALoopWrittenByHandIsRepairedByAMoveToTheTop(): void
    {
        $a = $this->link('LusA');
        $b = $this->link('LusB', $a);
        Database::connection()->prepare('UPDATE nav_items SET parent_id = ? WHERE id = ?')->execute([$b, $a]);

        $tree = $this->repository->tree();
        $this->assertContains($a, $tree->unreachableIds());
        $this->assertSame([], NavigationService::buildTree([$this->repository->findById($a), $this->repository->findById($b)]), 'the public tree never enters it');

        $this->assertSame('validation.nav_place_parent_unknown', $this->repository->place($b, 999999999));
        $this->assertNull($this->repository->place($a, null));
        $this->assertSame(1, $this->repository->depthOf($a));
        $this->assertSame(2, $this->repository->depthOf($b));
    }

    public function testThePublicTreeFollowsAMoveInEveryLanguage(): void
    {
        $m = $this->menu();
        $this->assertNull($this->repository->place($m['metaal'], $m['portfolio']));

        $find = static function (array $items, int $id) use (&$find): ?array {
            foreach ($items as $item) {
                if ($item['id'] === $id) {
                    return $item;
                }
                $found = $find($item['children'], $id);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        };

        foreach (['nl' => '', 'en' => ' EN'] as $language => $suffix) {
            RequestLanguage::set($language, $language !== 'nl');
            NavigationLocalization::clearCache();
            $tree = NavigationService::buildTree($this->repository->findVisibleForPublic());

            $portfolio = $find($tree, $m['portfolio']);
            $this->assertNotNull($portfolio, $language);
            $this->assertSame(['Metaal' . $suffix], array_column($portfolio['children'], 'label'), $language);
            $this->assertSame(['Hout' . $suffix], array_column($find($tree, $m['diensten'])['children'], 'label'), $language);
            $this->assertSame(['Snijplanken' . $suffix], array_column($find($tree, $m['hout'])['children'], 'label'), $language);
        }
    }
}
