<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminLocale;
use PHPUnit\Framework\TestCase;

/**
 * The shared admin UI primitives: field help, the global help switch, the
 * info panel, the file input and the confirmation dialog (admin/_admin_ui.php,
 * admin/assets/admin-ui.js and the ADMIN UI PRIMITIVES section of admin.css —
 * ADMIN-UI.md).
 *
 * WHAT THIS FILE IS ABOUT. There is no browser in this suite, so what the
 * script does on hover, click and Escape is checked by hand (ADMIN-UI.md
 * keeps the list). What can break silently is pinned here instead: the
 * accessibility contract of the markup, that CMS text is escaped with only a
 * tiny allowance given back, that the shell really loads the behaviour on
 * every screen, that the preference lives under one Mygdala key, and that no
 * sentence an editor reads has crept into the script.
 *
 * HOW IT CHECKS. Same technique as AdminEditorNavigationTest: the partial is
 * a plain include of output functions, rendered in-process with no login, no
 * request and no database. The markup is read back with DOMDocument rather
 * than matched as a string, so attribute order is free to change.
 */
final class AdminUiPrimitivesTest extends TestCase
{
    private const SCRIPT = 'admin/assets/admin-ui.js';

    public static function setUpBeforeClass(): void
    {
        require_once self::root() . '/admin/_translate.php';
        require_once self::root() . '/admin/_admin_ui.php';
    }

    protected function setUp(): void
    {
        AdminLocale::overrideForTests('nl');
    }

    protected function tearDown(): void
    {
        AdminLocale::overrideForTests(null);
        parent::tearDown();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function source(string $relativePath): string
    {
        $path = self::root() . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private static function one(\DOMXPath $xpath, string $query): \DOMElement
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes, $query);
        self::assertSame(1, $nodes->length, 'expected exactly one match for ' . $query);

        $node = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    private static function nodeCount(\DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes, $query);

        return $nodes->length;
    }

    /**
     * The primitives section of admin.css, from its banner to the end of the
     * file, with the comments removed so a scan reads rules and not prose.
     *
     * @return array{0: int, 1: string} where the section starts, and its rules
     */
    private static function primitivesCss(): array
    {
        $css = self::source('admin/assets/admin.css');
        $title = strpos($css, 'ADMIN UI PRIMITIVES (admin/_admin_ui.php');
        self::assertNotFalse($title, 'admin.css has no ADMIN UI PRIMITIVES section');

        $start = (int) strrpos(substr($css, 0, $title), '/*');

        return [$start, (string) preg_replace('#/\*.*?\*/#s', '', substr($css, $start))];
    }

    // --- Field help ------------------------------------------------------------

    public function testTheHelpIconIsARealButtonWiredToItsExplanation(): void
    {
        $xpath = self::xpath(admin_help('E-mailadres', 'Hier komen berichten binnen.'));

        $trigger = self::one($xpath, '//button[@data-admin-help-trigger]');
        $popover = self::one($xpath, '//*[@data-admin-help-popover]');
        $id = $popover->getAttribute('id');

        $this->assertNotSame('', $id);
        $this->assertSame('button', $trigger->getAttribute('type'), 'a help icon must never submit the form it sits in');
        $this->assertSame('false', $trigger->getAttribute('aria-expanded'));
        $this->assertSame($id, $trigger->getAttribute('aria-controls'));
        $this->assertSame('dialog', $trigger->getAttribute('aria-haspopup'));
        $this->assertSame($id, $trigger->getAttribute('popovertarget'), 'without the script, the browser opens the explanation through this');
        $this->assertSame('Uitleg over E-mailadres', $trigger->getAttribute('aria-label'), 'the button says what it explains');
        $this->assertSame('true', self::one($xpath, '//button[@data-admin-help-trigger]/span')->getAttribute('aria-hidden'), 'the "?" itself is decoration');

        $this->assertSame('manual', $popover->getAttribute('popover'), 'the script decides when an explanation closes, not the browser');
        $this->assertSame('dialog', $popover->getAttribute('role'));
        $this->assertSame('E-mailadres', trim(self::one($xpath, '//*[@id="' . $popover->getAttribute('aria-labelledby') . '"]')->textContent));
        $this->assertSame('Hier komen berichten binnen.', trim(self::one($xpath, '//*[contains(@class, "admin-help__body")]')->textContent));

        $close = self::one($xpath, '//button[@data-admin-help-close]');
        $this->assertSame('button', $close->getAttribute('type'));
        $this->assertSame('Uitleg sluiten', $close->getAttribute('aria-label'), 'the cross needs a name a screen reader can say');
        $this->assertSame($id, $close->getAttribute('popovertarget'));
        $this->assertSame('hide', $close->getAttribute('popovertargetaction'));

        // The explanation follows its button directly, so Tab goes from the
        // icon to the cross, in the order the explanation is read.
        $next = $trigger->nextSibling;
        $this->assertInstanceOf(\DOMElement::class, $next);
        $this->assertSame($id, $next->getAttribute('id'));
    }

    public function testEveryExplanationOnAScreenHasItsOwnIds(): void
    {
        // The Dutch and the English pane of one setting carry the same help,
        // so an id can never be derived from the text.
        $xpath = self::xpath(admin_help('Plaats', 'Uitleg.') . admin_help('Plaats', 'Uitleg.'));

        $ids = [];
        foreach ($xpath->query('//*[@id]') ?: [] as $element) {
            $ids[] = $element->getAttribute('id');
        }

        $this->assertCount(4, $ids, 'two explanations, each with its own id and its title id');
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function testCmsTextIsEscapedAndKeepsOnlyItsSmallMarkup(): void
    {
        $xpath = self::xpath(admin_help(
            '<img src=x onerror=alert(1)>',
            '<script>alert(1)</script> Let <strong>goed</strong> op, <em>echt</em>.'
                . ' <strong onclick="steal()">nee</strong> <a href="javascript:alert(1)">link</a>'
                . "\n\nTweede alinea.\nNieuwe regel."
        ));

        foreach (['//script', '//img', '//a', '//*[@onclick]', '//*[@onerror]', '//*[@href]'] as $forbidden) {
            $this->assertSame(0, self::nodeCount($xpath, $forbidden), $forbidden . ' must not survive escaping');
        }

        // Shown as text, not silently dropped: an editor who types a "<" sees it.
        $this->assertSame('<img src=x onerror=alert(1)>', self::one($xpath, '//*[contains(@class, "admin-help__title")]')->textContent);
        $this->assertStringContainsString('<script>alert(1)</script>', self::one($xpath, '//*[contains(@class, "admin-help__body")]')->textContent);

        // The allowance: <strong> and <em> without attributes, paragraphs, line breaks.
        $this->assertSame('goed', self::one($xpath, '//*[contains(@class, "admin-help__body")]//strong')->textContent);
        $this->assertSame('echt', self::one($xpath, '//em')->textContent);
        $this->assertSame(2, self::nodeCount($xpath, '//*[@class="admin-help__p"]'));
        $this->assertSame(1, self::nodeCount($xpath, '//br'));

        // The catalog writes typographic entities; they are characters, not text.
        $this->assertSame('Pagina’s & meer', self::one(self::xpath(admin_help_text('Pagina&rsquo;s &amp; meer')), '//span')->textContent);
    }

    public function testAFieldLabelSitsBesideItsHelpAndNotAroundIt(): void
    {
        $xpath = self::xpath(admin_field_label('settings-email', 'E-mailadres', 'Uitleg.', true));

        $label = self::one($xpath, '//label');
        $this->assertSame('settings-email', $label->getAttribute('for'));
        $this->assertSame('E-mailadres*', trim($label->textContent));

        // Inside a <label> the button's name would become part of the field's
        // name, and a click in the explanation would land on the field.
        $this->assertSame(0, self::nodeCount($xpath, '//label//button'));
        $this->assertSame(1, self::nodeCount($xpath, '//label/following-sibling::*[@data-admin-help]'));

        $this->assertSame(0, self::nodeCount(self::xpath(admin_field_label('x', 'Zonder uitleg')), '//*[@data-admin-help]'));
    }

    // --- Info panel and the global switch ------------------------------------

    public function testAnInfoPanelIsANoteThatFollowsTheGlobalSwitch(): void
    {
        $xpath = self::xpath(admin_info_panel("Hier beheer je <strong>alle</strong> pagina's. <script>x</script>"));

        $panel = self::one($xpath, '//div[@data-admin-help-panel]');
        $this->assertSame('note', $panel->getAttribute('role'));
        $this->assertStringContainsString('admin-info-panel', $panel->getAttribute('class'));
        $this->assertSame(0, self::nodeCount($xpath, '//script'));
        $this->assertSame('alle', self::one($xpath, '//strong')->textContent);

        [, $rules] = self::primitivesCss();
        $this->assertMatchesRegularExpression(
            '/\[data-admin-help="off"\] \.admin-help,\s*\[data-admin-help="off"\] \.admin-info-panel\s*\{\s*display:\s*none;\s*\}/',
            $rules,
            'switching help off hides every icon and every info panel, and nothing else'
        );
    }

    public function testTheGlobalHelpSwitchIsAToggleButtonInTheShell(): void
    {
        foreach (ADMIN_HELP_TOGGLE_PLACEMENTS as $placement) {
            $xpath = self::xpath(admin_help_toggle($placement));
            $toggle = self::one($xpath, '//button[@data-admin-help-toggle]');

            $this->assertSame('button', $toggle->getAttribute('type'));
            $this->assertSame('true', $toggle->getAttribute('aria-pressed'), 'help is on for somebody who never chose');
            $this->assertSame('Uitleg', trim(self::one($xpath, '//*[contains(@class, "admin-help-toggle__label")]')->textContent));

            foreach ($xpath->query('//*[contains(@class, "admin-help-toggle__state")]') ?: [] as $state) {
                $this->assertSame('true', $state->getAttribute('aria-hidden'), 'aria-pressed already says it; the word is for the eye');
            }
        }

        // The placement is a closed list; anything else becomes the sidebar's.
        $this->assertStringContainsString('admin-help-toggle--sidebar"', admin_help_toggle('" onclick="x'));

        $header = self::source('admin/_header.php');
        $this->assertStringContainsString("admin_help_toggle('sidebar')", $header);
        $this->assertStringContainsString("admin_help_toggle('topbar')", $header);
    }

    // --- Loading ---------------------------------------------------------------

    public function testTheShellLoadsTheBehaviourBeforeAnythingItHides(): void
    {
        $header = self::source('admin/_header.php');

        $this->assertStringContainsString("require_once __DIR__ . '/_admin_ui.php';", $header);

        $call = strpos($header, '<?php admin_ui_script(); ?>');
        $this->assertNotFalse($call, 'admin/_header.php must load admin-ui.js');
        $this->assertLessThan(
            (int) strpos($header, '<aside'),
            $call,
            'loaded before the shell markup, so a stored "help off" is on <html> before the first paint'
        );

        ob_start();
        admin_ui_script();
        $tag = (string) ob_get_clean();

        $this->assertStringContainsString('src="/admin/assets/admin-ui.js?v=', $tag);
        $this->assertStringNotContainsString('defer', $tag, 'a deferred script would paint the help first and hide it afterwards');
        $this->assertStringNotContainsString('async', $tag);
    }

    public function testEveryScreenThatRendersTheShellGetsTheSharedBehaviour(): void
    {
        $screens = [];
        $users = [];

        foreach ((array) glob(self::root() . '/admin/*.php') as $path) {
            $file = basename((string) $path);
            $source = (string) file_get_contents((string) $path);

            if (preg_match('/^<body[^>]*>/m', $source) === 1) {
                $screens[$file] = $source;
            }

            if ($file !== '_admin_ui.php' && preg_match('/\badmin_(help|field_label|info_panel|file_input)\(/', $source) === 1) {
                $users[] = $file;
            }
        }

        // Guards the guard: a broken scan would otherwise pass silently.
        $this->assertGreaterThan(50, count($screens), 'The admin page scan found almost nothing.');

        foreach ($screens as $file => $source) {
            // Before a login there is no shell, no form help and nothing to
            // switch. The preview of a page is the other way round: it shows
            // the public website, so its shell is the site's own
            // (admin/page-preview.php), and the preview of one block shows
            // that block alone inside the library's frame
            // (admin/block-preview.php).
            if (in_array($file, ['login.php', 'setup.php', 'page-preview.php', 'block-preview.php'], true)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                "#require(_once)? __DIR__ \\. '/_header\\.php'#",
                $source,
                'admin/' . $file . ' renders no shell, so it gets neither the help switch nor admin-ui.js'
            );
        }

        foreach ($users as $file) {
            $this->assertArrayHasKey($file, $screens, 'admin/' . $file . ' uses a help component but is no screen of its own');
            $this->assertStringContainsString("/_header.php'", $screens[$file], 'admin/' . $file . ' uses a help component without the shell that defines and drives it');
        }
    }

    // --- The script ------------------------------------------------------------

    public function testTheHelpPreferenceLivesUnderOneMygdalaKey(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertSame(1, preg_match_all('/var STORAGE_KEY = "([^"]+)";/', $script, $key));
        $this->assertSame('mygdalaAdminHelp', $key[1][0], 'named like mygdalaAdminTab: and mygdalaSaveBarSaved');

        preg_match_all('/localStorage\.(?:getItem|setItem|removeItem)\(([^,)]+)/', $script, $uses);
        $this->assertNotSame([], $uses[1], 'the preference is remembered in localStorage');
        $this->assertSame(['STORAGE_KEY'], array_values(array_unique(array_map('trim', $uses[1]))), 'every read and write goes through the one key');

        $this->assertStringNotContainsString('sessionStorage', $script);
        $this->assertDoesNotMatchRegularExpression('/vvl/i', $script, "the old site's storage prefix");
    }

    public function testTheScriptCarriesNoSentenceAnEditorReads(): void
    {
        $script = self::source(self::SCRIPT);

        foreach (['nl', 'en'] as $locale) {
            $catalog = require self::root() . '/src/Service/Language/messages/' . $locale . '.php';

            foreach ($catalog as $key => $text) {
                // "aan", "uit", "on" and "off" are words of code as well.
                if ((!str_starts_with($key, 'ui.') && !str_starts_with($key, 'help.')) || mb_strlen($text) < 8) {
                    continue;
                }

                $this->assertStringNotContainsString($text, $script, $key . ' is CMS text: it belongs in the catalog and reaches the page through the server');
            }
        }

        // Text goes in through textContent; the script never writes markup.
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringNotContainsString('insertAdjacentHTML', $script);
    }

    public function testNothingUsesAnInlineEventHandler(): void
    {
        $rendered = admin_help('Veld', 'Uitleg.')
            . admin_field_label('veld', 'Veld', 'Uitleg.')
            . admin_info_panel('Uitleg.')
            . admin_help_toggle('topbar')
            . admin_file_input(['name' => 'image', 'onchange' => 'steal()', 'onClick' => 'steal()'])
            . admin_file_preview('veld', '/assets/x.jpg')
            . admin_confirm_dialog()
            . '<form' . admin_confirm_attributes('Vraag" onclick="steal()', 'Uitleg.', 'Ja') . '></form>';

        $this->assertSame(0, self::nodeCount(self::xpath($rendered), '//@*[starts-with(name(), "on")]'));

        $this->assertDoesNotMatchRegularExpression('/\.on[a-z]+\s*=[^=]|setAttribute\(\s*["\']on/', self::source(self::SCRIPT));

        // Not one admin screen, partial or admin endpoint writes an onclick.
        $offenders = [];
        $files = array_merge(
            (array) glob(self::root() . '/admin/*.php'),
            (array) glob(self::root() . '/api/admin/*.php')
        );

        foreach ($files as $path) {
            if (preg_match('/\sonclick\s*=/i', (string) file_get_contents((string) $path)) === 1) {
                $offenders[] = substr((string) $path, strlen(self::root()) + 1);
            }
        }

        $this->assertSame([], $offenders, 'use a data- attribute and a delegated listener instead');
    }

    // --- File input ------------------------------------------------------------

    public function testTheFileInputStaysTheNativeControl(): void
    {
        $xpath = self::xpath(admin_file_input([
            'name' => 'image',
            'accept' => 'image/jpeg,image/png',
            'required' => true,
            'multiple' => false,
            'type' => 'text',
            'class' => 'hijack',
            'data-note' => '"><script>x</script>',
        ]));

        $input = self::one($xpath, '//input');
        $this->assertSame('file', $input->getAttribute('type'), 'always a file input, whatever a template passed');
        $this->assertSame('image', $input->getAttribute('name'));
        $this->assertSame('image/jpeg,image/png', $input->getAttribute('accept'));
        $this->assertTrue($input->hasAttribute('required'));
        $this->assertFalse($input->hasAttribute('multiple'));
        $this->assertSame('admin-file__input', $input->getAttribute('class'));
        $this->assertSame('"><script>x</script>', $input->getAttribute('data-note'));
        $this->assertSame(0, self::nodeCount($xpath, '//script'));

        // A screen reader hears the native control, not the drawing of it.
        foreach ($xpath->query('//*[@data-admin-file]/span') ?: [] as $visual) {
            $this->assertSame('true', $visual->getAttribute('aria-hidden'));
        }

        $this->assertSame('Bestand kiezen', trim(self::one($xpath, '//*[contains(@class, "admin-file__button")]')->textContent));

        // The wording the script needs comes from the catalog, via the markup.
        $name = self::one($xpath, '//*[@data-admin-file-name]');
        $this->assertSame('Nog geen bestand gekozen', $name->getAttribute('data-admin-file-none'));
        $this->assertSame(':count bestanden gekozen', $name->getAttribute('data-admin-file-many'));

        $many = self::xpath(admin_file_input(['name' => 'images[]', 'multiple' => true]));
        $this->assertTrue(self::one($many, '//input')->hasAttribute('multiple'));
        $this->assertSame('Bestanden kiezen', trim(self::one($many, '//*[contains(@class, "admin-file__button")]')->textContent));
    }

    /**
     * The picture a chosen file will become, before anything is uploaded — and
     * the stored image while nothing new is chosen. Paired with its input by
     * id, drawn from the file the browser holds, and given back as soon as it
     * is replaced.
     */
    public function testTheFilePreviewShowsWhatWillBeStoredBeforeItIsSaved(): void
    {
        $xpath = self::xpath(admin_file_preview('portfolio-image', '/assets/images/sections/thumbs/werk.webp'));
        $preview = self::one($xpath, '//*[@data-admin-file-preview]');

        $this->assertSame('portfolio-image', $preview->getAttribute('data-admin-file-preview'), 'paired with its input by id');
        $this->assertFalse($preview->hasAttribute('hidden'), 'a stored image is shown, also without the script');

        $image = self::one($xpath, '//img[@data-admin-file-preview-image]');
        $this->assertSame('/assets/images/sections/thumbs/werk.webp', $image->getAttribute('src'));
        $this->assertTrue($image->hasAttribute('alt'));
        $this->assertSame('', $image->getAttribute('alt'));

        $state = self::one($xpath, '//*[@data-admin-file-preview-state]');
        $this->assertSame('Huidige afbeelding', trim($state->textContent));
        $this->assertSame('polite', $state->getAttribute('aria-live'), 'a screen reader hears the switch too');
        $this->assertSame('Nieuwe afbeelding, nog niet opgeslagen', $state->getAttribute('data-admin-file-preview-new-label'));

        $clear = self::one($xpath, '//button[@data-admin-file-clear]');
        $this->assertSame('button', $clear->getAttribute('type'), 'clearing never submits the form');
        $this->assertTrue($clear->hasAttribute('hidden'), 'nothing to clear until a file is chosen');

        // A new item has nothing stored: no picture, and no empty src a browser would fetch.
        $empty = self::xpath(admin_file_preview('portfolio-image'));
        $this->assertTrue(self::one($empty, '//*[@data-admin-file-preview]')->hasAttribute('hidden'));
        $this->assertFalse(self::one($empty, '//img')->hasAttribute('src'));

        // Whatever a template hands over is escaped.
        $this->assertSame(0, self::nodeCount(self::xpath(admin_file_preview('x"><script>y</script>', '"><b>z</b>')), '//script|//b'));

        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString('URL.createObjectURL(file)', $script, 'drawn from the file the browser already holds');
        $this->assertStringContainsString('URL.revokeObjectURL(url)', $script, 'and given back when it is replaced');
        $this->assertStringContainsString('[data-admin-file-clear]', $script);
        $this->assertStringContainsString('input.value = "";', $script);
    }

    // --- Confirmation ------------------------------------------------------------

    public function testTheConfirmationIsOneNativeModalDialogInTheCmsOwnWords(): void
    {
        $xpath = self::xpath(admin_confirm_dialog());
        $dialog = self::one($xpath, '//dialog[@data-admin-confirm-dialog]');

        // Named by its heading, described by the question it is showing.
        $heading = self::one($xpath, '//*[@id="' . $dialog->getAttribute('aria-labelledby') . '"]');
        $this->assertTrue($heading->hasAttribute('data-admin-confirm-heading'));
        $this->assertSame('Weet je het zeker?', trim($heading->textContent));
        $this->assertSame('Weet je het zeker?', $heading->getAttribute('data-admin-confirm-default'));
        $this->assertTrue(self::one($xpath, '//*[@id="' . $dialog->getAttribute('aria-describedby') . '"]')->hasAttribute('data-admin-confirm-text'));

        // Its answers only close the dialog; they send nothing anywhere.
        $form = self::one($xpath, '//dialog/form');
        $this->assertSame('dialog', $form->getAttribute('method'));
        $this->assertFalse($form->hasAttribute('action'));

        $buttons = $xpath->query('//dialog//button');
        $this->assertNotFalse($buttons);
        $this->assertSame(2, $buttons->length);

        // "No" first: where focus lands, and what a stray Enter presses.
        $no = $buttons->item(0);
        $yes = $buttons->item(1);
        $this->assertInstanceOf(\DOMElement::class, $no);
        $this->assertInstanceOf(\DOMElement::class, $yes);

        $this->assertSame(['submit', 'cancel', 'Annuleren'], [$no->getAttribute('type'), $no->getAttribute('value'), trim($no->textContent)]);
        $this->assertTrue($no->hasAttribute('data-admin-confirm-no'));

        $this->assertSame(['submit', 'confirm', 'Doorgaan'], [$yes->getAttribute('type'), $yes->getAttribute('value'), trim($yes->textContent)]);
        $this->assertTrue($yes->hasAttribute('data-admin-confirm-yes'));
        $this->assertStringContainsString('admin-btn-danger', $yes->getAttribute('class'));
    }

    public function testAFormAsksFirstThroughEscapedAttributesAlone(): void
    {
        $xpath = self::xpath('<form method="post" action="/api/admin/x.php"'
            . admin_confirm_attributes('“<b>Blok</b>” verwijderen?', 'Weg is weg" onmouseover="steal()', 'Verwijderen')
            . '><button type="submit">Verwijderen</button></form>');
        $form = self::one($xpath, '//form');

        $this->assertSame('Weg is weg" onmouseover="steal()', $form->getAttribute('data-admin-confirm'));
        $this->assertSame('“<b>Blok</b>” verwijderen?', $form->getAttribute('data-admin-confirm-title'));
        $this->assertSame('Verwijderen', $form->getAttribute('data-admin-confirm-action'));
        $this->assertSame(0, self::nodeCount($xpath, '//b'));
        $this->assertSame(0, self::nodeCount($xpath, '//@*[starts-with(name(), "on")]'));

        // What a screen leaves out, the dialog says in its own words.
        $bare = self::one(self::xpath('<form' . admin_confirm_attributes('', 'Zeker?') . '></form>'), '//form');
        $this->assertSame('Zeker?', $bare->getAttribute('data-admin-confirm'));
        $this->assertFalse($bare->hasAttribute('data-admin-confirm-title'));
        $this->assertFalse($bare->hasAttribute('data-admin-confirm-action'));
    }

    /**
     * The script holds the submit back and, after a "yes", sends that very
     * same form. What happens on screen is checked by hand (ADMIN-UI.md);
     * what would quietly change the meaning of a form is pinned here.
     */
    public function testTheScriptAsksBeforeAFormGoesAndThenSendsThatSameForm(): void
    {
        $script = self::source(self::SCRIPT);

        // Capture phase on the document: before anything else reacts to the submit.
        $this->assertMatchesRegularExpression('/document\.addEventListener\("submit", function \(event\) \{[\s\S]{0,1400}\}, true\);/', $script);
        $this->assertStringContainsString('hasAttribute("data-admin-confirm")', $script);
        $this->assertStringContainsString('event.preventDefault();', $script);

        $this->assertStringContainsString('dialog.showModal();', $script);

        // The answer is acted on when it is given — a button, Escape, a press
        // beside the dialog — and never left to the queued close event alone.
        $this->assertStringContainsString('answer(dialog, event.submitter ? event.submitter.value : "cancel");', $script);
        $this->assertStringContainsString('dialog.addEventListener("cancel"', $script);
        $this->assertStringContainsString('value === "confirm"', $script);

        // "Yes" is the browser's own submit of that form, with the button that was pressed.
        $this->assertStringContainsString('form.requestSubmit(', $script);
        $this->assertStringContainsString('event.submitter', $script);

        // Never sent without a question: a screen without the dialog gets the browser's own.
        $this->assertStringContainsString('window.confirm(', $script);

        // "No" hands focus back to the button that asked.
        $this->assertMatchesRegularExpression('/request\.submitter[\s\S]{0,200}back\.focus\(\);/', $script);

        // It asks; it never sends anything of its own.
        $this->assertStringNotContainsString('fetch(', $script);
        $this->assertStringNotContainsString('XMLHttpRequest', $script);
        $this->assertStringNotContainsString('/api/admin/', $script);
    }

    // --- admin.css -------------------------------------------------------------

    public function testThePrimitivesLiveInOneSectionAndOnlyReadTheThemeTokens(): void
    {
        $css = self::source('admin/assets/admin.css');
        [$start, $rules] = self::primitivesCss();
        $before = substr($css, 0, $start);

        foreach ([
            '.admin-field__label', '.admin-help__trigger', '.admin-help__popover', '.admin-help-toggle',
            '.admin-info-panel', '.admin-btn-danger', '.admin-btn-ghost', '.admin-search', '.admin-select',
            '.admin-checkbox', '.admin-switch', '.admin-file', '.admin-file-preview', '.admin-confirm',
        ] as $selector) {
            $this->assertStringContainsString($selector . '{', $rules, $selector . ' is not styled in ADMIN UI PRIMITIVES');
            $this->assertStringNotContainsString($selector . '{', $before, $selector . ' has a second definition outside ADMIN UI PRIMITIVES');
        }

        $this->assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/',
            $rules,
            'a primitive reads the --admin-* tokens, so all four dashboard themes get it without a line of their own'
        );
        $this->assertDoesNotMatchRegularExpression('/--admin-[a-z0-9-]+\s*:/', $rules, 'a new token would have to be declared by every theme (THEMING.md)');

        $this->assertStringContainsString('prefers-reduced-motion', $rules);
        $this->assertStringContainsString('forced-colors', $rules);

        $this->assertStringContainsString(
            'select, input[type="text"], input[type="password"], input[type="email"], input[type="search"]{',
            $css,
            'no search box in the admin is the browser\'s own white bar'
        );
    }

    // --- The proof of concept (ADMIN-UI.md, "Waar het al gebruikt wordt") ------

    /**
     * The source of one field's .admin-field block: from its opening <div> to
     * the next block, or to the end of its form. Blocks do not nest, and the
     * LAST occurrence of the name is the field itself — a checkbox's hidden
     * fallback comes before its block.
     */
    private static function fieldSource(string $source, string $name): string
    {
        $position = strrpos($source, 'name="' . $name . '"');
        self::assertNotFalse($position, 'no field named ' . $name);

        $opening = '/<div class="admin-field(?: admin-field--inline)?">/';
        preg_match_all($opening, substr($source, 0, $position), $before, PREG_OFFSET_CAPTURE);
        self::assertNotSame([], $before[0], $name . ' is not inside an .admin-field');
        $start = (int) end($before[0])[1];

        $end = preg_match($opening, $source, $after, PREG_OFFSET_CAPTURE, $position) === 1
            ? (int) $after[0][1]
            : (int) strpos($source, '</form>', $position);

        return substr($source, $start, $end - $start);
    }

    public function testTheSiteSettingsFieldsCarryTheirExplanation(): void
    {
        $source = self::source('admin/settings.php');
        $catalog = require self::root() . '/src/Service/Language/messages/nl.php';

        $fields = [
            'site_name' => 'help.settings.site_name',
            'kvk_number' => 'help.settings.kvk_number',
            'email' => 'help.settings.email',
            // One website language at a time: the one being edited.
            'city' => 'help.settings.city',
            'company_phone' => 'help.settings.phone',
            'company_city' => 'help.settings.company_city',
            'company_country' => 'help.settings.country',
            'primary_content_language' => 'help.settings.primary_language',
            'seo_default_description' => 'help.settings.seo_description',
            'seo_robots_index_default' => 'help.settings.robots',
        ];

        foreach ($fields as $name => $helpKey) {
            $field = self::fieldSource($source, $name);

            $this->assertStringContainsString("admin_t('" . $helpKey . "')", $field, $name . ' lost its explanation');
            $this->assertArrayHasKey($helpKey, $catalog, $helpKey . ' is used but not in the catalog');

            if (preg_match("/admin_field_label\\('([a-z-]+)'/", $field, $label) === 1) {
                $this->assertStringContainsString('id="' . $label[1] . '"', $field, $name . ': the label must point at the field it explains');
            } else {
                $this->assertStringContainsString('admin_help(', $field, $name);
            }
        }

        // The two new controls on this screen.
        $this->assertStringContainsString('id="field-primary-language" class="admin-select"', $source);
        $this->assertStringContainsString('class="admin-switch" role="switch" name="seo_robots_index_default"', $source);
    }

    public function testThePagesOverviewUsesTheInfoPanelAndTheSharedSearch(): void
    {
        $source = self::source('admin/pages.php');

        $this->assertStringContainsString("admin_info_panel(admin_t('help.pages.overview'))", $source);
        $this->assertStringContainsString(
            '<form method="get" action="/admin/pages.php" class="admin-toolbar" role="search">',
            $source,
            'a search only reads: a GET, with no token, that survives a reload'
        );
        $this->assertMatchesRegularExpression(
            '#<label class="admin-search">\s*<span class="admin-visually-hidden">#',
            $source,
            'the search field keeps a name a screen reader can say'
        );
        $this->assertStringContainsString('<input type="search" name="q"', $source);
        $this->assertStringContainsString('PageContent::matchesAdminSearch(', $source);
    }

    public function testTheFormEditorUsesTheSharedControls(): void
    {
        $source = self::source('admin/form.php');

        $this->assertStringContainsString('<input type="checkbox" class="admin-switch" role="switch" name="is_active" value="1"', $source);
        $this->assertStringContainsString('<input type="checkbox" class="admin-switch" role="switch" name="store_submissions" value="1"', $source);
        $this->assertStringContainsString('<select name="reply_to_field_key" id="form-reply-to" class="admin-select">', $source);
        // The kind of a new field is chosen from described radio cards in a
        // native dialog (admin/_form_fields.php), not from a select of names.
        $this->assertStringNotContainsString('<select name="field_type"', $source);
        $this->assertStringContainsString('<dialog class="admin-field-picker" id="form-field-add"', $source);
        $this->assertStringContainsString("form_field_type_cards('field_type', \$addType)", $source);
        $this->assertStringContainsString("admin_field_label('form-field-add-label', admin_t('forms.label'), admin_t('help.forms.field_label'), true)", $source);

        // Geavanceerd folds away the way admin/page-new.php folds its SEO card.
        $this->assertStringContainsString('<details class="admin-collapse admin-collapse--card" data-form-advanced', $source);
    }

    /**
     * Every setting of the form editor explains itself behind a "?", and the
     * overview says what the screen is for. Which fields sit under
     * Geavanceerd, and that they still submit, is
     * Tests\Service\FormAdminHttpTest.
     */
    public function testTheFormsScreensCarryTheirExplanation(): void
    {
        $source = self::source('admin/form.php');
        $catalog = require self::root() . '/src/Service/Language/messages/nl.php';

        $fields = [
            'is_active' => 'help.forms.active',
            'name' => 'help.forms.name',
            // One website language at a time (Multilingual 2.0 phase 4).
            'success_message' => 'help.forms.thank_you_message',
            'notification_email' => 'help.forms.notification_email',
            'store_submissions' => 'help.forms.store_submissions',
            'reply_to_field_key' => 'help.forms.reply_to',
        ];

        foreach ($fields as $name => $helpKey) {
            $field = self::fieldSource($source, $name);

            $this->assertStringContainsString("admin_t('" . $helpKey . "')", $field, $name . ' lost its explanation');
            $this->assertArrayHasKey($helpKey, $catalog, $helpKey . ' is used but not in the catalog');

            if (preg_match("/admin_field_label\\('([a-z-]+)'/", $field, $label) === 1) {
                $this->assertStringContainsString('id="' . $label[1] . '"', $field, $name . ': the label must point at the field it explains');
            } else {
                $this->assertStringContainsString('admin_help(', $field, $name);
            }
        }

        $this->assertStringContainsString("admin_info_panel(admin_t('help.forms.overview'))", self::source('admin/forms.php'));
    }

    /**
     * What the three screens SUBMIT did not change: the same names, the same
     * required fields, the same hidden fallback where there was one and none
     * where there was not — pinned against what their endpoints read.
     */
    public function testTheProofOfConceptFormsStillSubmitWhatTheirEndpointsRead(): void
    {
        $settings = self::source('admin/settings.php');

        // Only the site name is required (App\Service\SiteSettingsValidator);
        // Tests\Service\SiteSettingsValidatorTest pins every field against it.
        $this->assertMatchesRegularExpression('/name="site_name"[^>]*\brequired\b/', $settings, 'site_name must stay required');

        foreach (['email', 'city', 'kvk_number', 'seo_default_description'] as $name) {
            $this->assertDoesNotMatchRegularExpression('/name="' . $name . '"[^>]*\brequired\b/', $settings, $name . ' is optional');
            $this->assertDoesNotMatchRegularExpression('/name="' . $name . '"[^>]*admin_lang_required\(/', $settings, $name . ' is optional in every language');
        }

        $this->assertStringContainsString('<input type="email" id="settings-email" name="email"', $settings);
        $this->assertMatchesRegularExpression(
            '#<input type="hidden" name="seo_robots_index_default" value="0">\s*<div class="admin-field admin-field--inline">\s*<label class="admin-checkbox-label">\s*<input type="checkbox" class="admin-switch" role="switch" name="seo_robots_index_default" value="1"#',
            $settings,
            'the hidden 0 still comes before the switch, so switching indexing off still saves'
        );
        $this->assertStringContainsString(
            "\$values['seo_robots_index_default'] = \$values['seo_robots_index_default'] === '1' ? '1' : '0';",
            self::source('src/Service/SiteSettingsValidator.php')
        );

        $form = self::source('admin/form.php');
        $endpoint = self::source('api/admin/update-form.php');

        foreach (['is_active', 'store_submissions'] as $name) {
            $this->assertSame(1, substr_count($form, 'name="' . $name . '"'), $name . ': one checkbox and no hidden fallback, because the endpoint reads isset()');
            $this->assertStringContainsString("'" . $name . "' => isset(\$_POST['" . $name . "'])", $endpoint);
        }

        $this->assertStringContainsString("'reply_to_field_key' => trim((string) (\$_POST['reply_to_field_key'] ?? ''))", $endpoint);

        foreach (['admin/settings.php', 'admin/pages.php', 'admin/form.php', 'admin/forms.php'] as $screen) {
            $this->assertDoesNotMatchRegularExpression(
                '#<label\b[^>]*>(?:(?!</label>).)*admin_help\(#s',
                self::source($screen),
                $screen . ' puts a help icon inside a <label>'
            );
        }
    }
}
