<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Folding the menu tree of Header & navigatie (admin/assets/navigation-tree.js,
 * ADMIN-UI.md "Een boom inklappen"), from its sources: the markup
 * NavigationAdminHttpTest renders is driven like this.
 *
 *   - a view only: the script posts nothing and the screen's forms and zones
 *     are untouched; the state is this browser's, in localStorage, under one
 *     key, and storage that fails is caught;
 *   - the button says whether it is open (aria-expanded) and the zone it
 *     names is hidden when it is not, so every level below goes with it;
 *   - a row #nav-item-<id> named in the address opens the items above it;
 *   - dragging a row takes its submenu along, folded or not (admin.js).
 */
final class NavigationTreeContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path));
    }

    public function testTheScreenLoadsTheScriptAndEveryFoldIsARealButtonNamingItsZone(): void
    {
        $screen = self::source('admin/navigation.php');

        $this->assertStringContainsString("AssetVersion::url('/admin/assets/navigation-tree.js') ?>\" defer", $screen);
        $this->assertStringContainsString('<button type="button" class="admin-nav-tree__toggle" aria-expanded="true" aria-controls="nav-children-<?= $id ?>" data-nav-tree-toggle="<?= $id ?>">', $screen);
        $this->assertStringContainsString('<div class="admin-nav-children" id="nav-children-<?= $itemId ?>" data-nav-zone', $screen);
        $this->assertStringContainsString("admin_te('navigation.tree_children', ['item' => \$label])", $screen);
    }

    public function testFoldingIsAViewKeptInThisBrowserOnly(): void
    {
        $script = self::source('admin/assets/navigation-tree.js');

        $this->assertStringContainsString("var STORAGE_KEY = 'mygdalaNavigationTree';", $script);
        $this->assertSame(1, substr_count($script, 'window.localStorage.getItem('));
        $this->assertSame(1, substr_count($script, 'window.localStorage.setItem('));
        $this->assertSame(2, substr_count($script, '} catch (e) {'), 'reading and writing storage may both fail');
        foreach (['fetch(', 'XMLHttpRequest', '.submit(', 'sessionStorage'] as $never) {
            $this->assertStringNotContainsString($never, $script, $never);
        }

        $this->assertStringContainsString("button.setAttribute('aria-expanded', open ? 'true' : 'false');", $script);
        $this->assertStringContainsString('children.hidden = !open;', $script);
        $this->assertStringContainsString('window.location.hash', $script, 'a linked row is never folded away');
        $this->assertStringContainsString('.admin-nav-children[hidden]{ display: none; }', self::source('admin/assets/admin.css'));
    }

    public function testDraggingARowTakesItsSubmenuAlong(): void
    {
        $admin = self::source('admin/assets/admin.js');

        $this->assertStringContainsString('function navChildrenOf(row) {', $admin);
        $this->assertStringContainsString('zone.insertBefore(draggedChildren, dragged.nextSibling);', $admin);
        $this->assertStringContainsString('var target = isAfter ? (navChildrenOf(row) || row).nextSibling : row;', $admin);
        // The order posted is still the zone's own rows, never a row of a submenu.
        $this->assertStringContainsString('zone.querySelectorAll(":scope > .admin-nav-item-row")', $admin);
    }
}
