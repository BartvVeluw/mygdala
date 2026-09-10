<?php

declare(strict_types=1);

use App\Service\AssetVersion;

/**
 * The always-visible save/status bar for the page editor and the block
 * editors it links into.
 *
 * THE PROBLEM IT SOLVES. These screens are made of several small forms, each
 * with its own "Opslaan" button somewhere down the page — the section's own
 * fields, then one form per repeated item, then a form to add another. An
 * editor who typed something and scrolled had to work out which of those
 * buttons belonged to what they changed, and nothing on screen said whether
 * anything was still unsaved.
 *
 * WHAT IT IS NOT. It is not a page-wide form and it does not replace a single
 * endpoint. Every form on these screens still posts to its own
 * api/admin/ endpoint with its own validation and its own PRG redirect, and
 * every existing "Opslaan" button still works exactly as before. The bar
 * watches those forms and drives them; it owns no fields of its own, and
 * there is no second copy of any validation rule in the browser (see
 * admin/assets/save-bar.js).
 *
 * HOW A SCREEN USES IT
 *
 *     require_once __DIR__ . '/_save_bar.php';
 *     ...
 *     </main>
 *     <?php save_bar(); ?>
 *     <?php save_bar_script(); ?>
 *
 * Every POST form inside <main class="admin-main"> is watched automatically,
 * so a screen does not annotate its forms. Two escapes exist for the cases
 * where that is wrong: `.admin-inline-form` (the one-button hide/move/delete
 * forms, which carry nothing an editor can type) is skipped, and any form
 * with `data-no-dirty-track` opts out by hand — that is what the block
 * picker's form uses, since choosing a block is not an edit that can be
 * "saved later". The sidebar's logout form sits outside <main> and is
 * therefore never touched: submitting THAT from a save button would log the
 * editor out mid-edit.
 */

/**
 * The bar. Call once, immediately after </main>.
 *
 * The spacer is a real element in the flow rather than padding on
 * .admin-main, so only the screens that actually render a bar reserve room
 * for it and nothing has to know in CSS which screens those are.
 */
function save_bar(): void
{
    ?>
    <div class="admin-save-bar-spacer" aria-hidden="true"></div>
    <div class="admin-save-bar" data-save-bar data-save-bar-state="saved" hidden>
      <p class="admin-save-bar__status" data-save-bar-status role="status" aria-live="polite">
        <span class="admin-save-bar__dot" aria-hidden="true"></span>
        <span data-save-bar-text>Alles opgeslagen</span>
      </p>
      <button type="button" class="admin-save-bar__button" data-save-bar-save disabled>Opslaan</button>
    </div>
    <?php
}

/**
 * The bar's script. Separate from admin.js because only these screens carry
 * a save bar, and the same reason media-picker.js is its own file: a screen
 * asks for the behaviour it renders.
 */
function save_bar_script(): void
{
    echo '<script src="' . htmlspecialchars(AssetVersion::url('/admin/assets/save-bar.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
