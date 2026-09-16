<?php

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\NavigationPresentation;
use App\Service\NavigationService;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the public read side of the header: the menu tree
 * (NavigationService::buildTree()), the header buttons (buildButtons()) and
 * which menu item is the current page (isCurrent()). The rows are synthetic —
 * route, external and none link types only, which resolve without touching
 * the database. The CMS-page target needs real `pages` rows and lives in
 * Tests\Service\HeaderFooterRenderingTest.
 *
 * The button half replaces what Tests\Service\HeaderFooterSettingsTest used
 * to prove for the single header CTA in site_settings: every rule it had
 * (no label or no target means no button, an empty translation falls back, a
 * new tab gets the safe rel, a switched-off module's route disappears
 * without the row changing) now holds per button, plus the order and the
 * closed list of styles.
 */
class NavigationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
    }

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

    /** @param array<string, mixed> $overrides */
    private function button(int $id, int $sortOrder, array $overrides = []): array
    {
        return array_merge($this->row($id, null, 'external', null, $sortOrder), [
            'label_nl' => 'Knop ' . $id,
            'label_en' => 'Button ' . $id,
            'external_url' => '/knop-' . $id,
            'presentation' => NavigationPresentation::BUTTON,
            'button_variant' => NavigationPresentation::VARIANT_PRIMARY,
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $items */
    private function ids(array $items): array
    {
        return array_map(static fn (array $i): int => $i['id'], $items);
    }

    // ------------------------------------------------------------------ menu

    public function testTopLevelItemsAreSortedByOrder(): void
    {
        $rows = [
            $this->row(1, null, 'route', 'shop', 1),
            $this->row(2, null, 'route', 'home', 0),
        ];

        $tree = NavigationService::buildTree($rows);

        $this->assertSame([2, 1], $this->ids($tree));
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
        $this->assertSame([2, 3], $this->ids($tree[0]['children']));
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

    /** A row from before the presentation column existed is a menu link. */
    public function testARowWithoutAPresentationIsAMenuLink(): void
    {
        $rows = [$this->row(1, null, 'route', 'home', 0)];

        $this->assertSame([1], $this->ids(NavigationService::buildTree($rows)));
        $this->assertSame([], NavigationService::buildButtons($rows));
    }

    public function testTheMenuAndTheButtonsAreSeparateLists(): void
    {
        $rows = [
            $this->row(1, null, 'route', 'home', 0),
            $this->button(2, 0),
            $this->row(3, null, 'route', 'shop', 1),
        ];

        $this->assertSame([1, 3], $this->ids(NavigationService::buildTree($rows)), 'a button is never a menu link');
        $this->assertSame([2], $this->ids(NavigationService::buildButtons($rows)), 'a menu link is never a button');
    }

    // --------------------------------------------------------------- buttons

    public function testNoButtonsRenderNothing(): void
    {
        $this->assertSame([], NavigationService::buildButtons([$this->row(1, null, 'route', 'home', 0)]));
        $this->assertSame([], NavigationService::buildButtons([]));
    }

    public function testOneButtonKeepsTheLookTheSingleHeaderButtonHad(): void
    {
        $buttons = NavigationService::buildButtons([$this->button(7, 0, [
            'label_nl' => 'Vraag offerte aan',
            'label_en' => 'Request a quote',
            'external_url' => '/contact.php',
        ])]);

        $this->assertSame([[
            'id' => 7,
            'label_nl' => 'Vraag offerte aan',
            'label_en' => 'Request a quote',
            'href' => '/contact.php',
            'open_in_new_tab' => false,
            'rel' => null,
            'class' => 'btn btn--sm',
        ]], $buttons);
    }

    public function testSeveralButtonsFollowTheirOwnOrder(): void
    {
        $rows = [
            $this->button(1, 2),
            $this->button(2, 0),
            $this->button(3, 1),
        ];

        $this->assertSame([2, 3, 1], $this->ids(NavigationService::buildButtons($rows)));
    }

    public function testTheStyleComesFromTheClosedListOnly(): void
    {
        $buttons = NavigationService::buildButtons([
            $this->button(1, 0, ['button_variant' => 'ghost']),
            $this->button(2, 1, ['button_variant' => 'btn--huge" onclick="x']),
        ]);

        $this->assertSame('btn btn--sm btn--ghost', $buttons[0]['class']);
        $this->assertSame('btn btn--sm', $buttons[1]['class'], 'an unknown variant falls back to the primary look, it never becomes a class');
    }

    public function testAButtonWithoutAnyLabelRendersNothing(): void
    {
        $rows = [$this->button(1, 0, ['label_nl' => '', 'label_en' => ''])];

        $this->assertSame([], NavigationService::buildButtons($rows));
    }

    public function testAnEmptyTranslationStillRendersTheButton(): void
    {
        $buttons = NavigationService::buildButtons([$this->button(1, 0, ['label_en' => ''])]);

        $this->assertCount(1, $buttons);
        $this->assertSame('Knop 1', $buttons[0]['label_nl']);
    }

    public function testAButtonWithoutADestinationRendersNothing(): void
    {
        $rows = [
            $this->button(1, 0, ['external_url' => '']),
            $this->button(2, 1, ['link_type' => 'route', 'target_route' => '', 'external_url' => null]),
            $this->button(3, 2, ['link_type' => 'none', 'external_url' => null]),
        ];

        $this->assertSame([], NavigationService::buildButtons($rows), 'no href, no button — never a link into nothing');
    }

    public function testARegisteredRouteAndAnExternalAddressResolve(): void
    {
        $buttons = NavigationService::buildButtons([
            $this->button(1, 0, ['link_type' => 'route', 'target_route' => 'home', 'external_url' => null]),
            $this->button(2, 1, ['external_url' => 'https://example.com/offerte']),
        ]);

        $this->assertSame(['/index.php', 'https://example.com/offerte'], array_column($buttons, 'href'));
    }

    public function testOpeningInANewTabAddsTheSafeRel(): void
    {
        $buttons = NavigationService::buildButtons([$this->button(1, 0, ['open_in_new_tab' => 1])]);

        $this->assertTrue($buttons[0]['open_in_new_tab']);
        $this->assertSame('noopener noreferrer', $buttons[0]['rel']);
    }

    /**
     * The case the one link model exists for: a button pointing at a route
     * the Shop owns, on a deployment where the Shop is switched off. The
     * route is not registered any more, so the button is not rendered — and
     * the row is untouched, so switching the Shop back on brings it back.
     */
    public function testAButtonPointingAtASwitchedOffModulesRouteDisappearsAndComesBack(): void
    {
        $row = $this->button(1, 0, ['link_type' => 'route', 'target_route' => 'shop', 'external_url' => null]);

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);
        $this->assertSame(['/shop.php'], array_column(NavigationService::buildButtons([$row]), 'href'));

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);
        $this->assertSame([], NavigationService::buildButtons([$row]));
        $this->assertSame([], NavigationService::buildTree([array_merge($row, ['presentation' => 'link'])]), 'the same holds for a menu link');

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);
        $this->assertCount(1, NavigationService::buildButtons([$row]));
    }

    /** The action area has no dropdown: a button row inside a submenu is not shown. */
    public function testAButtonInsideASubmenuIsNotRendered(): void
    {
        $rows = [
            $this->row(1, null, 'none', null, 0),
            array_merge($this->button(2, 0), ['parent_id' => 1]),
        ];

        $this->assertSame([], NavigationService::buildButtons($rows));
        $this->assertSame([], NavigationService::buildTree($rows)[0]['children']);
    }

    // ---------------------------------------------------------- current page

    public function testARouteLinkIsCurrentByTheTemplatesRouteKey(): void
    {
        $item = ['route_key' => 'shop', 'href' => '/shop.php'];

        $this->assertTrue(NavigationService::isCurrent($item, 'shop', '/product.php'));
        $this->assertFalse(NavigationService::isCurrent($item, 'blog', '/blog'));
    }

    /**
     * Page links have no route key: Diensten, Contact and every page an
     * editor made are page links. They are current on their own address.
     */
    public function testAPageLinkIsCurrentOnItsOwnAddress(): void
    {
        $contact = ['route_key' => null, 'href' => '/contact.php'];
        $page = ['route_key' => null, 'href' => '/over-ons'];

        $this->assertTrue(NavigationService::isCurrent($contact, 'contact', '/contact.php'));
        $this->assertTrue(NavigationService::isCurrent($page, null, '/over-ons'));
        $this->assertTrue(NavigationService::isCurrent($page, null, '/over-ons/'), 'a trailing slash is the same page');
        $this->assertFalse(NavigationService::isCurrent($page, null, '/over-ons-2'));
        $this->assertFalse(NavigationService::isCurrent($page, null, '/'));
    }

    public function testTheHomepageIsCurrentOnBothOfItsAddresses(): void
    {
        $home = ['route_key' => null, 'href' => '/'];

        $this->assertTrue(NavigationService::isCurrent($home, null, '/index.php'));
        $this->assertTrue(NavigationService::isCurrent(['route_key' => null, 'href' => '/index.php'], null, '/'));
    }

    public function testAnExternalLinkOrADropdownHeadingIsNeverCurrent(): void
    {
        $this->assertFalse(NavigationService::isCurrent(['route_key' => null, 'href' => 'https://example.com/'], null, '/'));
        $this->assertFalse(NavigationService::isCurrent(['route_key' => null, 'href' => '//example.com/'], null, '/'));
        $this->assertFalse(NavigationService::isCurrent(['route_key' => null, 'href' => null], null, '/'));
    }
}
