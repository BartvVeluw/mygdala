<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminLocale;
use PHPUnit\Framework\TestCase;

/**
 * The dynamic admin editor's source contract (admin/_admin_editor.php,
 * admin/assets/admin-editor.js; ADMIN-UI.md "Een editor die opslaat zonder
 * te herladen"). The behaviour itself is proven in a browser (TESTING.md,
 * the Shop Admin UX 2.0 acceptance); what can drift silently in the source
 * is pinned here:
 *
 *  - what makes the editor dirty (input and change inside the form) and what
 *    cannot (opening a section is a <details> toggle, no such event);
 *  - the save: fetch with Accept: application/json, the form inert while it
 *    travels, clean only after `ok`, dirty after every failure;
 *  - leaving: the CMS's own dialog for a link or form elsewhere in the CMS,
 *    never for a new tab, a modifier click, a download, another site or a
 *    jump within the page; "Opslaan en doorgaan" goes on only after a stored
 *    save; the browser's own question only while something is unsaved;
 *  - the dialog: a native modal <dialog> named by its title, "Blijven" first;
 *  - the script writes no markup from text and says no sentence of its own.
 *
 * No database, no server.
 */
final class AdminEditorContractTest extends TestCase
{
    protected function setUp(): void
    {
        AdminLocale::overrideForTests('nl');
    }

    protected function tearDown(): void
    {
        AdminLocale::overrideForTests(null);
    }

    private static function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testWhatMakesTheEditorDirtyAndWhatDoesNot(): void
    {
        $script = self::source('admin/assets/admin-editor.js');

        $this->assertStringContainsString('["input", "change", "admin-editor:change"].forEach', $script);
        $this->assertStringContainsString('form.contains(target)) markDirty();', $script);
        // A section folding fires "toggle", which the editor never listens to.
        $this->assertStringNotContainsString('"toggle"', $script);
        // Drawing a region again after a save is no edit.
        $this->assertStringContainsString('quiet++;', $script);
        $this->assertStringContainsString('if (quiet > 0 || saving) return;', $script);
    }

    public function testTheSaveIsOneJsonRequestAndCleanOnlyWhenStored(): void
    {
        $script = self::source('admin/assets/admin-editor.js');

        $this->assertStringContainsString('headers: { Accept: "application/json" }', $script);
        $this->assertStringContainsString('var body = new FormData(form);', $script);
        $this->assertStringContainsString('form.inert = true;', $script);
        $this->assertStringContainsString('if (!answer.ok) {', $script);
        $this->assertSame(1, substr_count($script, 'markClean(answer'), 'clean in one place only: after a stored save');
        $this->assertMatchesRegularExpression('/return done\.then\(function \(\) \{\s*markClean\(/', $script, 'clean after the regions are drawn again');
        // The page is never reloaded to show a save, only when it can no
        // longer be trusted (the regions could not be drawn again).
        $this->assertSame(1, substr_count($script, 'window.location.reload()'));
        $this->assertStringContainsString('data-admin-editor-region', $script);
        $this->assertStringContainsString('new CustomEvent("admin-editor:replaced", { bubbles: true })', $script);
    }

    public function testLeavingAsksOnlyForTheCmsOwnNavigation(): void
    {
        $script = self::source('admin/assets/admin-editor.js');

        foreach ([
            'event.metaKey || event.ctrlKey || event.shiftKey || event.altKey',
            'link.hasAttribute("download")',
            'target !== "" && target !== "_self"',
            'url.origin !== window.location.origin',
            'url.protocol !== "http:" && url.protocol !== "https:"',
            'url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== ""',
        ] as $rule) {
            $this->assertStringContainsString($rule, $script);
        }

        // A form elsewhere that navigates (the language switch, Uitloggen)
        // asks too; a dialog's own form never does.
        $this->assertStringContainsString('=== "dialog") return;', $script);

        // "Opslaan en doorgaan" goes on only when the server stored it.
        $this->assertMatchesRegularExpression('/save\(\{ refresh: false, follow: false \}\)\.then\(function \(stored\) \{.*?if \(stored\) \{.*?request\.go\(\);/s', $script);

        // The browser's own question: only while unsaved, never after a choice.
        $this->assertMatchesRegularExpression('/addEventListener\("beforeunload", function \(event\) \{\s*if \(!dirty \|\| leavingOnPurpose\) return;/', $script);
    }

    public function testTheLeaveDialogIsANativeModalInTheCmsOwnWords(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/_admin_editor.php';

        $dialog = admin_editor_leave_dialog();

        $this->assertStringStartsWith('<dialog class="admin-confirm admin-editor-leave" data-admin-editor-leave-dialog', $dialog);
        $this->assertMatchesRegularExpression('/aria-labelledby="([^"]+)".*<h2 class="admin-confirm__title" id="\1">Niet-opgeslagen wijzigingen<\/h2>/s', $dialog);
        $this->assertStringContainsString('<form method="dialog"', $dialog);
        preg_match_all('/<button type="submit" value="([a-z]+)"[^>]*>([^<]+)<\/button>/', $dialog, $buttons);
        $this->assertSame(['stay', 'discard', 'save'], $buttons[1], '"Blijven" first: where focus lands and what a stray Enter does');
        $this->assertSame(['Blijven', 'Zonder opslaan doorgaan', 'Opslaan en doorgaan'], $buttons[2]);

        $script = self::source('admin/assets/admin-editor.js');
        $this->assertStringContainsString('dialog.showModal();', $script);
        $this->assertStringContainsString('dialog.addEventListener("cancel"', $script, 'Escape stays');
        $this->assertStringContainsString('request.from.focus()', $script, 'focus goes back to what asked');
    }

    public function testTheScriptWritesNoMarkupAndNoSentence(): void
    {
        $script = self::source('admin/assets/admin-editor.js');

        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringNotContainsString('insertAdjacentHTML', $script);

        // Only the code, not the comments that explain it in the CMS's words.
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $script);
        foreach (['Opslaan', 'Blijven', 'opgeslagen', 'wijzigingen'] as $dutch) {
            $this->assertStringNotContainsString($dutch, $code, 'CMS text travels as data-label-* from the catalog');
        }

        require_once dirname(__DIR__, 2) . '/admin/_admin_editor.php';
        ob_start();
        admin_editor_bar();
        $bar = (string) ob_get_clean();
        foreach (['save', 'saving', 'dirty', 'error', 'saved', 'just-saved', 'invalid', 'check', 'expired', 'forbidden', 'failed', 'offline', 'leave-question'] as $label) {
            $this->assertMatchesRegularExpression('/data-label-' . preg_quote($label, '/') . '="[^"]+"/', $bar, $label);
        }
        $this->assertStringContainsString(' hidden', $bar, 'without the script the form has its own button');
    }
}
