<?php

declare(strict_types=1);

use App\Service\AssetVersion;

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

/**
 * The dynamic admin editor: ONE form with ONE Opslaan that stores the whole
 * screen without a page load, knows when something is unsaved, and asks in
 * the CMS's own dialog before an editor walks away from it (ADMIN-UI.md,
 * "Een editor die opslaat zonder te herladen"). The product editor is the
 * first screen on it; any editor with one form can follow.
 *
 * NOT THE SAVE BAR. admin/_save_bar.php drives several ordinary forms with a
 * page load after every save, and the screens that use it keep it. This is
 * the next step for an editor that is ONE form: its endpoint answers the
 * script in JSON (App\Service\AdminEditorResponse) and keeps its redirect for
 * a form posted without it. A screen uses one of the two, never both.
 *
 * HOW A SCREEN USES IT
 *
 *     require_once __DIR__ . '/_admin_editor.php';
 *     ...
 *     <?= admin_editor_summary($errors) ?>
 *     <form method="post" action="/api/admin/…" data-admin-editor>
 *       … sections, each [data-admin-editor-section="<key>"] …
 *       <button type="submit" data-admin-editor-fallback>Opslaan</button>
 *     </form>
 *     </main>
 *     <?php admin_editor_bar(); ?>
 *     <?= admin_editor_leave_dialog() ?>
 *     <?php admin_editor_script(); ?>
 *
 * The rest is markup the script reads (admin/assets/admin-editor.js):
 * `data-admin-editor-errors="<section>"` for a section's own messages,
 * `data-admin-editor-error-for="<field name>"` for a message about a group of
 * fields, `data-admin-editor-region="<key>"` around what the server must draw
 * again after a save (rows that just got their id), and
 * `data-admin-editor-leave` on a link that throws the input away on purpose.
 */

/**
 * The fixed bar at the bottom: what state the editor is in, and its one
 * Opslaan. Hidden until the script shows it: without the script the form's
 * own button saves, the way it always did. The look is the save bar's
 * (.admin-save-bar), so an editor reads the same on every screen.
 */
function admin_editor_bar(): void
{
    ?>
    <div class="admin-save-bar-spacer" aria-hidden="true"></div>
    <div class="admin-save-bar" data-admin-editor-bar data-save-bar-state="saved" hidden
         data-label-save="<?= admin_te('common.save') ?>"
         data-label-saving="<?= admin_te('common.saving') ?>"
         data-label-dirty="<?= admin_te('common.unsaved_changes') ?>"
         data-label-error="<?= admin_te('common.save_failed') ?>"
         data-label-saved="<?= admin_te('common.all_saved') ?>"
         data-label-just-saved="<?= admin_te('common.saved') ?>"
         data-label-invalid="<?= admin_te('editor.invalid') ?>"
         data-label-check="<?= admin_te('editor.check_fields') ?>"
         data-label-expired="<?= admin_te('editor.expired') ?>"
         data-label-forbidden="<?= admin_te('editor.forbidden') ?>"
         data-label-failed="<?= admin_te('editor.failed') ?>"
         data-label-offline="<?= admin_te('editor.offline') ?>"
         data-label-leave-question="<?= admin_te('editor.leave.native') ?>">
      <p class="admin-save-bar__status" role="status" aria-live="polite">
        <span class="admin-save-bar__dot" aria-hidden="true"></span>
        <span data-admin-editor-text><?= admin_te('common.all_saved') ?></span>
      </p>
      <button type="button" class="admin-save-bar__button" data-admin-editor-save disabled><?= admin_te('common.save') ?></button>
    </div>
    <?php
}

/**
 * Every message of a refused save, above the form. Shown by the server for a
 * form posted without the script (the session flash), and filled by the
 * script otherwise; focused when it appears, so it is read out first.
 *
 * @param list<string> $errors what the server refused last time, if anything
 */
function admin_editor_summary(array $errors = []): string
{
    $items = '';
    foreach ($errors as $error) {
        $items .= '<li>' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    return '<div class="admin-alert admin-alert--error admin-editor-summary" data-admin-editor-summary tabindex="-1" role="alert"'
        . ($errors === [] ? ' hidden' : '') . '>'
        . '<p class="admin-editor-summary__title" data-admin-editor-summary-title>'
        . ($errors === [] ? '' : admin_te('editor.invalid'))
        . '</p>'
        . '<ul class="admin-error-list" data-admin-editor-summary-list>' . $items . '</ul>'
        . '</div>';
}

/**
 * What an editor is asked before leaving with unsaved changes, for a link or
 * a form elsewhere in the CMS. A native modal <dialog>, like
 * admin_confirm_dialog(): the page behind it is inert, Tab stays inside, and
 * Escape and a press on the dimmed page mean "stay". Its three answers:
 *
 *   Blijven                  first in the source, so it has the focus and a
 *                            stray Enter stays;
 *   Zonder opslaan doorgaan  goes, and the changes are gone;
 *   Opslaan en doorgaan      the editor's own save; only when the server
 *                            stored everything does the browser go on, and a
 *                            refused save closes the dialog and shows why.
 *
 * Only the CMS's own navigation gets this. Reloading, closing the tab, the
 * address bar and Back belong to the browser, which asks its own question
 * there and lets no page restyle it.
 */
function admin_editor_leave_dialog(): string
{
    $headingId = admin_ui_id('admin-editor-leave-title');
    $textId = admin_ui_id('admin-editor-leave-text');

    return '<dialog class="admin-confirm admin-editor-leave" data-admin-editor-leave-dialog'
        . ' aria-labelledby="' . $headingId . '" aria-describedby="' . $textId . '">'
        . '<form method="dialog" class="admin-confirm__panel">'
        . '<h2 class="admin-confirm__title" id="' . $headingId . '">' . admin_te('editor.leave.title') . '</h2>'
        . '<p class="admin-confirm__message" id="' . $textId . '">' . admin_te('editor.leave.message') . '</p>'
        . '<div class="admin-confirm__actions admin-editor-leave__actions">'
        . '<button type="submit" value="stay" class="admin-btn-secondary" data-admin-editor-leave-stay>' . admin_te('editor.leave.stay') . '</button>'
        . '<button type="submit" value="discard" class="admin-btn-danger" data-admin-editor-leave-discard>' . admin_te('editor.leave.discard') . '</button>'
        . '<button type="submit" value="save" class="admin-btn-primary" data-admin-editor-leave-save>' . admin_te('editor.leave.save') . '</button>'
        . '</div>'
        . '</form>'
        . '</dialog>';
}

/** The behaviour. Deferred, after the screen's own scripts that enhance its regions. */
function admin_editor_script(): void
{
    echo '<script src="' . htmlspecialchars(AssetVersion::url('/admin/assets/admin-editor.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
