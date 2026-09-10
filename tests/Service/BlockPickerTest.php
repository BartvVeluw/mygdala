<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The block picker and the page editor's save bar: the two pieces of UX the
 * page editor gained, checked where they can be checked without a browser.
 *
 * WHAT THIS FILE IS ABOUT. Both features are deliberately thin: the picker is
 * one ordinary POST form whose submit buttons happen to look like cards, and
 * the save bar drives the forms that were already on the screen. The risk is
 * therefore not that they misbehave, it is that they quietly become a SECOND
 * way to do something — a second list of addable blocks that disagrees with
 * the server's, a page-wide endpoint that bypasses per-block validation, a
 * save button that reaches the sidebar's logout form. Those are the
 * properties pinned below.
 *
 * The picker's markup is rendered in-process: admin/_block_picker.php is a
 * plain include that defines two output functions, so it can be required and
 * called with a real definition list without a login, a request or a
 * database. The repository it needs is stubbed for the same reason (see
 * FakePageSectionRepository at the bottom).
 *
 * Behaviour that genuinely needs the database — that creating really attaches
 * exactly one row to the right page, that a capped type stops being offered —
 * is Tests\Service\SectionRegistryTest's, and was already there before the
 * picker existed. This file does not repeat it.
 */
final class BlockPickerTest extends TestCase
{
    /** @var array<string, mixed> a `pages` row shaped like a real one */
    private const PAGE = [
        'id' => 4242,
        'content_key' => '__picker__',
        'slug' => '__picker__',
        'title' => 'Pickertestpagina',
        'status' => 'published',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/_block_picker.php';
    }

    protected function tearDown(): void
    {
        // overrideForTests(null) and not reset(): reset() forgets the caches
        // but KEEPS the override, so a test that switched the Shop off would
        // leave it off for the rest of the run.
        ModuleRegistry::overrideForTests(null);
        parent::tearDown();
    }

    /**
     * @param list<string> $existingTypes types already on the page
     */
    private function renderPicker(array $existingTypes = []): string
    {
        $available = SectionRegistry::availableDefinitionsForPage(
            self::PAGE,
            new FakePageSectionRepository($existingTypes)
        );

        ob_start();
        block_picker_modal($available, (int) self::PAGE['id'], 'test-csrf-token');

        return (string) ob_get_clean();
    }

    private function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // --- The old control is really gone --------------------------------

    public function testThePageBuilderNoLongerAsksForASectionTypeInADropdown(): void
    {
        $source = $this->sourceOf('admin/page.php');

        $this->assertStringNotContainsString(
            'select name="section_type"',
            str_replace(['<select ', '  '], ['select ', ' '], $source),
            'the section-type dropdown must be gone — the picker replaced it'
        );
        $this->assertStringNotContainsString('Sectie toevoegen', $source);
        $this->assertStringNotContainsString('admin-add-section-form', $source);

        $this->assertStringContainsString('block_picker_button()', $source);
        $this->assertStringContainsString('block_picker_modal(', $source);
    }

    // --- What the picker offers ----------------------------------------

    public function testThePickerShowsACardForEveryBlockTheServerWouldAccept(): void
    {
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());
        $html = $this->renderPicker();

        $this->assertNotSame([], $available);

        foreach ($available as $type => $definition) {
            $this->assertStringContainsString(
                'name="section_type" value="' . $type . '"',
                $html,
                "{$type} may be added to this page but has no card"
            );
            $this->assertStringContainsString(htmlspecialchars($definition->label(), ENT_QUOTES, 'UTF-8'), $html);
            $this->assertStringContainsString(htmlspecialchars($definition->description(), ENT_QUOTES, 'UTF-8'), $html);
        }

        $this->assertSame(
            count($available),
            substr_count($html, 'data-block-card'),
            'one card per available block, no more and no fewer'
        );
    }

    /**
     * A fixed block is on a page because the site put it there. It is not in
     * the picker, and posting its type is refused by the same list the picker
     * was drawn from — so a card that is not on screen is a request the
     * server does not honour either.
     */
    public function testABlockNobodyMayAddByHandIsNeitherOfferedNorAccepted(): void
    {
        $html = $this->renderPicker();
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());

        foreach (BlockDefinitions::types() as $type) {
            if (SectionRegistry::isManuallyAddable($type)) {
                continue;
            }

            $this->assertArrayNotHasKey($type, $available, "{$type} is fixed and must not be offered");
            $this->assertStringNotContainsString('value="' . $type . '"', $html);
        }

        $this->assertArrayNotHasKey('quicknav', $available);
        $this->assertArrayNotHasKey('product_grid', $available);
    }

    public function testACappedBlockDisappearsFromThePickerOnceThePageHasIt(): void
    {
        $before = $this->renderPicker();
        $this->assertStringContainsString('value="page_hero"', $before);

        $after = $this->renderPicker(['page_hero']);
        $this->assertStringNotContainsString('value="page_hero"', $after);

        // …while a repeatable one is still offered after the page has one.
        $this->assertStringContainsString('value="rich_text"', $after);
    }

    public function testABlockDeniedOnThisPageIsNotOffered(): void
    {
        $homepage = ['id' => 1, 'content_key' => 'index', 'slug' => '', 'title' => 'Home', 'status' => 'published'];
        $available = SectionRegistry::availableDefinitionsForPage($homepage, new FakePageSectionRepository());

        $this->assertArrayHasKey('homepage_hero', $available, 'the homepage has its own hero');
        $this->assertArrayNotHasKey('page_hero', $available, 'and the ordinary one is denied there');

        $this->assertArrayNotHasKey(
            'homepage_hero',
            SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository()),
            'the homepage hero belongs to the homepage alone'
        );
    }

    // --- One click, one request ----------------------------------------

    public function testChoosingABlockIsTheOnlySubmitActionInThePicker(): void
    {
        $html = $this->renderPicker();

        $this->assertSame(1, substr_count($html, '<form '), 'the picker is one form');
        $this->assertStringContainsString('action="/api/admin/add-page-section.php"', $html);
        $this->assertStringContainsString('name="csrf_token" value="test-csrf-token"', $html);

        // Every submit button IS a block card: there is no separate
        // "toevoegen" button to press afterwards.
        $this->assertSame(
            substr_count($html, 'data-block-card'),
            substr_count($html, 'type="submit"'),
            'a second submit control would put the confirmation step back'
        );
    }

    public function testThePickerIsExcludedFromTheSaveBarsDirtyTracking(): void
    {
        $this->assertStringContainsString(
            'data-no-dirty-track',
            $this->renderPicker(),
            'choosing a block is not an edit that can be "saved later"'
        );
    }

    // --- Search and filter ---------------------------------------------

    public function testEveryCardCarriesTheTermsTheSearchBoxMatchesOn(): void
    {
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());
        $html = $this->renderPicker();

        $this->assertSame(count($available), substr_count($html, 'data-block-terms='));
        $this->assertSame(count($available), substr_count($html, 'data-block-category='));

        $richText = $available['rich_text'];
        $this->assertMatchesRegularExpression(
            '/data-block-terms="[^"]*' . preg_quote(mb_strtolower($richText->label()), '/') . '[^"]*"/',
            $html,
            'the label must be searchable'
        );
        $this->assertMatchesRegularExpression(
            '/data-block-terms="[^"]*' . preg_quote(mb_strtolower(mb_substr($richText->description(), 0, 20)), '/') . '/',
            $html,
            'the description must be searchable'
        );
    }

    public function testSearchTermsNeverContainTheRegistryKey(): void
    {
        preg_match_all('/data-block-terms="([^"]*)"/', $this->renderPicker(), $matches);
        $this->assertNotSame([], $matches[1]);

        foreach ($matches[1] as $terms) {
            foreach (BlockDefinitions::types() as $type) {
                if (!str_contains($type, '_')) {
                    // A one-word key like `form` is also an ordinary word;
                    // only an identifier-shaped string is a leak. Same rule
                    // as Tests\Service\BlockPresentationTest.
                    continue;
                }

                $this->assertStringNotContainsString(
                    $type,
                    $terms,
                    'an editor never sees a registry key, so searching for one must not be a feature'
                );
            }
        }
    }

    public function testTheFilterOffersExactlyTheCategoriesThatHaveCards(): void
    {
        $available = SectionRegistry::availableDefinitionsForPage(self::PAGE, new FakePageSectionRepository());
        $categories = array_unique(array_map(
            static fn ($definition): string => $definition->category(),
            array_values($available)
        ));

        $html = $this->renderPicker();

        foreach ($categories as $category) {
            $this->assertStringContainsString('data-block-picker-filter="' . $category . '"', $html);
            $this->assertStringContainsString('data-block-picker-group="' . $category . '"', $html);
        }

        // Plus the "Alles" reset, which filters on nothing.
        $this->assertSame(count($categories) + 1, substr_count($html, 'data-block-picker-filter='));
    }

    // --- Modules --------------------------------------------------------

    public function testTheShopsBlocksFollowTheirModuleInAndOutOfTheCatalogue(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true]);
        $shopPage = ['id' => 9, 'content_key' => 'shop', 'slug' => 'shop', 'title' => 'Shop', 'status' => 'published'];
        $this->assertArrayHasKey('product_grid', BlockDefinitions::all());

        ModuleRegistry::overrideForTests(['shop' => false]);
        SectionRegistry::reset();

        $html = $this->renderPicker();
        $this->assertStringNotContainsString('value="product_grid"', $html);
        $this->assertStringNotContainsString('value="shop_collections"', $html);

        $this->assertArrayNotHasKey(
            'product_grid',
            SectionRegistry::availableDefinitionsForPage($shopPage, new FakePageSectionRepository()),
            'a switched-off module\'s block is not addable anywhere'
        );

        // Core is unaffected.
        $this->assertStringContainsString('value="rich_text"', $html);
    }

    // --- Accessibility and touch ---------------------------------------

    public function testThePickerIsADialogThatWorksWithoutAMouse(): void
    {
        $html = $this->renderPicker();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="admin-block-picker-title"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('aria-pressed=', $html);

        $script = $this->sourceOf('admin/assets/block-picker.js');
        $this->assertStringContainsString('Escape', $script, 'Escape must close the panel');
        $this->assertStringContainsString('lastFocused', $script, 'focus must return to the opener');
        $this->assertStringContainsString('Tab', $script, 'Tab must stay inside the open dialog');

        $this->assertStringNotContainsString(
            ':hover',
            $script,
            'nothing may be revealed only on hover — a touch device would never see it'
        );
    }

    // --- The save bar ---------------------------------------------------

    public function testEveryScreenReachedFromThePageBuilderCarriesTheSaveBar(): void
    {
        $editors = ['page.php'];
        foreach (BlockDefinitions::all() as $definition) {
            $url = $definition->editUrl([
                'id' => 1,
                'page_slug' => '__picker__',
                'section_type' => $definition->type(),
                'section_key' => 'custom-abcd1234',
                'section_id' => 1,
            ]);

            if ($url !== null && preg_match('#/admin/([a-z0-9-]+)\.php#', $url, $m) === 1) {
                $editors[] = $m[1] . '.php';
            }
        }

        foreach (array_unique($editors) as $editor) {
            $source = $this->sourceOf('admin/' . $editor);
            $this->assertStringContainsString('_save_bar.php', $source, "{$editor} does not include the save bar");
            $this->assertStringContainsString('save_bar()', $source, "{$editor} does not render the save bar");
            $this->assertStringContainsString('save_bar_script()', $source, "{$editor} does not load the save bar's script");
        }
    }

    /**
     * The save bar must never become a page-wide endpoint. It has no URL of
     * its own: it submits the forms that are already on the screen, to the
     * actions those forms already declare.
     */
    public function testTheSaveBarPostsOnlyToTheFormsOwnActions(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertStringNotContainsString(
            '/api/admin/',
            $script,
            'a hardcoded endpoint here would be the page-wide POST this design refuses to build'
        );
        $this->assertStringContainsString('form.action', $script);
        $this->assertStringContainsString('requestSubmit()', $script, 'one dirty form is handed back to the browser');
    }

    /**
     * The sidebar's logout form is an ordinary POST form. A save button that
     * reached it would sign the editor out in the middle of an edit.
     */
    public function testTheSaveBarNeverReachesAFormOutsideThePagesOwnMain(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertStringContainsString('main.admin-main form', $script);
        $this->assertStringContainsString('admin-inline-form', $script, 'the action-only forms are skipped');
        $this->assertStringContainsString('data-no-dirty-track', $script, 'a form may opt out by hand');
    }

    /**
     * "Opgeslagen" is the server's answer, not the browser's guess: every
     * write endpoint in this project appends ?saved=1 / ?updated=1 /
     * ?created=1 on success and redirects back without it on failure.
     */
    public function testTheSaveBarOnlyReportsSuccessTheServerConfirmed(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertStringContainsString('saved|updated|created', $script);
        $this->assertStringContainsString('response.ok', $script);
        $this->assertStringContainsString('markClean', $script);

        $endpoints = (array) glob(dirname(__DIR__, 2) . '/api/admin/update-*.php');
        $this->assertNotSame([], $endpoints);

        $withMarker = 0;
        foreach ($endpoints as $endpoint) {
            $source = (string) file_get_contents($endpoint);
            if (preg_match('/[?&](saved|updated|created)=1/', $source) === 1) {
                $withMarker++;
            }
        }

        $this->assertGreaterThan(
            count($endpoints) * 0.9,
            $withMarker,
            'the success marker is what tells a saved redirect from a rejected one — it must stay near-universal'
        );
    }

    public function testTheLeaveWarningIsOnlyArmedWhileSomethingIsUnsaved(): void
    {
        $script = $this->sourceOf('admin/assets/save-bar.js');

        $this->assertMatchesRegularExpression(
            '/beforeunload[\s\S]{0,400}dirty\.length === 0/',
            $script,
            'a page with nothing unsaved must never warn on leaving'
        );
        $this->assertStringContainsString('leavingOnPurpose', $script, 'a save of our own must not warn');
    }

    /**
     * The rich-text editor rewrites its own field while it loads. Treating
     * that as an edit would mark every freshly opened editor dirty, which is
     * the mistake that makes a dirty indicator worth ignoring.
     */
    public function testARichTextEditorReportsUserEditsOnlyWhileLoadingSilently(): void
    {
        $source = $this->sourceOf('admin/assets/admin.js');

        $this->assertMatchesRegularExpression(
            '/quill\.on\("text-change"[\s\S]{0,600}source === "user"[\s\S]{0,200}dispatchEvent/',
            $source,
            'only a user edit may dispatch the input event the save bar listens for'
        );
    }
}

/**
 * A PageSectionRepository that answers from a fixed list instead of the
 * database, so "what may be added to this page" can be asked in the fast
 * tier. It overrides the constructor because the real one opens a
 * connection, and only findForPage() is ever called on this path.
 */
final class FakePageSectionRepository extends PageSectionRepository
{
    /** @param list<string> $types */
    public function __construct(private array $types = [])
    {
    }

    public function findForPage(int $pageId, bool $onlyActive = false): array
    {
        $rows = [];
        foreach ($this->types as $index => $type) {
            $rows[] = [
                'id' => $index + 1,
                'page_id' => $pageId,
                'section_type' => $type,
                'section_key' => 'custom-0000000' . $index,
                'section_id' => $index + 1,
                'sort_order' => $index,
                'is_active' => 1,
            ];
        }

        return $rows;
    }
}
