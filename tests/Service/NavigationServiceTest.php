<?php

namespace Tests\Service;

use App\Service\NavigationService;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for NavigationService::buildTree() — given rows are
 * synthetic (route/none link types only, no database needed since those
 * resolve without touching InformationPageRepository).
 */
class NavigationServiceTest extends TestCase
{
    private function row(int $id, ?int $parentId, string $linkType, ?string $route, int $sortOrder, bool $visible = true): array
    {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'label_nl' => 'Item ' . $id,
            'label_en' => 'Item ' . $id,
            'link_type' => $linkType,
            'target_page_id' => null,
            'target_route' => $route,
            'external_url' => null,
            'open_in_new_tab' => 0,
            'sort_order' => $sortOrder,
            'is_visible' => $visible ? 1 : 0,
        ];
    }

    public function testTopLevelItemsAreSortedByOrder(): void
    {
        $rows = [
            $this->row(1, null, 'route', 'shop', 1),
            $this->row(2, null, 'route', 'home', 0),
        ];

        $tree = NavigationService::buildTree($rows);

        $this->assertSame([2, 1], array_map(static fn (array $i): int => $i['id'], $tree));
    }

    public function testChildrenAreNestedUnderTheirParentAndSorted(): void
    {
        $rows = [
            $this->row(1, null, 'none', null, 0),
            $this->row(3, 1, 'route', 'checkout', 1),
            $this->row(2, 1, 'route', 'shop', 0),
        ];

        $tree = NavigationService::buildTree($rows);

        $this->assertCount(1, $tree);
        $this->assertSame([2, 3], array_map(static fn (array $c): int => $c['id'], $tree[0]['children']));
    }

    public function testUnresolvableChildIsDropped(): void
    {
        $rows = [
            $this->row(1, null, 'none', null, 0),
            $this->row(2, 1, 'route', 'nonexistent-route', 0),
        ];

        $tree = NavigationService::buildTree($rows);

        $this->assertSame([], $tree[0]['children']);
    }

    public function testUnresolvableTopLevelItemIsDropped(): void
    {
        $rows = [$this->row(1, null, 'route', 'nonexistent-route', 0)];

        $this->assertSame([], NavigationService::buildTree($rows));
    }

    public function testRouteKeyIsExposedForActiveStateMatching(): void
    {
        $rows = [$this->row(1, null, 'route', 'shop', 0)];

        $tree = NavigationService::buildTree($rows);

        $this->assertSame('shop', $tree[0]['route_key']);
    }
}
