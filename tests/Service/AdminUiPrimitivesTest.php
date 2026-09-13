<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminLocale;
use PHPUnit\Framework\TestCase;

/**
 * The shared admin UI primitives: field help, the global help switch, the
 * info panel and the file input (admin/_admin_ui.php, admin/assets/admin-ui.js
 * and the ADMIN UI PRIMITIVES section of admin.css — ADMIN-UI.md).
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
            // Before a login there is no shell, no form help and nothing to switch.
            if (in_array($file, ['login.php', 'setup.php'], true)) {
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
            . admin_file_input(['name' => 'image', 'onchange' => 'steal()', 'onClick' => 'steal()']);

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

    // --- admin.css -------------------------------------------------------------

    public function testThePrimitivesLiveInOneSectionAndOnlyReadTheThemeTokens(): void
    {
        $css = self::source('admin/assets/admin.css');
        [$start, $rules] = self::primitivesCss();
        $before = substr($css, 0, $start);

        foreach ([
            '.admin-field__label', '.admin-help__trigger', '.admin-help__popover', '.admin-help-toggle',
            '.admin-info-panel', '.admin-btn-danger', '.admin-btn-ghost', '.admin-search', '.admin-select',
            '.admin-checkbox', '.admin-switch', '.admin-file',
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
}
