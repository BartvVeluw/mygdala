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
 *   - dragging a row takes its submenu along, folded or not, and a drop is
 *     one request naming the new parent and place (navigation-drag.js).
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
        $this->assertStringContainsString('<div class="admin-nav-children" id="nav-children-<?= $itemId ?>" data-parent-id="<?= $itemId ?>">', $screen);
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
        $drag = self::source('admin/assets/navigation-drag.js');

        // The dragged row's own submenu is never a drop target and fades
        // with it; the server moves it along (NavigationRepository::place()).
        $this->assertStringContainsString('function isOwn(row) {', $drag);
        $this->assertStringContainsString('if (isOwn(row)) return;', $drag);
        $this->assertStringContainsString("if (zone) zone.classList.add('is-dragging');", $drag);
        $this->assertStringContainsString('.admin-nav-children.is-dragging{ opacity: 0.4; }', self::source('admin/assets/admin.css'));
    }

    /**
     * Dragging between parents (admin/assets/navigation-drag.js,
     * HEADER-FOOTER.md "Verplaatsen"): one request per drop with the item,
     * its new parent and its place; three distinct marks; the levels and the
     * limit come from the server; a refusal is shown and nothing moves.
     */
    public function testADropIsOneRequestWithParentAndPlace(): void
    {
        $screen = self::source('admin/navigation.php');
        $drag = self::source('admin/assets/navigation-drag.js');

        $this->assertStringContainsString("AssetVersion::url('/admin/assets/navigation-drag.js') ?>\" defer", $screen);
        $this->assertStringContainsString('data-nav-tree data-max-depth="<?= NavigationRepository::MAX_DEPTH ?>" data-place-url="/api/admin/place-nav-item.php"', $screen);
        $this->assertStringContainsString("' data-nav-height=\"' . \$tree->heightOf(\$id) . '\"'", $screen);

        $this->assertSame(1, substr_count($drag, 'fetch('), 'one request per drop, never one per sibling');
        foreach (["body.set('id', id);", "body.set('parent_id', drop.parent);", "body.set('position', String(drop.position));"] as $field) {
            $this->assertStringContainsString($field, $drag);
        }
        $this->assertStringContainsString('if (isNoMove(drop)) return;', $drag, 'dropping a row where it is sends nothing');
        $this->assertStringContainsString("drop.inside.classList.add('is-drop-inside');", $drag, 'into: the row lights up');
        $this->assertStringContainsString('showLine(drop.y, drop.left);', $drag, 'before or after: a line at the level');
        $this->assertStringContainsString("return targetLevel + height(dragged) - 1 <= maxDepth;", $drag);
        $this->assertStringContainsString("dragged.hasAttribute('data-nav-heading') && targetLevel > 1", $drag);
        $this->assertStringContainsString('showError(data.error);', $drag);
        $this->assertStringContainsString("var page = '/admin/navigation.php?moved=' + encodeURIComponent(id);", $drag, 'the screen reloads what is stored');
        $this->assertStringContainsString('window.location.reload();', $drag, 'also when the address would only change its #fragment');

        // The menu's lists are no reorder zones any more; the buttons still are.
        $this->assertSame(1, substr_count($screen, 'data-nav-zone'));
        $this->assertStringContainsString('data-nav-zone data-parent-id="" data-presentation="button"', $screen);
    }
}
