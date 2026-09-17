<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * Tabs on a long admin screen, and the collapsible rows in the page
 * builder's block list.
 *
 * WHAT THIS FILE IS ABOUT. Both features are navigation and nothing else:
 * they show and hide markup that was already on the screen. The risk is
 * therefore not that they misbehave on their own, it is that they quietly
 * change what a screen SAVES — a field that moved out of the form it belongs
 * to, a settings field that disappeared behind a tab that does not exist, a
 * page editor whose Pagina and SEO halves became two partial POSTs to an
 * endpoint that reads the whole form. Those are the properties pinned below,
 * together with the accessibility contract of the two components.
 *
 * HOW IT CHECKS. The tab helper is rendered in-process — admin/_admin_tabs.php
 * is a plain include of output functions, so it needs no login, no request
 * and no database. The two screens that use it cannot be rendered that way
 * (they are real admin pages), so what is asserted about them is the
 * structure of their source: which panel each field is written inside. That
 * is exactly the property at risk, and it costs no giant HTML snapshot.
 */
final class AdminEditorNavigationTest extends TestCase
{
    /**
     * Which tab every field on admin/settings.php belongs to. This IS the
     * grouping: a field that moves between tabs, disappears, or turns up in
     * two of them fails here, which is what "the same settings as before,
     * only tabbed" has to mean.
     *
     * @var array<string, string>
     */
    private const SETTINGS_FIELDS = [
        'site_name' => 'algemeen',
        'kvk_number' => 'algemeen',
        'email' => 'algemeen',
        'city_nl' => 'algemeen',
        'city_en' => 'algemeen',
        // Not the footer description: it moved to the Footer screen in
        // Footer phase B (HEADER-FOOTER.md), so it has one place.
        // The postal address and the phone number are the site's, not the
        // invoice's: the e-mail footer line and the site footer read them too.
        'company_phone' => 'algemeen',
        'company_street' => 'algemeen',
        'company_house_number' => 'algemeen',
        'company_postal_code' => 'algemeen',
        'company_city' => 'algemeen',
        'company_country' => 'algemeen',
        // The WEBSITE's languages (MULTILINGUAL.md). Deliberately their own
        // tab and not part of Algemeen: they are the one setting an editor
        // has to be able to find without already knowing this CMS, and the
        // tab is also where the copy explains that the CMS's own language is
        // a different, per-person choice.
        'primary_content_language' => 'talen',
                'seo_default_description' => 'seo',
        'seo_robots_index_default' => 'seo',
        // Invoices, order numbers and the order e-mail are the Shop's own
        // screen now; Tests\Service\ShopSettingsTest holds its tabs.
        'admin_theme' => 'dashboard',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/_admin_tabs.php';
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        parent::tearDown();
    }

    private function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The source a screen writes inside one tab, all of that tab's panels
     * joined. Panels do not nest, so pairing each admin_tab_panel('key')
     * with the next admin_tab_panel_end() is the whole parse.
     *
     * @return array<string, string> tab key => its source
     */
    private function panelSources(string $relativePath): array
    {
        $source = $this->sourceOf($relativePath);

        preg_match_all(
            "/admin_tab_panel\('([a-z-]+)'\)(.*?)admin_tab_panel_end\(\)/s",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotSame([], $matches, $relativePath . ' renders no tab panels');

        $panels = [];
        foreach ($matches as $match) {
            $panels[$match[1]] = ($panels[$match[1]] ?? '') . $match[2];
        }

        return $panels;
    }

    /**
     * @param array<string, string> $tabs
     * @param array<string, mixed>  $options
     */
    private function renderTabs(array $tabs, array $options = [], array $panelKeys = []): string
    {
        ob_start();
        admin_tabs_start('test-group', $tabs, $options);

        foreach ($panelKeys === [] ? array_keys($tabs) : $panelKeys as $key) {
            admin_tab_panel($key);
            echo '<p>' . $key . '</p>';
            admin_tab_panel_end();
        }

        admin_tabs_end();

        return (string) ob_get_clean();
    }

    // --- The reusable component -----------------------------------------

    public function testATabStripIsARealTablistAndNotARowOfLinks(): void
    {
        $html = $this->renderTabs(['een' => 'Een', 'twee' => 'Twee']);

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertSame(2, substr_count($html, 'role="tab"'));
        $this->assertSame(2, substr_count($html, 'role="tabpanel"'));
        $this->assertStringContainsString('aria-label="Onderdelen"', $html);

        // Buttons, and buttons that cannot submit anything: a tab strip
        // inside a form must never become a second way to save.
        $this->assertSame(2, substr_count($html, '<button type="button"'));
        $this->assertStringNotContainsString('type="submit"', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testExactlyOneTabIsSelectedAndOnlyThatTabIsReachableByTab(): void
    {
        $html = $this->renderTabs(['een' => 'Een', 'twee' => 'Twee', 'drie' => 'Drie']);

        $this->assertSame(1, substr_count($html, 'aria-selected="true"'));
        $this->assertSame(2, substr_count($html, 'aria-selected="false"'));

        // Roving tabindex: the open tab is in the tab order, its neighbours
        // are reached with the arrow keys instead.
        $this->assertSame(1, substr_count($html, 'tabindex="0"'));
        $this->assertSame(2, substr_count($html, 'tabindex="-1"'));

        // The first tab is the default, and the other panels start hidden —
        // from the server, so nothing flashes before the script runs.
        $this->assertMatchesRegularExpression('/data-admin-tab="een"[^>]*aria-selected="true"/', $html);
        $this->assertSame(2, substr_count($html, ' hidden>'));
    }

    public function testEveryTabPointsAtEveryPanelItOpens(): void
    {
        // Two panels for one tab: what the page editor needs, because the
        // Verwijderen card carries a <form> and could not be nested inside
        // the settings form the rest of that tab lives in.
        $html = $this->renderTabs(
            ['een' => 'Een', 'twee' => 'Twee'],
            [],
            ['een', 'twee', 'een']
        );

        $this->assertSame(3, substr_count($html, 'role="tabpanel"'));
        $this->assertStringContainsString('id="admin-tab-test-group-een"', $html);
        $this->assertStringContainsString('id="admin-tab-test-group-een-2"', $html);
        $this->assertStringContainsString(
            'aria-controls="admin-tab-test-group-een admin-tab-test-group-een-2"',
            $html
        );

        // And every panel says which tab labels it, both ways round.
        $this->assertSame(3, substr_count($html, 'aria-labelledby="admin-tabbtn-test-group-'));
    }

    public function testAScreenCanInsistOnATabWithoutForgettingTheEditorsOwnChoice(): void
    {
        $html = $this->renderTabs(['een' => 'Een', 'twee' => 'Twee'], ['force' => 'twee']);

        $this->assertMatchesRegularExpression('/data-admin-tab="twee"[^>]*aria-selected="true"/', $html);
        $this->assertStringContainsString('data-admin-tabs-force="twee"', $html);

        // The default is still printed: the force is for THIS render (a
        // rejected save), not a new default.
        $this->assertStringContainsString('data-admin-tabs-default="een"', $html);
    }

    public function testTheRememberedTabIsScopedSoOnePageNeverRestoresAnothers(): void
    {
        $html = $this->renderTabs(['een' => 'Een', 'twee' => 'Twee'], ['scope' => '12']);
        $this->assertStringContainsString('data-admin-tabs-scope="12"', $html);

        $script = $this->sourceOf('admin/assets/admin-tabs.js');
        $this->assertMatchesRegularExpression(
            '/data-admin-tabs"\)[\s\S]{0,120}data-admin-tabs-scope/',
            $script,
            'the storage key must be group AND scope'
        );
        $this->assertStringContainsString('sessionStorage', $script);
    }

    public function testWithoutJavaScriptTheScreenIsTheLongStackItUsedToBe(): void
    {
        $html = $this->renderTabs(['een' => 'Een', 'twee' => 'Twee']);

        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringContainsString('.admin-tabs__panel[hidden]{display:block !important}', $html);
        $this->assertStringContainsString('.admin-tabs__list{display:none !important}', $html);
    }

    public function testTheTabsScriptSubmitsNothingAndTouchesNoFieldValue(): void
    {
        $script = $this->sourceOf('admin/assets/admin-tabs.js');

        $this->assertStringNotContainsString('/api/admin/', $script, 'a tab strip has no endpoint');
        $this->assertStringNotContainsString('requestSubmit', $script);
        $this->assertStringNotContainsString('.submit(', $script);

        // Switching a tab may never look like an edit to the save bar: it
        // sets `hidden` and some aria state, and dispatches nothing.
        $this->assertStringNotContainsString('dispatchEvent', $script);
        $this->assertStringNotContainsString('.value =', $script);

        // A required field the browser rejects inside a closed tab has to be
        // shown, or the submit fails with nothing on screen to explain it.
        $this->assertStringContainsString('"invalid"', $script);
    }

    public function testTheTabsScriptHasTheKeyboardTheTabsPatternPromises(): void
    {
        $script = $this->sourceOf('admin/assets/admin-tabs.js');

        foreach (['ArrowLeft', 'ArrowRight', 'Home', 'End'] as $key) {
            $this->assertStringContainsString($key, $script, $key . ' must move between tabs');
        }

        $this->assertStringContainsString('focus()', $script, 'the moved-to tab takes focus');
        $this->assertStringNotContainsString(
            ':hover',
            $script,
            'nothing may depend on hover — a touch device would never see it'
        );
    }

    // --- The page editor's three tabs ------------------------------------

    public function testThePageEditorOffersInhoudPaginaAndSeoInThatOrder(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $this->assertMatchesRegularExpression(
            // The three tabs and their order; the words themselves are catalogue
            // keys now (MULTILINGUAL.md).
            "/admin_tabs_start\('page-editor', \[\s*'inhoud' => admin_t\('tabs\.content'\),\s*'pagina' => admin_t\('tabs\.page'\),\s*'seo' => admin_t\('tabs\.seo'\),/",
            $source
        );
        $this->assertStringContainsString("'scope' => (string) \$pageId", $source, 'one page must not restore another\'s tab');
        $this->assertStringContainsString('admin_tabs_end()', $source);
        $this->assertStringContainsString('admin_tabs_script()', $source);
    }

    public function testEachPageEditorFieldSitsInExactlyOneTab(): void
    {
        $panels = $this->panelSources('admin/page.php');
        $this->assertSame(['pagina', 'seo', 'inhoud'], array_keys($panels));

        $expected = [
            'title' => 'pagina',
            'slug' => 'pagina',
            'status' => 'pagina',
            // One language's text per save (admin/_localized_fields.php), so
            // there is no `_en` twin of a field any more.
            'meta_title' => 'seo',
            'meta_description' => 'seo',
            'noindex' => 'seo',
        ];

        foreach ($expected as $field => $tab) {
            foreach ($panels as $key => $panel) {
                $count = substr_count($panel, 'name="' . $field . '"');

                if ($key === $tab) {
                    $this->assertGreaterThan(0, $count, $field . ' belongs on the ' . $tab . ' tab');
                } else {
                    $this->assertSame(0, $count, $field . ' must not also be on the ' . $key . ' tab');
                }
            }
        }

        // The page's own sharing image is a Media Library picker rather than
        // a plain input, so it is named by its call and not by an attribute.
        $this->assertStringContainsString("'og_media_id'", $panels['seo']);
        $this->assertStringNotContainsString("'og_media_id'", $panels['pagina']);

        // Inhoud is the blocks and the picker, and nothing that saves page
        // settings.
        $this->assertStringContainsString('data-page-section-zone', $panels['inhoud']);
        $this->assertStringContainsString('block_picker_button()', $panels['inhoud']);
        $this->assertStringNotContainsString('name="title"', $panels['inhoud']);
    }

    /**
     * api/admin/update-page.php reads Title, Slug, Status AND every meta
     * field out of one request, and writes all of them. Two forms would
     * therefore each blank what the other carries — the partial-POST bug
     * this project has already paid for once. Tabs are allowed to split the
     * SCREEN; they are not allowed to split the save.
     */
    public function testPaginaAndSeoAreTwoPanelsOfOneFormToOneEndpoint(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $this->assertSame(
            1,
            substr_count($source, 'action="/api/admin/update-page.php"'),
            'the page settings must post once, from one form'
        );

        $formOpen = strpos($source, '<form method="post" action="/api/admin/update-page.php"');
        $paginaPanel = strpos($source, "admin_tab_panel('pagina')");
        $seoEnd = strpos($source, "admin_tab_panel('seo')");
        $formClose = strpos($source, '</form>', (int) $seoEnd);

        $this->assertIsInt($formOpen);
        $this->assertLessThan($paginaPanel, $formOpen, 'the form must open before the first panel it spans');
        $this->assertIsInt($formClose);
        $this->assertGreaterThan($seoEnd, $formClose, 'and close after the last one');
    }

    public function testTheDeletePanelIsTheSecondPaginaPanelAndNotANestedForm(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $this->assertSame(
            2,
            preg_match_all("/admin_tab_panel\('pagina'\)/", $source),
            'Verwijderen carries a form of its own, so it is a second panel of the same tab'
        );

        $deleteForm = strpos($source, 'action="/api/admin/delete-page.php"');
        $formClose = strpos($source, '</form>');
        $this->assertIsInt($deleteForm);
        $this->assertGreaterThan($formClose, $deleteForm, 'the delete form may never be nested in the settings form');
    }

    // --- Collapsible blocks ----------------------------------------------

    public function testEveryBlockRowIsTheSameGenericDisclosure(): void
    {
        $source = $this->sourceOf('admin/page.php');

        // One <details> written once, inside the one loop over the page's
        // blocks — so a block type cannot have its own, and a new block type
        // gets this for free.
        $this->assertSame(1, substr_count($source, '<details class="admin-collapse"'));
        $this->assertSame(1, substr_count($source, '<summary class="admin-collapse__summary">'));

        $loop = strpos($source, 'foreach ($allSections as $pageSection)');
        $this->assertIsInt($loop);
        $this->assertGreaterThan($loop, strpos($source, '<details class="admin-collapse"'));

        // The one line a collapsed row shows comes from the registry's own
        // instance label, so no block type invents a summary of its own.
        $this->assertStringContainsString('SectionRegistry::instanceLabel($pageSection)', $source);
    }

    public function testTheCollapseMechanismKnowsNothingAboutBlockTypes(): void
    {
        $script = $this->sourceOf('admin/assets/admin-collapse.js');

        $this->assertStringNotContainsString('section_type', $script);
        $this->assertStringNotContainsString('/api/admin/', $script);

        foreach (BlockDefinitions::types() as $type) {
            if (!str_contains($type, '_')) {
                continue;
            }

            $this->assertStringNotContainsString(
                $type,
                $script,
                'a block type named in the collapse script is per-type collapse code'
            );
        }
    }

    public function testACollapsedRowCanStillBeDraggedAndStillCarriesItsActions(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $handle = strpos($source, 'class="admin-drag-handle"');
        $details = strpos($source, '<details class="admin-collapse"');
        $this->assertIsInt($handle);
        $this->assertLessThan($details, $handle, 'the drag handle must sit outside the part that folds away');

        // Edit, hide and delete are in the body: an open row is the row it
        // always was.
        $body = substr($source, (int) strpos($source, '<div class="admin-collapse__body">'));
        $this->assertStringContainsString('SectionRegistry::editLinks($pageSection)', $body);
        $this->assertStringContainsString('action="/api/admin/toggle-page-section.php"', $body);
        $this->assertStringContainsString('action="/api/admin/delete-page-section.php"', $body);
    }

    /**
     * Hiding and deleting are real buttons from the admin family now, not
     * text links. The toggle names what pressing it does — Verbergen on a
     * visible block, Tonen on a hidden one — and the state itself stays in
     * words on the row. Tests\Service\PageBuilderScreenTest renders both.
     */
    public function testHideShowAndDeleteAreRealButtonsThatSayWhatTheyDo(): void
    {
        $source = $this->sourceOf('admin/page.php');
        $inhoud = $this->panelSources('admin/page.php')['inhoud'];

        $this->assertStringContainsString(
            '<button type="submit" class="admin-btn-secondary admin-section-row__button"><?= $isHidden ? admin_te(\'common.show\') : admin_te(\'common.hide\') ?></button>',
            $inhoud
        );
        $this->assertStringContainsString(
            '<button type="submit" class="admin-btn-danger admin-section-row__button"><?= admin_te(\'common.delete\') ?></button>',
            $inhoud
        );
        $this->assertStringNotContainsString('admin-btn-text', $inhoud, 'no action on a block row is a text link any more');

        // The state is said in words on the row, never by colour alone.
        $this->assertStringContainsString("\$isHidden ? ' is-hidden-section' : ''", $source);
        $this->assertMatchesRegularExpression('#<\?php if \(\$isHidden\): \?>\s*<span class="admin-badge admin-badge--muted">Verborgen</span>#', $source);

        $nl = require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php';
        $this->assertSame(['Tonen', 'Verbergen', 'Verwijderen'], [$nl['common.show'], $nl['common.hide'], $nl['common.delete']]);
    }

    public function testDeletingABlockStillAsksFirstInTheSharedDialog(): void
    {
        $source = $this->sourceOf('admin/page.php');
        $inhoud = $this->panelSources('admin/page.php')['inhoud'];

        // The browser's own confirm() is gone from the block list.
        $this->assertStringNotContainsString('onsubmit', $inhoud);
        $this->assertStringNotContainsString('confirm(', $inhoud);

        $delete = substr($inhoud, (int) strpos($inhoud, 'action="/api/admin/delete-page-section.php"'));
        $delete = substr($delete, 0, (int) strpos($delete, '</form>'));
        $this->assertStringContainsString('admin_confirm_attributes(', $delete);
        $this->assertStringContainsString("admin_t('page.block_delete_message', ['block' => \$rowLabel])", $delete, 'the question names the block that would go');
        $this->assertStringContainsString('name="csrf_token"', $delete);

        // Still offered only for a block that may be deleted at all.
        $this->assertMatchesRegularExpression(
            '#<\?php if \(SectionRegistry::isDeletable\(\$sectionType\)\): \?>\s*(?:<\?php /\*[\s\S]*?\*/ \?>\s*)?<form method="post" action="/api/admin/delete-page-section.php"#',
            $inhoud
        );

        // The one dialog it asks in, printed once, after <main>.
        $this->assertSame(1, substr_count($source, '<?= admin_confirm_dialog() ?>'));
        $this->assertGreaterThan((int) strpos($source, '</main>'), (int) strpos($source, '<?= admin_confirm_dialog() ?>'));
    }

    /**
     * Reordering is untouched: one zone wired to its endpoint, dragged by a
     * handle that sits outside the part that folds away and is the only
     * draggable element on the screen — pressing Verbergen or Verwijderen can
     * never start a drag.
     */
    public function testReorderingStillStartsFromTheHandleAlone(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $this->assertStringContainsString('data-page-section-zone data-reorder-url="/api/admin/reorder-page-sections.php"', $source);
        $this->assertSame(1, substr_count($source, 'draggable="true"'), 'only the handle drags');
        $this->assertStringContainsString('<span class="admin-drag-handle" draggable="true"', $source);

        $script = $this->sourceOf('admin/assets/admin.js');
        $this->assertMatchesRegularExpression('/zone\.querySelectorAll\("\.admin-drag-handle"\)\.forEach\(function \(handle\) \{[\s\S]{0,200}handle\.addEventListener\("dragstart"/', $script);
        $this->assertStringContainsString('body.set("section_ids", sectionIds);', $script);
        $this->assertFileExists(dirname(__DIR__, 2) . '/api/admin/reorder-page-sections.php');
    }

    public function testThePageBuilderInvitesTheFirstBlockUntilThePageHasContent(): void
    {
        $inhoud = $this->panelSources('admin/page.php')['inhoud'];

        $this->assertMatchesRegularExpression(
            '#<\?php if \(!SectionRegistry::hasContentBlocks\(\$allSections\)\): \?>\s*<\?php block_picker_empty_state\(\$availableBlocks !== \[\]\); \?>\s*<\?php elseif \(\$availableBlocks !== \[\]\): \?>\s*<\?php block_picker_button\(\); \?>#',
            $inhoud
        );
        $this->assertStringNotContainsString('page.secties_pagina', $inhoud, 'the old "Nog geen secties" line is replaced, not kept beside it');
    }

    public function testABlockThatWasJustAddedOpensItself(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $this->assertStringContainsString(
            "\$isJustAdded ? ' open data-admin-collapse-open' : ''",
            $source,
            'a new block must be open when the editor lands on it'
        );

        // …and it opens even in a browser that never ran the script, because
        // the `open` attribute is on the element itself.
        $this->assertStringContainsString('$isJustAdded = $addedSectionId === (int) $pageSection[\'id\'];', $source);
    }

    // --- Coming back to where you were ------------------------------------

    public function testEveryBlockRowHasTheAnchorTheWholeReturnPathUses(): void
    {
        $this->assertStringContainsString(
            'id="blok-<?= (int) $pageSection[\'id\'] ?>"',
            $this->sourceOf('admin/page.php')
        );

        $this->assertStringContainsString(
            "'#blok-' . \$newId",
            $this->sourceOf('api/admin/add-page-section.php'),
            'a freshly added block is where the editor is sent'
        );

        $this->assertStringContainsString(
            "'#blok-' . \$id",
            $this->sourceOf('api/admin/toggle-page-section.php'),
            'hiding a block must not throw the editor back to the top of the page'
        );
    }

    public function testTheReturnTargetIsRememberedInTheBrowserAndNotInAnEndpoint(): void
    {
        $script = $this->sourceOf('admin/assets/admin-collapse.js');

        $this->assertStringContainsString('mygdalaAdminReturn:', $script);
        $this->assertStringContainsString('sessionStorage', $script);
        $this->assertStringContainsString('scrollIntoView', $script);

        // Consumed on arrival: coming back from an edit moves the viewport,
        // an ordinary visit later does not.
        $this->assertMatchesRegularExpression('/read\(returnKey\);\s*drop\(returnKey\);/', $script);

        // Its tab first — scrolling to something inside a hidden panel would
        // scroll to nothing.
        $this->assertStringContainsString('AdminTabs.reveal', $script);
    }

    /**
     * The back link on a block editor used to point at
     * /admin/pages.php?page=<slug> — the pages OVERVIEW, which ignores that
     * parameter. "Terug naar Diensten" therefore landed on the list of every
     * page, which is the opposite of coming back to where you were.
     */
    public function testNoBlockEditorSendsTheEditorBackToTheWrongScreen(): void
    {
        $editors = (array) glob(dirname(__DIR__, 2) . '/admin/*.php');
        $this->assertNotSame([], $editors);

        foreach ($editors as $editor) {
            $this->assertStringNotContainsString(
                '/admin/pages.php?page=',
                (string) file_get_contents($editor),
                basename($editor) . ' links back to a URL the pages overview does not answer'
            );
        }
    }

    // --- The save bar keeps working ---------------------------------------

    public function testTheSaveBarStillWatchesTheFormsATabbedScreenRenders(): void
    {
        $saveBar = $this->sourceOf('admin/assets/save-bar.js');

        // Unchanged rule: POST forms inside <main>, minus the action-only
        // ones. Tabs put no form outside <main> and add none of their own.
        $this->assertStringContainsString('main.admin-main form', $saveBar);

        $source = $this->sourceOf('admin/page.php');
        $this->assertStringContainsString('save_bar()', $source);
        $this->assertStringContainsString('save_bar_script()', $source);

        // A hidden panel's fields are still that form's fields, so both of
        // the settings form's save buttons save the whole thing.
        $this->assertSame(2, substr_count($source, "admin_te('page.save_settings') ?></button>"));
    }

    // --- Site-instellingen -------------------------------------------------

    public function testSiteSettingsIsGroupedAndKeepsEveryFieldItHad(): void
    {
        $panels = $this->panelSources('admin/settings.php');
        $this->assertSame(['algemeen', 'talen', 'seo', 'dashboard'], array_keys($panels));

        foreach (self::SETTINGS_FIELDS as $field => $tab) {
            $found = [];
            foreach ($panels as $key => $panel) {
                if (str_contains($panel, 'name="' . $field . '"')) {
                    $found[] = $key;
                }
            }

            $this->assertSame([$tab], $found, $field . ' must be on exactly one tab');
        }

        // Nothing was left outside the tabs on the way in. From <main>
        // onwards, so the <head>'s own name= attributes stay out of it.
        $source = $this->sourceOf('admin/settings.php');
        $body = substr($source, (int) strpos($source, '<main class="admin-main">'));
        preg_match_all('/name="([a-z_]+)"/', $body, $matches);
        foreach (array_unique($matches[1]) as $field) {
            if ($field === 'csrf_token') {
                continue;
            }

            $this->assertArrayHasKey(
                $field,
                self::SETTINGS_FIELDS,
                $field . ' is on this screen but in no tab — add it to this map, and to a panel'
            );
        }

        // The four branding images are pickers rather than inputs, so they
        // are named by their call.
        foreach (['logo_path', 'logo_alt_path', 'favicon_path', 'og_image_path'] as $key) {
            $this->assertStringContainsString("'" . $key . "'", $panels['algemeen']);
        }
    }

    public function testEverySettingsFormStillPostsToItsOwnEndpoint(): void
    {
        $panels = $this->panelSources('admin/settings.php');

        foreach (['algemeen', 'seo'] as $key) {
            $this->assertSame(
                1,
                substr_count($panels[$key], 'action="/api/admin/update-site-settings.php"'),
                $key . ' must still be one form to the settings endpoint'
            );
        }

        // The dashboard skin is about the panel you are standing in, not
        // about the website, and keeps its own endpoint.
        $this->assertStringContainsString('action="/api/admin/update-admin-theme.php"', $panels['dashboard']);
        $this->assertStringNotContainsString('update-site-settings', $panels['dashboard']);
    }

    /**
     * Neither screen's tab structure may depend on which modules are on: a
     * CMS-only deployment gets the same Site-instellingen and the same three
     * page-editor tabs, with the Shop's own cards and blocks doing what they
     * always did inside them.
     */
    public function testTheTabsAreTheSameWithTheShopOnAndOff(): void
    {
        foreach (['admin/page.php', 'admin/settings.php'] as $screen) {
            $source = $this->sourceOf($screen);
            $tabsCall = substr($source, (int) strpos($source, 'admin_tabs_start('), 400);

            $this->assertStringNotContainsString('ModuleRegistry', $tabsCall, $screen);
            $this->assertStringNotContainsString('isEnabled', $tabsCall, $screen);
        }

        foreach ([true, false] as $shopEnabled) {
            ModuleRegistry::overrideForTests(['shop' => $shopEnabled]);

            $this->assertSame(
                ['algemeen', 'talen', 'seo', 'dashboard'],
                array_keys($this->panelSources('admin/settings.php'))
            );
            $this->assertSame(
                ['pagina', 'seo', 'inhoud'],
                array_keys($this->panelSources('admin/page.php'))
            );
        }
    }
}
