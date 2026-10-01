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
        $this->assertMatchesRegularExpression('/if \(form\.contains\(target\)\) \{\s*markDirty\(\);/', $script);
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
        // The editor's own save never reloads the page, only when it can no
        // longer be trusted (the regions could not be drawn again). The one
        // other reload follows saved companion forms, whose endpoints do not
        // speak the editor contract (testCompanionForms...).
        $this->assertSame(2, substr_count($script, 'window.location.reload()'));
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
        $this->assertMatchesRegularExpression('/save\(\{ refresh: false, follow: false, except: request\.except \}\)\.then\(function \(stored\) \{.*?if \(stored\) \{.*?request\.go\(\);/s', $script);

        // The browser's own question: only while unsaved, never after a choice.
        $this->assertMatchesRegularExpression('/addEventListener\("beforeunload", function \(event\) \{\s*if \(!anyDirty\(\) \|\| leavingOnPurpose\) return;/', $script);
    }

    /**
     * Companion forms (Extra vormgeving in a product's Inhoud tab): picked by
     * save-bar.js's own rule, each dirty on its own, saved once after the
     * editor and only on the endpoint's success redirect, never cleaned by
     * the editor's save, and never sent twice when its own button is the way
     * out of the leave dialog.
     */
    public function testCompanionFormsAreWatchedAndSavedOnceBySaveBarsRule(): void
    {
        $editor = self::source('admin/assets/admin-editor.js');
        $bar = self::source('admin/assets/save-bar.js');

        // One rule for "a form the bar watches", the same text in both scripts.
        foreach ([
            "\"input:not([type='hidden']):not([type='submit']):not([type='button']), select, textarea\"",
            "document.querySelectorAll(\"main.admin-main form[method='post'], main.admin-main form[method='POST']\")",
            '!form.classList.contains("admin-inline-form")',
            '!form.hasAttribute("data-no-dirty-track")',
            "querySelector(\"[type='submit'], button:not([type])\") !== null",
            'querySelector(EDITABLE) !== null',
        ] as $rule) {
            $this->assertStringContainsString($rule, $bar, $rule);
            $this->assertStringContainsString(str_replace('form.', 'other.', $rule), $editor, $rule);
        }
        $this->assertStringContainsString('other !== form &&', $editor, 'the editor itself is no companion');

        // Dirty per form; the screen is unsaved while anything is.
        $this->assertStringContainsString('if (other && companions.indexOf(other) !== -1) markCompanionDirty(other);', $editor);
        $this->assertStringContainsString('return dirty || dirtyCompanions.length > 0;', $editor);
        $this->assertMatchesRegularExpression('/function markClean\(message\) \{\s*dirty = false;.*?if \(dirtyCompanions\.length > 0\) \{\s*render\("dirty"\);\s*return;/s', $editor, 'the editor save never cleans a companion');
        $this->assertStringContainsString('if (!anyDirty() || leavingOnPurpose) return;', $editor);

        // Saved once each, in order, after the editor, accepted only on the server's marker.
        $this->assertStringContainsString('if (saving) return saving;', $editor);
        $this->assertStringContainsString('return other !== except && dirtyCompanions.indexOf(other) !== -1;', $editor);
        $this->assertStringContainsString('(dirty ? saveEditor({ follow: false }) : Promise.resolve(true)).then(function (stored) {', $editor);
        $this->assertStringContainsString('return stored ? saveCompanions(queue, follow) : false;', $editor);
        $this->assertSame(1, substr_count($editor, 'body: new FormData(other)'), 'one request per companion');
        $this->assertStringContainsString('response.ok && /[?&](saved|updated|created)=[1-9][0-9]*(&|$)/.test(response.url)', $editor);
        $this->assertStringContainsString('response.ok && /[?&](saved|updated|created)=[1-9][0-9]*(&|$)/.test(response.url)', $bar);
        $this->assertStringContainsString('dirtyCompanions.splice(dirtyCompanions.indexOf(other), 1);', $editor);
        $this->assertStringContainsString('other.inert = true;', $editor, 'nothing typed while it travels');

        // Its own button: the ordinary POST. Alone it is the save; with
        // anything else unsaved the leave dialog asks, and "Opslaan en
        // doorgaan" saves the rest WITHOUT that form, which go() then sends.
        $this->assertMatchesRegularExpression('/if \(!dirtyBesides\(other\)\) \{\s*if \(companions\.indexOf\(other\) !== -1\) \{\s*leavingOnPurpose = true;/', $editor);
        $this->assertStringContainsString('}, submitter, other);', $editor);
        $this->assertStringContainsString('pendingLeave = { go: go, from: from, except: except || null };', $editor);

        // The CMS's words for a refused companion travel as data, like the rest.
        require_once dirname(__DIR__, 2) . '/admin/_admin_editor.php';
        ob_start();
        admin_editor_bar();
        $markup = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/data-label-error-in="[^"]*:form[^"]*"/', $markup);
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
