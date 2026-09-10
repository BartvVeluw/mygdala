<?php

declare(strict_types=1);

use App\Service\AssetVersion;

/**
 * Collapsible items in a long admin list, and the "put me back where I was"
 * that goes with them.
 *
 * THE MECHANISM IS <details>. One generic one, for every kind of item —
 * there is deliberately no per-block-type collapse code and nothing for a
 * new content block to implement. A <summary> is a real disclosure button in
 * every browser: it opens on click and on Enter/Space, it is in the tab
 * order, and it reports expanded/collapsed to a screen reader without a
 * single aria attribute of ours. The same pattern the personalisation editor
 * already uses for its voorbeelden and zones.
 *
 * THE MARKUP A SCREEN WRITES
 *
 *     <div data-admin-collapse-group="page-blocks" data-admin-collapse-scope="12">
 *       <div class="admin-section-row" ...>
 *         <span class="admin-drag-handle" draggable="true">...</span>
 *         <details class="admin-collapse" data-admin-collapse-id="42">
 *           <summary class="admin-collapse__summary"> ... </summary>
 *           <div class="admin-collapse__body"> ... </div>
 *         </details>
 *       </div>
 *     </div>
 *
 * Note where the drag handle sits: OUTSIDE the <details>. Reordering a
 * collapsed row is the whole point of collapsing them, so the handle can
 * never be inside the part that folds away.
 *
 * WHAT THE SCRIPT ADDS. Two things, both about not losing the editor's
 * place:
 *
 *   - which items are open is remembered per group and per scope, so a save
 *     that reloads the screen does not fold everything back up. An item the
 *     server marked `data-admin-collapse-open` (a block that was just added)
 *     opens whatever was remembered;
 *   - the item an editor last acted on — followed its Bewerken link, pressed
 *     its Verbergen button — is remembered as that group's return target and
 *     is opened, revealed (its tab too, see admin/assets/admin-tabs.js) and
 *     scrolled to on the next load of the screen. Consumed once, so it moves
 *     the viewport when an editor comes back from an edit and never again.
 *
 * All of it is sessionStorage in the editor's own browser: no endpoint
 * changed, no redirect gained a parameter, nothing is stored per user on the
 * server for a preference this small.
 */

/**
 * The behaviour. Load it AFTER admin-tabs.js on a screen that has both —
 * revealing a block's tab before scrolling to it needs window.AdminTabs.
 */
function admin_collapse_script(): void
{
    echo '<script src="' . htmlspecialchars(AssetVersion::url('/admin/assets/admin-collapse.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
